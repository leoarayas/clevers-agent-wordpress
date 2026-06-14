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
}
