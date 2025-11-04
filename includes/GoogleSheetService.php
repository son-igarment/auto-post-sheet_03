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
            $response = $this->service->spreadsheets_values->get($this->sheet_id, $range);
            $values = $response->getValues();
            $this->log("Fetched " . count($values ?? []) . " rows");
            return $values ?? [];
        } catch (Exception $e) {
            $this->log("Error fetching rows: " . $e->getMessage(), 'error');
            return [];
        }
    }

    public function update_cell($range, $values) {
        try {
            $body = new \Google\Service\Sheets\ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'RAW'];
            $response = $this->service->spreadsheets_values->update($this->sheet_id, $range, $body, $params);
            $this->log("Updated Sheet Range: {$range}, Updated Cells: " . $response->getUpdatedCells());
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
            $body = new \Google\Service\Sheets\ValueRange(['values' => [array_values($values)]]);
            $params = ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS'];
            $response = $this->service->spreadsheets_values->append($this->sheet_id, $range, $body, $params);
            $this->log("Appended row to {$range}");
            return true;
        } catch (Exception $e) {
            $this->log("Error appending row: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function log($msg, $type='info') {
        $prefix = date('Y-m-d H:i:s') . " [GoogleSheetService] ";
        if ($type==='error') error_log($prefix . "ERROR: " . $msg);
        else error_log($prefix . $msg);
    }
}
