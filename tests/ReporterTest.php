<?php

use PHPUnit\Framework\TestCase;

final class ReporterTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['mock_state']['options'] = array();
        $GLOBALS['mock_state']['transients'] = array();
    }

    public function test_send_report_returns_error_when_disabled(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => false,
        );

        $reporter = new Clevers_Agent_Reporter();
        $result = $reporter->send_report(false);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('clevers_agent_disabled', $result->get_error_code());
    }

    public function test_send_report_returns_error_when_key_missing(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => true,
            'agent_key' => '',
            'endpoint_url' => 'https://example.com',
        );

        $reporter = new Clevers_Agent_Reporter();
        $result = $reporter->send_report(false);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('clevers_agent_missing_key', $result->get_error_code());
    }

    public function test_send_report_returns_error_when_endpoint_missing(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => true,
            'agent_key' => 'test-key',
            'endpoint_url' => '',
        );

        $reporter = new Clevers_Agent_Reporter();
        $result = $reporter->send_report(false);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('clevers_agent_missing_endpoint', $result->get_error_code());
    }

    public function test_build_payload_contains_required_fields(): void {
        $reporter = new Clevers_Agent_Reporter();
        $payload = $reporter->build_payload();

        $this->assertArrayHasKey('wordpress', $payload);
        $this->assertArrayHasKey('php', $payload);
        $this->assertArrayHasKey('plugins', $payload);
        $this->assertArrayHasKey('themes', $payload);
        $this->assertArrayHasKey('health', $payload);

        $this->assertArrayHasKey('version', $payload['wordpress']);
        $this->assertArrayHasKey('multisite', $payload['wordpress']);
        $this->assertArrayHasKey('debug', $payload['wordpress']);

        $this->assertArrayHasKey('version', $payload['php']);
    }

    public function test_build_payload_plugins_have_required_fields(): void {
        $reporter = new Clevers_Agent_Reporter();
        $payload = $reporter->build_payload();

        $this->assertNotEmpty($payload['plugins']);

        $plugin = $payload['plugins'][0];
        $this->assertArrayHasKey('name', $plugin);
        $this->assertArrayHasKey('slug', $plugin);
        $this->assertArrayHasKey('version', $plugin);
        $this->assertArrayHasKey('active', $plugin);
        $this->assertArrayHasKey('update_available', $plugin);
    }

    public function test_build_payload_health_has_update_counts(): void {
        $reporter = new Clevers_Agent_Reporter();
        $payload = $reporter->build_payload();

        $this->assertArrayHasKey('updates', $payload['health']);
        $this->assertArrayHasKey('core', $payload['health']['updates']);
        $this->assertArrayHasKey('plugins', $payload['health']['updates']);
        $this->assertArrayHasKey('themes', $payload['health']['updates']);
        $this->assertArrayHasKey('total', $payload['health']['updates']);
    }

    public function test_send_report_succeeds_with_valid_config(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => true,
            'agent_key' => 'test-key-123',
            'endpoint_url' => 'https://monitoring.example.com',
        );

        $reporter = new Clevers_Agent_Reporter();
        $result = $reporter->send_report(false);

        $this->assertNotInstanceOf(WP_Error::class, $result);
    }

    public function test_send_emergency_ping_sends_fatal_payload(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => true,
            'agent_key' => 'emergency-key-456',
            'endpoint_url' => 'https://monitoring.example.com',
        );

        $error = array(
            'type' => E_ERROR,
            'message' => 'Uncaught Error: Call to undefined function critical_crash()',
            'file' => '/var/www/site/wp-content/plugins/broken/plugin.php',
            'line' => 123,
        );

        $reporter = new Clevers_Agent_Reporter();
        $result = $reporter->send_emergency_ping($error, true);

        $this->assertNotFalse($result);
        $this->assertNotEmpty($GLOBALS['mock_state']['last_remote_post']);

        $post = $GLOBALS['mock_state']['last_remote_post'];
        $this->assertSame('https://monitoring.example.com/api/website-monitoring/agent/report', $post['url']);
        $this->assertSame('emergency-key-456', $post['args']['headers']['X-Website-Agent-Key']);
        $this->assertSame('fatal-error', $post['args']['headers']['X-Clevers-Emergency']);

        $body = json_decode((string) $post['args']['body'], true);
        $this->assertTrue($body['is_emergency']);
        $this->assertSame('fatal_error', $body['event_type']);
        $this->assertSame(E_ERROR, $body['error']['type']);
        $this->assertSame('E_ERROR', $body['error']['type_name']);
        $this->assertSame('Uncaught Error: Call to undefined function critical_crash()', $body['error']['message']);
        $this->assertSame(123, $body['error']['line']);

        $this->assertNotEmpty($GLOBALS['mock_state']['options']['clevers_agent_last_fatal_error']);
        $this->assertNotEmpty($GLOBALS['mock_state']['options']['clevers_agent_last_emergency_at']);
    }

    public function test_send_emergency_ping_throttles_subsequent_calls(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => true,
            'agent_key' => 'emergency-key-456',
            'endpoint_url' => 'https://monitoring.example.com',
        );

        $error = array(
            'type' => E_PARSE,
            'message' => 'syntax error, unexpected token',
            'file' => '/var/www/site/index.php',
            'line' => 10,
        );

        $reporter = new Clevers_Agent_Reporter();

        // First call sends
        $first = $reporter->send_emergency_ping($error, false);
        $this->assertNotFalse($first);

        // Immediate second call is throttled
        $second = $reporter->send_emergency_ping($error, false);
        $this->assertFalse($second);

        // Forced call bypasses throttle
        $third = $reporter->send_emergency_ping($error, true);
        $this->assertNotFalse($third);
    }

    public function test_get_error_type_name_resolves_all_constants(): void {
        $reporter = new Clevers_Agent_Reporter();

        $this->assertSame('E_ERROR', $reporter->get_error_type_name(E_ERROR));
        $this->assertSame('E_PARSE', $reporter->get_error_type_name(E_PARSE));
        $this->assertSame('E_CORE_ERROR', $reporter->get_error_type_name(E_CORE_ERROR));
        $this->assertSame('E_COMPILE_ERROR', $reporter->get_error_type_name(E_COMPILE_ERROR));
        $this->assertSame('E_USER_ERROR', $reporter->get_error_type_name(E_USER_ERROR));
        $this->assertSame('E_WARNING', $reporter->get_error_type_name(E_WARNING));
        $this->assertSame('E_NOTICE', $reporter->get_error_type_name(E_NOTICE));
        $this->assertStringContainsString('E_UNKNOWN', $reporter->get_error_type_name(9999));
    }
}
