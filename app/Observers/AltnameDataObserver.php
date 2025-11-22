<?php

namespace App\Observers;

use App\AltnameData;
use App\Services\NameSearchIndexService;
use Illuminate\Support\Facades\Schema;

/**
 * AltnameData 模型觀察者
 *
 * 監聽 AltnameData 模型的增刪改事件，自動維護別名搜尋索引。
 */
class AltnameDataObserver
{
    /**
     * 姓名索引服務
     *
     * @var NameSearchIndexService
     */
    protected $indexService;

    /**
     * 建構函式
     *
     * @param NameSearchIndexService $indexService
     */
    public function __construct(NameSearchIndexService $indexService)
    {
        $this->indexService = $indexService;
    }

    /**
     * 別名創建後
     *
     * @param AltnameData $altname
     * @return void
     */
    public function created(AltnameData $altname)
    {
        // 檢查索引表是否存在
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        if (!$altname->c_alt_name_chn || !$altname->c_personid) {
            return;
        }

        $this->indexService->indexAltname(
            $altname->c_personid,
            $altname->c_alt_name_type_code,
            $altname->c_alt_name_chn
        );
    }

    /**
     * 別名更新後
     *
     * @param AltnameData $altname
     * @return void
     */
    public function updated(AltnameData $altname)
    {
        // 檢查索引表是否存在
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        // 只有當別名欄位變化時才重新索引
        if ($altname->isDirty(['c_alt_name_chn', 'c_alt_name_type_code'])) {
            // 如果別名本身變了，需要刪除舊索引
            if ($altname->isDirty('c_alt_name_chn')) {
                $oldName = $altname->getOriginal('c_alt_name_chn');
                $oldTypeCode = $altname->getOriginal('c_alt_name_type_code') ?? $altname->c_alt_name_type_code;

                if ($oldName) {
                    $this->indexService->removeAltname(
                        $altname->c_personid,
                        $oldTypeCode,
                        $oldName
                    );
                }
            }

            // 重建索引
            if ($altname->c_alt_name_chn) {
                $this->indexService->indexAltname(
                    $altname->c_personid,
                    $altname->c_alt_name_type_code,
                    $altname->c_alt_name_chn
                );
            }
        }
    }

    /**
     * 別名刪除後
     *
     * @param AltnameData $altname
     * @return void
     */
    public function deleted(AltnameData $altname)
    {
        // 檢查索引表是否存在
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        if (!$altname->c_alt_name_chn || !$altname->c_personid) {
            return;
        }

        $this->indexService->removeAltname(
            $altname->c_personid,
            $altname->c_alt_name_type_code,
            $altname->c_alt_name_chn
        );
    }
}
