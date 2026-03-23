<?php

namespace App\Repositories;

use App\Models\Operation;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BiogSourceRepository {
    public const RESOURCE = 'BIOG_SOURCE_DATA';
    public const RESOURCE_TYPE = 'sources';
    public const KEY_COLUMNS = ['c_personid', 'c_textid', 'c_pages'];
    public const MUTABLE_COLUMNS = ['c_notes', 'c_main_source', 'c_self_bio'];

    protected OperationRepository $operationRepository;

    public function __construct(OperationRepository $operationRepository) {
        $this->operationRepository = $operationRepository;
    }

    public function findByPk(array $pk): ?array {
        $row = DB::table(self::RESOURCE)
            ->where('c_personid', $pk['c_personid'])
            ->where('c_textid', $pk['c_textid'])
            ->where('c_pages', $pk['c_pages'])
            ->first();

        return $row ? (array) $row : null;
    }

    public function validateMutation(int $personId, array $targetPk, array $changes, string $operation): array {
        $errors = [];

        if (!array_key_exists('c_personid', $changes)) {
            $errors['changes.c_personid'][] = 'required';
        } elseif ((string) $changes['c_personid'] !== (string) $personId) {
            $errors['changes.c_personid'][] = 'mismatch';
        }

        if ((string) ($targetPk['c_personid'] ?? '') !== (string) $personId) {
            $errors['target.pk.c_personid'][] = 'mismatch';
        }

        $textId = $changes['c_textid'] ?? $targetPk['c_textid'] ?? null;
        if ($textId === null || $textId === '') {
            $errors['c_textid'][] = 'required';
        } elseif (!$this->textExists($textId)) {
            $errors['c_textid'][] = 'invalid';
        }

        $pages = $changes['c_pages'] ?? $targetPk['c_pages'] ?? null;
        if (!is_string($pages) || trim($pages) === '') {
            $errors['c_pages'][] = 'required';
        }

        if ($operation === 'update') {
            foreach (self::KEY_COLUMNS as $column) {
                if (!array_key_exists($column, $targetPk)) {
                    $errors['target.pk.'.$column][] = 'required';
                    continue;
                }

                if (array_key_exists($column, $changes) && (string) $changes[$column] !== (string) $targetPk[$column]) {
                    $errors['changes.'.$column][] = 'immutable';
                }
            }

            if ($this->extractRequestedUpdateFields($changes) === []) {
                $errors['changes'][] = 'no_supported_fields';
            }
        }

        return $errors;
    }

    public function buildCreatePayload(int $personId, array $targetPk, array $changes): array {
        $data = [
            'c_personid' => $personId,
            'c_textid' => $this->normalizeTextId($changes['c_textid'] ?? $targetPk['c_textid']),
            'c_pages' => trim((string) ($changes['c_pages'] ?? $targetPk['c_pages'])),
            'c_notes' => $this->normalizeNullableString($changes['c_notes'] ?? null),
            'c_main_source' => $this->normalizeBooleanFlag($changes['c_main_source'] ?? 0),
            'c_self_bio' => $this->normalizeBooleanFlag($changes['c_self_bio'] ?? 0),
        ];

        return $data;
    }

    public function buildUpdatePayload(int $personId, array $targetPk, array $changes, array $existing): array {
        return [
            'c_personid' => $personId,
            'c_textid' => $this->normalizeTextId($targetPk['c_textid']),
            'c_pages' => (string) $targetPk['c_pages'],
            'c_notes' => array_key_exists('c_notes', $changes)
                ? $this->normalizeNullableString($changes['c_notes'])
                : $this->normalizeNullableString($existing['c_notes'] ?? null),
            'c_main_source' => array_key_exists('c_main_source', $changes)
                ? $this->normalizeBooleanFlag($changes['c_main_source'])
                : $this->normalizeBooleanFlag($existing['c_main_source'] ?? 0),
            'c_self_bio' => array_key_exists('c_self_bio', $changes)
                ? $this->normalizeBooleanFlag($changes['c_self_bio'])
                : $this->normalizeBooleanFlag($existing['c_self_bio'] ?? 0),
        ];
    }

    public function hasMeaningfulUpdate(array $existing, array $data): bool {
        foreach (self::MUTABLE_COLUMNS as $column) {
            if ($this->normalizeComparableValue($existing[$column] ?? null) !== $this->normalizeComparableValue($data[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function hasPendingCreateProposal(array $pk): bool {
        $resourceId = CompositePrimaryKey::buildStoredResourceId($pk);

        return DB::table('operations')
            ->where('resource', self::RESOURCE)
            ->where('resource_id', $resourceId)
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->get()
            ->contains(function ($operation) {
                $payload = json_decode($operation->resource_data ?? '[]', true);

                return is_array($payload) && ($payload['__review_status'] ?? null) === 'pending';
            });
    }

    public function createProposal(int $personId, array $data, string $comment = ''): array {
        $operation = $this->storeProposalOperation(Operation::TYPE_PROPOSAL_CREATE, $personId, $data, [], $comment);

        return [
            'pk' => $this->extractPk($data),
            'operation_id' => $operation->id,
        ];
    }

    public function updateProposal(int $personId, array $targetPk, array $data, array $existing, string $comment = ''): array {
        $operation = $this->storeProposalOperation(Operation::TYPE_PROPOSAL_UPDATE, $personId, $data, $existing, $comment);

        return [
            'pk' => $this->extractPk($targetPk),
            'operation_id' => $operation->id,
        ];
    }

    public function createDirect(int $personId, array $data): array {
        $actor = Auth::user()->name ?? Auth::id();
        $timestamp = Carbon::now();

        $data['c_created_by'] = $actor;
        $data['c_created_date'] = $timestamp;

        $operation = null;

        DB::transaction(function () use ($personId, $data, &$operation) {
            DB::table(self::RESOURCE)->insert($data);

            $operation = $this->operationRepository->store(
                Auth::id(),
                $personId,
                Operation::TYPE_CREATE,
                self::RESOURCE,
                CompositePrimaryKey::buildStoredResourceId($this->extractPk($data)),
                $data
            );

            (new AuditLogService())->write(
                self::RESOURCE,
                'INSERT',
                $this->extractPk($data),
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });

        return [
            'pk' => $this->extractPk($data),
            'operation_id' => $operation?->id,
            'row' => $this->findByPk($this->extractPk($data)),
        ];
    }

    public function updateDirect(int $personId, array $targetPk, array $data, array $existing): array {
        $data['c_modified_by'] = Auth::user()->name ?? Auth::id();
        $data['c_modified_date'] = Carbon::now();

        $operation = null;

        DB::transaction(function () use ($personId, $targetPk, $data, $existing, &$operation) {
            DB::table(self::RESOURCE)
                ->where('c_personid', $targetPk['c_personid'])
                ->where('c_textid', $targetPk['c_textid'])
                ->where('c_pages', $targetPk['c_pages'])
                ->update($data);

            $operation = $this->operationRepository->store(
                Auth::id(),
                $personId,
                Operation::TYPE_UPDATE,
                self::RESOURCE,
                CompositePrimaryKey::buildStoredResourceId($this->extractPk($data)),
                $data,
                $existing
            );

            (new AuditLogService())->write(
                self::RESOURCE,
                'UPDATE',
                $this->extractPk($data),
                (new AuditLogService())->normalizeRow($existing),
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });

        return [
            'pk' => $this->extractPk($data),
            'operation_id' => $operation?->id,
            'row' => $this->findByPk($this->extractPk($data)),
        ];
    }

    protected function storeProposalOperation(int $type, int $personId, array $data, array $original, string $comment = '') {
        $resourceData = $data;
        $resourceData['__proposal_meta'] = [
            'action' => $type === Operation::TYPE_PROPOSAL_CREATE ? 'create' : 'update',
            'resource_type' => self::RESOURCE_TYPE,
            'table' => self::RESOURCE,
            'display_name' => '出處',
            'submitted_by' => Auth::user()->name ?? Auth::id(),
            'submitted_by_id' => Auth::id(),
            'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'comment' => $comment,
        ];
        $resourceData['__review_status'] = 'pending';
        $resourceData['__key_columns'] = self::KEY_COLUMNS;

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            $type,
            self::RESOURCE,
            CompositePrimaryKey::buildStoredResourceId($this->extractPk($data)),
            $resourceData,
            $original
        );

        return $operation;
    }

    protected function textExists($textId): bool {
        $normalized = $this->normalizeTextId($textId);

        if (!Schema::hasTable('TEXT_CODES')) {
            return false;
        }

        return DB::table('TEXT_CODES')->where('c_textid', $normalized)->exists();
    }

    protected function extractRequestedUpdateFields(array $changes): array {
        $fields = [];
        foreach (self::MUTABLE_COLUMNS as $column) {
            if (array_key_exists($column, $changes)) {
                $fields[] = $column;
            }
        }

        return $fields;
    }

    protected function normalizeTextId($value): int {
        $normalized = (int) $value;

        return $normalized === -999 ? 0 : $normalized;
    }

    protected function normalizeBooleanFlag($value): int {
        return (int) $value;
    }

    protected function normalizeNullableString($value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function normalizeComparableValue($value): string {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    protected function extractPk(array $data): array {
        return [
            'c_personid' => (int) $data['c_personid'],
            'c_textid' => (int) $data['c_textid'],
            'c_pages' => (string) $data['c_pages'],
        ];
    }
}
