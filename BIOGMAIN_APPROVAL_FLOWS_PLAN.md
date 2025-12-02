# BiogMain 審批流程實施計劃

## 一、背景與目標

### 1.1 目標
參考 `AGENTS.md` 和 `APPROVAL_FLOWS.md`，為 BiogMain 及其 12 個子頁面（共 13 類項目）實現完整的審批流程，仿照現有 CodesController 的提案審批模式。

### 1.2 技術環境
- **Laravel**：10.0
- **PHP**：8.1+（建議 8.4）
- **Carbon**：2.67+
- **PHPUnit**：10.1
- **數據庫**：MariaDB 10.3.39

### 1.3 涉及範圍
- **BIOG_MAIN 主表**：人物基本信息
- **12 個子頁面**：
  1. 地址（BIOG_ADDR_DATA）
  2. 別名/字號（ALTNAME_DATA）
  3. 著述/文獻（BIOG_TEXT_DATA）
  4. 官職（POSTED_TO_OFFICE_DATA）
  5. 關聯人物（ASSOC_DATA）
  6. 條目（ENTRY_DATA）
  7. 事件（EVENTS_DATA）
  8. 親屬（KIN_DATA）
  9. 身份（STATUS_DATA）
  10. 所有物（POSSESSION_DATA）
  11. 社交機構（BIOG_INST_DATA）
  12. 來源（待確認具體表名）

## 二、現有審批流程分析

### 2.1 CodesController 提案模式總結

#### 操作類型
- `Operation::TYPE_PROPOSAL_CREATE = 8`：新增提案
- `Operation::TYPE_PROPOSAL_UPDATE = 9`：修改提案
- `Operation::TYPE_CREATE = 1`：正式新增（核准後）
- `Operation::TYPE_UPDATE = 2`：正式更新（核准後）

#### 提案數據結構
```json
{
  "field1": "value1",
  "field2": "value2",
  "__proposal_meta": {
    "action": "create|update",
    "table": "TABLE_NAME",
    "submitted_by": "使用者名稱",
    "submitted_by_id": 123,
    "submitted_at": "2025-11-01 23:40:00",
    "comment": "提案說明"
  },
  "__review_status": "pending|approved|rejected|cancelled",
  "__key_columns": ["c_personid", "c_sequence"],
  "__reviewed_by": "審核者名稱",
  "__reviewed_by_id": 456,
  "__reviewed_at": "2025-11-02 10:00:00",
  "__review_comment": "審核備註"
}
```

#### 核心流程
1. **提交提案**：
   - `proposalStore()`：新增提案
   - `proposalUpdate()`：修改提案
   - 檢查主鍵衝突、數據重複
   - 寫入 `operations` 表，`op_type` = 8 或 9

2. **審核提案**（OperationsProposalController）：
   - `approve()`：核准並應用至實際數據表
   - `reject()`：退回提案

3. **提案管理**：
   - `proposalEdit()`：編輯待審核或已退回的提案
   - `proposalUpdateExisting()`：更新提案內容
   - 撤回提案功能

### 2.2 BiogMain 現有操作模式

#### 權限檢查
```php
if (!Auth::check()) {
    flash('请登入后编辑', 'error');
    return redirect()->back();
}
elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2) {
    flash('该用户没有权限，请联系管理员', 'error');
    return redirect()->back();
}
```

#### 操作記錄
```php
$this->operationRepository->store(
    Auth::id(),
    $c_personid,
    1, // op_type
    'ALTNAME_DATA',
    $resourceId,
    $data,
    $originalData // 僅更新時提供
);
```

#### 數據處理特點
- 使用複合主鍵（如 `c_personid-c_sequence-c_alt_name_chn-c_alt_name_type_code`）
- 主鍵中包含特殊字符處理（`unionPKDef()` / `unionPKDef_decode()`）
- 時間戳自動填充（`toolsRepository->timestamp()`）
- 重複數據檢查

## 三、實施策略

### 3.1 整體架構設計

採用**集中式控制器**（推薦）：創建一個統一的 `BasicInformationProposalController`

**優點**：
- 代碼集中，易於維護
- 統一的業務邏輯處理
- 減少重複代碼

**實施方案**：
1. 創建 `BasicInformationProposalController`
2. 創建 `BasicInformationProposalTrait` 提取共用邏輯
3. 擴展 `OperationsProposalController` 支持 BiogMain 資源

### 3.2 路由設計

```php
// routes/web.php

// BiogMain 提案路由組
Route::prefix('basicinformation')->middleware('auth')->group(function () {
    // 主表提案
    Route::post('/proposal-store', 'BasicInformationProposalController@proposalStoreMain')
        ->name('basicinformation.proposal.store');
    Route::post('/{id}/proposal-update', 'BasicInformationProposalController@proposalUpdateMain')
        ->name('basicinformation.proposal.update');

    // 子資源提案（統一格式）
    Route::post('/{personid}/{resource}/proposal-store', 'BasicInformationProposalController@proposalStore')
        ->name('basicinformation.{resource}.proposal.store');
    Route::post('/{personid}/{resource}/{id}/proposal-update', 'BasicInformationProposalController@proposalUpdate')
        ->name('basicinformation.{resource}.proposal.update');

    // 提案管理
    Route::get('/proposals/{operation}/edit', 'BasicInformationProposalController@proposalEdit')
        ->name('basicinformation.proposals.edit');
    Route::put('/proposals/{operation}', 'BasicInformationProposalController@proposalUpdateExisting')
        ->name('basicinformation.proposals.update');
    Route::delete('/proposals/{operation}/cancel', 'BasicInformationProposalController@cancel')
        ->name('basicinformation.proposals.cancel');
});
```

### 3.3 數據表映射

| 資源類型 | 數據表 | 主鍵欄位 | Resource ID 格式 |
|---------|--------|----------|-----------------|
| main | BIOG_MAIN | c_personid | `{c_personid}` |
| addresses | BIOG_ADDR_DATA | c_personid, c_addr_id, c_sequence, c_addr_type | `{c_personid}-{c_addr_id}-{c_sequence}-{c_addr_type}` |
| altnames | ALTNAME_DATA | c_personid, c_sequence, c_alt_name_chn, c_alt_name_type_code | `{c_personid}-{c_sequence}-{c_alt_name_chn}-{c_alt_name_type_code}` |
| texts | BIOG_TEXT_DATA | c_personid, c_textid, c_year_year, c_text_role_code | `{c_personid}-{c_textid}-{c_year_year}-{c_text_role_code}` |
| offices | POSTED_TO_OFFICE_DATA | c_personid, c_posting_id, c_office_id | `{c_personid}-{c_posting_id}-{c_office_id}` |
| assocs | ASSOC_DATA | c_personid, c_assoc_id, c_assoc_code, c_assoc_type | `{c_personid}-{c_assoc_id}-{c_assoc_code}-{c_assoc_type}` |
| entries | ENTRY_DATA | c_personid, c_entry_id, c_entry_code | `{c_personid}-{c_entry_id}-{c_entry_code}` |
| events | EVENTS_DATA | c_personid, c_event_id, c_event_type | `{c_personid}-{c_event_id}-{c_event_type}` |
| kinship | KIN_DATA | c_personid, c_kin_id, c_kin_code | `{c_personid}-{c_kin_id}-{c_kin_code}` |
| statuses | STATUS_DATA | c_personid, c_status_code, c_sequence | `{c_personid}-{c_status_code}-{c_sequence}` |
| possessions | POSSESSION_DATA | c_personid, c_poss_code, c_sequence | `{c_personid}-{c_poss_code}-{c_sequence}` |
| socialinst | BIOG_INST_DATA | c_personid, c_inst_code, c_inst_role_code | `{c_personid}-{c_inst_code}-{c_inst_role_code}` |

### 3.4 資源配置數組

```php
// BasicInformationProposalController.php

protected $resourceConfigs = [
    'altnames' => [
        'table' => 'ALTNAME_DATA',
        'key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
        'controller' => 'BasicInformationAltnamesController',
        'route_prefix' => 'basicinformation.altnames',
    ],
    'addresses' => [
        'table' => 'BIOG_ADDR_DATA',
        'key_columns' => ['c_personid', 'c_addr_id', 'c_sequence', 'c_addr_type'],
        'controller' => 'BasicInformationAddressesController',
        'route_prefix' => 'basicinformation.addresses',
    ],
    // ... 其他資源配置
];
```

## 四、實施步驟

### 階段一：基礎架構（第 1 週）

#### 1.1 創建提案控制器
```bash
php artisan make:controller BasicInformationProposalController
```

**實現內容**：
- [ ] 定義資源配置數組 `$resourceConfigs`
- [ ] 實現 `proposalStore()` - 新增提案
- [ ] 實現 `proposalUpdate()` - 修改提案
- [ ] 實現 `proposalStoreMain()` - 主表新增提案
- [ ] 實現 `proposalUpdateMain()` - 主表修改提案
- [ ] 實現 `proposalEdit()` - 編輯提案
- [ ] 實現 `proposalUpdateExisting()` - 更新提案內容
- [ ] 實現 `cancel()` - 撤回提案

#### 1.2 創建共用 Trait
```bash
touch app/Traits/BasicInformationProposalTrait.php
```

**實現內容**：
- [ ] `getResourceConfig($resourceType)` - 獲取資源配置
- [ ] `buildProposalMeta($action, $resourceType, $request)` - 構建提案元數據
- [ ] `recordProposalOperation($opType, $resourceType, $personId, $data, $original, $meta)` - 記錄提案
- [ ] `sanitizeProposalPayload($payload)` - 清理提案數據
- [ ] `buildCompositeId($keyColumns, $data)` - 構建複合主鍵 ID
- [ ] `parseCompositeId($id, $keyColumns)` - 解析複合主鍵 ID
- [ ] `ensureProposalEditable($operation, $resourceType)` - 確保提案可編輯
- [ ] `hasActiveProposalConflict($table, $keyColumns, $data, $opType)` - 檢查提案衝突

#### 1.3 擴展 OperationsProposalController
在 `OperationsProposalController::approve()` 中添加對 BiogMain 資源的支持：

```php
protected function applyApprovedProposal(Operation $proposal, array $data, array $original, array $keyColumns)
{
    $table = $proposal->resource;
    $opType = (int) $proposal->op_type;

    // 特殊處理：官職需要使用 Repository 方法
    if ($table === 'POSTED_TO_OFFICE_DATA') {
        return $this->applyOfficeProposal($proposal, $data, $original, $opType);
    }

    // 通用處理
    if ($opType === Operation::TYPE_PROPOSAL_CREATE) {
        return $this->applyCreateProposal($table, $data, $keyColumns);
    }

    return $this->applyUpdateProposal($table, $data, $keyColumns, $original);
}

protected function applyOfficeProposal(Operation $proposal, array $data, array $original, int $opType)
{
    // 使用 BiogMainRepository 的專門方法
    // ...
}
```

### 階段二：視圖調整（第 2 週）

#### 2.1 修改表單視圖
為每個子頁面的 create/edit 視圖添加「提交提案」按鈕。

**範例**：`resources/views/biogmains/altname/create.blade.php`

```blade
@extends('layouts.app')

@section('content')
<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title">新增別名</h3>
    </div>

    <form method="POST" action="{{ route('basicinformation.altnames.store', ['id' => $id]) }}">
        @csrf

        <!-- 表單欄位 -->
        <div class="box-body">
            <!-- ... 現有表單欄位 ... -->
        </div>

        <!-- 按鈕區 -->
        <div class="box-footer">
            @if(Auth::check() && Auth::user()->is_active == 1 && Auth::user()->is_admin != 2)
                <!-- 直接儲存按鈕（管理員可見） -->
                @if(Auth::user()->is_admin == 1)
                    <button type="submit" name="action" value="save" class="btn btn-primary">
                        <i class="fa fa-save"></i> 直接儲存
                    </button>
                @endif

                <!-- 提交提案按鈕（所有活躍用戶可見） -->
                <button type="submit" name="action" value="proposal" class="btn btn-info">
                    <i class="fa fa-paper-plane"></i> 提交提案
                </button>
            @endif

            <a href="{{ route('basicinformation.altnames.index', ['id' => $id]) }}" class="btn btn-default">
                <i class="fa fa-times"></i> 取消
            </a>
        </div>
    </form>
</div>
@endsection
```

**修改控制器**：`BasicInformationAltnamesController::store()`

```php
public function store(Request $request, $id)
{
    // 權限檢查
    if (!Auth::check()) {
        flash('请登入后编辑 @ '.Carbon::now(), 'error');
        return redirect()->back();
    }
    elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
        flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
        return redirect()->back();
    }

    // 檢查動作類型
    $action = $request->input('action', 'save');

    if ($action === 'proposal') {
        // 轉發到提案控制器
        return app(BasicInformationProposalController::class)->proposalStore($request, $id, 'altnames');
    }

    // 原有的直接儲存邏輯
    $data = $request->all();
    $data = array_except($data, ['_token', 'action']);
    // ... 現有邏輯
}
```

#### 2.2 創建提案編輯視圖
創建 `resources/views/biogmains/proposal-edit.blade.php`：

```blade
@extends('layouts.app')

@section('content')
<div class="box box-warning">
    <div class="box-header with-border">
        <h3 class="box-title">
            <i class="fa fa-edit"></i> 編輯提案
            @if($reviewStatus === 'pending')
                <span class="label label-warning">待審核</span>
            @elseif($reviewStatus === 'rejected')
                <span class="label label-danger">已退回</span>
            @endif
        </h3>
    </div>

    @if(isset($reviewComment) && $reviewComment)
        <div class="box-body">
            <div class="alert alert-info">
                <strong>審核備註：</strong>{{ $reviewComment }}
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('basicinformation.proposals.update', ['operation' => $operationId]) }}">
        @csrf
        @method('PUT')

        <div class="box-body">
            @foreach($columns as $column)
                <div class="form-group">
                    <label>{{ $column }}</label>
                    <input type="text" name="{{ $column }}" value="{{ $values[$column] ?? '' }}"
                           class="form-control"
                           @if(in_array($column, $keyColumns) && !$isCreateProposal) readonly @endif>
                </div>
            @endforeach

            <div class="form-group">
                <label>提案說明</label>
                <textarea name="__proposal_comment" class="form-control" rows="3"></textarea>
            </div>
        </div>

        <div class="box-footer">
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save"></i> 更新提案
            </button>
            <a href="{{ route('operations.index', ['proposals_only' => 1]) }}" class="btn btn-default">
                <i class="fa fa-times"></i> 取消
            </a>
        </div>
    </form>
</div>
@endsection
```

### 階段三：逐步實現各模組（第 3-15 週）

建議實施順序（由簡到繁）：

#### 第 3 週：ALTNAME_DATA（別名）✅ 優先
- [ ] 實現提案方法
- [ ] 修改視圖添加提案按鈕
- [ ] 編寫測試
- **原因**：結構簡單，主鍵清晰，無複雜關聯，適合作為模板

#### 第 4 週：STATUS_DATA（身份）
- [ ] 實現提案方法
- [ ] 修改視圖
- [ ] 編寫測試

#### 第 5 週：POSSESSION_DATA（所有物）
- [ ] 實現提案方法
- [ ] 修改視圖
- [ ] 編寫測試

#### 第 6-7 週：BIOG_ADDR_DATA（地址）
- [ ] 實現提案方法（涉及地址代碼關聯）
- [ ] 修改視圖
- [ ] 編寫測試

#### 第 8-9 週：BIOG_TEXT_DATA（著述）
- [ ] 實現提案方法（涉及文本代碼關聯）
- [ ] 修改視圖
- [ ] 編寫測試

#### 第 10 週：ASSOC_DATA（關聯人物）
- [ ] 實現提案方法（涉及人物關聯）
- [ ] 修改視圖
- [ ] 編寫測試

#### 第 11 週：KIN_DATA（親屬）
- [ ] 實現提案方法
- [ ] 修改視圖
- [ ] 編寫測試

#### 第 12 週：其他簡單模組（ENTRY_DATA、EVENTS_DATA、BIOG_INST_DATA）
- [ ] 批量實現
- [ ] 編寫測試

#### 第 13-14 週：POSTED_TO_OFFICE_DATA（官職）⚠️ 最複雜
- [ ] 設計特殊處理邏輯（使用 BiogMainRepository 方法）
- [ ] 處理 POSTED_TO_ADDR_DATA 關聯
- [ ] 實現數據庫交易
- [ ] 編寫測試

#### 第 15 週：BIOG_MAIN（主表）⚠️ 字段眾多
- [ ] 實現提案方法
- [ ] 修改視圖
- [ ] 編寫測試

### 階段四：測試與文檔（第 16-18 週）

#### 4.1 單元測試
創建 `tests/Feature/BasicInformationProposalTest.php`：

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\User;
use App\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BasicInformationProposalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 配置 SQLite 內存數據庫
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 創建必要的表結構
        $this->createTables();
    }

    protected function createTables()
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_admin')->default(0);
            $table->string('confirmation_token')->nullable();
            $table->timestamps();
        });

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->integer('c_personid')->default(0);
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->timestamps();
        });

        Schema::create('ALTNAME_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->string('c_alt_name_chn');
            $table->integer('c_alt_name_type_code');
            $table->timestamps();
            $table->primary(['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code']);
        });
    }

    public function test_can_submit_create_proposal_for_altname()
    {
        $user = User::factory()->create(['is_active' => 1]);

        $response = $this->actingAs($user)->post(route('basicinformation.altnames.proposal.store', ['personid' => 1]), [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '測試別名',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '新增別名提案',
        ]);

        $response->assertSessionHas('flash_notification');
        $this->assertDatabaseHas('operations', [
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ALTNAME_DATA',
        ]);
    }

    public function test_can_submit_update_proposal_for_altname()
    {
        // 先創建一筆資料
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '原別名',
            'c_alt_name_type_code' => 1,
        ]);

        $user = User::factory()->create(['is_active' => 1]);

        $response = $this->actingAs($user)->post(
            route('basicinformation.altnames.proposal.update', ['personid' => 1, 'id' => '1-1-原別名-1']),
            [
                'c_alt_name_chn' => '修改後別名',
                '__proposal_comment' => '修改別名提案',
            ]
        );

        $response->assertSessionHas('flash_notification');
        $this->assertDatabaseHas('operations', [
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ALTNAME_DATA',
        ]);
    }

    // 更多測試...
}
```

#### 4.2 文檔更新
- [ ] 更新 `APPROVAL_FLOWS.md` - 添加 BiogMain 審批流程說明
- [ ] 更新 `CHANGELOG.md` - 記錄新功能
- [ ] 創建用戶操作手冊 `docs/biogmain-proposal-guide.md`

## 五、技術要點

### 5.1 複合主鍵處理

```php
// BasicInformationProposalTrait.php

protected function buildCompositeId($keyColumns, $data)
{
    $parts = [];
    foreach ($keyColumns as $column) {
        $value = $data[$column] ?? '';
        // 處理 NULL 值
        if ($value === null || $value === '') {
            $value = 'NULL';
        }
        // 處理特殊字符（連字符）
        $value = str_replace('-', 'minus', (string) $value);
        $parts[] = $value;
    }
    return implode('-', $parts);
}

protected function parseCompositeId($id, $keyColumns)
{
    // 使用現有的 unionPKDef_decode
    $id = app(\App\Repositories\BiogMainRepository::class)->unionPKDef_decode($id);

    $parts = explode('-', $id);

    $conditions = [];
    foreach ($keyColumns as $index => $column) {
        if (!isset($parts[$index])) {
            throw new \Exception("主鍵解析失敗：缺少 {$column}");
        }

        $value = $parts[$index];
        // 還原特殊字符
        $value = str_replace('minus', '-', $value);
        // 處理 NULL
        if ($value === 'NULL') {
            $value = null;
        }

        $conditions[$column] = $value;
    }

    return $conditions;
}
```

### 5.2 時間戳處理

```php
protected function applyTimestamp($data, $isCreate = false)
{
    return app(\App\Repositories\ToolsRepository::class)->timestamp($data, $isCreate);
}
```

### 5.3 官職特殊處理

```php
// OperationsProposalController.php

protected function applyOfficeProposal(Operation $proposal, array $data, array $original, int $opType)
{
    $payload = json_decode($proposal->resource_data, true);
    $personId = $payload['c_personid'] ?? null;

    if (!$personId) {
        throw new \RuntimeException('缺少 c_personid');
    }

    $biogMainRepo = app(\App\Repositories\BiogMainRepository::class);

    if ($opType === Operation::TYPE_PROPOSAL_CREATE) {
        // 使用 officeStoreById
        return $biogMainRepo->officeStoreById($personId, $data);
    } else {
        // 使用 officeUpdateById
        $officeId = $payload['c_office_id'] ?? null;
        $postingId = $payload['c_posting_id'] ?? null;

        if (!$officeId || !$postingId) {
            throw new \RuntimeException('缺少官職主鍵');
        }

        return $biogMainRepo->officeUpdateById($personId, "{$officeId}-{$postingId}", $data);
    }
}
```

### 5.4 權限控制

```php
// BasicInformationProposalController.php

protected function ensureCanPropose()
{
    if (!Auth::check()) {
        abort(403, '請登入後提交提案');
    }

    if (Auth::user()->is_active != 1) {
        abort(403, '該用戶沒有權限，請聯繫管理員');
    }

    if (Auth::user()->is_admin == 2) {
        abort(403, '該用戶類型不允許提交提案');
    }
}

protected function ensureCanDirectSave()
{
    $this->ensureCanPropose();

    if (Auth::user()->is_admin != 1) {
        abort(403, '僅管理員可直接儲存');
    }
}
```

### 5.5 數據驗證

```php
protected function validatePrimaryKey($keyColumns, $data)
{
    foreach ($keyColumns as $column) {
        if (!array_key_exists($column, $data) || $data[$column] === '' || $data[$column] === null) {
            throw new \InvalidArgumentException("主鍵欄位 {$column} 不得為空");
        }
    }
}

protected function checkDuplicateData($table, $conditions)
{
    $query = DB::table($table);
    foreach ($conditions as $column => $value) {
        if ($value === null) {
            $query->whereNull($column);
        } else {
            $query->where($column, $value);
        }
    }

    if ($query->exists()) {
        throw new \RuntimeException('數據已存在，無法新增');
    }
}

protected function hasActiveProposalConflict($table, $keyColumns, $data, $opType)
{
    $resourceId = $this->buildCompositeId($keyColumns, $data);

    return Operation::where('resource', $table)
        ->where('resource_id', $resourceId)
        ->where('op_type', $opType)
        ->whereRaw("JSON_EXTRACT(resource_data, '$.__review_status') = 'pending'")
        ->exists();
}
```

## 六、風險與挑戰

### 6.1 技術風險

| 風險 | 影響 | 緩解措施 |
|-----|------|---------|
| 複合主鍵解析錯誤 | 高 | 使用現有的 `unionPKDef()` 函數，充分測試 |
| Carbon 2.x 兼容性問題 | 低 | 使用 `format()` 方法，Laravel 10 完全兼容 |
| Laravel 10 新特性 | 低 | 參考官方文檔，使用穩定 API |
| PHPUnit 10 斷言變更 | 中 | 使用新的斷言方法（如 `assertStringContainsString`）|
| 數據庫交易衝突 | 中 | 官職操作使用現有 Repository 方法 |
| JSON 數據過大 | 低 | 監控 `resource_data` 大小 |

### 6.2 業務風險

| 風險 | 影響 | 緩解措施 |
|-----|------|---------|
| 用戶不熟悉提案流程 | 中 | 提供清晰的 UI 提示和操作手冊 |
| 提案積壓 | 中 | 提供提案統計和提醒功能 |
| 誤審核 | 高 | 提供審核預覽 |

## 七、待確認問題

### 問題 1：是否保留直接儲存功能？
- **選項 A**：普通用戶僅能提案，管理員可直接儲存
- **選項 B**：所有用戶（包括管理員）都走提案流程
- **建議**：選項 A

### 問題 2：是否支持刪除提案？
- 當前 CodesController 不支持刪除提案
- **建議**：暫不支持，未來可擴展

### 問題 3：第 12 個子頁面（來源）的具體表是？
- 需要確認是 SOURCES 還是其他表
- **待確認**

### 問題 4：官職的地址列表如何處理？
- POSTED_TO_ADDR_DATA 與 POSTED_TO_OFFICE_DATA 關聯
- **建議**：將地址列表作為 JSON 存儲在提案的 resource_data 中

### 問題 5：是否需要批量操作支持？
- 如批量審核、批量撤回
- **建議**：後續優化

## 八、總結

### 8.1 關鍵成功因素
1. **遵循現有模式** - 嚴格參考 CodesController 的實現
2. **充分測試** - 每個模組都要有完整測試覆蓋
3. **漸進實施** - 從簡單模組開始逐步推進
4. **文檔同步** - 及時更新文檔

### 8.2 預期成果
- 13 個資源類型全部支持提案審批流程
- 統一的提案管理介面
- 完整的操作審計追蹤
- 降低數據錯誤風險

### 8.3 時間估算
- **總工期**：16-18 週
- **核心開發**：12-14 週
- **測試與優化**：4 週

---

**文檔版本**：3.0（基於 Laravel 10.0 / PHP 8.1+）
**創建日期**：2025-11-27
**最後更新**：2025-12-02
**作者**：Claude AI
**審核狀態**：待審核

### 版本歷史
- v3.0 (2025-12-02): 同步 Laravel 10.0 + PHPUnit 10.1 升級
- v2.0 (2025-11-27): 基於 Laravel 8.83 初始版本
- v1.0 (2025-11-17): 初始草案
