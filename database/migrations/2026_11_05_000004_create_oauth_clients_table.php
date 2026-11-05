<?php

use App\Support\PassportMigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        // 本專案曾裝過 Passport 又移除（2025_12_18_050122_remove_passport_oauth_tables 只 drop 其中幾張）。
        // 套件原檔名是 2016／2024 年的時戳：全新 DB 會排在那支 drop 之前（先建後刪、最後沒表），
        // 而舊 prod 的 migrations 表也可能留著同名紀錄而被跳過——所以改用新時戳。
        // 表已存在且欄位齊全才跳過；已存在但欄位不齊會**直接報錯**，不悄悄標成已套用。
        // 見 App\Support\PassportMigrationGuard 與 docs/OIDC_PROVIDER_PLAN.md §6.7。
        if (PassportMigrationGuard::shouldSkipCreate('oauth_clients', ['id', 'owner_type', 'owner_id', 'name', 'secret', 'provider', 'redirect_uris', 'grant_types', 'revoked', 'created_at', 'updated_at'])) {
            return;
        }

        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        PassportMigrationGuard::dropIfEmpty('oauth_clients');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string {
        return $this->connection ?? config('passport.connection');
    }
};
