<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clevers_Agent_Reporter {
    public function send_report($manual = false) {
        $options = clevers_agent_get_options();

        if (!$manual && empty($options['enabled'])) {
            return new WP_Error('clevers_agent_disabled', 'Clevers agent disabled.');
        }

        $agent_key = isset($options['agent_key']) ? trim($options['agent_key']) : '';
        if ($agent_key === '') {
            return new WP_Error('clevers_agent_missing_key', 'Agent key missing.');
        }

        $endpoint = $this->build_endpoint($options['endpoint_url']);
        if (!$endpoint) {
            return new WP_Error('clevers_agent_missing_endpoint', 'Endpoint URL missing.');
        }

        $payload = $this->build_payload();

        $args = array(
            'timeout' => 12,
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Website-Agent-Key' => $agent_key,
            ),
            'body' => wp_json_encode($payload),
        );

        $response = $this->post_with_retry($endpoint, $args, 2);

        $this->store_last_status($response);

        return $response;
    }

    public function build_payload() {
        $wordpress = array(
            'version' => get_bloginfo('version'),
            'multisite' => is_multisite(),
            'debug' => defined('WP_DEBUG') ? (bool) WP_DEBUG : false,
        );

        $php = array(
            'version' => PHP_VERSION,
        );

        $plugins = $this->get_plugins();
        $themes = $this->get_themes();
        $health = $this->get_health_summary($plugins, $themes);

        return array(
            'wordpress' => $wordpress,
            'php' => $php,
            'plugins' => $plugins,
            'themes' => $themes,
            'health' => $health,
        );
    }

    private function build_endpoint($base_url) {
        $base_url = trim((string) $base_url);
        if ($base_url === '') {
            return '';
        }

        return rtrim($base_url, '/') . '/api/website-monitoring/agent/report';
    }

    private function post_with_retry($endpoint, $args, $max_attempts = 2) {
        $attempt = 0;
        $response = null;

        while ($attempt < $max_attempts) {
            $attempt++;
            $response = wp_remote_post($endpoint, $args);

            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code > 0 && $code < 500) {
                    break;
                }
            }

            if ($attempt < $max_attempts) {
                sleep(1);
            }
        }

        return $response;
    }

    private function store_last_status($response) {
        update_option(CLEVERS_AGENT_LAST_SENT_AT, current_time('mysql'));

        if (is_wp_error($response)) {
            update_option(CLEVERS_AGENT_LAST_RESPONSE_CODE, 0);
            update_option(CLEVERS_AGENT_LAST_ERROR, $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        update_option(CLEVERS_AGENT_LAST_RESPONSE_CODE, $code);

        if ($code >= 200 && $code < 300) {
            update_option(CLEVERS_AGENT_LAST_ERROR, '');
        } else {
            $message = wp_remote_retrieve_body($response);
            update_option(CLEVERS_AGENT_LAST_ERROR, is_string($message) ? wp_strip_all_tags($message) : '');
        }
    }

    private function get_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $updates = get_site_transient('update_plugins');
        $updates = isset($updates->response) ? $updates->response : array();

        $results = array();
        foreach ($plugins as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') {
                $slug = sanitize_title($data['Name']);
            }

            $results[] = array(
                'name' => sanitize_text_field($data['Name']),
                'slug' => sanitize_text_field($slug),
                'version' => sanitize_text_field($data['Version']),
                'active' => is_plugin_active($file),
                'update_available' => isset($updates[$file]),
            );
        }

        return $results;
    }

    private function get_themes() {
        $themes = wp_get_themes();
        $updates = get_site_transient('update_themes');
        $updates = isset($updates->response) ? $updates->response : array();
        $active = get_stylesheet();

        $results = array();
        foreach ($themes as $stylesheet => $theme) {
            $results[] = array(
                'name' => sanitize_text_field($theme->get('Name')),
                'slug' => sanitize_text_field($stylesheet),
                'version' => sanitize_text_field($theme->get('Version')),
                'active' => $stylesheet === $active,
                'update_available' => isset($updates[$stylesheet]),
            );
        }

        return $results;
    }

    private function get_health_summary($plugins, $themes) {
        $update_counts = $this->get_update_counts();
        $log_counts = $this->get_log_counts();

        return array(
            'updates' => $update_counts,
            'errors_last_24h' => $log_counts['errors'],
            'warnings_last_24h' => $log_counts['warnings'],
        );
    }

    private function get_update_counts() {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $core_updates = get_core_updates();
        $core_count = 0;
        if (is_array($core_updates)) {
            foreach ($core_updates as $update) {
                if (isset($update->response) && $update->response !== 'latest') {
                    $core_count = 1;
                    break;
                }
            }
        }

        $plugin_updates = get_site_transient('update_plugins');
        $plugin_count = isset($plugin_updates->response) ? count($plugin_updates->response) : 0;

        $theme_updates = get_site_transient('update_themes');
        $theme_count = isset($theme_updates->response) ? count($theme_updates->response) : 0;

        return array(
            'core' => $core_count,
            'plugins' => $plugin_count,
            'themes' => $theme_count,
            'total' => $core_count + $plugin_count + $theme_count,
        );
    }

    private function get_log_counts() {
        $counts = array('errors' => 0, 'warnings' => 0);

        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return $counts;
        }

        $log_path = WP_CONTENT_DIR . '/debug.log';
        if (is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '1') {
            $log_path = WP_DEBUG_LOG;
        }
        if (!file_exists($log_path)) {
            return $counts;
        }

        $max_bytes = 2 * 1024 * 1024;
        if (filesize($log_path) > $max_bytes) {
            return $counts;
        }

        $lines = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return $counts;
        }

        $cutoff = time() - DAY_IN_SECONDS;
        foreach ($lines as $line) {
            $timestamp = $this->extract_log_timestamp($line);
            if ($timestamp && $timestamp < $cutoff) {
                continue;
            }

            if (stripos($line, 'warning') !== false) {
                $counts['warnings']++;
            }

            if (stripos($line, 'fatal error') !== false || stripos($line, 'error') !== false) {
                $counts['errors']++;
            }
        }

        return $counts;
    }

    private function extract_log_timestamp($line) {
        if (!preg_match('/\[(.*?)\]/', $line, $matches)) {
            return 0;
        }

        $timestamp = strtotime($matches[1]);
        if (!$timestamp) {
            return 0;
        }

        return $timestamp;
    }

    public function register_shutdown_handler(): void {
        register_shutdown_function(array($this, 'handle_shutdown'));
    }

    public function handle_shutdown(): void {
        $error = error_get_last();
        if (!$error || !is_array($error)) {
            return;
        }

        $fatal_types = array(
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        );

        if (!in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
            return;
        }

        $this->send_emergency_ping($error);
    }

    public function send_emergency_ping(array $error, bool $force = false) {
        $options = clevers_agent_get_options();

        if (empty($options['enabled'])) {
            return false;
        }

        $agent_key = isset($options['agent_key']) ? trim($options['agent_key']) : '';
        if ($agent_key === '') {
            return false;
        }

        $endpoint = $this->build_endpoint($options['endpoint_url']);
        if (!$endpoint) {
            return false;
        }

        // Throttle emergency pings to max 1 per 5 minutes to avoid flood loops during persistent fatals
        if (!$force) {
            $transient_key = 'clevers_agent_emergency_throttle';
            if (function_exists('get_transient') && get_transient($transient_key)) {
                return false;
            }
            if (function_exists('set_transient')) {
                set_transient($transient_key, 1, 5 * MINUTE_IN_SECONDS);
            }
        }

        $type_int = (int) ($error['type'] ?? 0);
        $type_name = $this->get_error_type_name($type_int);

        $payload = array(
            'is_emergency' => true,
            'event_type' => 'fatal_error',
            'occurred_at' => current_time('mysql'),
            'error' => array(
                'type' => $type_int,
                'type_name' => $type_name,
                'message' => (string) ($error['message'] ?? ''),
                'file' => (string) ($error['file'] ?? ''),
                'line' => (int) ($error['line'] ?? 0),
            ),
            'server' => array(
                'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                'request_method' => isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '',
                'http_host' => isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '',
            ),
            'wordpress' => array(
                'version' => function_exists('get_bloginfo') ? get_bloginfo('version') : '',
            ),
            'php' => array(
                'version' => PHP_VERSION,
            ),
        );

        if (defined('CLEVERS_AGENT_LAST_FATAL_ERROR')) {
            update_option(CLEVERS_AGENT_LAST_FATAL_ERROR, $payload['error']);
        }
        if (defined('CLEVERS_AGENT_LAST_EMERGENCY_AT')) {
            update_option(CLEVERS_AGENT_LAST_EMERGENCY_AT, current_time('mysql'));
        }

        $args = array(
            'timeout' => 5,
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Website-Agent-Key' => $agent_key,
                'X-Clevers-Emergency' => 'fatal-error',
            ),
            'body' => wp_json_encode($payload),
        );

        return wp_remote_post($endpoint, $args);
    }

    public function get_error_type_name(int $type): string {
        switch ($type) {
            case E_ERROR:
                return 'E_ERROR';
            case E_PARSE:
                return 'E_PARSE';
            case E_CORE_ERROR:
                return 'E_CORE_ERROR';
            case E_COMPILE_ERROR:
                return 'E_COMPILE_ERROR';
            case E_USER_ERROR:
                return 'E_USER_ERROR';
            case E_WARNING:
                return 'E_WARNING';
            case E_NOTICE:
                return 'E_NOTICE';
            default:
                return 'E_UNKNOWN (' . $type . ')';
        }
    }
}
