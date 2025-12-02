<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 姓名搜尋索引服務
 *
 * 負責維護 CBDB__NAME_FTS 倒排索引表的增量更新。
 * 當人物姓名或別名資料變更時，自動同步索引。
 */
class NameSearchIndexService
{
    /**
     * 繁簡映射緩存
     *
     * @var array
     */
    protected $tradSimpMap = [];

    /**
     * 名稱類型元數據
     *
     * @var array
     */
    protected $typeMeta = [
        'main' => [
            'desc' => 'main_name',
            'desc_chn' => '本名',
            'source' => 'BIOG_MAIN'
        ],
        4 => [
            'desc' => 'zi',
            'desc_chn' => '字',
            'source' => 'ALTNAME_DATA'
        ],
        5 => [
            'desc' => 'hao',
            'desc_chn' => '號',
            'source' => 'ALTNAME_DATA'
        ],
    ];

    /**
     * 默認別名元數據
     *
     * @var array
     */
    protected $defaultAltMeta = [
        'desc' => 'altname',
        'desc_chn' => '別名',
        'source' => 'ALTNAME_DATA'
    ];

    /**
     * 建構函式
     */
    public function __construct()
    {
        $this->loadTradSimpMap();
    }

    /**
     * 為人物創建索引（新增時使用）
     *
     * @param \App\BiogMain $person
     * @return void
     */
    public function indexPerson($person)
    {
        if (!$person || !$person->c_name_chn) {
            return;
        }

        $fullName = $this->normalizeName($person->c_name_chn);
        if (!$fullName) {
            return;
        }

        $records = $this->generateRecordsForName([
            'c_personid' => $person->c_personid,
            'name_type_code' => null,
            'name_type_desc' => $this->typeMeta['main']['desc'],
            'name_type_desc_chn' => $this->typeMeta['main']['desc_chn'],
            'full_name' => $fullName,
            'source' => $this->typeMeta['main']['source'],
            'source_key' => 'biog_main:' . $person->c_personid,
        ]);

        if (!empty($records)) {
            DB::table('CBDB__NAME_FTS')->insert($records);
        }
    }

    /**
     * 重新索引人物（修改時使用）
     *
     * @param \App\BiogMain $person
     * @return void
     */
    public function reindexPerson($person)
    {
        DB::transaction(function () use ($person) {
            // 刪除舊索引（只刪除本名）
            DB::table('CBDB__NAME_FTS')
                ->where('c_personid', $person->c_personid)
                ->whereNull('name_type_code')
                ->delete();

            // 重建索引
            $this->indexPerson($person);
        });
    }

    /**
     * 移除人物的所有索引（刪除時使用）
     *
     * @param int $personId
     * @return void
     */
    public function removePerson($personId)
    {
        DB::table('CBDB__NAME_FTS')
            ->where('c_personid', $personId)
            ->delete();
    }

    /**
     * 為別名創建索引
     *
     * @param int $personId
     * @param int $typeCode
     * @param string $altname
     * @param string|null $sourceKey
     * @return void
     */
    public function indexAltname($personId, $typeCode, $altname, $sourceKey = null)
    {
        $fullName = $this->normalizeName($altname);
        if (!$fullName) {
            return;
        }

        $meta = $this->typeMeta[$typeCode] ?? $this->defaultAltMeta;

        $records = $this->generateRecordsForName([
            'c_personid' => $personId,
            'name_type_code' => $typeCode,
            'name_type_desc' => $meta['desc'],
            'name_type_desc_chn' => $meta['desc_chn'],
            'full_name' => $fullName,
            'source' => $meta['source'],
            'source_key' => $sourceKey ?? sprintf('altname:%d-%d-%s', $personId, $typeCode, $altname),
        ]);

        if (!empty($records)) {
            DB::table('CBDB__NAME_FTS')->insert($records);
        }
    }

    /**
     * 重新索引別名
     *
     * @param int $personId
     * @param int $typeCode
     * @param string $altname
     * @return void
     */
    public function reindexAltname($personId, $typeCode, $altname)
    {
        DB::transaction(function () use ($personId, $typeCode, $altname) {
            // 刪除舊索引
            $this->removeAltname($personId, $typeCode, $altname);

            // 重建索引
            $this->indexAltname($personId, $typeCode, $altname);
        });
    }

    /**
     * 移除別名索引
     *
     * @param int $personId
     * @param int $typeCode
     * @param string|null $altname
     * @return void
     */
    public function removeAltname($personId, $typeCode, $altname = null)
    {
        $query = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', $personId)
            ->where('name_type_code', $typeCode);

        if ($altname !== null) {
            // 移除特定別名的索引
            $sourceKey = sprintf('altname:%d-%d-%s', $personId, $typeCode, $altname);
            $query->where('source_key', $sourceKey);
        }

        $query->delete();
    }

    /**
     * 為單個姓名生成倒排記錄（包含繁簡體）
     *
     * @param array $nameInfo
     * @param \Illuminate\Support\Carbon|null $timestamp
     * @return array
     */
    protected function generateRecordsForName(array $nameInfo, $timestamp = null)
    {
        $records = [];
        $insertedTerms = [];

        if ($timestamp === null) {
            $timestamp = now();
        }

        // 生成繁體版本的後綴
        $tradSuffixes = $this->generateSuffixes($nameInfo['full_name']);
        foreach ($tradSuffixes as $suffix) {
            if ($this->isValidSearchTerm($suffix)) {
                $records[] = array_merge($nameInfo, [
                    'search_term' => $suffix,
                    'is_simplified' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                $insertedTerms[$suffix] = true;
            }
        }

        // 生成簡體版本的後綴（只插入與繁體不同的）
        $simplifiedName = $this->convertToSimplified($nameInfo['full_name']);
        if ($simplifiedName && $simplifiedName !== $nameInfo['full_name']) {
            $simpSuffixes = $this->generateSuffixes($simplifiedName);
            foreach ($simpSuffixes as $suffix) {
                if (!isset($insertedTerms[$suffix]) && $this->isValidSearchTerm($suffix)) {
                    $records[] = array_merge($nameInfo, [
                        'search_term' => $suffix,
                        'is_simplified' => 1,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }
            }
        }

        return $records;
    }

    /**
     * 載入繁簡映射表
     *
     * @return void
     */
    protected function loadTradSimpMap()
    {
        if (!Schema::hasTable('CBDB__TRAD_SIMP_MAP')) {
            return;
        }

        $rows = DB::table('CBDB__TRAD_SIMP_MAP')->get();

        foreach ($rows as $row) {
            $this->tradSimpMap[$row->trad_char] = $row->simp_char;
        }
    }

    /**
     * 規範化姓名（去除括號符號但保留內容）
     *
     * @param string $name
     * @return string|null
     */
    protected function normalizeName($name)
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // 移除括號符號本身，但保留內容以便搜尋
        // 例如："宗氏（李白妻）" → "宗氏李白妻"
        $name = preg_replace('/[()（）]/u', '', $name);
        $name = trim($name);

        return $name ?: null;
    }

    /**
     * 將繁體字轉換為簡體字
     *
     * @param string $text
     * @return string
     */
    protected function convertToSimplified($text)
    {
        if (empty($this->tradSimpMap)) {
            return $text;
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $result = '';

        foreach ($chars as $char) {
            $result .= $this->tradSimpMap[$char] ?? $char;
        }

        return $result;
    }

    /**
     * 生成字串的所有後綴
     *
     * @param string $text
     * @return array
     */
    protected function generateSuffixes($text)
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $suffixes = [];

        // 完整名稱
        $suffixes[] = $text;

        // 生成所有後綴（從第2個字開始）
        for ($i = 1; $i < count($chars); $i++) {
            $suffixes[] = implode('', array_slice($chars, $i));
        }

        return $suffixes;
    }

    /**
     * 檢查搜尋詞是否有效
     *
     * @param string $term
     * @return bool
     */
    protected function isValidSearchTerm($term)
    {
        $term = trim($term);

        if ($term === '') {
            return false;
        }

        // 排除以括號開頭的詞
        $firstChar = mb_substr($term, 0, 1, 'UTF-8');
        if (in_array($firstChar, ['(', ')', '（', '）'])) {
            return false;
        }

        return true;
    }
}
