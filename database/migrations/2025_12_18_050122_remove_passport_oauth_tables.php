<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        // Drop all Laravel Passport OAuth tables
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_clients');
        Schema::dropIfExists('oauth_personal_access_clients');
        Schema::dropIfExists('oauth_refresh_tokens');
    }

    /**
     * Reverse the migrations.
     *
     * NOTE: This migration is intentionally irreversible.
     *
     * The up() method permanently drops all Laravel Passport OAuth tables.
     * We do NOT recreate these tables here, because their exact schema and
     * indexes are managed by the Laravel Passport package itself.
     *
     * If you need to restore Passport OAuth functionality and its tables:
     *  1. Re-install Laravel Passport (if removed) via Composer
     *  2. Ensure this migration does not run (temporarily comment or remove from migrations table)
     *  3. Run the original Passport migrations: php artisan migrate
     *
     * After that, the oauth_* tables will be recreated according to the
     * version of Passport you have installed.
     */
    public function down(): void {
        // Intentionally left empty - see docblock above
    }
};
