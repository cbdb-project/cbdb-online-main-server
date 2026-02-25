<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IndexYearRebuildService {
    /**
     * @var callable|null
     */
    protected $logger;
    protected bool $showSql = false;

    /**
     * @var array<int>
     */
    protected array $specialDynastyCodes = [2, 25, 29, 46, 83];

    /**
     * 視為「妾／側室」的反向關係碼集合。
     *
     * @var array<int>
     */
    protected array $concubineKinCodes = [168, 163, 344, 467, 585];

    public function setLogger(?callable $logger): self {
        $this->logger = $logger;

        return $this;
    }

    public function setShowSql(bool $showSql): self {
        $this->showSql = $showSql;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuild(): array {
        DB::connection()->disableQueryLog();

        $stats = [
            'reset' => 0,
            'phase_a' => [],
            'phase_b' => [],
            'loops' => [],
            'total_updates' => 0,
        ];

        $this->log('初始化：清空 BIOG_MAIN index year 欄位（含 source_id）');
        $stats['reset'] = $this->applyRule(
            'RESET',
            "UPDATE BIOG_MAIN
             SET c_index_year = NULL,
                 c_index_year_type_code = '',
                 c_index_year_source_id = NULL"
        );
        $stats['total_updates'] += $stats['reset'];

        $this->log('Phase A：直接更新規則');
        $phaseARules = [
            ['01', $this->sqlRule01()],
            ['02', $this->sqlRule02()],
            ['03', $this->sqlRule03(false)],
            ['29', $this->sqlRule29Or30(false)],
            ['30', $this->sqlRule29Or30(true)],
            ['05', $this->sqlEntryRule('040101', 30, '05')],
            ['06', $this->sqlWifeFromEntryRule('040101', 27, '06')],
            ['07', $this->sqlEntryRule('040102', 27, '07')],
            ['08', $this->sqlWifeFromEntryRule('040102', 24, '08')],
            ['09', $this->sqlEntryRule('040103', 21, '09')],
            ['10', $this->sqlWifeFromEntryRule('040103', 18, '10')],
            ['11', $this->sqlRule11()],
        ];
        foreach ($phaseARules as [$ruleCode, $sql]) {
            $count = $this->applyRule("Phase A Rule {$ruleCode}", $sql);
            $stats['phase_a'][$ruleCode] = $count;
            $stats['total_updates'] += $count;
        }

        $this->log('Phase B：聚合規則（不使用 TMP_INDEX_YEAR，改用子查詢聚合）');
        $phaseBRules = [
            ['13', $this->sqlAggregateRule13()],
            ['15', $this->sqlAggregateRule15()],
            ['17', $this->sqlRule03(true)],
            ['19', $this->sqlAggregateSiblingRule([125, 165], 'MAX', 2, '19')],
            ['21', $this->sqlAggregateSiblingRule([126, 166], 'MIN', -2, '21')],
            ['23', $this->sqlAggregateSonInLawRule(false, 27, '23')],
            ['25', $this->sqlAggregateSonInLawRule(true, 24, '25')],
            ['27', $this->sqlRule27()],
        ];
        foreach ($phaseBRules as [$ruleCode, $sql]) {
            $count = $this->applyRule("Phase B Rule {$ruleCode}", $sql);
            $stats['phase_b'][$ruleCode] = $count;
            $stats['total_updates'] += $count;
        }

        $this->log('Phase C：迭代傳播（最多 2 輪）');
        $loopCount = 1;
        $totalNew = 1;

        while ($totalNew > 0 && $loopCount < 3) {
            $this->log("Loop {$loopCount} 開始");
            $loopStats = [];
            $totalNew = 0;

            $loopRules = [
                ['04', $this->sqlLoopHusbandPropagationRule(false)],
                ['12', $this->sqlLoopFatherPropagationRule()],
                ['14', $this->sqlLoopOldestChildIndexToFatherRule()],
                ['16', $this->sqlLoopOldestChildIndexToMotherRule()],
                ['18', $this->sqlLoopHusbandPropagationRule(true)], // 修正版：妾規則也用 > -400
                ['20', $this->sqlLoopSiblingRule([125, 165], 'MAX', 2, '20')],
                ['22', $this->sqlLoopSiblingRule([126, 166], 'MIN', -2, '22')],
                ['24', $this->sqlLoopSonInLawRule(false, 27, '24')],
                ['26', $this->sqlLoopSonInLawRule(true, 24, '26')],
                ['28', $this->sqlLoopGrandfatherPropagationRule()],
            ];

            foreach ($loopRules as [$ruleCode, $sql]) {
                $count = $this->applyRule("Loop {$loopCount} Rule {$ruleCode}", $sql);
                $loopStats[$ruleCode] = $count;
                $totalNew += $count;
                $stats['total_updates'] += $count;
            }

            $loopStats['_total_new'] = $totalNew;
            $stats['loops'][$loopCount] = $loopStats;
            $this->log("Loop {$loopCount} 完成：新增 {$totalNew} 筆");
            $loopCount++;
        }

        return $stats;
    }

    protected function log(string $message): void {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $message);
        }
    }

    protected function applyRule(string $label, string $sql): int {
        if ($this->showSql) {
            $this->log(sprintf('SQL [%s]:', $label));
            $this->log($sql);
        }

        $count = DB::affectingStatement($sql);
        $this->log(sprintf('%s -> %d', $label, $count));

        return $count;
    }

    protected function dynastyInList(): string {
        return implode(',', $this->specialDynastyCodes);
    }

    protected function concubineKinCodeList(): string {
        return implode(',', $this->concubineKinCodes);
    }

    protected function validYearExpr(string $alias, string $yearColumn): string {
        $dynasties = $this->dynastyInList();

        return "(($alias.$yearColumn <> 0) OR ($alias.$yearColumn = 0 AND $alias.c_dy IN ($dynasties)))";
    }

    protected function sqlRule01(): string {
        return "UPDATE BIOG_MAIN bm
                SET bm.c_index_year = bm.c_birthyear,
                    bm.c_index_year_type_code = '01'
                WHERE {$this->validYearExpr('bm', 'c_birthyear')}";
    }

    protected function sqlRule02(): string {
        return "UPDATE BIOG_MAIN bm
                SET bm.c_index_year = bm.c_deathyear - bm.c_death_age + 1,
                    bm.c_index_year_type_code = '02'
                WHERE bm.c_index_year IS NULL
                  AND {$this->validYearExpr('bm', 'c_deathyear')}
                  AND bm.c_death_age > 0";
    }

    protected function sqlRule03(bool $concubine): string {
        $concubineCodes = $this->concubineKinCodeList();
        $typeCode = $concubine ? '17' : '03';
        $reverseConcubineExists = "EXISTS (
                        SELECT 1
                        FROM KIN_DATA kd_rev
                        WHERE kd_rev.c_personid = kd.c_kin_id
                          AND kd_rev.c_kin_id = kd.c_personid
                          AND kd_rev.c_kin_code IN ($concubineCodes)
                    )";
        $reverseRelationCondition = $concubine
            ? $reverseConcubineExists
            : "NOT $reverseConcubineExists";

        return "UPDATE BIOG_MAIN wife
                JOIN KIN_DATA kd
                  ON kd.c_personid = wife.c_personid
                 AND kd.c_kin_code = 134
                JOIN BIOG_MAIN husband
                  ON husband.c_personid = kd.c_kin_id
                SET wife.c_index_year = husband.c_index_year + 3,
                    wife.c_index_year_type_code = '$typeCode',
                    wife.c_index_year_source_id = husband.c_personid
                WHERE wife.c_index_year IS NULL
                  AND $reverseRelationCondition
                  AND {$this->validYearExpr('husband', 'c_index_year')}";
    }

    protected function sqlRule29Or30(bool $female): string {
        $femaleValue = $female ? 1 : 0;
        $offset = $female ? 56 : 63;
        $typeCode = $female ? '30' : '29';

        return "UPDATE BIOG_MAIN bm
                SET bm.c_index_year = bm.c_deathyear - $offset,
                    bm.c_index_year_type_code = '$typeCode'
                WHERE bm.c_index_year IS NULL
                  AND {$this->validYearExpr('bm', 'c_deathyear')}
                  AND bm.c_female = $femaleValue";
    }

    protected function sqlEntryRule(string $entryType, int $subtractYears, string $typeCode): string {
        return "UPDATE BIOG_MAIN bm
                JOIN (
                    SELECT ed.c_personid,
                           MIN(ed.c_year) AS entry_year
                    FROM ENTRY_DATA ed
                    JOIN ENTRY_CODE_TYPE_REL ectr
                      ON ectr.c_entry_code = ed.c_entry_code
                     AND ectr.c_entry_type = '$entryType'
                    WHERE ed.c_year > 0
                    GROUP BY ed.c_personid
                ) entry_agg
                  ON entry_agg.c_personid = bm.c_personid
                SET bm.c_index_year = entry_agg.entry_year - $subtractYears,
                    bm.c_index_year_type_code = '$typeCode'
                WHERE bm.c_index_year IS NULL";
    }

    protected function sqlWifeFromEntryRule(string $entryType, int $subtractYears, string $typeCode): string {
        return "UPDATE BIOG_MAIN wife
                JOIN (
                    SELECT kd.c_personid AS wife_personid,
                           MIN(ed.c_year) AS entry_year,
                           MIN(kd.c_kin_id) AS source_personid
                    FROM KIN_DATA kd
                    JOIN ENTRY_DATA ed
                      ON ed.c_personid = kd.c_kin_id
                    JOIN ENTRY_CODE_TYPE_REL ectr
                      ON ectr.c_entry_code = ed.c_entry_code
                     AND ectr.c_entry_type = '$entryType'
                    WHERE kd.c_kin_code = 134
                      AND ed.c_year > 0
                    GROUP BY kd.c_personid
                ) entry_agg
                  ON entry_agg.wife_personid = wife.c_personid
                SET wife.c_index_year = entry_agg.entry_year - $subtractYears,
                    wife.c_index_year_type_code = '$typeCode',
                    wife.c_index_year_source_id = entry_agg.source_personid
                WHERE wife.c_index_year IS NULL";
    }

    protected function sqlRule11(): string {
        return "UPDATE BIOG_MAIN child
                JOIN KIN_DATA kd
                  ON kd.c_personid = child.c_personid
                 AND kd.c_kin_code = 75
                JOIN BIOG_MAIN father
                  ON father.c_personid = kd.c_kin_id
                SET child.c_index_year = father.c_birthyear + 30,
                    child.c_index_year_type_code = '11',
                    child.c_index_year_source_id = father.c_personid
                WHERE child.c_index_year IS NULL
                  AND {$this->validYearExpr('father', 'c_birthyear')}";
    }

    protected function sqlAggregateRule13(): string {
        return "UPDATE BIOG_MAIN father
                JOIN (
                    SELECT kd.c_kin_id AS target_personid,
                           MIN(child.c_birthyear) - 30 AS calc_year
                    FROM KIN_DATA kd
                    JOIN BIOG_MAIN child
                      ON child.c_personid = kd.c_personid
                    WHERE kd.c_kin_code = 75
                      AND {$this->validYearExpr('child', 'c_birthyear')}
                    GROUP BY kd.c_kin_id
                ) agg ON agg.target_personid = father.c_personid
                SET father.c_index_year = agg.calc_year,
                    father.c_index_year_type_code = '13'
                WHERE father.c_index_year IS NULL";
    }

    protected function sqlAggregateRule15(): string {
        return "UPDATE BIOG_MAIN mother
                JOIN (
                    SELECT kd.c_kin_id AS target_personid,
                           MIN(child.c_birthyear) - 27 AS calc_year
                    FROM KIN_DATA kd
                    JOIN BIOG_MAIN child
                      ON child.c_personid = kd.c_personid
                    WHERE kd.c_kin_code = 111
                      AND {$this->validYearExpr('child', 'c_birthyear')}
                    GROUP BY kd.c_kin_id
                ) agg ON agg.target_personid = mother.c_personid
                SET mother.c_index_year = agg.calc_year,
                    mother.c_index_year_type_code = '15'
                WHERE mother.c_index_year IS NULL";
    }

    /**
     * @param array<int> $kinCodes
     */
    protected function sqlAggregateSiblingRule(array $kinCodes, string $aggFn, int $delta, string $typeCode): string {
        $kinList = implode(',', $kinCodes);
        $yearExpr = $aggFn === 'MAX' ? 'MAX(sib.c_birthyear)' : 'MIN(sib.c_birthyear)';
        $calcExpr = $delta >= 0 ? "$yearExpr + $delta" : "$yearExpr - " . abs($delta);

        return "UPDATE BIOG_MAIN person
                JOIN (
                    SELECT kd.c_personid AS target_personid,
                           $calcExpr AS calc_year
                    FROM KIN_DATA kd
                    JOIN BIOG_MAIN sib
                      ON sib.c_personid = kd.c_kin_id
                    WHERE kd.c_kin_code IN ($kinList)
                      AND {$this->validYearExpr('sib', 'c_birthyear')}
                    GROUP BY kd.c_personid
                ) agg ON agg.target_personid = person.c_personid
                SET person.c_index_year = agg.calc_year,
                    person.c_index_year_type_code = '$typeCode'
                WHERE person.c_index_year IS NULL";
    }

    protected function sqlAggregateSonInLawRule(bool $femaleTarget, int $subtractYears, string $typeCode): string {
        $genderValue = $femaleTarget ? 1 : 0;

        return "UPDATE BIOG_MAIN person
                JOIN (
                    SELECT kd.c_personid AS target_personid,
                           MIN(soninlaw.c_birthyear) - $subtractYears AS calc_year
                    FROM KIN_DATA kd
                    JOIN BIOG_MAIN soninlaw
                      ON soninlaw.c_personid = kd.c_kin_id
                    WHERE kd.c_kin_code IN (181,201,224,332)
                      AND {$this->validYearExpr('soninlaw', 'c_birthyear')}
                    GROUP BY kd.c_personid
                ) agg ON agg.target_personid = person.c_personid
                SET person.c_index_year = agg.calc_year,
                    person.c_index_year_type_code = '$typeCode'
                WHERE person.c_index_year IS NULL
                  AND person.c_female = $genderValue";
    }

    protected function sqlRule27(): string {
        return "UPDATE BIOG_MAIN descendant
                JOIN KIN_DATA kd
                  ON kd.c_personid = descendant.c_personid
                 AND kd.c_kin_code = 62
                JOIN BIOG_MAIN grandfather
                  ON grandfather.c_personid = kd.c_kin_id
                SET descendant.c_index_year = grandfather.c_birthyear + 60,
                    descendant.c_index_year_type_code = '27',
                    descendant.c_index_year_source_id = grandfather.c_personid
                WHERE descendant.c_index_year IS NULL
                  AND {$this->validYearExpr('grandfather', 'c_birthyear')}";
    }

    protected function sqlLoopHusbandPropagationRule(bool $concubine): string {
        $concubineCodes = $this->concubineKinCodeList();
        $typeSuffix = $concubine ? '18' : '04';
        $reverseConcubineExists = "EXISTS (
                        SELECT 1
                        FROM KIN_DATA kd_rev
                        WHERE kd_rev.c_personid = kd.c_kin_id
                          AND kd_rev.c_kin_id = kd.c_personid
                          AND kd_rev.c_kin_code IN ($concubineCodes)
                    )";
        $reverseRelationCondition = $concubine
            ? $reverseConcubineExists
            : "NOT $reverseConcubineExists";

        return "UPDATE BIOG_MAIN wife
                JOIN KIN_DATA kd
                  ON kd.c_personid = wife.c_personid
                 AND kd.c_kin_code = 134
                JOIN BIOG_MAIN husband
                  ON husband.c_personid = kd.c_kin_id
                SET wife.c_index_year = husband.c_index_year + 3,
                    wife.c_index_year_type_code = CONCAT(COALESCE(husband.c_index_year_type_code, ''), '$typeSuffix'),
                    wife.c_index_year_source_id = husband.c_personid
                WHERE wife.c_index_year IS NULL
                  AND $reverseRelationCondition
                  AND husband.c_index_year > -400";
    }

    protected function sqlLoopFatherPropagationRule(): string {
        return "UPDATE BIOG_MAIN child
                JOIN KIN_DATA kd
                  ON kd.c_personid = child.c_personid
                 AND kd.c_kin_code = 75
                JOIN BIOG_MAIN father
                  ON father.c_personid = kd.c_kin_id
                SET child.c_index_year = father.c_index_year + 30,
                    child.c_index_year_type_code = CONCAT(COALESCE(father.c_index_year_type_code, ''), '12'),
                    child.c_index_year_source_id = father.c_personid
                WHERE child.c_index_year IS NULL
                  AND father.c_index_year > -400";
    }

    protected function sqlLoopOldestChildIndexToFatherRule(): string {
        return "UPDATE BIOG_MAIN father
                JOIN (
                    SELECT kd.c_kin_id AS target_personid,
                           MIN(child.c_index_year) - 30 AS calc_year
                    FROM KIN_DATA kd
                    JOIN BIOG_MAIN child
                      ON child.c_personid = kd.c_personid
                    WHERE kd.c_kin_code = 75
                      AND child.c_index_year > -400
                    GROUP BY kd.c_kin_id
                ) agg ON agg.target_personid = father.c_personid
                SET father.c_index_year = agg.calc_year,
                    father.c_index_year_type_code = '14'
                WHERE father.c_index_year IS NULL";
    }

    protected function sqlLoopOldestChildIndexToMotherRule(): string {
        return "UPDATE BIOG_MAIN mother
                JOIN (
                    SELECT kd.c_kin_id AS target_personid,
                           MIN(child.c_index_year) - 27 AS calc_year
                    FROM KIN_DATA kd
                    JOIN BIOG_MAIN child
                      ON child.c_personid = kd.c_personid
                    WHERE kd.c_kin_code = 111
                      AND child.c_index_year > -400
                    GROUP BY kd.c_kin_id
                ) agg ON agg.target_personid = mother.c_personid
                SET mother.c_index_year = agg.calc_year,
                    mother.c_index_year_type_code = '16'
                WHERE mother.c_index_year IS NULL";
    }

    /**
     * @param array<int> $kinCodes
     */
    protected function sqlLoopSiblingRule(array $kinCodes, string $aggFn, int $delta, string $typeCode): string {
        $kinList = implode(',', $kinCodes);
        $yearExpr = $aggFn === 'MAX' ? 'MAX(sib.c_index_year)' : 'MIN(sib.c_index_year)';
        $calcExpr = $delta >= 0 ? "$yearExpr + $delta" : "$yearExpr - " . abs($delta);

        return "UPDATE BIOG_MAIN person
                JOIN (
                    SELECT kd.c_personid AS target_personid,
                           $calcExpr AS calc_year
                    FROM KIN_DATA kd
                    JOIN BIOG_MAIN sib
                      ON sib.c_personid = kd.c_kin_id
                    WHERE kd.c_kin_code IN ($kinList)
                      AND sib.c_index_year > -400
                    GROUP BY kd.c_personid
                ) agg ON agg.target_personid = person.c_personid
                SET person.c_index_year = agg.calc_year,
                    person.c_index_year_type_code = '$typeCode'
                WHERE person.c_index_year IS NULL";
    }

    protected function sqlLoopSonInLawRule(bool $femaleTarget, int $subtractYears, string $typeCode): string {
        $genderValue = $femaleTarget ? 1 : 0;

        return "UPDATE BIOG_MAIN person
                JOIN (
                    SELECT kd.c_personid AS target_personid,
                           MIN(soninlaw.c_index_year) - $subtractYears AS calc_year
                    FROM KIN_DATA kd
                    JOIN BIOG_MAIN soninlaw
                      ON soninlaw.c_personid = kd.c_kin_id
                    WHERE kd.c_kin_code IN (181,201,224,332)
                      AND soninlaw.c_index_year > -400
                    GROUP BY kd.c_personid
                ) agg ON agg.target_personid = person.c_personid
                SET person.c_index_year = agg.calc_year,
                    person.c_index_year_type_code = '$typeCode'
                WHERE person.c_index_year IS NULL
                  AND person.c_female = $genderValue";
    }

    protected function sqlLoopGrandfatherPropagationRule(): string {
        return "UPDATE BIOG_MAIN descendant
                JOIN KIN_DATA kd
                  ON kd.c_personid = descendant.c_personid
                 AND kd.c_kin_code = 62
                JOIN BIOG_MAIN grandfather
                  ON grandfather.c_personid = kd.c_kin_id
                SET descendant.c_index_year = grandfather.c_index_year + 60,
                    descendant.c_index_year_type_code = CONCAT(COALESCE(grandfather.c_index_year_type_code, ''), '28'),
                    descendant.c_index_year_source_id = grandfather.c_personid
                WHERE descendant.c_index_year IS NULL
                  AND grandfather.c_index_year > -400";
    }
}
