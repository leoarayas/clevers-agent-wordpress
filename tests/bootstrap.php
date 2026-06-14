<?php

define('ABSPATH', __DIR__);
define('CLEVERS_AGENT_VERSION', '1.1.0');
define('CLEVERS_AGENT_PLUGIN_FILE', __DIR__ . '/../clevers-agent.php');
define('CLEVERS_AGENT_PLUGIN_DIR', dirname(__DIR__) . '/');
define('CLEVERS_AGENT_OPTIONS_KEY', 'clevers_agent_options');
define('CLEVERS_AGENT_LAST_SENT_AT', 'clevers_agent_last_sent_at');
define('CLEVERS_AGENT_LAST_RESPONSE_CODE', 'clevers_agent_last_response_code');
define('CLEVERS_AGENT_LAST_ERROR', 'clevers_agent_last_error');

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

$GLOBALS['mock_state'] = array(
    'options' => array(),
    'scheduled' => array(),
    'transients' => array(),
);

function get_option($key, $default = false) {
    return $GLOBALS['mock_state']['options'][$key] ?? $default;
}

function update_option($key, $value, $autoload = null) {
    unset($autoload);
    $GLOBALS['mock_state']['options'][$key] = $value;
    return true;
}

function wp_next_scheduled($hook) {
    return $GLOBALS['mock_state']['scheduled'][$hook] ?? false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
    unset($recurrence, $args);
    $GLOBALS['mock_state']['scheduled'][$hook] = $timestamp;
    return true;
}

function wp_clear_scheduled_hook($hook) {
    unset($GLOBALS['mock_state']['scheduled'][$hook]);
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    unset($hook, $callback, $priority, $accepted_args);
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    unset($hook, $callback, $priority, $accepted_args);
    return true;
}

function current_user_can($cap) {
    unset($cap);
    return true;
}

function is_multisite() {
    return false;
}

function get_bloginfo($show) {
    unset($show);
    return '6.4';
}

function sanitize_text_field($str) {
    return trim(strip_tags($str));
}

function sanitize_title($str) {
    return strtolower(preg_replace('/[^a-z0-9-]/', '-', $str));
}

function wp_json_encode($value) {
    return json_encode($value);
}

function rest_ensure_response($data) {
    return $data;
}

function register_rest_route($namespace, $route, $args = array()) {
    unset($namespace, $route, $args);
    return true;
}

function get_plugins() {
    return array(
        'hello.php' => array('Name' => 'Hello Dolly', 'Version' => '1.7.2'),
        'akismet/akismet.php' => array('Name' => 'Akismet', 'Version' => '5.3'),
    );
}

function is_plugin_active($plugin) {
    return $plugin === 'hello.php';
}

function get_site_transient($transient) {
    return $GLOBALS['mock_state']['transients'][$transient] ?? false;
}

function wp_get_themes() {
    return array();
}

function get_stylesheet() {
    return 'twentytwentyfour';
}

function get_core_updates() {
    return array();
}

function wp_remote_post($url, $args = array()) {
    unset($url, $args);
    return array('response' => array('code' => 200));
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body($response) {
    return '';
}

function current_time($type) {
    unset($type);
    return '2026-06-14 12:00:00';
}

function wp_strip_all_tags($str) {
    return strip_tags($str);
}

function wp_die($message) {
    throw new Exception($message);
}

class WP_Error {
    private $code;
    private $message;

    public function __construct($code, $message) {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_code() {
        return $this->code;
    }
}

require_once dirname(__DIR__) . '/includes/class-agent-reporter.php';
require_once dirname(__DIR__) . '/includes/class-agent-scheduler.php';
