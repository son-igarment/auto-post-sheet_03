<?php
use Google\Client as GoogleClient;
use Google\Service\Sheets;

class GoogleSheetService {
    private $sheet_id;
    private $service;

    public function __construct($sheet_id, $json_path) {
        $this->sheet_id = $sheet_id;

        $client = new GoogleClient();
        $client->setAuthConfig($json_path);
        $client->addScope(Sheets::SPREADSHEETS);

        $this->service = new Sheets($client);
    }

    public function get_rows($range) {
        try {
            require_once __DIR__ . '/Retry.php';
            require_once __DIR__ . '/Cache.php';
            // Try cache first for fast dashboard responsiveness
            $config = function_exists('get_option') ? get_option('auto_post_sheet_config', []) : [];
            $ttl = intval($config['cache_ttl'] ?? 60);
            $buster = AutoPostCache::get_buster();
            $cacheKey = 'rows_' . md5($this->sheet_id . '|' . $range . '|' . $buster);
            if ($ttl > 0) {
                $cached = AutoPostCache::get($cacheKey);
                if ($cached !== false && is_array($cached)) {
                    $this->log("Served from cache: " . count($cached) . " rows");
                    return $cached;
                }
            }
            $attemptUsed = 0;
            $response = RetryHelper::run(function($attempt) use (&$attemptUsed, $range) {
                $attemptUsed = $attempt;
                return $this->service->spreadsheets_values->get($this->sheet_id, $range);
            }, null, null, null, 'google-sheet-get', null, ['context_slug' => 'auto_api']);
            $values = $response->getValues();
            $this->log("Fetched " . count($values ?? []) . " rows" . ($attemptUsed>0 ? " (retry x{$attemptUsed})" : ''));
            if ($ttl > 0 && is_array($values)) {
                AutoPostCache::set($cacheKey, $values, $ttl);
            }
            return $values ?? [];
        } catch (Exception $e) {
            $this->log("Error fetching rows: " . $e->getMessage(), 'error');
            return [];
        }
    }

    public function update_cell($range, $values) {
        try {
            require_once __DIR__ . '/Retry.php';
            $body = new \Google\Service\Sheets\ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'RAW'];
            $attemptUsed = 0;
            $response = RetryHelper::run(function($attempt) use (&$attemptUsed, $range, $body, $params) {
                $attemptUsed = $attempt;
                return $this->service->spreadsheets_values->update($this->sheet_id, $range, $body, $params);
            }, null, null, null, 'google-sheet-update', null, ['context_slug' => 'auto_api']);
            $this->log("Updated Sheet Range: {$range}, Updated Cells: " . $response->getUpdatedCells() . ($attemptUsed>0 ? " (retry x{$attemptUsed})" : ''));
            // Invalidate cache via buster increment
            require_once __DIR__ . '/Cache.php';
            AutoPostCache::bump_buster();
            return true;
        } catch (Exception $e) {
            $this->log("Error updating sheet: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function append_row($values, $range = null) {
        // If range not specified, default to first sheet, columns A:G
        $range = $range ?: 'A:G';
        try {
            require_once __DIR__ . '/Retry.php';
            $body = new \Google\Service\Sheets\ValueRange(['values' => [array_values($values)]]);
            $params = ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS'];
            $attemptUsed = 0;
            RetryHelper::run(function($attempt) use (&$attemptUsed, $range, $body, $params) {
                $attemptUsed = $attempt;
                return $this->service->spreadsheets_values->append($this->sheet_id, $range, $body, $params);
            }, null, null, null, 'google-sheet-append', null, ['context_slug' => 'auto_api']);
            $this->log("Appended row to {$range}" . ($attemptUsed>0 ? " (retry x{$attemptUsed})" : ''));
            // Invalidate cache via buster increment
            require_once __DIR__ . '/Cache.php';
            AutoPostCache::bump_buster();
            return true;
        } catch (Exception $e) {
            $this->log("Error appending row: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function log($msg, $type='info') {
        if (class_exists('AutoPostLogger')) {
            AutoPostLogger::log($msg, 'google', $type);
            return;
        }
        $prefix = date('Y-m-d H:i:s') . " [GoogleSheetService] ";
        if ($type==='error') error_log($prefix . "ERROR: " . $msg);
        else error_log($prefix . $msg);
    }
}
