<?php

class Notifier {
    public static function dispatch($message, $config) {
        require_once __DIR__ . '/Logger.php';
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
            try {
                $response = wp_remote_post($url, [
                    'timeout' => 10,
                    'body' => $body
                ]);
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $sent = true;
                } else {
                    AutoPostLogger::log('Telegram notify failed', 'notifier', 'error');
                }
            } catch (\Throwable $e) {
                AutoPostLogger::log('Telegram notify error: ' . $e->getMessage(), 'notifier', 'error');
            }
        }

        if (!$sent) {
            AutoPostLogger::log('No notifier target configured; message suppressed', 'notifier', 'info');
        }
    }
}

