<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            column_comment($table->dateTime('occurred_at'), 'When the operation actually occurred');
            column_comment($table->dateTime('created_at'), 'When the audit log was written');

            column_comment($table->string('table_name', 64), 'Target business table');
            column_comment($table->string('operation', 16), 'INSERT/UPDATE/DELETE');

            column_comment($table->string('actor_type', 32), 'user/system/job/api_key');
            column_comment($table->string('actor_id', 128), 'Actor identifier in business layer');

            column_comment($table->char('operation_id', 26), 'Unique identifier of the operation');

            column_comment($table->json('row_pk'), 'Primary key (supports composite key)');
            column_comment($table->string('row_pk_text', 512), 'Stable serialized primary key');

            column_comment($table->json('old_data')->nullable(), 'Full row before change');
            column_comment($table->json('new_data')->nullable(), 'Full row after change');
        });
    }

    public function down(): void {
        Schema::dropIfExists('audit_log');
    }
};
