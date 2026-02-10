<?php

namespace App\Services;

use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogService {
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
            'row_pk' => $rowPk,
            'row_pk_text' => $this->buildRowPkText($table, $rowPk),
            'old_data' => $oldData,
            'new_data' => $newData,
        ]);
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
}
