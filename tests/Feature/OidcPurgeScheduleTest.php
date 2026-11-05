<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * passport:purge 的排程（docs/OIDC_PROVIDER_PLAN.md §8.1）：總開關關閉時不跑、開啟時才跑。
 */
class OidcPurgeScheduleTest extends TestCase {
    private function purgeEvent() {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'passport:purge'));

        $this->assertCount(1, $events, 'passport:purge 必須恰好排程一次');

        return $events->first();
    }

    public function test_purge_is_scheduled_daily_with_retention(): void {
        $event = $this->purgeEvent();

        $this->assertStringContainsString('--expired --hours=24', $event->command);
        $this->assertSame('15 4 * * *', $event->expression);
    }

    public function test_purge_only_runs_when_oidc_is_enabled(): void {
        $event = $this->purgeEvent();

        config(['oidc.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['oidc.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
