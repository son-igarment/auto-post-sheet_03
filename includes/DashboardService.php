<?php

require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/Retry.php';

class AutoPostDashboardService {
    const CACHE_KEY = 'aps_dashboard_snapshot';

    /**
     * Return cached dashboard snapshot or rebuild it if TTL expired.
     */
    public static function snapshot($force = false) {
        $config = function_exists('get_option') ? (get_option('auto_post_sheet_config', []) ?: []) : [];
        $ttl = intval($config['dashboard_cache_ttl'] ?? 45);

        if (!$force && $ttl > 0) {
            $cached = AutoPostCache::get(self::CACHE_KEY);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }

        $stats = self::collectStats();
        $learning = function_exists('get_option') ? (get_option('auto_post_sheet_retry_ai', []) ?: []) : [];
        $snapshot = [
            'generated_at' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
            'last_heartbeat' => function_exists('get_option')
                ? get_option('auto_post_sheet_last_heartbeat', 'N/A')
                : 'N/A',
            'stats' => $stats,
            'cache' => [
                'sheet_ttl' => intval($config['cache_ttl'] ?? 60),
                'dashboard_ttl' => $ttl,
                'buster' => AutoPostCache::get_buster(),
            ],
            'auto_api_ready' => !empty($config['sheet_id']) && !empty($config['json_path']),
            'auto_bot_mode' => $config['auto_bot_adaptive_mode'] ?? 'off',
            'retry_profiles' => RetryHelper::exportProfiles(),
            'retry_ai' => $learning,
            'retry_queue_ai_enabled' => array_key_exists('retry_queue_ai_enabled', $config)
                ? (bool)$config['retry_queue_ai_enabled']
                : true,
            'unified_api_version' => '2.0',
        ];

        if ($ttl > 0) {
            AutoPostCache::set(self::CACHE_KEY, $snapshot, max(5, $ttl));
        }

        return $snapshot;
    }

    /**
     * Force next snapshot to rebuild (e.g. after posts processed).
     */
    public static function flush() {
        AutoPostCache::delete(self::CACHE_KEY);
    }

    private static function collectStats() {
        if (!function_exists('get_option')) {
            return self::defaultStats();
        }
        return RetryHelper::run(function () {
            $stats = get_option('auto_post_sheet_stats', self::defaultStats());
            return is_array($stats) ? array_merge(self::defaultStats(), $stats) : self::defaultStats();
        }, null, null, null, 'dashboard', null, ['source' => 'dashboard']);
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

