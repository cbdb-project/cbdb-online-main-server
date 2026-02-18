<?php

namespace Tests\Unit;

use App\Services\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase {
    // NOTE: Avoid RefreshDatabase; full migrations on SQLite can fail due to
    // foreign key mismatch constraints. Create the audit_log table manually.

    protected AuditLogService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->service = new AuditLogService();

        DB::statement('PRAGMA foreign_keys = OFF');
        Schema::dropIfExists('audit_log');
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });
    }

    /** @test */
    public function it_builds_row_pk_text_using_schema_order_and_rfc3986(): void {
        $rowPk = [
            'c_alt_name_type_code' => 10,
            'c_alt_name_chn' => 'A & B',
            'c_sequence' => 1,
            'c_personid' => 123,
        ];

        $text = $this->service->buildRowPkText('ALTNAME_DATA', $rowPk);

        // Schema 已切為 3-key，c_sequence 被過濾
        $this->assertSame(
            'c_personid=123&c_alt_name_chn=A%20%26%20B&c_alt_name_type_code=10',
            $text
        );
    }

    /** @test */
    public function it_writes_audit_log_with_generated_operation_id(): void {
        $this->service->write(
            'BIOG_MAIN',
            'insert',
            ['c_personid' => 100],
            null,
            ['c_personid' => 100, 'c_name_chn' => '張三'],
            'user',
            '1'
        );

        $row = DB::table('audit_log')->first();

        $this->assertNotNull($row);
        $this->assertSame('BIOG_MAIN', $row->table_name);
        $this->assertSame('INSERT', $row->operation);
        $this->assertSame('c_personid=100', $row->row_pk_text);
        $this->assertSame(26, strlen($row->operation_id));
        $this->assertSame(['c_personid' => 100], json_decode($row->row_pk, true));
        $this->assertSame(
            ['c_personid' => 100, 'c_name_chn' => '張三'],
            json_decode($row->new_data, true)
        );
    }
}
