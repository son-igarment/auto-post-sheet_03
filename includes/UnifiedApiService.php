<?php

require_once __DIR__ . '/DashboardService.php';
require_once __DIR__ . '/CharmContactClient.php';
require_once __DIR__ . '/Retry.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/GoogleSheetService.php';

class UnifiedApiService {
    public const VERSION = '2.0';

    /**
     * Main entry for /auto-post-sheet/v2/unified.
     */
    public static function handle(\WP_REST_Request $request) {
        $action = $request->get_param('action') ?: 'status';
        switch ($action) {
            case 'status':
            case 'dashboard':
                return self::status($request);
            case 'retry-queue':
                return self::retryQueue();
            case 'auto-report':
                return self::autoReport($request);
            case 'auto-api':
                return self::autoApi($request);
            case 'auto-bot':
                return self::autoBot($request);
            case 'api-test':
                return self::apiTest($request);
            case 'form-to-crm':
                return self::formToCrm($request);
            default:
                return new \WP_Error('unknown_action', 'Unsupported Unified API action', ['status' => 400]);
        }
    }

    /**
     * Dedicated route for CRM form pushes.
     */
    public static function formToCrm(\WP_REST_Request $request) {
        $config = function_exists('get_option') ? (get_option('auto_post_sheet_config', []) ?: []) : [];
        $payload = $request->get_json_params();
        if (empty($payload)) {
            $payload = $request->get_body_params();
        }
        if (empty($payload)) {
            return new \WP_Error('invalid_payload', 'Missing form data payload', ['status' => 400]);
        }
        try {
            $result = CharmContactClient::push($payload, $config);
            return [
                'ok' => true,
                'version' => self::VERSION,
                'crm' => $result,
            ];
        } catch (\Throwable $e) {
            AutoPostLogger::log('CRM sync failed: ' . $e->getMessage(), 'unified-api', 'error');
            return new \WP_Error('crm_error', $e->getMessage(), ['status' => 500]);
        }
    }

    private static function status(\WP_REST_Request $request) {
        $force = filter_var($request->get_param('refresh'), FILTER_VALIDATE_BOOLEAN);
        $snapshot = AutoPostDashboardService::snapshot($force);
        return [
            'ok' => true,
            'version' => self::VERSION,
            'snapshot' => $snapshot,
        ];
    }

    private static function retryQueue() {
        $ai = function_exists('get_option') ? (get_option('auto_post_sheet_retry_ai', []) ?: []) : [];
        return [
            'ok' => true,
            'version' => self::VERSION,
            'profiles' => RetryHelper::exportProfiles(),
            'learning' => $ai,
        ];
    }

    private static function autoReport(\WP_REST_Request $request) {
        $config = function_exists('get_option') ? (get_option('auto_post_sheet_config', []) ?: []) : [];
        $stats = function_exists('get_option')
            ? get_option('auto_post_sheet_stats', self::defaultStats())
            : self::defaultStats();
        $retry_ok = intval($stats['retry_ok'] ?? 0);
        $retry_fail = intval($stats['retry_fail'] ?? 0);
        $retry_total = max(0, $retry_ok + $retry_fail);
        $retry_rate = $retry_total > 0 ? round(($retry_ok / $retry_total) * 100) : null;

        $message = sprintf(
            "Auto Report %s\nPosted: %d\nFailed: %d\nRetry OK: %d\nRetry Fail: %d%s\nWebhook OK: %d\nWebhook Fail: %d",
            function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
            intval($stats['posted'] ?? 0),
            intval($stats['failed'] ?? 0),
            $retry_ok,
            $retry_fail,
            is_null($retry_rate) ? '' : ("\nRetry Success Rate: " . $retry_rate . "%"),
            intval($stats['webhook_ok'] ?? 0),
            intval($stats['webhook_fail'] ?? 0)
        );

        $sent = false;
        if ($request->get_param('send_now')) {
            $sent = RetryHelper::run(function () use ($message, $config) {
                Notifier::dispatch($message, $config);
                return true;
            }, null, null, null, 'auto-report', null, ['context_slug' => 'auto_report']);
        }

        return [
            'ok' => true,
            'version' => self::VERSION,
            'message' => $message,
            'stats' => $stats,
            'sent' => (bool)$sent,
        ];
    }

    private static function autoApi(\WP_REST_Request $request) {
        $config = function_exists('get_option') ? (get_option('auto_post_sheet_config', []) ?: []) : [];
        $ready = !empty($config['sheet_id']) && !empty($config['json_path']) && !empty($config['sheet_range']);
        $payload = [
            'ok' => true,
            'version' => self::VERSION,
            'ready' => $ready,
            'sheet_id' => $config['sheet_id'] ?? '',
            'sheet_range' => $config['sheet_range'] ?? '',
            'retry_profile' => RetryHelper::exportProfiles()['auto_api'] ?? [],
            'cache_ttl' => intval($config['cache_ttl'] ?? 60),
        ];

        if ($ready && $request->get_param('ping')) {
            try {
                $service = new GoogleSheetService($config['sheet_id'], $config['json_path']);
                $range = $config['sheet_range'];
                $rows = $service->get_rows($range);
                $payload['ping'] = [
                    'rows' => count($rows ?? []),
                    'sample' => array_slice($rows ?? [], 0, 3),
                ];
            } catch (\Throwable $e) {
                $payload['ping'] = ['error' => $e->getMessage()];
            }
        }

        return $payload;
    }

    private static function autoBot(\WP_REST_Request $request) {
        $config = function_exists('get_option') ? (get_option('auto_post_sheet_config', []) ?: []) : [];
        $mode = $config['auto_bot_adaptive_mode'] ?? 'off';
        $ai = function_exists('get_option') ? (get_option('auto_post_sheet_retry_ai', []) ?: []) : [];
        $botStats = $ai['auto_bot'] ?? ['success' => 0, 'fail' => 0, 'avg_attempts' => 0];

        $response = [
            'ok' => true,
            'version' => self::VERSION,
            'mode' => $mode,
            'stats' => $botStats,
        ];

        if ($request->get_param('simulate')) {
            try {
                $result = RetryHelper::run(function ($attempt) {
                    if ($attempt < 1) {
                        throw new \Exception('Adaptive guard simulated fail');
                    }
                    return 'recovered';
                }, null, null, null, 'auto-bot-sim', null, [
                    'auto_bot_mode' => $mode,
                    'context_slug' => 'auto_bot',
                    'use_learning' => true,
                ]);
                $response['simulation'] = ['result' => $result];
            } catch (\Throwable $e) {
                $response['simulation'] = ['error' => $e->getMessage()];
            }
        }

        if ($request->get_param('prime_cache')) {
            AutoPostDashboardService::flush();
            AutoPostCache::bump_buster();
            $response['cache_primed'] = true;
        }

        return $response;
    }

    private static function apiTest(\WP_REST_Request $request) {
        $config = function_exists('get_option') ? (get_option('auto_post_sheet_config', []) ?: []) : [];
        try {
            $crm = CharmContactClient::testPayload($config);
            return [
                'ok' => true,
                'version' => self::VERSION,
                'crm' => $crm,
            ];
        } catch (\Throwable $e) {
            return new \WP_Error('api_test_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    private static function defaultStats() {
        return [
            'posted' => 0,
            'failed' => 0,
            'webhook_ok' => 0,
            'webhook_fail' => 0,
            'retry_ok' => 0,
            'retry_fail' => 0,
        ];
    }
}
