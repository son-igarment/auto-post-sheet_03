<?php

class RetryHelper {
    /**
     * Run a callable with retry and exponential backoff.
     * @param callable $fn function receiving (attemptIndex) and returning value
     * @param int|null $retries number of retries on failure (null => read from settings)
     * @param int|null $delayMs initial delay in milliseconds (null => read from settings)
     * @param float|null $factor backoff factor (null => read from settings)
     * @param string $context for logging
     * @param int|null $jitterMs add +/- jitter in milliseconds (null => read from settings)
     */
    public static function run(callable $fn, $retries = 3, $delayMs = 500, $factor = 2.0, $context = 'retry', $jitterMs = 0) {
        require_once __DIR__ . '/Logger.php';
        // Allow config-driven overrides when nulls provided
        if ($retries === null || $delayMs === null || $factor === null || $jitterMs === null) {
            $cfg = function_exists('get_option') ? (get_option('auto_post_sheet_config', []) ?: []) : [];
            if ($retries === null) $retries = intval($cfg['retry_max_attempts'] ?? 3);
            if ($delayMs === null) $delayMs = intval($cfg['retry_initial_delay_ms'] ?? 500);
            if ($factor === null) $factor = floatval($cfg['retry_backoff_factor'] ?? 1.7);
            if ($jitterMs === null) $jitterMs = intval($cfg['retry_jitter_ms'] ?? 0);
        }
        $attempt = 0;
        while (true) {
            try {
                return $fn($attempt);
            } catch (\Throwable $e) {
                $attempt++;
                AutoPostLogger::log('Attempt ' . $attempt . ' failed: ' . $e->getMessage(), $context, 'error');
                if ($attempt > $retries) {
                    throw $e;
                }
                $sleepMs = (int)max(0, $delayMs + ($jitterMs > 0 ? random_int(-$jitterMs, $jitterMs) : 0));
                usleep($sleepMs * 1000);
                $delayMs = (int)($delayMs * $factor);
            }
        }
    }
}

