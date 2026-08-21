<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `OperationsController::restore()` 對 char_variant_map 的結構把關（計畫 S1）。
 *
 * 為什麼 restore 需要這道 guard：char_variant_map 明文登記在 `resourceKeyColumns()`，
 * 所以 restore 對它是刻意支援的；但它同時是**所有落地替換的資料來源**，還原一筆壞對照
 * （成環、多字元）會讓全站的替換降級。restore 是 10 個寫入入口中唯一不在 S2／S5／S6
 * 編輯範圍內的那條，所以在 S1 補。
 *
 * **注意這與「restore 不做內容替換」不衝突**：那是不對還原的內容套落地替換（保留歷史
 * 字形），這裡是對這張表的寫入做結構驗證。本檔最後一條測試把這個區分釘住。
 */
class OperationsRestoreCharVariantMapGuardTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        Schema::dropIfExists('char_variant_map');
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        Schema::dropIfExists('operations');
        Schema::create('operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->nullable();
            $table->integer('op_type')->nullable();
            $table->string('resource', 255)->nullable();
            $table->string('resource_id', 255)->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->tinyInteger('is_admin')->default(0);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        CharVariantMapService::reset();
    }

    private function actAsRestorer(): User {
        $user = new User();
        $user->name = 'restorer';
        $user->email = 'restorer@example.com';
        $user->password = bcrypt('secret');
        // canRestoreOperations() = isActive() && isAdmin()，而 isAdmin() 讀的是 is_admin
        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $user->is_active = User::STATUS_ACTIVE;
        $user->save();

        $this->actingAs($user);

        return $user;
    }

    /**
     * 斷言 flash 訊息含指定字串。
     *
     * 少了這一層，「被擋下」的測試只會斷言 assertRedirect() + DB 未變——那在
     * restore 因**別的**原因失敗時（例如日後把 char_variant_map 從
     * resourceKeyColumns() 拿掉 ⇒ restore_no_pk）同樣會綠，等於假綠。
     */
    private function assertFlashContains(string $needle): void {
        $flash = session('flash_notification', collect())->toArray();
        $messages = array_map(fn ($item) => (string) ($item['message'] ?? ''), $flash);

        $this->assertTrue(
            collect($messages)->contains(fn (string $m) => str_contains($m, $needle)),
            "flash 訊息不含「{$needle}」，實際為：".implode(' | ', $messages)
        );
    }

    /** DYNASTIES 在 resourceKeyColumns() 有登記（['c_dy']），所以 restore 走得通。 */
    private function createDynastiesTable(): void {
        Schema::dropIfExists('DYNASTIES');
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy');
            $table->string('c_dynasty_chn', 255)->nullable();
            $table->string('c_dynasty', 255)->nullable();
        });
    }

    /**
     * 建立一筆「update」operation（op_type=3）：resource_data 是改動後、
     * resource_original 是改動前（restore 會把 original 寫回去）。
     */
    private function makeUpdateOperation(int $id, array $after, array $before): int {
        return (int) DB::table('operations')->insertGetId([
            'user_id' => 1,
            'op_type' => 3,
            'resource' => 'char_variant_map',
            'resource_id' => (string) $id,
            'resource_data' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode($before, JSON_UNESCAPED_UNICODE),
        ]);
    }

    #[Test]
    public function testRestoringALegitimateMappingSucceeds(): void {
        $this->actAsRestorer();

        $id = (int) DB::table('char_variant_map')->insertGetId([
            'id' => 1,
            'c_variant_char' => '淸',
            'c_reference_char' => '菁',
            'c_strict_excluded' => 0,
        ]);

        // 之前是 淸→清，被改成 淸→菁；還原應該把 c_reference_char 還原成「清」
        $operationId = $this->makeUpdateOperation(
            $id,
            ['id' => $id, 'c_variant_char' => '淸', 'c_reference_char' => '菁', 'c_strict_excluded' => 0],
            ['id' => $id, 'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        );

        $this->post("/operations/{$operationId}/restore")->assertRedirect();

        $this->assertSame(
            '清',
            DB::table('char_variant_map')->where('id', $id)->value('c_reference_char'),
            '合法還原不該被 guard 誤殺'
        );
    }

    /**
     * 還原一筆會造成環的對照 ⇒ 乾淨的 flash error，DB 不變。
     *
     * 表裡已有 淸→清；把另一列還原成 清→淸 就成環。若沒有 guard，這筆會落庫，
     * 之後每次載入對照表都會觸發環偵測降級（丟掉這兩條邊）。
     */
    #[Test]
    public function testRestoringAMappingThatWouldCreateACycleIsRejected(): void {
        $this->actAsRestorer();

        DB::table('char_variant_map')->insert([
            'id' => 1, 'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0,
        ]);
        DB::table('char_variant_map')->insert([
            'id' => 2, 'c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1,
        ]);

        // 把 id=2 還原成 清→淸（與 id=1 的 淸→清 成環）
        $operationId = $this->makeUpdateOperation(
            2,
            ['id' => 2, 'c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['id' => 2, 'c_variant_char' => '清', 'c_reference_char' => '淸', 'c_strict_excluded' => 1],
        );

        $this->post("/operations/{$operationId}/restore")->assertRedirect();

        $row = DB::table('char_variant_map')->where('id', 2)->first();
        $this->assertSame('峯', $row->c_variant_char, '被擋下的還原不該改動 DB');
        $this->assertSame('峰', $row->c_reference_char);

        // 釘住「是 guard 擋的」而不是 restore 因別的原因失敗
        $this->assertFlashContains(__('variant.cycle_not_allowed', ['char' => '清']));
    }

    /** 還原一筆多字元對照也要被擋（幂等論證只在單一 codepoint 下成立）。 */
    #[Test]
    public function testRestoringAMultiCodepointMappingIsRejected(): void {
        $this->actAsRestorer();

        DB::table('char_variant_map')->insert([
            'id' => 1, 'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0,
        ]);

        $operationId = $this->makeUpdateOperation(
            1,
            ['id' => 1, 'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['id' => 1, 'c_variant_char' => '甲乙', 'c_reference_char' => '丙', 'c_strict_excluded' => 0],
        );

        $this->post("/operations/{$operationId}/restore")->assertRedirect();

        $this->assertSame(
            '淸',
            DB::table('char_variant_map')->where('id', 1)->value('c_variant_char'),
            '多字元對照不該被還原進表裡'
        );
        $this->assertFlashContains(__('variant.single_codepoint_required'));
    }

    /**
     * op_type=4（restoreDelete）也會經過 guard。
     *
     * 註：本案例的 `resource_id` 有值，所以 `buildKeyConditions()` 仍給出 `['id' => 2]`、
     * `$excludeId = 2`。真正的 `$conditions === []` 只在 `operations.resource_id` 為 NULL
     * 時才出現，那條路徑 `$excludeId = null` 是正確語義（被還原的列已被刪、不在任何邊上）。
     */
    #[Test]
    public function testRestoringADeletedMappingIsAlsoGuarded(): void {
        $this->actAsRestorer();

        DB::table('char_variant_map')->insert([
            'id' => 1, 'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0,
        ]);

        // 還原一筆被刪的 清→淸（與現存的 淸→清 成環）
        $operationId = (int) DB::table('operations')->insertGetId([
            'user_id' => 1,
            'op_type' => 4,
            'resource' => 'char_variant_map',
            'resource_id' => '2',
            'resource_data' => json_encode(
                ['id' => 2, 'c_variant_char' => '清', 'c_reference_char' => '淸', 'c_strict_excluded' => 1],
                JSON_UNESCAPED_UNICODE
            ),
            'resource_original' => null,
        ]);

        $this->post("/operations/{$operationId}/restore")->assertRedirect();

        $this->assertSame(1, DB::table('char_variant_map')->count(), '成環的刪除還原不該落庫');
        $this->assertFlashContains(__('variant.cycle_not_allowed', ['char' => '清']));
    }

    /** op_type=4 還原一筆不成環的對照應該成功。 */
    #[Test]
    public function testRestoringADeletedLegitimateMappingSucceeds(): void {
        $this->actAsRestorer();

        $operationId = (int) DB::table('operations')->insertGetId([
            'user_id' => 1,
            'op_type' => 4,
            'resource' => 'char_variant_map',
            'resource_id' => '5',
            'resource_data' => json_encode(
                ['id' => 5, 'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
                JSON_UNESCAPED_UNICODE
            ),
            'resource_original' => null,
        ]);

        $this->post("/operations/{$operationId}/restore")->assertRedirect();

        $this->assertSame(
            '清',
            DB::table('char_variant_map')->where('c_variant_char', '淸')->value('c_reference_char'),
            'op_type=4 的合法還原不該被 guard 誤殺'
        );
    }

    /**
     * 別的表的還原完全不受這道 guard 影響（它以表名早退）。
     */
    #[Test]
    public function testGuardDoesNotAffectOtherTables(): void {
        $this->actAsRestorer();

        $this->createDynastiesTable();
        DB::table('DYNASTIES')->insert(['c_dy' => 1, 'c_dynasty_chn' => '改後']);

        $operationId = (int) DB::table('operations')->insertGetId([
            'user_id' => 1,
            'op_type' => 3,
            'resource' => 'DYNASTIES',
            'resource_id' => '1',
            'resource_data' => json_encode(['c_dy' => 1, 'c_dynasty_chn' => '改後'], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_dy' => 1, 'c_dynasty_chn' => '改前'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->post("/operations/{$operationId}/restore")->assertRedirect();

        $this->assertSame('改前', DB::table('DYNASTIES')->where('c_dy', 1)->value('c_dynasty_chn'));
    }

    /**
     * **restore 不做內容替換**：還原的歷史字形要原樣保留。
     *
     * 這條把 D6／S6 那個刻意的不對稱釘住——S1 只在 restore 掛結構驗證（單 codepoint、
     * 不成環），**不**對還原的內容套落地替換。若日後有人「順手補上」內容替換，
     * 這條會紅。
     */
    #[Test]
    public function testRestoreKeepsHistoricalVariantFormsVerbatim(): void {
        $this->actAsRestorer();

        $this->createDynastiesTable();
        DB::table('DYNASTIES')->insert(['c_dy' => 1, 'c_dynasty_chn' => '清明']);

        DB::table('char_variant_map')->insert([
            'id' => 1, 'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0,
        ]);

        // 歷史快照裡是變體形「淸明」
        $operationId = (int) DB::table('operations')->insertGetId([
            'user_id' => 1,
            'op_type' => 3,
            'resource' => 'DYNASTIES',
            'resource_id' => '1',
            'resource_data' => json_encode(['c_dy' => 1, 'c_dynasty_chn' => '清明'], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_dy' => 1, 'c_dynasty_chn' => '淸明'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->post("/operations/{$operationId}/restore")->assertRedirect();

        $this->assertSame(
            '淸明',
            DB::table('DYNASTIES')->where('c_dy', 1)->value('c_dynasty_chn'),
            'restore 必須忠實還原歷史字形，不可套落地替換'
        );
    }
}
