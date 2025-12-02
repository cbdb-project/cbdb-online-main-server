<?php

namespace App\Observers;

use App\BiogMain;
use App\Services\NameSearchIndexService;
use Illuminate\Support\Facades\Schema;

/**
 * BiogMain 模型觀察者
 *
 * 監聽 BiogMain 模型的增刪改事件，自動維護姓名搜尋索引。
 */
class BiogMainObserver
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
     * 人物創建後
     *
     * @param BiogMain $person
     * @return void
     */
    public function created(BiogMain $person)
    {
        // 檢查索引表是否存在
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $this->indexService->indexPerson($person);
    }

    /**
     * 人物更新後
     *
     * @param BiogMain $person
     * @return void
     */
    public function updated(BiogMain $person)
    {
        // 檢查索引表是否存在
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        // 只有當姓名欄位變化時才重新索引
        if ($person->isDirty(['c_name_chn', 'c_surname', 'c_mingzi'])) {
            $this->indexService->reindexPerson($person);
        }
    }

    /**
     * 人物刪除後
     *
     * @param BiogMain $person
     * @return void
     */
    public function deleted(BiogMain $person)
    {
        // 檢查索引表是否存在
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $this->indexService->removePerson($person->c_personid);
    }
}
