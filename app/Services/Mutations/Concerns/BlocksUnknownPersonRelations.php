<?php

namespace App\Services\Mutations\Concerns;

use App\Support\UnknownPerson;
use Illuminate\Http\JsonResponse;

/**
 * 「未詳」人物（c_personid = 0）的關係記錄守衛。
 *
 * CBDB 以 personid 0 表示「未詳」——它不是一個真實人物，只是資料上的佔位。對它、或把它
 * 當成對象建立雙向關係，會產出一條指向不存在人物的邊：關係鏡像沒有對面可同步、人物頁
 * 點進去是空的、聚合統計也會被污染。legacy Blade controller 從一開始就擋著這件事
 * （BasicInformationKinshipController / BasicInformationAssocController 各有 4 道
 * flash 攔截），但 v2 的 kinship／association handler 一直沒有對應守衛——
 * 同族的 PossessionCreateHandler:89 與 PostingCreateHandler:106 反而有，可見是逐一
 * 掛上時漏了這兩個。
 *
 * 缺口是在 Blade 下架計畫環節 1.5（測試分流）被發現的：`UnknownPersonKinshipAssocBlockTest`
 * 的 18 個測試全部只打 legacy 路由，一旦隨環節 2 刪除，這條不變量在全庫將沒有任何憑證，
 * 而走 React 編輯器本來就擋不住。故在刪除 legacy 測試之前先把守衛補到 v2 路徑上。
 *
 * 語義與 legacy 對齊（含訊息文字），改以 422 JSON 回應而非 flash + redirect back。
 *
 * **刻意的兩點差異**（非疏漏）：
 *  1. `-999` 也擋。legacy assoc 是先擋原值 0、之後才把 -999 正規化成 0，於是送 -999 可以
 *     繞過攔截、最後仍寫進一條指向 0 的邊。這裡提前一併視為未詳，是**修正 legacy 的漏洞**。
 *  2. legacy 的 delete 路徑沒有這道攔截，本 trait 也**不掛到 delete handler**——不阻止
 *     清理歷史上已經存在的 0 資料是正確的。
 *
 * **同族路徑的覆蓋現況**（環節 7 已收斂，2026-09-14）：
 *  - **提案核准**不經 mutation handler（`OperationsProposalController::applyKinshipProposal()`／
 *    `applyAssocProposal()` → `BiogMainRepository::kinshipStoreById()` 等），所以本 trait 罩不到。
 *    已在該 controller 用 `blockUnknownPersonProposal()` 補上等價守衛（中止核准、提案維持
 *    pending、理由 flash 給審核者；刻意不自動退回）。
 *  - `BasicInformationController::Duplicate_Collateral_Info()` 逐列複製 KIN_DATA／ASSOC_DATA，
 *    已用 `shouldSkipUnknownPersonRelationRow()` 跳過歷史 0／-999 髒列並記 warning
 *    （跳過而非整批拒絕，與同函式的異體字去重器一致）。
 *  - `PossessionMutationHandler`／`PostingMutationHandler` 的 **update** 側原本沒有擁有者守衛
 *    （create 側早就有），已各自補上；三條入口都掛：一般 update 與兩條「僅改地址」快捷路徑。
 *
 * ⚠️ **仍未覆蓋**：`app/Services/Mutations` 之外、不經 handler 直接落庫的路徑。已知的一條是
 *    `app/Services/Import/OfficeImportService.php`（批次匯入 POSTED_TO_OFFICE_DATA）。
 *    這類路徑沒有機械化把關，只有 code review 擋得住。
 */
trait BlocksUnknownPersonRelations {
    /**
     * 記錄擁有者不得為「未詳」人物。
     *
     * @param int    $personId  記錄所屬人物
     * @param string $recordLabel 記錄類型（「親屬」／「社會關係」），用於組訊息
     * @param string $verb      動作（「新增」／「修改」）
     */
    protected function blockUnknownOwner(int $personId, string $recordLabel, string $verb): ?JsonResponse {
        if (!UnknownPerson::isUnknown($personId)) {
            return null;
        }

        return $this->errorResponse(
            "「未詳」人物不能{$verb}{$recordLabel}記錄。",
            422,
            ['person_id' => ['unknown_person_not_allowed']]
        );
    }

    /**
     * 關係對象不得為「未詳」人物。
     *
     * 對象 id 同時是複合主鍵成員，所以**改鍵**時新值在 $changes、未改鍵時在 $targetPk；
     * 一律以「$changes 優先、退回 $targetPk」取得生效值，否則改鍵改成 0 會漏擋。
     *
     * 取不到值（兩邊都沒有該鍵）時回傳 null 不攔截：那屬於主鍵不完整，由既有的
     * CompositePrimaryKey::validateOrFail() 負責報錯，本守衛不越權。
     *
     * @param array  $changes    請求的異動欄位
     * @param array  $targetPk   目標主鍵
     * @param string $column     對象 id 欄名（c_kin_id／c_assoc_id）
     * @param string $targetLabel 對象類型（「親屬」／「社會關係對象」），用於組訊息
     */
    protected function blockUnknownRelationTarget(
        array $changes,
        array $targetPk,
        string $column,
        string $targetLabel
    ): ?JsonResponse {
        // 0／-999 的判定集中在 UnknownPerson（含「非數值不視為未詳」）；取不到值時它回 false
        // 不攔截——那屬於主鍵不完整，由 CompositePrimaryKey::validateOrFail() 負責報錯。
        $value = $changes[$column] ?? ($targetPk[$column] ?? null);
        if (!UnknownPerson::isUnknown($value)) {
            return null;
        }

        return $this->errorResponse(
            "不能將「未詳」人物加為{$targetLabel}。",
            422,
            [$column => ['unknown_person_not_allowed']]
        );
    }
}
