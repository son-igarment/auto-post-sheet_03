<?php

class RetryHelper {
    /**
     * Cached config payload for cheap lookups.
     * @var array|null
     */
    private static $configCache = null;

    /**
     * Cached retry profiles map.
     * @var array|null
     */
    private static $profileCache = null;

    /**
     * Cached AI learning stats.
     * @var array|null
     */
    private static $learningCache = null;

    /**
     * Run a callable with retry, adaptive backoff, and AI-assisted learning.
     *
     * @param callable   $fn       Callable receiving attempt index (0-based).
     * @param int|null   $retries  Override retry count or pull from config/profile.
     * @param int|null   $delayMs  Override initial delay in ms or pull from config/profile.
     * @param float|null $factor   Override backoff factor or pull from config/profile.
     * @param string     $context  Logical context name (used for logging + profile lookup).
     * @param int|null   $jitterMs Override jitter or pull from config/profile.
     * @param array      $meta     Additional metadata for adaptive rules.
     *
     * @return mixed
     * @throws \Throwable
     */
    public static function run(
        callable $fn,
        $retries = 3,
        $delayMs = 500,
        $factor = 2.0,
        $context = 'retry',
        $jitterMs = 0,
        array $meta = []
    ) {
        require_once __DIR__ . '/Logger.php';

        self::hydrateParameters($context, $retries, $delayMs, $factor, $jitterMs, $meta);

        $attempt = 0;
        while (true) {
            try {
                $result = $fn($attempt);
                self::recordOutcome($context, $attempt + 1, true, $meta);
                return $result;
            } catch (\Throwable $e) {
                $attempt++;
                AutoPostLogger::log('Attempt ' . $attempt . ' failed: ' . $e->getMessage(), $context, 'error');
                self::recordOutcome($context, $attempt, false, $meta, $e);
                if ($attempt > $retries) {
                    throw $e;
                }
                $sleepMs = self::calculateDelay($delayMs, $jitterMs, $attempt, $context, $meta);
                usleep($sleepMs * 1000);
                $delayMs = max(1, (int)($delayMs * $factor));
            }
        }
    }

    /**
     * Merge config + profile overrides into the active runtime parameters.
     */
    private static function hydrateParameters(&$context, &$retries, &$delayMs, &$factor, &$jitterMs, array &$meta) {
        $config = self::getConfig();
        $profiles = self::getProfiles($config);
        $slug = self::contextSlug($context);
        $profile = $profiles[$slug] ?? ($profiles['default'] ?? []);

        $resolved = array_merge(
            [
                'max_attempts' => intval($config['retry_max_attempts'] ?? 3),
                'delay_ms' => intval($config['retry_initial_delay_ms'] ?? 500),
                'backoff_factor' => floatval($config['retry_backoff_factor'] ?? 1.7),
                'jitter_ms' => intval($config['retry_jitter_ms'] ?? 0)
            ],
            $profile
        );

        if ($retries === null) {
            $retries = max(0, intval($resolved['max_attempts']));
        }
        if ($delayMs === null) {
            $delayMs = max(0, intval($resolved['delay_ms']));
        }
        if ($factor === null) {
            $factor = max(1, floatval($resolved['backoff_factor']));
        }
        if ($jitterMs === null) {
            $jitterMs = max(0, intval($resolved['jitter_ms']));
        }

        $meta['context_slug'] = $slug;
        $meta['profile'] = $profile ? $slug : 'default';
        $meta['use_learning'] = array_key_exists('retry_queue_ai_enabled', $config)
            ? (bool)$config['retry_queue_ai_enabled']
            : true;
        $meta['auto_bot_mode'] = $config['auto_bot_adaptive_mode'] ?? 'off';
    }

    /**
     * Build the delay for the next attempt by applying jitter + adaptive multipliers.
     */
    private static function calculateDelay($delayMs, $jitterMs, $attempt, $context, array $meta) {
        $baseDelay = max(0, intval($delayMs));
        $adaptive = self::learningMultiplier($meta);
        $autoBot = self::autoBotMultiplier($context, $attempt, $meta);
        $delay = (int)round($baseDelay * $adaptive * $autoBot);
        if ($jitterMs > 0) {
            $delay += random_int(-$jitterMs, $jitterMs);
        }
        return max(0, $delay);
    }

    /**
     * Calculate AI queue multiplier based on historical success/fail ratio.
     */
    private static function learningMultiplier(array $meta) {
        if (empty($meta['use_learning']) || empty($meta['context_slug'])) {
            return 1;
        }
        $stats = self::getLearningStats();
        $ctx = $stats[$meta['context_slug']] ?? null;
        if (!$ctx) {
            return 1;
        }
        $success = intval($ctx['success'] ?? 0);
        $fail = intval($ctx['fail'] ?? 0);
        if ($success === 0 && $fail === 0) {
            return 1;
        }
        $ratio = ($fail + 1) / ($success + 1); // >1 => more failures
        $multiplier = 1 + (($ratio - 1) * 0.3);
        return max(0.5, min(2.5, $multiplier));
    }

    /**
     * Extra multiplier for auto bot contexts to "adapt" its behaviour.
     */
    private static function autoBotMultiplier($context, $attempt, array $meta) {
        if (stripos($context, 'auto-bot') === false) {
            return 1;
        }
        $mode = $meta['auto_bot_mode'] ?? 'off';
        switch ($mode) {
            case 'fast':
                return 0.8;
            case 'safe':
                return 1.25 + ($attempt * 0.05);
            case 'ai':
                $learning = self::learningMultiplier($meta);
                $fatigue = 1 + ($attempt * 0.08);
                return max(0.7, min(2.3, $learning * $fatigue));
            default:
                return 1;
        }
    }

    /**
     * Return plugin config cached per request.
     */
    private static function getConfig() {
        if (self::$configCache !== null) {
            return self::$configCache;
        }
        if (!function_exists('get_option')) {
            self::$configCache = [];
        } else {
            $cfg = get_option('auto_post_sheet_config', []);
            self::$configCache = is_array($cfg) ? $cfg : [];
        }
        return self::$configCache;
    }

    /**
     * Build normalized retry profiles map.
     */
    private static function getProfiles($config) {
        if (self::$profileCache !== null) {
            return self::$profileCache;
        }

        $defaults = [
            'default' => [
                'max_attempts' => intval($config['retry_max_attempts'] ?? 3),
                'delay_ms' => intval($config['retry_initial_delay_ms'] ?? 500),
                'backoff_factor' => floatval($config['retry_backoff_factor'] ?? 1.7),
                'jitter_ms' => intval($config['retry_jitter_ms'] ?? 0),
            ],
            'auto_api' => [
                'max_attempts' => 4,
                'delay_ms' => 600,
                'backoff_factor' => 1.8,
                'jitter_ms' => 120,
            ],
            'auto_report' => [
                'max_attempts' => 3,
                'delay_ms' => 800,
                'backoff_factor' => 1.5,
                'jitter_ms' => 60,
            ],
            'dashboard' => [
                'max_attempts' => 2,
                'delay_ms' => 250,
                'backoff_factor' => 1.2,
                'jitter_ms' => 25,
            ],
            'auto_bot' => [
                'max_attempts' => 5,
                'delay_ms' => 420,
                'backoff_factor' => 1.6,
                'jitter_ms' => 80,
            ],
            'crm_charm_contact' => [
                'max_attempts' => 3,
                'delay_ms' => 700,
                'backoff_factor' => 1.8,
                'jitter_ms' => 75,
            ],
        ];

        $profiles = is_array($config['retry_profiles'] ?? null) ? $config['retry_profiles'] : [];
        $normalized = $defaults;
        foreach ($profiles as $key => $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $slug = self::contextSlug($key);
            $override = array_filter([
                'max_attempts' => isset($profile['max_attempts']) ? intval($profile['max_attempts']) : null,
                'delay_ms' => isset($profile['delay_ms']) ? intval($profile['delay_ms']) : null,
                'backoff_factor' => isset($profile['backoff_factor']) ? floatval($profile['backoff_factor']) : null,
                'jitter_ms' => isset($profile['jitter_ms']) ? intval($profile['jitter_ms']) : null,
            ], static function ($value) {
                return $value !== null;
            });
            if (!isset($normalized[$slug])) {
                $normalized[$slug] = $defaults['default'];
            }
            $normalized[$slug] = array_merge($normalized[$slug], $override);
        }

        self::$profileCache = $normalized;
        return self::$profileCache;
    }

    /**
     * Cached AI stats for retry queue learning.
     */
    private static function getLearningStats() {
        if (self::$learningCache !== null) {
            return self::$learningCache;
        }
        if (!function_exists('get_option')) {
            self::$learningCache = [];
        } else {
            $stats = get_option('auto_post_sheet_retry_ai', []);
            self::$learningCache = is_array($stats) ? $stats : [];
        }
        return self::$learningCache;
    }

    /**
     * Persist retry attempt outcome for AI learning.
     */
    private static function recordOutcome($context, $attemptNumber, $success, array $meta = [], \Throwable $e = null) {
        if (empty($meta['use_learning']) || !function_exists('get_option')) {
            return;
        }
        $slug = $meta['context_slug'] ?? self::contextSlug($context);
        $stats = self::getLearningStats();
        if (!isset($stats[$slug])) {
            $stats[$slug] = ['success' => 0, 'fail' => 0, 'avg_attempts' => 0];
        }
        if ($success) {
            $stats[$slug]['success']++;
        } else {
            $stats[$slug]['fail']++;
        }
        $events = max(1, $stats[$slug]['success'] + $stats[$slug]['fail']);
        $stats[$slug]['avg_attempts'] = round(
            (($stats[$slug]['avg_attempts'] * ($events - 1)) + max(1, $attemptNumber)) / $events,
            2
        );
        update_option('auto_post_sheet_retry_ai', $stats);
        self::$learningCache = $stats;
    }

    /**
     * Convert arbitrary context names into reusable slugs.
     */
    private static function contextSlug($context) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', (string)$context));
        $slug = trim($slug, '_');
        return $slug ?: 'default';
    }

    /**
     * Clear cached config/profiles (mainly used by tests).
     */
    public static function resetCaches() {
        self::$configCache = null;
        self::$profileCache = null;
        self::$learningCache = null;
    }

    /**
     * Expose normalized profiles for dashboards / Unified API.
     */
    public static function exportProfiles() {
        return self::getProfiles(self::getConfig());
    }
}
