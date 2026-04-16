<?php

namespace Tests\Unit;

use App\Support\LlmFallbackTrait;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LlmFallbackTraitTest extends TestCase {
    /**
     * 備援未設定時，hasFallback() 回傳 false
     */
    public function test_fallback_unavailable_when_no_config() {
        Config::set('services.gemini_fallback.api_key', '');
        Config::set('services.gemini_fallback.api_endpoint', '');
        Config::set('services.gemini_fallback.model', '');

        $stub = $this->makeStub('primary-key', 'https://primary.example.com', 'primary-model');

        $this->assertFalse($stub->hasFallback());
    }

    /**
     * 只設定 api_key（無 endpoint）即可啟用備援，endpoint 自動沿用主要設定
     */
    public function test_fallback_enabled_with_key_only_inherits_endpoint() {
        Config::set('services.gemini_fallback.api_key', 'fallback-key');
        Config::set('services.gemini_fallback.api_endpoint', '');
        Config::set('services.gemini_fallback.model', '');

        $stub = $this->makeStub('primary-key', 'https://primary.example.com', 'primary-model');

        $this->assertTrue($stub->hasFallback());

        $original = $stub->switchToFallback();
        $this->assertSame('fallback-key', $stub->apiKey);
        $this->assertSame('https://primary.example.com', $stub->apiEndpoint);
        $this->assertSame('primary-model', $stub->model);

        $stub->restoreFromFallback($original);
        $this->assertSame('primary-key', $stub->apiKey);
    }

    /**
     * api_key 與 api_endpoint 都設定時，使用備援自己的 endpoint
     */
    public function test_fallback_uses_own_endpoint_when_provided() {
        Config::set('services.gemini_fallback.api_key', 'fallback-key');
        Config::set('services.gemini_fallback.api_endpoint', 'https://fallback.example.com');
        Config::set('services.gemini_fallback.model', 'fallback-model');

        $stub = $this->makeStub('primary-key', 'https://primary.example.com', 'primary-model');

        $this->assertTrue($stub->hasFallback());

        $stub->switchToFallback();
        $this->assertSame('fallback-key', $stub->apiKey);
        $this->assertSame('https://fallback.example.com', $stub->apiEndpoint);
        $this->assertSame('fallback-model', $stub->model);
    }

    /**
     * 備援 model 為空時沿用主要 model
     */
    public function test_fallback_inherits_primary_model_when_not_set() {
        Config::set('services.gemini_fallback.api_key', 'fallback-key');
        Config::set('services.gemini_fallback.api_endpoint', 'https://fallback.example.com');
        Config::set('services.gemini_fallback.model', '');

        $stub = $this->makeStub('primary-key', 'https://primary.example.com', 'primary-model');

        $stub->switchToFallback();
        $this->assertSame('primary-model', $stub->model);
    }

    /**
     * switchToFallback() 回傳原始憑證，restoreFromFallback() 可完整還原
     */
    public function test_switch_and_restore_roundtrip() {
        Config::set('services.gemini_fallback.api_key', 'fb-key');
        Config::set('services.gemini_fallback.api_endpoint', 'https://fb.example.com');
        Config::set('services.gemini_fallback.model', 'fb-model');

        $stub = $this->makeStub('pk', 'https://p.example.com', 'pm');

        $original = $stub->switchToFallback();
        $this->assertSame('fb-key', $stub->apiKey);

        $stub->restoreFromFallback($original);
        $this->assertSame('pk', $stub->apiKey);
        $this->assertSame('https://p.example.com', $stub->apiEndpoint);
        $this->assertSame('pm', $stub->model);
    }

    private function makeStub(string $apiKey, string $apiEndpoint, string $model): object {
        return new class ($apiKey, $apiEndpoint, $model) {
            use LlmFallbackTrait {
                initLlmFallback as public;
                hasFallback as public;
                switchToFallback as public;
                restoreFromFallback as public;
            }

            public string $apiKey;
            public string $apiEndpoint;
            public string $model;

            public function __construct(string $apiKey, string $apiEndpoint, string $model) {
                $this->apiKey = $apiKey;
                $this->apiEndpoint = $apiEndpoint;
                $this->model = $model;
                $this->initLlmFallback();
            }
        };
    }
}
