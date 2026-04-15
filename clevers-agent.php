<?php
/**
 * Plugin Name: Clevers Monitoring Agent
 * Description: Sends periodic site health reports to Clevers Monitoring.
 * Version: 1.1.0
 * Author: Clevers
 * Text Domain: clevers-agent
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLEVERS_AGENT_VERSION', '1.1.0');
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
    $scheduler->maybe_schedule();
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
    new Clevers_Agent_Admin($reporter, $scheduler);
});

function clevers_agent_status_permission_callback() {
    return current_user_can('manage_options');
}

add_action('rest_api_init', function () {
    register_rest_route('clevers-agent/v1', '/status', array(
        'methods' => 'GET',
        'permission_callback' => 'clevers_agent_status_permission_callback',
        'callback' => function () {
            $options = clevers_agent_get_options();

            return rest_ensure_response(array(
                'plugin' => 'clevers-agent',
                'plugin_name' => 'Clevers Monitoring Agent',
                'version' => defined('CLEVERS_AGENT_VERSION') ? CLEVERS_AGENT_VERSION : null,
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'enabled' => !empty($options['enabled']),
                'configured' => !empty($options['agent_key']) && !empty($options['endpoint_url']),
                'telemetry' => array(
                    'last_sent_at' => get_option(CLEVERS_AGENT_LAST_SENT_AT, ''),
                    'last_response_code' => get_option(CLEVERS_AGENT_LAST_RESPONSE_CODE, ''),
                    'last_error' => get_option(CLEVERS_AGENT_LAST_ERROR, ''),
                    'next_report_at' => wp_next_scheduled(Clevers_Agent_Scheduler::CRON_HOOK),
                ),
            ));
        },
    ));
});

add_action('clevers_agent_send_report', function () {
    $reporter = new Clevers_Agent_Reporter();
    $reporter->send_report();
});
