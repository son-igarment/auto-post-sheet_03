<?php
class AutoPostHandler {
    private $sheetService;

    public function __construct($sheetService) {
        $this->sheetService = $sheetService;
        require_once __DIR__ . '/Logger.php';
        require_once __DIR__ . '/Retry.php';
        require_once __DIR__ . '/SocialDispatcher.php';
        require_once __DIR__ . '/Notifier.php';
    }

    public function process($rows) {
        $this->log("=== AutoPostHandler started ===");
        foreach ($rows as $index => $row) {
            $row_number = $index + 1; // Sheet bắt đầu từ dòng 2
            $title = trim($row[1] ?? '');
            $content = trim($row[2] ?? '');
            $author = strtolower(trim($row[3] ?? 'admin'));
            $status = strtolower(trim($row[4] ?? ''));
            $telegram = trim($row[5] ?? '');
            $post_url = trim($row[6] ?? '');

            if ($status !== 'ready') continue;
            if (empty($title) || empty($content)) {
                $this->sheetService->update_cell("E{$row_number}:E{$row_number}", [['Missing title/content']]);
                continue;
            }

            // mark processing so heartbeat logs can reflect progress
            $this->sheetService->update_cell("E{$row_number}:E{$row_number}", [['Processing']]);

            if ($existing = post_exists($title)) {
                $url = get_permalink($existing);
                $this->sheetService->update_cell("A{$row_number}:G{$row_number}", [
                    [$existing, $title, $content, $author, 'Posted', $telegram, $url]
                ]);
                continue;
            }

            $user = get_user_by('login', $author);
            $author_id = $user ? $user->ID : 1;

            // Insert with retry policy and attempt tracking
            $attemptUsed = 0;
            try {
                $post_id = RetryHelper::run(function($attempt) use (&$attemptUsed, $title, $content, $author_id) {
                    $attemptUsed = $attempt;
                    $result = wp_insert_post([
                        'post_title' => $title,
                        'post_content' => $content,
                        'post_status' => 'publish',
                        'post_author' => $author_id
                    ]);
                    if (is_wp_error($result) || intval($result) <= 0) {
                        throw new \Exception('wp_insert_post failed');
                    }
                    return $result;
                }, null, null, null, 'wp-insert-post', null);
            } catch (\Throwable $e) {
                $post_id = 0;
                $this->log('Insert post error: ' . $e->getMessage());
            }

            if ($post_id) {
                $url = get_permalink($post_id);
                $statusText = 'Posted';
                if ($attemptUsed > 0) {
                    $statusText = "Posted (Retry OK x{$attemptUsed})";
                    $this->bump_stat('retry_ok');
                    // Optional notify on retry success
                    $config = get_option('auto_post_sheet_config', []);
                    $message = sprintf('Retry thành công: "%s" sau %d lần thử. URL: %s', $title, $attemptUsed, $url);
                    Notifier::dispatch($message, $config);
                }
                $this->sheetService->update_cell("A{$row_number}:G{$row_number}", [
                    [$post_id, $title, $content, $author, $statusText, $telegram, $url]
                ]);
                $this->bump_stat('posted');

                // Dispatch to socials via webhook if configured
                $config = get_option('auto_post_sheet_config', []);
                $res = SocialDispatcher::dispatch($title, $content, $url, $config);
                if (!empty($res['error'])) {
                    $this->sheetService->update_cell("E{$row_number}:E{$row_number}", [["Posted (social err)"]]);
                }
            } else {
                $this->sheetService->update_cell("E{$row_number}:E{$row_number}", [['Error (Retry Fail)']]);
                $this->bump_stat('failed');
                $this->bump_stat('retry_fail');
            }
        }
        $this->log("=== AutoPostHandler finished ===");
    }

    private function log($msg) {
        if (class_exists('AutoPostLogger')) {
            AutoPostLogger::log($msg, 'handler');
        } else {
            error_log(date('Y-m-d H:i:s') . " [AutoPostHandler] " . $msg);
        }
    }

    private function bump_stat($key) {
        $stats = get_option('auto_post_sheet_stats', [
            'posted'=>0,
            'failed'=>0,
            'webhook_ok'=>0,
            'webhook_fail'=>0,
            'retry_ok'=>0,
            'retry_fail'=>0
        ]);
        $stats[$key] = intval($stats[$key] ?? 0) + 1;
        update_option('auto_post_sheet_stats', $stats);
    }
}
