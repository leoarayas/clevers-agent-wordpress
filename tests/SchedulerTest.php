<?php

use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['mock_state']['options'] = array();
        $GLOBALS['mock_state']['scheduled'] = array();
    }

    public function test_add_schedule_returns_correct_interval(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'interval_hours' => 6,
        );

        $scheduler = new Clevers_Agent_Scheduler();
        $schedules = $scheduler->add_schedule(array());

        $this->assertArrayHasKey(Clevers_Agent_Scheduler::SCHEDULE_KEY, $schedules);
        $this->assertSame(6 * HOUR_IN_SECONDS, $schedules[Clevers_Agent_Scheduler::SCHEDULE_KEY]['interval']);
    }

    public function test_schedule_creates_event_when_enabled(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => true,
        );

        $scheduler = new Clevers_Agent_Scheduler();
        $scheduler->schedule();

        $this->assertNotEmpty($GLOBALS['mock_state']['scheduled']);
        $this->assertArrayHasKey(Clevers_Agent_Scheduler::CRON_HOOK, $GLOBALS['mock_state']['scheduled']);
    }

    public function test_schedule_does_not_create_event_when_disabled(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => false,
        );

        $scheduler = new Clevers_Agent_Scheduler();
        $scheduler->schedule();

        $this->assertArrayNotHasKey(Clevers_Agent_Scheduler::CRON_HOOK, $GLOBALS['mock_state']['scheduled']);
    }

    public function test_clear_removes_scheduled_event(): void {
        $GLOBALS['mock_state']['scheduled'][Clevers_Agent_Scheduler::CRON_HOOK] = time();

        $scheduler = new Clevers_Agent_Scheduler();
        $scheduler->clear();

        $this->assertArrayNotHasKey(Clevers_Agent_Scheduler::CRON_HOOK, $GLOBALS['mock_state']['scheduled']);
    }

    public function test_maybe_schedule_clears_when_disabled(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => false,
        );
        $GLOBALS['mock_state']['scheduled'][Clevers_Agent_Scheduler::CRON_HOOK] = time();

        $scheduler = new Clevers_Agent_Scheduler();
        $scheduler->maybe_schedule();

        $this->assertArrayNotHasKey(Clevers_Agent_Scheduler::CRON_HOOK, $GLOBALS['mock_state']['scheduled']);
    }

    public function test_maybe_schedule_schedules_when_enabled(): void {
        $GLOBALS['mock_state']['options'][CLEVERS_AGENT_OPTIONS_KEY] = array(
            'enabled' => true,
        );

        $scheduler = new Clevers_Agent_Scheduler();
        $scheduler->maybe_schedule();

        $this->assertArrayHasKey(Clevers_Agent_Scheduler::CRON_HOOK, $GLOBALS['mock_state']['scheduled']);
    }
}
