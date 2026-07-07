<?php

namespace App\Services\Mutations;

/**
 * 年號（NIAN_HAO）拼音更新 handler。
 *
 * 為 {@see AbstractCodeTableMutationHandler} 的第一個使用者；僅宣告表名／resource／主鍵／白名單，
 * 交易・審計・變更偵測・direct/proposal 邏輯全由基底提供。
 */
class NianHaoMutationHandler extends AbstractCodeTableMutationHandler {
    protected function tableName(): string {
        return 'NIAN_HAO';
    }

    protected function resourceName(): string {
        return 'nianhao';
    }

    protected function resourceAliases(): array {
        return ['nianhao', 'nian_hao'];
    }

    protected function displayName(): string {
        return '年號';
    }

    protected function keyColumns(): array {
        return ['c_nianhao_id'];
    }

    protected function allowedFields(): array {
        return ['c_nianhao_pin'];
    }
}
