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

        add_settings_section('clevers_agent_main', 'Configuración', '__return_false', 'clevers-agent');

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

        if (isset($_GET['clevers_agent_sent'])) {
            $sent = sanitize_text_field(wp_unslash($_GET['clevers_agent_sent']));
            if ($sent === '1') {
                echo '<div class="notice notice-success"><p>Reporte enviado.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>No se pudo enviar el reporte.</p></div>';
            }
        }

        $options = clevers_agent_get_options();
        $last_sent = get_option(CLEVERS_AGENT_LAST_SENT_AT, '');
        $last_code = get_option(CLEVERS_AGENT_LAST_RESPONSE_CODE, '');
        $last_error = get_option(CLEVERS_AGENT_LAST_ERROR, '');

        $next_run = wp_next_scheduled(Clevers_Agent_Scheduler::CRON_HOOK);
        $next_run_display = $next_run ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run) : 'No programado';

        ?>
        <div class="wrap">
            <h1>Clevers Website Monitoring Agent</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('clevers_agent_settings');
                do_settings_sections('clevers-agent');
                submit_button('Guardar');
                ?>
            </form>

            <h2>Envio manual</h2>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('clevers_agent_send_now'); ?>
                <input type="hidden" name="action" value="clevers_agent_send_now">
                <?php submit_button('Send now', 'secondary'); ?>
            </form>

            <h2>Estado</h2>
            <table class="widefat striped" style="max-width: 720px;">
                <tbody>
                    <tr>
                        <th>Último envío</th>
                        <td><?php echo esc_html($last_sent ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Último código</th>
                        <td><?php echo esc_html($last_code === '' ? 'Sin datos' : $last_code); ?></td>
                    </tr>
                    <tr>
                        <th>Último error</th>
                        <td><?php echo esc_html($last_error ?: 'Sin datos'); ?></td>
                    </tr>
                    <tr>
                        <th>Próxima ejecución</th>
                        <td><?php echo esc_html($next_run_display); ?></td>
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
            Habilitar envíos automáticos
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
        <?php
    }

    public function render_interval() {
        $options = clevers_agent_get_options();
        ?>
        <input type="number" min="1" max="168" name="<?php echo esc_attr(CLEVERS_AGENT_OPTIONS_KEY); ?>[interval_hours]" value="<?php echo esc_attr($options['interval_hours']); ?>" />
        <?php
    }

    public function handle_send_now() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('clevers_agent_send_now');

        $response = $this->reporter->send_report(true);

        $redirect = add_query_arg('clevers_agent_sent', is_wp_error($response) ? '0' : '1', wp_get_referer());
        wp_safe_redirect($redirect);
        exit;
    }
}
