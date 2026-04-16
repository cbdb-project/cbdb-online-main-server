<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * 為使用 LLM API 的服務提供備援（fallback）能力。
 *
 * 使用此 trait 的服務須擁有 $apiKey、$apiEndpoint、$model 三個屬性。
 * 在建構子中呼叫 initLlmFallback() 即可載入備援設定。
 */
trait LlmFallbackTrait {
    protected string $fallbackApiKey = '';
    protected string $fallbackApiEndpoint = '';
    protected string $fallbackModel = '';
    protected bool $fallbackAvailable = false;

    /**
     * 從 config('services.gemini_fallback') 載入備援設定。
     * 只要 api_key 非空即視為可用；endpoint 與 model 為空時沿用主要設定。
     */
    protected function initLlmFallback(): void {
        $key = (string) config('services.gemini_fallback.api_key', '');
        $endpoint = (string) config('services.gemini_fallback.api_endpoint', '');
        $model = (string) config('services.gemini_fallback.model', '');

        if ($key !== '') {
            $this->fallbackApiKey = $key;
            $this->fallbackApiEndpoint = $endpoint !== '' ? $endpoint : $this->apiEndpoint;
            $this->fallbackModel = $model !== '' ? $model : $this->model;
            $this->fallbackAvailable = true;
        }
    }

    protected function hasFallback(): bool {
        return $this->fallbackAvailable;
    }

    /**
     * 將服務的 API 憑證切換到備援設定，並回傳原始憑證以便還原。
     *
     * @return array{apiKey: string, apiEndpoint: string, model: string}
     */
    protected function switchToFallback(): array {
        $original = [
            'apiKey' => $this->apiKey,
            'apiEndpoint' => $this->apiEndpoint,
            'model' => $this->model,
        ];

        $this->apiKey = $this->fallbackApiKey;
        $this->apiEndpoint = $this->fallbackApiEndpoint;
        $this->model = $this->fallbackModel;

        Log::info('LLM fallback 已啟用', [
            'service' => static::class,
            'fallback_endpoint' => $this->fallbackApiEndpoint,
            'fallback_model' => $this->fallbackModel,
        ]);

        return $original;
    }

    /**
     * 還原至主要 API 憑證。
     */
    protected function restoreFromFallback(array $original): void {
        $this->apiKey = $original['apiKey'];
        $this->apiEndpoint = $original['apiEndpoint'];
        $this->model = $original['model'];
    }
}
