<?php
/*
Plugin Name: Auto Post From Google Sheet
Description: Tự động đăng bài từ Google Sheet lên WordPress bằng Service Account JSON
Version: 2.0
Author: Le Viet
*/

if (!defined('ABSPATH')) exit;

// Load Composer autoload (Google API Client)
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

require_once plugin_dir_path(__FILE__) . 'includes/GoogleSheetService.php';
require_once plugin_dir_path(__FILE__) . 'includes/AutoPostHandler.php';

class AutoPostSheet {
    private $option_name = 'auto_post_sheet_config';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_auto_post_from_sheet', [$this, 'handle']);

        // Cron schedules and background jobs
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('auto_post_sheet_heartbeat', [$this, 'cron_heartbeat']);
        add_action('auto_post_sheet_daily_report_morning', [$this, 'cron_daily_report']);
        add_action('auto_post_sheet_daily_report_evening', [$this, 'cron_daily_report']);

        // REST endpoint for ClickUp webhook -> Sheet
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Lifecycle
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function menu() {
        add_menu_page('Auto Post Sheet', 'Auto Post Sheet', 'manage_options', 'auto-post-sheet', [$this, 'render']);
    }

    public function render() {
        $config = get_option($this->option_name, []);
        $success = isset($_GET['success']);
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';
        ?>
        <div class="wrap">
            <h1>Auto Post from Google Sheet</h1>

            <h2>System Dashboard</h2>
            <?php
                $stats = get_option('auto_post_sheet_stats', [
                    'posted'=>0,
                    'failed'=>0,
                    'webhook_ok'=>0,
                    'webhook_fail'=>0,
                    'retry_ok'=>0,
                    'retry_fail'=>0
                ]);
                $last_hb = get_option('auto_post_sheet_last_heartbeat', 'N/A');
                $cache_ttl_current = intval($config['cache_ttl'] ?? 60);
            ?>
            <ul>
                <li><strong>Last Heartbeat:</strong> <?php echo esc_html($last_hb); ?></li>
                <li><strong>Posted:</strong> <?php echo intval($stats['posted'] ?? 0); ?>,
                    <strong>Failed:</strong> <?php echo intval($stats['failed'] ?? 0); ?></li>
                <li><strong>Retry OK:</strong> <?php echo intval($stats['retry_ok'] ?? 0); ?>,
                    <strong>Retry Fail:</strong> <?php echo intval($stats['retry_fail'] ?? 0); ?></li>
                <li><strong>Webhook OK:</strong> <?php echo intval($stats['webhook_ok'] ?? 0); ?>,
                    <strong>Webhook Fail:</strong> <?php echo intval($stats['webhook_fail'] ?? 0); ?></li>
                <li><strong>Cache TTL:</strong> <?php echo $cache_ttl_current; ?> seconds</li>
            </ul>

            <?php if ($success): ?>
                <div class="notice notice-success"><p>Posts processed successfully!</p></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('auto_post_from_sheet_nonce'); ?>
                <input type="hidden" name="action" value="auto_post_from_sheet">

                <label>Google Sheet ID:</label><br>
                <input type="text" name="sheet_id" value="<?php echo esc_attr($config['sheet_id'] ?? ''); ?>" style="width:400px"><br><br>

                <label>Service Account JSON File:</label><br>
                <input type="file" name="service_json" accept=".json"><br><br>

                <label>Sheet Name & Range (VD: Trang tính1!A1:G1000):</label><br>
                <input type="text" name="sheet_range" value="<?php echo esc_attr($config['sheet_range'] ?? ''); ?>" style="width:400px"><br><br>

                <h2>Performance</h2>
                <label>Cache TTL (seconds):</label><br>
                <input type="number" min="0" step="1" name="cache_ttl" value="<?php echo esc_attr(intval($config['cache_ttl'] ?? 60)); ?>" style="width:150px"><br><br>

                <h2>Integrations</h2>
                <label>Socials Webhook URL (optional):</label><br>
                <input type="url" name="social_webhook_url" value="<?php echo esc_attr($config['social_webhook_url'] ?? ''); ?>" style="width:500px" placeholder="https://your-bot-central.example.com/webhooks/socials"><br><br>

                <label>Enable platforms (comma-separated, e.g. facebook,twitter,linkedin,tiktok):</label><br>
                <input type="text" name="enabled_platforms" value="<?php echo esc_attr(implode(',', $config['enabled_platforms'] ?? [])); ?>" style="width:500px"><br><br>

                <h3>Notifications</h3>
                <label>Report Email (optional):</label><br>
                <input type="email" name="report_email" value="<?php echo esc_attr($config['report_email'] ?? ''); ?>" style="width:300px"><br><br>

                <label>Telegram Bot Token (optional):</label><br>
                <input type="text" name="telegram_bot_token" value="<?php echo esc_attr($config['telegram_bot_token'] ?? ''); ?>" style="width:300px"><br><br>

                <label>Telegram Chat ID (optional):</label><br>
                <input type="text" name="telegram_chat_id" value="<?php echo esc_attr($config['telegram_chat_id'] ?? ''); ?>" style="width:300px"><br><br>

                <input type="submit" class="button button-primary" value="Fetch & Auto Post">
            </form>
        </div>
        <?php
    }

    public function handle() {
        if (!current_user_can('manage_options')) wp_die('Access denied');
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'auto_post_from_sheet_nonce')) wp_die('Security check failed');

        $sheet_id = sanitize_text_field($_POST['sheet_id']);
        $sheet_range = sanitize_text_field($_POST['sheet_range']);

        if (empty($sheet_id) || empty($sheet_range)) {
            wp_redirect(admin_url('admin.php?page=auto-post-sheet&error=Missing+fields'));
            exit;
        }

        // Upload JSON file
        if (!empty($_FILES['service_json']['tmp_name'])) {
            $uploaded_file = $_FILES['service_json']['tmp_name'];
            $json_content = file_get_contents($uploaded_file);
            $json_path = plugin_dir_path(__FILE__) . 'service_account.json';
            file_put_contents($json_path, $json_content);
        } elseif (isset(get_option($this->option_name)['json_path'])) {
            $json_path = get_option($this->option_name)['json_path'];
        } else {
            wp_redirect(admin_url('admin.php?page=auto-post-sheet&error=Service+Account+JSON+required'));
            exit;
        }

        // Save config
        $enabled_platforms = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['enabled_platforms'] ?? ''))));
        update_option($this->option_name, [
            'sheet_id' => $sheet_id,
            'sheet_range' => $sheet_range,
            'json_path' => $json_path,
            'social_webhook_url' => esc_url_raw($_POST['social_webhook_url'] ?? ''),
            'enabled_platforms' => $enabled_platforms,
            'report_email' => sanitize_email($_POST['report_email'] ?? ''),
            'telegram_bot_token' => sanitize_text_field($_POST['telegram_bot_token'] ?? ''),
            'telegram_chat_id' => sanitize_text_field($_POST['telegram_chat_id'] ?? ''),
            'cache_ttl' => max(0, intval($_POST['cache_ttl'] ?? 60))
        ]);

        $service = new GoogleSheetService($sheet_id, $json_path);
        $data = $service->get_rows($sheet_range);

        if (empty($data)) {
            wp_redirect(admin_url('admin.php?page=auto-post-sheet&error=No+data+found'));
            exit;
        }

        $poster = new AutoPostHandler($service);
        $poster->process($data);

        wp_redirect(admin_url('admin.php?page=auto-post-sheet&success=1'));
        exit;
    }

    // ---- Scheduling & REST ----
    public function register_cron_schedules($schedules) {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 5 * 60,
                'display' => __('Every Five Minutes')
            ];
        }
        return $schedules;
    }

    public function activate() {
        if (!wp_next_scheduled('auto_post_sheet_heartbeat')) {
            wp_schedule_event(time() + 60, 'five_minutes', 'auto_post_sheet_heartbeat');
        }
        if (!wp_next_scheduled('auto_post_sheet_daily_report_morning')) {
            $ts = $this->next_occurrence('08:00');
            wp_schedule_event($ts, 'daily', 'auto_post_sheet_daily_report_morning');
        }
        if (!wp_next_scheduled('auto_post_sheet_daily_report_evening')) {
            $ts = $this->next_occurrence('22:00');
            wp_schedule_event($ts, 'daily', 'auto_post_sheet_daily_report_evening');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('auto_post_sheet_heartbeat');
        wp_clear_scheduled_hook('auto_post_sheet_daily_report_morning');
        wp_clear_scheduled_hook('auto_post_sheet_daily_report_evening');
    }

    private function next_occurrence($time_hhmm) {
        $today = current_time('timestamp');
        $target_today = strtotime(date('Y-m-d', $today) . ' ' . $time_hhmm, $today);
        return ($target_today > $today) ? $target_today : strtotime('+1 day', $target_today);
    }

    public function cron_heartbeat() {
        update_option('auto_post_sheet_last_heartbeat', current_time('mysql'));
        require_once plugin_dir_path(__FILE__) . 'includes/Logger.php';
        $stats = get_option('auto_post_sheet_stats', [
            'posted'=>0,
            'failed'=>0,
            'webhook_ok'=>0,
            'webhook_fail'=>0,
            'retry_ok'=>0,
            'retry_fail'=>0
        ]);
        AutoPostLogger::log(
            'Heartbeat - posted=' . ($stats['posted'] ?? 0)
            . ', failed=' . ($stats['failed'] ?? 0)
            . ', retry_ok=' . ($stats['retry_ok'] ?? 0)
            . ', retry_fail=' . ($stats['retry_fail'] ?? 0)
            . ', wh_ok=' . ($stats['webhook_ok'] ?? 0)
            . ', wh_fail=' . ($stats['webhook_fail'] ?? 0),
            'cron'
        );
    }

    public function cron_daily_report() {
        $config = get_option($this->option_name, []);
        $stats = get_option('auto_post_sheet_stats', [
            'posted'=>0,
            'failed'=>0,
            'webhook_ok'=>0,
            'webhook_fail'=>0,
            'retry_ok'=>0,
            'retry_fail'=>0
        ]);
        $retry_ok = intval($stats['retry_ok'] ?? 0);
        $retry_fail = intval($stats['retry_fail'] ?? 0);
        $retry_total = max(0, $retry_ok + $retry_fail);
        $retry_rate = $retry_total > 0 ? round(($retry_ok / $retry_total) * 100) : null;
        $success100 = ($retry_ok > 0 && $retry_fail === 0);
        $message = sprintf(
            "Auto Report %s\nPosted: %d\nFailed: %d\nRetry OK: %d\nRetry Fail: %d%s%s\nWebhook OK: %d\nWebhook Failed: %d",
            current_time('mysql'),
            intval($stats['posted'] ?? 0),
            intval($stats['failed'] ?? 0),
            $retry_ok,
            $retry_fail,
            is_null($retry_rate) ? '' : ('\nRetry Success Rate: ' . $retry_rate . '%'),
            $success100 ? "\nRetry thành công 100%" : '',
            intval($stats['webhook_ok'] ?? 0),
            intval($stats['webhook_fail'] ?? 0)
        );
        require_once plugin_dir_path(__FILE__) . 'includes/Notifier.php';
        Notifier::dispatch($message, $config);
        update_option('auto_post_sheet_stats', [
            'posted'=>0,
            'failed'=>0,
            'webhook_ok'=>0,
            'webhook_fail'=>0,
            'retry_ok'=>0,
            'retry_fail'=>0
        ]);
    }

    public function register_rest_routes() {
        register_rest_route('auto-post-sheet/v1', '/clickup-webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_clickup_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_clickup_webhook($request) {
        $params = $request->get_json_params();
        $config = get_option($this->option_name, []);
        $sheet_id = $config['sheet_id'] ?? '';
        $json_path = $config['json_path'] ?? '';
        if (!$sheet_id || !$json_path) {
            return new WP_REST_Response(['ok'=>false,'error'=>'Sheet not configured'], 400);
        }
        $service = new GoogleSheetService($sheet_id, $json_path);
        $title = sanitize_text_field($params['title'] ?? '');
        $content = sanitize_textarea_field($params['content'] ?? '');
        $author = sanitize_text_field($params['author'] ?? 'admin');
        $telegram = sanitize_text_field($params['telegram'] ?? '');
        if (empty($title) || empty($content)) {
            return new WP_REST_Response(['ok'=>false,'error'=>'Missing title/content'], 400);
        }
        $service->append_row([$title, $content, $author, 'ready', $telegram, '']);
        return ['ok'=>true];
    }
}

new AutoPostSheet();
