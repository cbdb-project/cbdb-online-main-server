<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('ai_fill_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('執行填充的用戶 ID');
            $table->integer('c_personid')->comment('目標人物 ID');

            // 頁面上下文
            $table->string('route_name', 255)->comment('路由名稱');
            $table->string('route_url', 500)->comment('頁面 URL 路徑');

            // 用戶輸入
            $table->text('source_text')->comment('用戶輸入的原始史料文本');

            // AI 數據（三層結構）
            $table->longText('ai_raw')->nullable()->comment('AI 原始 JSON 回應（匹配前）');
            $table->longText('ai_matched')->nullable()->comment('AI 匹配後完整結果 JSON');
            $table->longText('user_submitted')->nullable()->comment('用戶實際提交的表單數據 JSON');

            // 狀態追蹤
            $table->boolean('success')->default(false)->comment('AI 提取是否成功');
            $table->string('error_message', 500)->nullable()->comment('錯誤訊息');
            $table->integer('execution_time_ms')->nullable()->comment('AI 處理耗時（毫秒）');
            $table->timestamp('submitted_at')->nullable()->comment('用戶提交表單的時間');

            $table->timestamps();

            $table->index('user_id');
            $table->index('c_personid');
            $table->index('success');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('ai_fill_logs');
    }
};
