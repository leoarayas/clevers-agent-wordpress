<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clevers_Agent_Remote {
    const REGISTER_PATH = '/api/website-monitoring/agent/register';
    const HEARTBEAT_PATH = '/api/website-monitoring/agent/heartbeat';
    const COMMAND_PULL_PATH = '/api/website-monitoring/agent/commands/pull';
    const COMMAND_RESULT_PATH = '/api/website-monitoring/agent/commands/result';

    private $reporter;

    public function __construct($reporter) {
        $this->reporter = $reporter;
    }

    public function register_site($force = false) {
        $state = clevers_agent_get_remote_state();
        if (!$force && !empty($state['site_id'])) {
            return array(
                'ok' => true,
                'message' => 'Site already registered.',
                'site_id' => $state['site_id'],
            );
        }

        $payload = array(
            'site_url' => site_url(),
            'home_url' => home_url(),
            'name' => get_bloginfo('name'),
            'admin_email' => get_bloginfo('admin_email'),
            'agent_version' => CLEVERS_AGENT_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'capabilities' => $this->get_supported_commands(),
        );

        $response = $this->post_json(self::REGISTER_PATH, $payload, false);
        $this->store_http_state('register', $response);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = $this->decode_json_body($response);
        if (is_wp_error($data)) {
            return $data;
        }

        $patch = array();
        if (!empty($data['site_id'])) {
            $patch['site_id'] = sanitize_text_field((string) $data['site_id']);
            $patch['registered_at'] = current_time('mysql');
        }
        if (!empty($data['agent_secret'])) {
            $patch['site_secret'] = sanitize_text_field((string) $data['agent_secret']);
        }

        if (!empty($patch)) {
            clevers_agent_update_remote_state($patch);
        }

        return array(
            'ok' => true,
            'message' => 'Registration completed.',
            'site_id' => isset($patch['site_id']) ? $patch['site_id'] : $state['site_id'],
            'raw' => $data,
        );
    }

    public function send_heartbeat($manual = false) {
        $options = clevers_agent_get_options();
        if (!$manual && empty($options['enabled'])) {
            return new WP_Error('clevers_agent_disabled', 'Clevers agent disabled.');
        }

        $registration = $this->ensure_registered();
        if (is_wp_error($registration)) {
            return $registration;
        }

        $snapshot = $this->reporter->build_payload();
        $payload = array(
            'site_url' => site_url(),
            'timestamp' => current_time('mysql'),
            'wordpress' => $snapshot['wordpress'],
            'php' => $snapshot['php'],
            'health' => $snapshot['health'],
            'stats' => array(
                'plugins_count' => is_array($snapshot['plugins']) ? count($snapshot['plugins']) : 0,
                'themes_count' => is_array($snapshot['themes']) ? count($snapshot['themes']) : 0,
            ),
        );

        $response = $this->post_json(self::HEARTBEAT_PATH, $payload, true);
        $this->store_http_state('heartbeat', $response);

        return $response;
    }

    public function poll_commands($manual = false) {
        $options = clevers_agent_get_options();
        if (!$manual && empty($options['enabled'])) {
            return new WP_Error('clevers_agent_disabled', 'Clevers agent disabled.');
        }

        $registration = $this->ensure_registered();
        if (is_wp_error($registration)) {
            return $registration;
        }

        $state = clevers_agent_get_remote_state();
        $payload = array(
            'site_url' => site_url(),
            'last_command_at' => $state['last_command_at'],
            'limit' => 5,
        );

        $response = $this->post_json(self::COMMAND_PULL_PATH, $payload, true);
        $this->store_http_state('poll', $response);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = $this->decode_json_body($response);
        if (is_wp_error($data)) {
            return $data;
        }

        $commands = array();
        if (isset($data['commands']) && is_array($data['commands'])) {
            $commands = $data['commands'];
        }

        $results = array();
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $result = $this->execute_command($command);
            $results[] = $result;
            $this->send_command_result($result);
        }

        return array(
            'ok' => true,
            'commands_received' => count($commands),
            'commands_executed' => count($results),
            'results' => $results,
        );
    }

    public function get_status_summary() {
        $state = clevers_agent_get_remote_state();
        return array(
            'site_id' => $state['site_id'],
            'registered_at' => $state['registered_at'],
            'last_register_code' => $state['last_register_code'],
            'last_register_error' => $state['last_register_error'],
            'last_heartbeat_at' => $state['last_heartbeat_at'],
            'last_heartbeat_code' => $state['last_heartbeat_code'],
            'last_heartbeat_error' => $state['last_heartbeat_error'],
            'last_poll_at' => $state['last_poll_at'],
            'last_poll_code' => $state['last_poll_code'],
            'last_poll_error' => $state['last_poll_error'],
            'last_command_at' => $state['last_command_at'],
            'last_command_id' => $state['last_command_id'],
            'last_command_type' => $state['last_command_type'],
            'last_command_status' => $state['last_command_status'],
            'last_command_message' => $state['last_command_message'],
        );
    }

    public function get_supported_commands() {
        return array(
            'ping',
            'get_snapshot',
            'list_plugins',
            'list_themes',
            'activate_plugin',
            'deactivate_plugin',
            'update_plugin',
            'run_due_cron',
            'send_report',
            'send_heartbeat',
        );
    }

    private function ensure_registered() {
        $state = clevers_agent_get_remote_state();
        if (!empty($state['site_id'])) {
            return true;
        }

        return $this->register_site(false);
    }

    private function execute_command($command) {
        $command_id = isset($command['id']) ? sanitize_text_field((string) $command['id']) : '';
        $type = isset($command['type']) ? sanitize_key((string) $command['type']) : '';
        $payload = isset($command['payload']) && is_array($command['payload']) ? $command['payload'] : array();
        $started = microtime(true);

        $status = 'failed';
        $message = '';
        $result_data = array();

        switch ($type) {
            case 'ping':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $status = 'success';
                $result_data = array(
                    'site_url' => site_url(),
                    'time' => current_time('mysql'),
                );
                break;

            case 'get_snapshot':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $status = 'success';
                $result_data = $this->reporter->build_payload();
                break;

            case 'list_plugins':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $snapshot = $this->reporter->build_payload();
                $status = 'success';
                $result_data = isset($snapshot['plugins']) ? $snapshot['plugins'] : array();
                break;

            case 'list_themes':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $snapshot = $this->reporter->build_payload();
                $status = 'success';
                $result_data = isset($snapshot['themes']) ? $snapshot['themes'] : array();
                break;

            case 'activate_plugin':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->activate_plugin_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'deactivate_plugin':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->deactivate_plugin_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'update_plugin':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->update_plugin_command($payload);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = $exec;
                }
                break;

            case 'run_due_cron':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $status = 'success';
                $result_data = $this->run_due_cron_command();
                break;

            case 'send_report':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->reporter->send_report(true);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = array(
                        'http_code' => wp_remote_retrieve_response_code($exec),
                    );
                }
                break;

            case 'send_heartbeat':
                if (!$this->is_command_allowed($type)) {
                    $message = 'Command blocked by local policy.';
                    break;
                }
                $exec = $this->send_heartbeat(true);
                if (is_wp_error($exec)) {
                    $message = $exec->get_error_message();
                } else {
                    $status = 'success';
                    $result_data = array(
                        'http_code' => wp_remote_retrieve_response_code($exec),
                    );
                }
                break;

            default:
                $message = 'Unsupported command type: ' . $type;
                break;
        }

        $duration_ms = (int) round((microtime(true) - $started) * 1000);
        if ($message === '') {
            $message = $status === 'success' ? 'OK' : 'Unknown error';
        }

        $result = array(
            'id' => $command_id,
            'type' => $type,
            'status' => $status,
            'message' => $this->truncate_text($message, 500),
            'result' => $this->normalize_result($result_data),
            'executed_at' => current_time('mysql'),
            'duration_ms' => $duration_ms,
        );

        clevers_agent_update_remote_state(array(
            'last_command_at' => $result['executed_at'],
            'last_command_id' => $command_id,
            'last_command_type' => $type,
            'last_command_status' => $status,
            'last_command_message' => $result['message'],
        ));
        clevers_agent_append_command_audit($result);

        return $result;
    }

    private function is_command_allowed($type) {
        $options = clevers_agent_get_options();

        $read_commands = array('ping', 'get_snapshot', 'list_plugins', 'list_themes');
        if (in_array($type, $read_commands, true)) {
            return !empty($options['remote_allow_read']);
        }

        $toggle_commands = array('activate_plugin', 'deactivate_plugin');
        if (in_array($type, $toggle_commands, true)) {
            return !empty($options['remote_allow_plugin_toggle']);
        }

        if ($type === 'update_plugin') {
            return !empty($options['remote_allow_plugin_updates']);
        }

        if ($type === 'run_due_cron') {
            return !empty($options['remote_allow_cron']);
        }

        $report_commands = array('send_report', 'send_heartbeat');
        if (in_array($type, $report_commands, true)) {
            return !empty($options['remote_allow_report_actions']);
        }

        return false;
    }

    private function activate_plugin_command($payload) {
        $plugin_file = isset($payload['plugin_file']) ? sanitize_text_field((string) $payload['plugin_file']) : '';
        if ($plugin_file === '') {
            return new WP_Error('clevers_agent_missing_plugin', 'plugin_file is required.');
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'plugin_file' => $plugin_file,
            'active' => is_plugin_active($plugin_file),
        );
    }

    private function deactivate_plugin_command($payload) {
        $plugin_file = isset($payload['plugin_file']) ? sanitize_text_field((string) $payload['plugin_file']) : '';
        if ($plugin_file === '') {
            return new WP_Error('clevers_agent_missing_plugin', 'plugin_file is required.');
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins($plugin_file, false, false);

        return array(
            'plugin_file' => $plugin_file,
            'active' => is_plugin_active($plugin_file),
        );
    }

    private function update_plugin_command($payload) {
        $plugin_file = isset($payload['plugin_file']) ? sanitize_text_field((string) $payload['plugin_file']) : '';
        if ($plugin_file === '') {
            return new WP_Error('clevers_agent_missing_plugin', 'plugin_file is required.');
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        wp_update_plugins();

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $upgrade_result = $upgrader->upgrade($plugin_file);

        if (is_wp_error($upgrade_result)) {
            return $upgrade_result;
        }
        if ($upgrade_result === false) {
            $messages = method_exists($skin, 'get_error_messages') ? $skin->get_error_messages() : '';
            return new WP_Error('clevers_agent_update_failed', $messages ? wp_strip_all_tags($messages) : 'Plugin update failed.');
        }

        return array(
            'plugin_file' => $plugin_file,
            'updated' => true,
        );
    }

    private function run_due_cron_command() {
        $ran = false;

        if (function_exists('wp_cron')) {
            wp_cron();
            $ran = true;
        } elseif (function_exists('_wp_cron')) {
            _wp_cron();
            $ran = true;
        } elseif (function_exists('spawn_cron')) {
            spawn_cron(time());
            $ran = true;
        }

        return array(
            'triggered' => $ran,
            'time' => current_time('mysql'),
        );
    }

    private function send_command_result($result) {
        $payload = array(
            'command' => $result,
            'site_url' => site_url(),
        );

        $response = $this->post_json(self::COMMAND_RESULT_PATH, $payload, true);
        return $response;
    }

    private function post_json($path, $payload, $requires_registration) {
        $options = clevers_agent_get_options();
        $endpoint_base = isset($options['endpoint_url']) ? $options['endpoint_url'] : '';
        $agent_key = isset($options['agent_key']) ? trim((string) $options['agent_key']) : '';

        if ($agent_key === '') {
            return new WP_Error('clevers_agent_missing_key', 'Agent key missing.');
        }
        if (empty($endpoint_base)) {
            return new WP_Error('clevers_agent_missing_endpoint', 'Endpoint URL missing.');
        }

        $url = rtrim((string) $endpoint_base, '/') . $path;
        $body = wp_json_encode($payload);
        if ($body === false) {
            return new WP_Error('clevers_agent_json_encode_failed', 'Could not encode JSON payload.');
        }

        $headers = $this->build_auth_headers('POST', $path, $body, $agent_key, $requires_registration);
        if (is_wp_error($headers)) {
            return $headers;
        }

        return wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => $headers,
            'body' => $body,
        ));
    }

    private function build_auth_headers($method, $path, $body, $agent_key, $requires_registration) {
        $state = clevers_agent_get_remote_state();

        if ($requires_registration && empty($state['site_id'])) {
            return new WP_Error('clevers_agent_not_registered', 'Site is not registered yet.');
        }

        $timestamp = (string) time();
        $nonce = wp_generate_password(12, false, false);

        $headers = array(
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Website-Agent-Key' => $agent_key,
            'X-Website-Agent-Timestamp' => $timestamp,
            'X-Website-Agent-Nonce' => $nonce,
        );

        if (!empty($state['site_id'])) {
            $headers['X-Website-Agent-Site-Id'] = $state['site_id'];
        }

        if (!empty($state['site_secret'])) {
            $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body;
            $headers['X-Website-Agent-Signature'] = hash_hmac('sha256', $canonical, $state['site_secret']);
        }

        return $headers;
    }

    private function decode_json_body($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if (($body === '' || $body === null) && $code >= 200 && $code < 300) {
            return array();
        }
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return new WP_Error('clevers_agent_invalid_json', 'Invalid JSON response.');
        }

        if ($code < 200 || $code >= 300) {
            $message = isset($decoded['message']) ? (string) $decoded['message'] : 'Remote request failed.';
            return new WP_Error('clevers_agent_remote_error', $this->truncate_text(wp_strip_all_tags($message), 300));
        }

        return $decoded;
    }

    private function store_http_state($type, $response) {
        $patch = array();
        $now = current_time('mysql');

        if ($type === 'register') {
            $patch['last_register_code'] = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $patch['last_register_error'] = is_wp_error($response) ? $response->get_error_message() : '';
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 400) {
                $patch['last_register_error'] = $this->truncate_text(wp_strip_all_tags(wp_remote_retrieve_body($response)), 300);
            }
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300) {
                $patch['registered_at'] = $now;
            }
        } elseif ($type === 'heartbeat') {
            $patch['last_heartbeat_at'] = $now;
            $patch['last_heartbeat_code'] = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $patch['last_heartbeat_error'] = is_wp_error($response) ? $response->get_error_message() : '';
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 400) {
                $patch['last_heartbeat_error'] = $this->truncate_text(wp_strip_all_tags(wp_remote_retrieve_body($response)), 300);
            }
        } elseif ($type === 'poll') {
            $patch['last_poll_at'] = $now;
            $patch['last_poll_code'] = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $patch['last_poll_error'] = is_wp_error($response) ? $response->get_error_message() : '';
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 400) {
                $patch['last_poll_error'] = $this->truncate_text(wp_strip_all_tags(wp_remote_retrieve_body($response)), 300);
            }
        }

        if (!empty($patch)) {
            clevers_agent_update_remote_state($patch);
        }
    }

    private function normalize_result($value) {
        $encoded = wp_json_encode($value);
        if ($encoded === false) {
            return array('summary' => 'Non-serializable result');
        }

        if (strlen($encoded) > 8000) {
            return array(
                'summary' => 'Result truncated',
                'size' => strlen($encoded),
            );
        }

        return $value;
    }

    private function truncate_text($text, $max_length) {
        $text = (string) $text;
        if (strlen($text) <= $max_length) {
            return $text;
        }

        return substr($text, 0, $max_length - 3) . '...';
    }
}
