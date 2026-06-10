<?php

namespace Tests\Unit;

use App\Jobs\FetchChgisMapJob;
use App\Services\ChgisMapManager;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FetchChgisMapJobTest extends TestCase {
    protected function tearDown(): void {
        Cache::forget(FetchChgisMapJob::STATE_KEY);
        Cache::forget(FetchChgisMapJob::LOCK_KEY);

        Mockery::close();

        parent::tearDown();
    }

    public function testTtlSecondsIsLongerThanDownloadTimeout(): void {
        config(['chgis_map.source.timeout' => 120]);

        $this->assertSame(720, FetchChgisMapJob::ttlSeconds());
        $this->assertGreaterThan((int) config('chgis_map.source.timeout'), FetchChgisMapJob::ttlSeconds());
    }

    public function testHandleClearsStateWhenBasemapAlreadyReady(): void {
        Cache::put(FetchChgisMapJob::STATE_KEY, ['state' => 'downloading', 'started_at' => time()], now()->addMinute());

        $manager = Mockery::mock(ChgisMapManager::class);
        $manager->shouldReceive('isReady')->once()->andReturn(true);
        $manager->shouldNotReceive('download');

        (new FetchChgisMapJob())->handle($manager);

        $this->assertNull(Cache::get(FetchChgisMapJob::STATE_KEY));
    }

    public function testHandleSkipsWhenAnotherWorkerHoldsLock(): void {
        $manager = Mockery::mock(ChgisMapManager::class);
        $manager->shouldReceive('isReady')->once()->andReturn(false);
        $manager->shouldNotReceive('download');

        $heldLock = Cache::lock(FetchChgisMapJob::LOCK_KEY, FetchChgisMapJob::ttlSeconds());
        $this->assertTrue($heldLock->get());

        try {
            (new FetchChgisMapJob())->handle($manager);
        } finally {
            $heldLock->release();
        }

        $this->assertNull(Cache::get(FetchChgisMapJob::STATE_KEY));
    }

    public function testHandleClearsStateAfterSuccessfulDownload(): void {
        $manager = Mockery::mock(ChgisMapManager::class);
        $manager->shouldReceive('isReady')->once()->andReturn(false);
        $manager->shouldReceive('download')->once();

        (new FetchChgisMapJob())->handle($manager);

        $this->assertNull(Cache::get(FetchChgisMapJob::STATE_KEY));
    }

    public function testHandleStoresFailedStateWhenDownloadThrows(): void {
        $manager = Mockery::mock(ChgisMapManager::class);
        $manager->shouldReceive('isReady')->once()->andReturn(false);
        $manager->shouldReceive('download')->once()->andThrow(new RuntimeException('download failed'));

        (new FetchChgisMapJob())->handle($manager);

        $state = Cache::get(FetchChgisMapJob::STATE_KEY);

        $this->assertSame('failed', $state['state'] ?? null);
        $this->assertSame('download failed', $state['message'] ?? null);
    }
}
