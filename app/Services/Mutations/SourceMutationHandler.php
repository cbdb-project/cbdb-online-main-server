<?php

namespace App\Services\Mutations;

use App\Repositories\BiogSourceRepository;
use App\Support\CompositePrimaryKey;
use App\Support\VariantEquivalentLookup;
use Illuminate\Http\JsonResponse;

class SourceMutationHandler extends AbstractMutationHandler {
    use \App\Services\Mutations\Concerns\AppliesVariantReplacement;

    /** BIOG_SOURCE_DATA 的 3 鍵主鍵（第三欄 c_pages 是文本欄，會被落地替換）；權威定義在 CompositePrimaryKey。 */
    private const KEY_COLUMNS = CompositePrimaryKey::SCHEMAS['BIOG_SOURCE_DATA'];
    protected BiogSourceRepository $biogSourceRepository;

    public function __construct(BiogSourceRepository $biogSourceRepository) {
        $this->biogSourceRepository = $biogSourceRepository;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $resource === 'sources'
            && in_array($mode, ['proposal', 'direct'], true)
            && in_array($operation, ['create', 'update'], true);
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        // 異體字落地替換的通知統一在此掛上（成功與 409／422 皆帶）。
        $this->resetVariantReplaced();

        return $this->withVariantNotices(
            $this->handleAfterVariantReset($resource, $mode, $operation, $personId, $targetPk, $changes, $meta)
        );
    }

    /** handle() 的原始流程；異體字通知由 handle() 統一掛上。 */
    protected function handleAfterVariantReset(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'BIOG_SOURCE_DATA', ['c_pages']);
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        // 異體字落地替換（型別驅動；BIOG_SOURCE_DATA 全文本欄走 lenient，含 PK 第三欄 c_pages）。
        //
        // 為什麼要在這裡、而且 create 與 update 對 $targetPk 的處理不同：
        // - create：$targetPk 就是「要建立的那個 PK」，必須一起替換，否則落庫的 c_pages
        //   會是變體形、與 $changes 那側正規化的結果不一致。
        // - update：$targetPk 是**既有列的定位器**，那列可能正存著變體形（D6 不做回溯校正），
        //   替換它會定位落空 404。所以只替換 $changes——使用者把 c_pages 改成參考形時，
        //   isReKeyed() 會如實判定為改鍵並走既有的碰撞偵測（D7「觸碰即歸一」）。
        //   反之若使用者送的變體形替換後恰好等於原 PK，isReKeyed() 判否，不會誤報 409。
        $changes = $this->applyVariantReplacement($changes, 'BIOG_SOURCE_DATA');
        if ($operation === 'create') {
            $targetPk = $this->applyVariantReplacement($targetPk, 'BIOG_SOURCE_DATA');
        }

        $validationErrors = $this->biogSourceRepository->validateMutation($personId, $targetPk, $changes, $operation);
        if ($validationErrors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        if ($operation === 'create') {
            $data = $this->biogSourceRepository->buildCreatePayload($personId, $targetPk, $changes);
            // 第二個條件是 D7「兩形並存」查重：c_pages 是 PK 第三欄且是文本欄，既有列可能
            // 存變體形（D6 不做回溯校正），只比對替換後的值會讓「原樣重送變體形」鑄出第二列
            // 語義重複資料，而唯一鍵擋不住（不同字形＝不同鍵值）。
            $existing = $this->biogSourceRepository->findByPk($data)
                ?: VariantEquivalentLookup::findExistingRow('BIOG_SOURCE_DATA', self::KEY_COLUMNS, $data);
            if ($existing) {
                return $this->errorResponse('BIOG_SOURCE_DATA 記錄已存在', 409, [
                    'target.pk' => ['duplicate'],
                ]);
            }

            // 核准提案時以 direct 重放本 handler，待審的那筆提案正是自己——排除之，否則自擋（§4.5）。
            $approvingOperationId = isset($meta['__approving_operation_id'])
                ? (int) $meta['__approving_operation_id']
                : null;

            // 同上：帶變體形 resource_id 的舊提案與帶歸一後 resource_id 的新提案不會相等。
            if ($this->biogSourceRepository->hasPendingCreateProposal($data, $approvingOperationId)
                || VariantEquivalentLookup::hasEquivalentPendingCreateProposal(
                    'BIOG_SOURCE_DATA',
                    self::KEY_COLUMNS,
                    $data,
                    $approvingOperationId,
                    null,
                    $personId,
                    // BIOG_SOURCE_DATA 的既有語義：只有 pending 算衝突，被拒絕的提案可重新提交
                    // （見 BiogSourceRepository::hasPendingCreateProposal()）。
                    ['pending']
                )) {
                return $this->errorResponse('相同主鍵已有待審核提案', 409, [
                    'target.pk' => ['pending_proposal_exists'],
                ]);
            }
        } else {
            $existing = $this->biogSourceRepository->findByPk($targetPk);
            if (!$existing) {
                return $this->errorResponse('BIOG_SOURCE_DATA 記錄不存在', 404);
            }

            $data = $this->biogSourceRepository->buildUpdatePayload($personId, $targetPk, $changes, $existing);

            // 改鍵（c_textid/c_pages 變更）碰撞偵測：新主鍵已存在另一列時擋下，避免 UPDATE 覆寫他列。
            // 第二個條件是 D7 查重：c_pages 是文本型 PK 欄，既有列可能存變體形，
            // 精確比對查不到、唯一鍵也不衝突（不同字形＝不同鍵值）⇒ 會落成兩形並存。
            if (($this->biogSourceRepository->isReKeyed($targetPk, $changes) && $this->biogSourceRepository->findByPk($data))
                || $this->findVariantEquivalentSourceConflict($existing, $data)) {
                return $this->errorResponse('變更後的出處主鍵與現有記錄重複', 409, [
                    'target.pk' => ['duplicate'],
                ]);
            }

            if (!$this->biogSourceRepository->hasMeaningfulUpdate($existing, $data)) {
                return $this->errorResponse('未偵測到任何修改內容', 422, [
                    'changes' => ['no_effective_changes'],
                ]);
            }
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        if ($mode === 'proposal') {
            $result = $operation === 'create'
                ? $this->biogSourceRepository->createProposal($personId, $data, $comment)
                : $this->biogSourceRepository->updateProposal($personId, $targetPk, $data, $existing, $comment);

            return response()->json([
                'ok' => true,
                'resource' => 'sources',
                'mode' => $mode,
                'operation' => $operation,
                'result' => [
                    'pk' => $result['pk'],
                    'status' => $operation === 'create' ? 'proposal_created' : 'proposal_updated',
                    'operation_id' => $result['operation_id'],
                ],
            ]);
        }

        try {
            $result = $operation === 'create'
                ? $this->biogSourceRepository->createDirect($personId, $data)
                : $this->biogSourceRepository->updateDirect($personId, $targetPk, $data, $existing);
        } catch (\Illuminate\Database\QueryException $e) {
            // 改鍵/新增競態：findByPk 預檢後另一請求搶占同主鍵 → DB 唯一鍵衝突，縱深防禦轉 409
            // （與其他子資源 handler 一致），不冒成未捕捉的 500。
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->errorResponse('變更後的出處主鍵與現有記錄重複', 409, [
                    'target.pk' => ['duplicate'],
                ]);
            }

            throw $e;
        }

        return response()->json([
            'ok' => true,
            'resource' => 'sources',
            'mode' => $mode,
            'operation' => $operation,
            'result' => [
                'pk' => $result['pk'],
                'status' => $operation === 'create' ? 'created' : 'updated',
                'operation_id' => $result['operation_id'],
                'row' => $result['row'],
            ],
        ]);
    }

    /**
     * 更新後的 PK 是否與**另一**既有列「異體字歸一後相同」（c_pages 是文本型 PK 欄）。
     *
     * 排除自己是必要的：只改非 PK 欄時其餘 PK 欄與原列相同，
     * `VariantEquivalentLookup` 會把原列自己撈回來，誤報 409。
     *
     * @param array<string,mixed>|object $existing 實際命中的既有列（repository 可能回陣列或物件）（**不能**用 $targetPk 比對：payload 的 PK
     *                          可能帶哨兵別名，例如 c_textid=-999 實際落庫是 0，
     *                          用別名比會把原列自己誤判成別列而回假 409）
     * @param array<string,mixed> $data 替換後的完整 payload
     */
    private function findVariantEquivalentSourceConflict(array|object $existing, array $data): bool {
        // 排除自己交給 lookup 內部做：候選集可以有多列同時歸一成同一個值，
        // 在外面看第一筆是不是自己會漏掉真正衝突的另一列。
        $selfPk = array_intersect_key((array) $existing, array_flip(self::KEY_COLUMNS));

        // 只在真的改鍵時才檢查：既有資料可能早就有兩列歸一後相同（D6 不回溯校正），
        // 使用者只改非 PK 欄是合法操作，無條件檢查會把那些列變成完全無法更新。
        $reKeyed = false;
        foreach (self::KEY_COLUMNS as $column) {
            if ((string) ($data[$column] ?? '') !== (string) ($selfPk[$column] ?? '')) {
                $reKeyed = true;

                break;
            }
        }
        if (!$reKeyed) {
            return false;
        }

        return VariantEquivalentLookup::findExistingRow('BIOG_SOURCE_DATA', self::KEY_COLUMNS, $data, [$selfPk]) !== null;
    }

    /**
     * 判斷 QueryException 是否為唯一性約束衝突（MySQL 1062 / SQLite 19 = SQLITE_CONSTRAINT）。
     * 供改鍵競態縱深防禦轉 409 用（對齊 AbstractPersonSubresourceMutationHandler 同名判斷）。
     */
    private function isUniqueConstraintViolation(\Illuminate\Database\QueryException $e): bool {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($code, [1062, 19], true)) {
            return true;
        }
        $msg = $e->getMessage();

        return str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate entry');
    }
}
