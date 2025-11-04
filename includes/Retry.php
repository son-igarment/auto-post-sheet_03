<?php

class RetryHelper {
    /**
     * Run a callable with retry and exponential backoff.
     * @param callable $fn function receiving (attemptIndex) and returning value
     * @param int $retries number of retries on failure
     * @param int $delayMs initial delay in milliseconds
     * @param float $factor backoff factor
     * @param string $context for logging
     */
    public static function run(callable $fn, $retries = 3, $delayMs = 500, $factor = 2.0, $context = 'retry') {
        require_once __DIR__ . '/Logger.php';
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
                usleep((int)($delayMs * 1000));
                $delayMs = (int)($delayMs * $factor);
            }
        }
    }
}

