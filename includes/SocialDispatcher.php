<?php

class SocialDispatcher {
    public static function dispatch($title, $content, $url, $config) {
        require_once __DIR__ . '/Retry.php';
        require_once __DIR__ . '/Logger.php';

        $webhook = $config['social_webhook_url'] ?? '';
        $platforms = $config['enabled_platforms'] ?? [];
        if (!$webhook || empty($platforms)) {
            AutoPostLogger::log('No social webhook or platforms configured', 'social', 'info');
            return ['ok' => false, 'skipped' => true];
        }

        $payload = [
            'title' => $title,
            'content' => $content,
            'url' => $url,
            'platforms' => array_values($platforms)
        ];

        try {
            $attemptUsed = 0;
            $result = RetryHelper::run(function ($attempt) use (&$attemptUsed, $webhook, $payload) {
                $attemptUsed = $attempt;
                $response = wp_remote_post($webhook, [
                    'timeout' => 15,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => wp_json_encode($payload)
                ]);
                if (is_wp_error($response)) {
                    throw new \Exception($response->get_error_message());
                }
                $code = wp_remote_retrieve_response_code($response);
                if ($code < 200 || $code >= 300) {
                    throw new \Exception('HTTP ' . $code);
                }
                return true;
            }, 3, 800, 1.8, 'social-webhook');

            if ($result === true) {
                self::bump_stat('webhook_ok');
                $suffix = $attemptUsed>0 ? (' (retry x' . $attemptUsed . ')') : '';
                AutoPostLogger::log('Social webhook dispatched successfully' . $suffix, 'social', 'info');
                return ['ok' => true];
            }
        } catch (\Throwable $e) {
            self::bump_stat('webhook_fail');
            AutoPostLogger::log('Social webhook failed: ' . $e->getMessage(), 'social', 'error');
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => false];
    }

    private static function bump_stat($key) {
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

