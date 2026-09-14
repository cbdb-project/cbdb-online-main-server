<?php

namespace App\Exceptions;

/**
 * 提案核准因「未詳」人物（personid 0／-999）被中止。
 *
 * 這是一次**正常的業務拒絕**，不是系統錯誤——所以它需要一個專屬類型，讓
 * `OperationsProposalController::approve()` 能把它導到 `Log::warning` 而不是 `Log::error`
 * 的告警通道（與同檔 `MirrorConflictException` 等的既有處理一致）。
 *
 * 語義：中止本次核准、整筆回滾、提案維持 pending，理由 flash 給審核者。**不自動退回提案**。
 */
class UnknownPersonProposalException extends \RuntimeException {
}
