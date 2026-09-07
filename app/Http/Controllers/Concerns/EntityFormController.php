<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Operation;
use App\Support\EntityAggregateRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 實體聚合表單頁（/app/office、/app/social-institution、/app/text 的新增／編輯頁）共用的
 * 授權與「修改提案」預填邏輯（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §4.5）。
 *
 * 三件事對三個實體完全同構，故收在一處：
 *  - 表單頁門檻由 config/entity_aggregates.php 的 form_capability 推導
 *    （EntityAggregateRegistry::userCanReachForm()），與 operations 列表的連結解析同源——
 *    守衛若自己另判一套，列表就可能發出一條必然 403 的「查閱」／「修改提案」。
 *  - 前端能力旗標 can_edit／can_propose（實際寫入由 mutation API 各自授權）。
 *  - 修改提案模式（?proposal={operation_id}）：把實體級提案存的「聚合意圖」（changes）
 *    原樣交給同一個表單預填，送出時打 resubmit 端點——與人物子資源編輯器同一套契約
 *    （BasicInformationController::proposalResubmitProps()）。
 */
trait EntityFormController {
    /** 本 controller 服務的聚合 API 名（config/entity_aggregates.php 的 resource）。 */
    abstract protected function entityResource(): string;

    /** @return array<string, mixed> */
    protected function entity(): array {
        $entity = EntityAggregateRegistry::entityForResource($this->entityResource());
        if ($entity === null) {
            throw new \LogicException("entity_aggregates 未登記 resource={$this->entityResource()}");
        }

        return $entity;
    }

    /** 表單頁（新增／編輯）門檻：依 form_capability，可提案者（含眾包）即可進。 */
    protected function ensureCanReachForm(): void {
        if (!EntityAggregateRegistry::userCanReachForm($this->entity())) {
            abort(403);
        }
    }

    /**
     * 前端表單能力旗標（與人物子資源頁一致）。
     *
     * @return array{can_edit: bool, can_propose: bool}
     */
    protected function formCapabilities(): array {
        $user = Auth::user();

        return [
            'can_edit' => $user ? $user->canWriteDirectly() : false,
            'can_propose' => $user ? $user->canPropose() : false,
        ];
    }

    /**
     * 修改提案模式：載入待審的實體級提案，回傳 [overlay, resubmit props]。
     *
     * overlay＝提案存的 changes（使用者原始輸入，形狀就是表單送出的形狀），表單以它覆蓋
     * 初始值；resubmit props 同人物子資源編輯器（resubmit_proposal_id／initial_comment／
     * resubmit_endpoint）。沒帶 ?proposal 時回兩個空陣列。
     *
     * 提案必須與本頁對得上：同一實體、同一操作（新增頁只收 create 提案、編輯頁只收
     * 該實體 id 的 update 提案）。對不上一律 404——預填到錯的實體上，比找不到糟得多。
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function proposalResubmitProps(Request $request, string $operation, ?int $id = null): array {
        $proposalId = $request->query('proposal');
        if ($proposalId === null || $proposalId === '') {
            return [[], []];
        }

        $proposal = Operation::find((int) $proposalId);
        $payload = $proposal ? json_decode((string) $proposal->resource_data, true) : null;
        $payload = is_array($payload) ? $payload : [];
        $expectedType = $operation === 'create' ? Operation::TYPE_PROPOSAL_CREATE : Operation::TYPE_PROPOSAL_UPDATE;

        if ($proposal === null
            || (int) $proposal->op_type !== $expectedType
            || !($payload['__entity_aggregate'] ?? false)
            || strtolower((string) ($payload['__entity_resource'] ?? '')) !== $this->entityResource()
            || ($payload['__entity_operation'] ?? null) !== $operation
            || ($operation === 'update' && (int) ($payload['__entity_pk'] ?? 0) !== $id)) {
            abort(404, '找不到對應的提案');
        }

        $user = Auth::user();
        if (!$user || ((int) $proposal->user_id !== (int) $user->id && !$user->canReviewProposals())) {
            abort(403, '只有提案人或審核人可以修改提案');
        }
        if (!in_array((string) ($payload['__review_status'] ?? 'pending'), ['pending', 'rejected'], true)) {
            abort(409, '提案已審結或撤回，無法修改');
        }

        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];

        return [$changes, [
            'resubmit_proposal_id' => (int) $proposal->id,
            'initial_comment' => (string) ($payload['__proposal_meta']['comment'] ?? ''),
            'resubmit_endpoint' => route('api.v2.proposals.resubmit.web', ['operation' => $proposal->id], false),
        ]];
    }
}
