<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clevers_Agent_Admin {
    private $reporter;
    private $scheduler;

    public function __construct($reporter, $scheduler) {
        $this->reporter = $reporter;
        $this->scheduler = $scheduler;

        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_clevers_agent_send_now', array($this, 'handle_send_now'));
    }

    public function register_menu() {
        add_options_page(
            'Clevers Agent',
            'Clevers Agent',
            'manage_options',
            'clevers-agent',
            array($this, 'render_page')
        );
    }

    public function register_settings() {
        register_setting('clevers_agent_settings', CLEVERS_AGENT_OPTIONS_KEY, array(
            'sanitize_callback' => array($this, 'sanitize_options'),
        ));

        add_settings_section('clevers_agent_main', 'Configuracion', '__return_false', 'clevers-agent');

        add_settings_field('enabled', 'Enviar reportes', array($this, 'render_enabled'), 'clevers-agent', 'clevers_agent_main');
        add_settings_field('agent_key', 'Agent Key', array($this, 'render_agent_key'), 'clevers-agent', 'clevers_agent_main');
        add_settings_field('endpoint_url', 'Endpoint URL', array($this, 'render_endpoint_url'), 'clevers-agent', 'clevers_agent_main');
        add_settings_field('interval_hours', 'Intervalo (horas)', array($this, 'render_interval'), 'clevers-agent', 'clevers_agent_main');
    }

    public function sanitize_options($input) {
        $output = clevers_agent_get_options();

        $output['enabled'] = !empty($input['enabled']);
        $output['agent_key'] = sanitize_text_field($input['agent_key'] ?? '');
        $output['endpoint_url'] = esc_url_raw(trim($input['endpoint_url'] ?? ''));
        $output['interval_hours'] = max(1, intval($input['interval_hours'] ?? 6));
        return $output;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->render_notice();

        $last_sent = get_option(CLEVERS_AGENT_LAST_SENT_AT, '');
        $last_code = get_option(CLEVERS_AGENT_LAST_RESPONSE_CODE, '');
        $last_error = get_option(CLEVERS_AGENT_LAST_ERROR, '');

        $next_report = wp_next_scheduled(Clevers_Agent_Scheduler::CRON_HOOK);

        ?>
        <div class="wrap">
            <h1>Clevers Monitoring Agent</h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('clevers_agent_settings');
                do_settings_sections('clevers-agent');
                submit_button('Guardar');
                ?>
            </form>

            <h2>Acciones manuales</h2>
            <p>Usa esta accion para probar la conectividad del agente de monitoreo desde esta instalacion.</p>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin:12px 0 20px;">
                <?php $this->render_post_button('clevers_agent_send_now', 'Send report', 'secondary'); ?>
            </div>

            <h2>Estado de reportes</h2>
            <table class="widefat striped" style="max-width: 900px; margin-bottom: 20px;">
                <tbody>
                    <tr>
                        <th>Ultimo envio</th>
                        <td><?php echo esc_html($last_sent ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Ultimo codigo</th>
                        <td><?php echo esc_html($last_code === '' ? 'Sin datos' : $last_code); ?></td>
                    </tr>
                    <tr>
                        <th>Ultimo error</th>
                        <td><?php echo esc_html($last_error ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Proximo reporte (cron)</th>
                        <td><?php echo esc_html($this->format_scheduled_time($next_report)); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_enabled() {
        $options = clevers_agent_get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(CLEVERS_AGENT_OPTIONS_KEY); ?>[enabled]" value="1" <?php checked(!empty($options['enabled'])); ?> />
            Habilitar envios automaticos del agente de monitoreo
        </label>
        <?php
    }

    public function render_agent_key() {
        $options = clevers_agent_get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr(CLEVERS_AGENT_OPTIONS_KEY); ?>[agent_key]" value="<?php echo esc_attr($options['agent_key']); ?>" />
        <?php
    }

    public function render_endpoint_url() {
        $options = clevers_agent_get_options();
        ?>
        <input type="url" class="regular-text" name="<?php echo esc_attr(CLEVERS_AGENT_OPTIONS_KEY); ?>[endpoint_url]" value="<?php echo esc_attr($options['endpoint_url']); ?>" placeholder="https://app.tu-dominio.com" />
        <p class="description">Base URL del backend de monitoreo (sin ruta final). Ejemplo: https://app.clevers.cl</p>
        <?php
    }

    public function render_interval() {
        $options = clevers_agent_get_options();
        ?>
        <input type="number" min="1" max="168" name="<?php echo esc_attr(CLEVERS_AGENT_OPTIONS_KEY); ?>[interval_hours]" value="<?php echo esc_attr($options['interval_hours']); ?>" />
        <p class="description">Frecuencia del reporte de telemetria del sitio.</p>
        <?php
    }

    public function handle_send_now() {
        $this->assert_admin();
        check_admin_referer('clevers_agent_send_now');

        $response = $this->reporter->send_report(true);
        $this->redirect_with_notice('send_report', !is_wp_error($response), is_wp_error($response) ? $response->get_error_message() : 'Reporte enviado.');
    }

    private function render_notice() {
        $notice_nonce_valid = false;
        if (isset($_GET['_clevers_agent_notice_nonce'])) {
            $notice_nonce_valid = wp_verify_nonce(
                sanitize_text_field(wp_unslash($_GET['_clevers_agent_notice_nonce'])),
                'clevers_agent_notice'
            );
        }

        if (!$notice_nonce_valid || !isset($_GET['clevers_agent_notice_ok'])) {
            return;
        }

        $ok = sanitize_text_field(wp_unslash($_GET['clevers_agent_notice_ok'])) === '1';
        $action = isset($_GET['clevers_agent_notice_action']) ? sanitize_text_field(wp_unslash($_GET['clevers_agent_notice_action'])) : 'accion';
        $message = isset($_GET['clevers_agent_notice_message']) ? sanitize_text_field(wp_unslash($_GET['clevers_agent_notice_message'])) : '';

        $class = $ok ? 'notice notice-success' : 'notice notice-error';
        if ($message === '') {
            $message = $ok ? 'Operacion exitosa.' : 'Operacion fallida.';
        }

        echo '<div class="' . esc_attr($class) . '"><p><strong>' . esc_html($action) . ':</strong> ' . esc_html($message) . '</p></div>';
    }

    private function render_post_button($action, $label, $button_class) {
        ?>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;">
            <?php wp_nonce_field($action); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php submit_button($label, $button_class, '', false); ?>
        </form>
        <?php
    }

    private function assert_admin() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
    }

    private function redirect_with_notice($action, $ok, $message) {
        $redirect_url = wp_get_referer();
        if (!$redirect_url) {
            $redirect_url = admin_url('options-general.php?page=clevers-agent');
        }

        $redirect = add_query_arg(
            array(
                'clevers_agent_notice_action' => sanitize_key($action),
                'clevers_agent_notice_ok' => $ok ? '1' : '0',
                'clevers_agent_notice_message' => $this->truncate_notice_message($message),
                '_clevers_agent_notice_nonce' => wp_create_nonce('clevers_agent_notice'),
            ),
            $redirect_url
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private function format_scheduled_time($timestamp) {
        if (!$timestamp) {
            return 'No programado';
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function truncate_notice_message($message) {
        $message = wp_strip_all_tags((string) $message);
        if (strlen($message) <= 180) {
            return $message;
        }

        return substr($message, 0, 177) . '...';
    }
}
