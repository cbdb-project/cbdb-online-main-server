<?php

namespace App\Services;

use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditLogService {
    public function write(
        string $table,
        string $operation,
        array $rowPk,
        ?array $oldData,
        ?array $newData,
        ?string $actorType = null,
        ?string $actorId = null,
        ?string $operationId = null,
        ?Carbon $occurredAt = null,
        ?Carbon $createdAt = null
    ): void {
        $this->logChange(
            $table,
            $operation,
            $rowPk,
            $oldData,
            $newData,
            $operationId,
            $actorType,
            $actorId,
            $occurredAt,
            $createdAt
        );
    }

    public function logChange(
        string $table,
        string $operation,
        array $rowPk,
        ?array $oldData,
        ?array $newData,
        ?string $operationId = null,
        ?string $actorType = null,
        ?string $actorId = null,
        ?Carbon $occurredAt = null,
        ?Carbon $createdAt = null
    ): void {
        $operationId = $operationId ?: (string) Str::ulid();
        $resolvedActorType = $actorType ?? (Auth::check() ? 'user' : 'system');
        $resolvedActorId = $actorId ?? (Auth::check() ? (string) Auth::id() : 'system');
        $occurredAt = $occurredAt ?: Carbon::now();
        $createdAt = $createdAt ?: $occurredAt;

        DB::table('audit_log')->insert([
            'occurred_at' => $occurredAt,
            'created_at' => $createdAt,
            'table_name' => $table,
            'operation' => strtoupper($operation),
            'actor_type' => $resolvedActorType,
            'actor_id' => $resolvedActorId,
            'operation_id' => $operationId,
            'row_pk' => $this->encodeJson($rowPk),
            'row_pk_text' => $this->buildRowPkText($table, $rowPk),
            'old_data' => $this->encodeJson($oldData),
            'new_data' => $this->encodeJson($newData),
        ]);

        // 單一寫者：每筆人物相關變更更新 person_change_index 水位線。
        // 此處是所有 direct mutation 與提案核准套用的收斂點，掛這裡即一網打盡。
        // recordChange 內含 scope 與 person_change_index 存在性守衛，非人物表或表未建時為 no-op。
        //
        // 用 afterCommit 把水位線更新延到外層 mutation 交易「提交後」才執行（交易外、獨立語句）：
        //  - 若 mutation 回滾，callback 不執行（不為未持久化的變更跳水位線，語意正確）；
        //  - 若水位線 upsert 自身失敗（含死鎖），只影響它自己，**絕不回滾已提交的使用者資料**；
        //    失敗僅記 Log::warning，缺口由 rebuild 命令（權威來源）補回；
        //  - 不在交易內時，afterCommit 會立即執行該 callback。
        $occurredAtString = $occurredAt->format('Y-m-d H:i:s');
        DB::afterCommit(function () use ($table, $rowPk, $newData, $oldData, $occurredAtString) {
            try {
                app(PersonChangeIndexService::class)->recordChange($table, $rowPk, $newData, $oldData, $occurredAtString);
            } catch (\Throwable $e) {
                Log::warning('person_change_index 即時更新失敗，將由 rebuild 補回', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function buildRowPkFromData(string $table, array $data): array {
        $schema = CompositePrimaryKey::getSchema($table);
        if ($schema === null) {
            return [];
        }

        $rowPk = [];
        foreach ($schema as $field) {
            if (array_key_exists($field, $data)) {
                $rowPk[$field] = $data[$field];
            }
        }

        return $rowPk;
    }

    public function buildRowPkText(string $table, array $rowPk): string {
        $schema = CompositePrimaryKey::getSchema($table);
        if ($schema === null) {
            return http_build_query($rowPk, '', '&', PHP_QUERY_RFC3986);
        }

        $ordered = [];
        foreach ($schema as $field) {
            if (array_key_exists($field, $rowPk)) {
                $ordered[$field] = $rowPk[$field];
            }
        }

        return http_build_query($ordered, '', '&', PHP_QUERY_RFC3986);
    }

    public function normalizeRow($row): array {
        if ($row === null) {
            return [];
        }

        if (is_array($row)) {
            return $row;
        }

        return json_decode(json_encode($row), true) ?: [];
    }

    private function encodeJson(?array $value): ?string {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
