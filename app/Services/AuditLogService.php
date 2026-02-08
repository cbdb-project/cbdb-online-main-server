<?php

namespace App\Services;

use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditLogService {
    public function buildRowPkText(string $tableName, array $rowPk): string {
        $normalized = $this->normalizeRowPk($tableName, $rowPk);

        return $this->buildRowPkTextFromNormalized($normalized);
    }

    public function write(
        string $tableName,
        string $operation,
        array $rowPk,
        ?array $oldData,
        ?array $newData,
        string $actorType,
        string $actorId,
        ?string $operationId = null,
        ?Carbon $occurredAt = null,
        ?Carbon $createdAt = null
    ): void {
        $normalizedRowPk = $this->normalizeRowPk($tableName, $rowPk);
        $rowPkText = $this->buildRowPkTextFromNormalized($normalizedRowPk);

        $occurredAt = $occurredAt ?? Carbon::now();
        $createdAt = $createdAt ?? $occurredAt;
        $operationId = $operationId ?: (string) Str::ulid();

        DB::table('audit_log')->insert([
            'occurred_at' => $occurredAt,
            'created_at' => $createdAt,
            'table_name' => $tableName,
            'operation' => strtoupper($operation),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'operation_id' => $operationId,
            'row_pk' => json_encode($normalizedRowPk, JSON_UNESCAPED_UNICODE),
            'row_pk_text' => $rowPkText,
            'old_data' => $oldData === null ? null : json_encode($oldData, JSON_UNESCAPED_UNICODE),
            'new_data' => $newData === null ? null : json_encode($newData, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function buildRowPkTextFromNormalized(array $rowPk): string {
        $encoded = array_map(fn ($value) => $value === null ? 'NULL' : $value, $rowPk);

        return http_build_query($encoded, '', '&', PHP_QUERY_RFC3986);
    }

    private function normalizeRowPk(string $tableName, array $rowPk): array {
        $schema = CompositePrimaryKey::getSchema($tableName);
        if (!$schema) {
            ksort($rowPk);

            return $rowPk;
        }

        $missing = [];
        $normalized = [];
        foreach ($schema as $field) {
            if (!array_key_exists($field, $rowPk)) {
                $missing[] = $field;
            }
            $normalized[$field] = $rowPk[$field] ?? null;
        }

        if (!empty($missing)) {
            throw new InvalidArgumentException(sprintf(
                'Missing primary key fields for %s: %s',
                $tableName,
                implode(', ', $missing)
            ));
        }

        return $normalized;
    }
}
