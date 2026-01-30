<?php
/**
 * Plugin Name: Clevers Website Monitoring Agent
 * Description: Sends periodic site health reports to Clevers Website Monitoring.
 * Version: 1.0.0
 * Author: Clevers
 * Text Domain: clevers-agent
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLEVERS_AGENT_VERSION', '1.0.0');
define('CLEVERS_AGENT_PLUGIN_FILE', __FILE__);
define('CLEVERS_AGENT_PLUGIN_DIR', plugin_dir_path(__FILE__));

define('CLEVERS_AGENT_OPTIONS_KEY', 'clevers_agent_options');

define('CLEVERS_AGENT_LAST_SENT_AT', 'clevers_agent_last_sent_at');
define('CLEVERS_AGENT_LAST_RESPONSE_CODE', 'clevers_agent_last_response_code');
define('CLEVERS_AGENT_LAST_ERROR', 'clevers_agent_last_error');

require_once CLEVERS_AGENT_PLUGIN_DIR . 'includes/class-agent-reporter.php';
require_once CLEVERS_AGENT_PLUGIN_DIR . 'includes/class-agent-scheduler.php';
require_once CLEVERS_AGENT_PLUGIN_DIR . 'includes/class-agent-admin.php';

function clevers_agent_get_options() {
    $defaults = array(
        'enabled' => true,
        'agent_key' => '',
        'endpoint_url' => '',
        'interval_hours' => 6,
    );

    $options = get_option(CLEVERS_AGENT_OPTIONS_KEY, array());
    if (!is_array($options)) {
        $options = array();
    }

    return array_merge($defaults, $options);
}

function clevers_agent_activate() {
    $scheduler = new Clevers_Agent_Scheduler();
    $scheduler->schedule();
}

function clevers_agent_deactivate() {
    $scheduler = new Clevers_Agent_Scheduler();
    $scheduler->clear();
}

register_activation_hook(__FILE__, 'clevers_agent_activate');
register_deactivation_hook(__FILE__, 'clevers_agent_deactivate');

add_action('plugins_loaded', function () {
    $reporter = new Clevers_Agent_Reporter();
    $scheduler = new Clevers_Agent_Scheduler($reporter);
    $admin = new Clevers_Agent_Admin($reporter, $scheduler);
});

add_action('clevers_agent_send_report', function () {
    $reporter = new Clevers_Agent_Reporter();
    $reporter->send_report();
});
