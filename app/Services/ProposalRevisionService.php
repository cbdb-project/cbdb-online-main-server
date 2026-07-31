<?php

namespace App\Services;

/**
 * 見 docs/PROPOSAL_REVISION_HASH_DESIGN.md。
 *
 * 為資料列計算「規範化形式 + hash」，供 proposal 提交／審核時做樂觀並行控制的
 * 衝突檢測：同一資料列在不同時間點算出的 revision 若不同，代表期間已被異動。
 */
class ProposalRevisionService {
    public const ALGO = 'canonical-v1';

    /**
     * 各資源的 canonicalization 規則。目前只支援 `exclude`（不參與 revision 計算的
     * 欄位），未註冊的資源套用預設規則（不排除任何欄位）。
     *
     * BIOG_MAIN 排除 audit 欄位：這些欄位會因任何保存而變動，納入會讓衝突判斷
     * 對「任何保存痕跡變動」過於敏感，第一階段只想偵測「內容衝突」。
     */
    protected array $resourceNormalizers = [
        'BIOG_MAIN' => [
            'exclude' => ['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'],
        ],
    ];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function canonicalize(string $resource, array $row): array {
        $exclude = $this->resourceNormalizers[$resource]['exclude'] ?? [];
        $filtered = array_diff_key($row, array_flip($exclude));

        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizeValue($filtered);

        return $normalized;
    }

    public function hash(string $resource, array $row): string {
        $canonical = $this->canonicalize($resource, $row);
        $serialized = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'sha256:'.hash('sha256', $serialized);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    public function matches(string $resource, array $left, array $right): bool {
        return hash_equals($this->hash($resource, $left), $this->hash($resource, $right));
    }

    /**
     * 消除型別差異（如 1 與 "1"）與無意義空白差異；陣列遞迴正規化後依固定鍵序輸出。
     */
    protected function normalizeValue(mixed $value): mixed {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }
            ksort($normalized);

            return $normalized;
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }
}
