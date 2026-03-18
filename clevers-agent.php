<?php
/**
 * Plugin Name: Clevers Website Monitoring Agent
 * Description: Sends periodic site health reports to Clevers Website Monitoring.
 * Version: 1.0.3
 * Author: Clevers
 * Text Domain: clevers-agent
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLEVERS_AGENT_VERSION', '1.0.0');
define('CLEVERS_AGENT_PLUGIN_FILE', __FILE__);
define('CLEVERS_AGENT_PLUGIN_DIR', plugin_dir_path(__FILE__));

define('CLEVERS_AGENT_OPTIONS_KEY', 'clevers_agent_options');
define('CLEVERS_AGENT_REMOTE_STATE_KEY', 'clevers_agent_remote_state');
define('CLEVERS_AGENT_COMMAND_AUDIT_KEY', 'clevers_agent_command_audit');

define('CLEVERS_AGENT_LAST_SENT_AT', 'clevers_agent_last_sent_at');
define('CLEVERS_AGENT_LAST_RESPONSE_CODE', 'clevers_agent_last_response_code');
define('CLEVERS_AGENT_LAST_ERROR', 'clevers_agent_last_error');

require_once CLEVERS_AGENT_PLUGIN_DIR . 'includes/class-agent-reporter.php';
require_once CLEVERS_AGENT_PLUGIN_DIR . 'includes/class-agent-remote.php';
require_once CLEVERS_AGENT_PLUGIN_DIR . 'includes/class-agent-scheduler.php';
require_once CLEVERS_AGENT_PLUGIN_DIR . 'includes/class-agent-admin.php';

function clevers_agent_get_options() {
    $defaults = array(
        'enabled' => true,
        'agent_key' => '',
        'endpoint_url' => '',
        'interval_hours' => 6,
        'remote_allow_read' => true,
        'remote_allow_plugin_toggle' => false,
        'remote_allow_plugin_updates' => false,
        'remote_allow_theme_updates' => false,
        'remote_allow_core_updates' => false,
        'remote_allow_cron' => true,
        'remote_allow_report_actions' => true,
    );

    $options = get_option(CLEVERS_AGENT_OPTIONS_KEY, array());
    if (!is_array($options)) {
        $options = array();
    }

    return array_merge($defaults, $options);
}

function clevers_agent_get_remote_state() {
    $defaults = array(
        'site_id' => '',
        'site_secret' => '',
        'registered_at' => '',
        'last_register_code' => '',
        'last_register_error' => '',
        'last_heartbeat_at' => '',
        'last_heartbeat_code' => '',
        'last_heartbeat_error' => '',
        'last_poll_at' => '',
        'last_poll_code' => '',
        'last_poll_error' => '',
        'last_command_at' => '',
        'last_command_id' => '',
        'last_command_type' => '',
        'last_command_status' => '',
        'last_command_message' => '',
    );

    $state = get_option(CLEVERS_AGENT_REMOTE_STATE_KEY, array());
    if (!is_array($state)) {
        $state = array();
    }

    return array_merge($defaults, $state);
}

function clevers_agent_update_remote_state($patch) {
    if (!is_array($patch)) {
        return;
    }

    $state = clevers_agent_get_remote_state();
    update_option(CLEVERS_AGENT_REMOTE_STATE_KEY, array_merge($state, $patch));
}

function clevers_agent_get_command_audit() {
    $rows = get_option(CLEVERS_AGENT_COMMAND_AUDIT_KEY, array());
    return is_array($rows) ? $rows : array();
}

function clevers_agent_append_command_audit($row) {
    if (!is_array($row)) {
        return;
    }

    $rows = clevers_agent_get_command_audit();
    array_unshift($rows, $row);
    $rows = array_slice($rows, 0, 30);
    update_option(CLEVERS_AGENT_COMMAND_AUDIT_KEY, $rows);
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
    $remote = new Clevers_Agent_Remote($reporter);
    $scheduler = new Clevers_Agent_Scheduler($reporter);
    $admin = new Clevers_Agent_Admin($reporter, $scheduler, $remote);
});

add_action('rest_api_init', function () {
    register_rest_route('clevers-agent/v1', '/status', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $options = clevers_agent_get_options();
            $state = clevers_agent_get_remote_state();

            return rest_ensure_response(array(
                'plugin' => 'clevers-agent',
                'plugin_name' => 'Clevers Website Monitoring Agent',
                'version' => defined('CLEVERS_AGENT_VERSION') ? CLEVERS_AGENT_VERSION : null,
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'enabled' => !empty($options['enabled']),
                'configured' => !empty($options['agent_key']) && !empty($options['endpoint_url']),
                'registered' => !empty($state['site_id']),
                'remote' => array(
                    'site_id_present' => !empty($state['site_id']),
                    'registered_at' => !empty($state['registered_at']) ? $state['registered_at'] : null,
                    'last_heartbeat_at' => !empty($state['last_heartbeat_at']) ? $state['last_heartbeat_at'] : null,
                    'last_poll_at' => !empty($state['last_poll_at']) ? $state['last_poll_at'] : null,
                    'last_command_at' => !empty($state['last_command_at']) ? $state['last_command_at'] : null,
                ),
                'capabilities' => array(
                    'remote_read' => !empty($options['remote_allow_read']),
                    'plugin_toggle' => !empty($options['remote_allow_plugin_toggle']),
                'plugin_updates' => !empty($options['remote_allow_plugin_updates']),
                'theme_updates' => !empty($options['remote_allow_theme_updates']),
                'core_updates' => !empty($options['remote_allow_core_updates']),
                'cron' => !empty($options['remote_allow_cron']),
                'report_actions' => !empty($options['remote_allow_report_actions']),
            ),
            ));
        },
    ));
});

add_action('clevers_agent_send_report', function () {
    $reporter = new Clevers_Agent_Reporter();
    $reporter->send_report();
});

add_action('clevers_agent_poll_commands', function () {
    $reporter = new Clevers_Agent_Reporter();
    $remote = new Clevers_Agent_Remote($reporter);

    $remote->send_heartbeat();
    $remote->poll_commands();
});
