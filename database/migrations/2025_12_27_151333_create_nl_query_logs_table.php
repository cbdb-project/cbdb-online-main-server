<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('nl_query_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户 ID');
            $table->text('question')->comment('用户的自然语言问题');
            $table->text('generated_sql')->nullable()->comment('生成的 SQL 查询');
            $table->text('explanation')->nullable()->comment('查询解释');
            $table->text('llm_prompt')->nullable()->comment('发送给 LLM 的完整提示词');
            $table->text('llm_response')->nullable()->comment('LLM 的原始响应');
            $table->boolean('success')->default(false)->comment('是否成功生成');
            $table->string('error_message', 500)->nullable()->comment('错误信息');
            $table->integer('execution_time_ms')->nullable()->comment('执行时间（毫秒）');
            $table->timestamps();

            $table->index('user_id');
            $table->index('success');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('nl_query_logs');
    }
};
