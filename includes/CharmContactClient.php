<?php

require_once __DIR__ . '/Retry.php';
require_once __DIR__ . '/Logger.php';

class CharmContactClient {
    /**
     * Push sanitized form data to the configured Charm.Contact CRM endpoint.
     *
     * @throws \InvalidArgumentException
     * @throws \Throwable
     */
    public static function push(array $payload, array $config = []) {
        $endpoint = trim($config['crm_endpoint'] ?? '');
        if (!$endpoint) {
            throw new \InvalidArgumentException('CRM endpoint is not configured');
        }

        $body = self::buildPayload($payload, $config);
        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($config['crm_token'])) {
            $headers['Authorization'] = 'Bearer ' . trim($config['crm_token']);
        }

        return RetryHelper::run(function ($attempt) use ($endpoint, $headers, $body) {
            $response = wp_remote_post($endpoint, [
                'timeout' => 20,
                'headers' => array_merge($headers, ['X-APS-Retry-Attempt' => $attempt]),
                'body' => wp_json_encode($body),
            ]);
            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }
            $code = wp_remote_retrieve_response_code($response);
            $respBody = wp_remote_retrieve_body($response);
            if ($code < 200 || $code >= 300) {
                throw new \Exception('CRM HTTP ' . $code . ': ' . $respBody);
            }
            return [
                'code' => $code,
                'body' => json_decode($respBody, true),
                'raw' => $respBody,
                'attempt' => $attempt,
            ];
        }, null, null, null, 'crm-charm-contact', null, ['channel' => 'crm']);
    }

    /**
     * Build a clean payload shape expected by Charm.Contact.
     */
    public static function buildPayload(array $data, array $config = []) {
        $defaultStage = $config['crm_default_stage'] ?? 'lead';
        return [
            'full_name' => self::sanitizeText($data['full_name'] ?? ($data['name'] ?? 'Unknown Contact')),
            'email' => self::sanitizeEmail($data['email'] ?? ''),
            'phone' => self::sanitizeText($data['phone'] ?? ''),
            'message' => self::sanitizeTextarea($data['message'] ?? ''),
            'stage' => self::sanitizeText($data['stage'] ?? $defaultStage),
            'source' => self::sanitizeText($data['source'] ?? 'auto-post-sheet'),
            'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : [],
            'form_id' => self::sanitizeText($data['form_id'] ?? ''),
            'tracking' => [
                'utm_source' => self::sanitizeText($data['utm_source'] ?? ''),
                'utm_campaign' => self::sanitizeText($data['utm_campaign'] ?? ''),
                'utm_medium' => self::sanitizeText($data['utm_medium'] ?? ''),
            ],
        ];
    }

    /**
     * Send a deterministic test event (used by Unified API "api-test").
     */
    public static function testPayload(array $config = []) {
        $payload = [
            'full_name' => 'Unified API v2.0 Test',
            'email' => 'unified-api-test@example.com',
            'phone' => '+84000000000',
            'message' => 'Charm.Contact sync test',
            'source' => 'api-test',
            'meta' => [
                'generated_at' => time(),
                'site_url' => function_exists('home_url') ? home_url() : 'cli',
            ],
        ];
        return self::push($payload, $config);
    }

    private static function sanitizeText($value) {
        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }
        return trim(strip_tags((string)$value));
    }

    private static function sanitizeEmail($value) {
        if (function_exists('sanitize_email')) {
            return sanitize_email($value);
        }
        return filter_var($value, FILTER_SANITIZE_EMAIL);
    }

    private static function sanitizeTextarea($value) {
        if (function_exists('sanitize_textarea_field')) {
            return sanitize_textarea_field($value);
        }
        return trim(strip_tags($value));
    }
}
