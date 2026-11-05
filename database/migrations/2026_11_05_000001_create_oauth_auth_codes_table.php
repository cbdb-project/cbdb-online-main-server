<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        // 本專案曾裝過 Passport 又移除（2025_12_18_050122_remove_passport_oauth_tables 會 dropIfExists 其中四張表）。
        // 套件原檔名是 2016／2024 年的時戳：全新 DB 會排在那支 drop 之前（先建後刪、最後沒表），
        // 而舊的 prod 的 migrations 表很可能仍留著同名紀錄而被跳過——所以改用新時戳；
        // 另加 hasTable 保護，殘留同名表的環境不會直接炸。見 docs/OIDC_PROVIDER_PLAN.md §6.7。
        if (Schema::hasTable('oauth_auth_codes')) {
            return;
        }

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->index();
            $table->foreignUuid('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('oauth_auth_codes');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string {
        return $this->connection ?? config('passport.connection');
    }
};
