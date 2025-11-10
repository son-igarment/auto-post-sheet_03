<?php

class Notifier {
    public static function dispatch($message, $config) {
        require_once __DIR__ . '/Logger.php';
        require_once __DIR__ . '/Retry.php';
        require_once __DIR__ . '/Cache.php';
        $sent = false;

        // Email
        if (!empty($config['report_email']) && function_exists('wp_mail')) {
            $sent = wp_mail($config['report_email'], '[AutoPostSheet] Report', $message) || $sent;
        }

        // Telegram
        $bot = $config['telegram_bot_token'] ?? '';
        $chat = $config['telegram_chat_id'] ?? '';
        if ($bot && $chat) {
            $url = 'https://api.telegram.org/bot' . rawurlencode($bot) . '/sendMessage';
            $body = [
                'chat_id' => $chat,
                'text' => $message,
                'parse_mode' => 'HTML'
            ];
            // Dedupe the same message for a short TTL to avoid spam
            $dedupeKey = 'tg_' . md5($bot . '|' . $chat . '|' . $message);
            $dedupeTtl = 30; // seconds
            if (AutoPostCache::get($dedupeKey)) {
                AutoPostLogger::log('Telegram message deduped (skipped duplicate within TTL)', 'notifier', 'info');
                $sent = true; // treat as sent to not trigger error reports
            } else {
            try {
                $attemptUsed = 0;
                $ok = RetryHelper::run(function($attempt) use (&$attemptUsed, $url, $body) {
                    $attemptUsed = $attempt;
                    $response = wp_remote_post($url, [
                        'timeout' => 10,
                        'body' => $body
                    ]);
                    if (is_wp_error($response)) {
                        throw new \Exception($response->get_error_message());
                    }
                    $code = wp_remote_retrieve_response_code($response);
                    if ($code !== 200) {
                        throw new \Exception('HTTP ' . $code);
                    }
                    return true;
                }, null, null, null, 'telegram', null);
                if ($ok) $sent = true;
                if ($attemptUsed > 0) {
                    AutoPostLogger::log('Telegram sent with retry x' . $attemptUsed, 'notifier', 'info');
                }
                if ($sent) {
                    AutoPostCache::set($dedupeKey, 1, $dedupeTtl);
                }
            } catch (\Throwable $e) {
                AutoPostLogger::log('Telegram notify error: ' . $e->getMessage(), 'notifier', 'error');
            }
            }
        }

        if (!$sent) {
            AutoPostLogger::log('No notifier target configured; message suppressed', 'notifier', 'info');
        }
    }
}

