# Index Year 規則假設驗證（工作文檔）

版本：`v0.1`  
目的：逐一驗證 `cbdb:rebuild-index-year` 各類規則的年差假設是否合理，並用統一統計口徑追蹤進度。  
資料庫：MariaDB/MySQL（以 `dev` 為主）

## 1. 驗證範圍與原則

### 1.1 驗證對象
本文件主要驗證「含假設偏移量」的規則（如 `+3`, `+30`, `-27`, `-63`, `-56` 等），而非單純資料直寫規則。

重點包含：
- 配偶（夫/妾）相關
- 父子/母子相關
- 兄弟相關
- 女婿相關
- 祖父相關
- 科舉年份相關
- 僅卒年估算（`29/30`）

### 1.2 不直接驗證（或作一致性檢查即可）
- `01`（據生年）
- `02`（據卒年 - 享年 + 1；更接近資料一致性規則）

### 1.3 驗證口徑（統一）
對每組規則，先產生樣本 `sample`，每列至少包含：
- `target_personid`
- `actual_birthyear`（目標人物真實出生年）
- `predicted_birthyear`（依該規則假設算出的預測出生年）
- `error_year = actual_birthyear - predicted_birthyear`

解讀：
- `error_year = 0`：該規則假設與真實出生年一致
- `error_year > 0`：規則把人推得太早（預測出生年偏小）
- `error_year < 0`：規則把人推得太晚（預測出生年偏大）

## 2. 每組必算統計（固定）

每組 `sample(error_year)` 都要計算：
1. 樣本數 `n`
2. 均值 `AVG(error_year)`
3. 方差 `VAR_SAMP(error_year)`（樣本方差）
4. 分布（直方圖）
5. 4 個百分位（暫定：`P10 / P25 / P50 / P75`）
6. 是否多峰（以直方圖峰值數 heuristic 判定）

## 3. 通用統計 SQL 模板（原始 SQL）

使用方式：
1. 把各 rule 的 `sample` SQL 貼到 `WITH sample AS (...)`
2. 再跑以下統計 SQL

### 3.1 基本統計（均值、方差、標準差、範圍）
```sql
WITH sample AS (
    /* 貼入某一組 rule 的 sample SQL */
    SELECT 1 AS target_personid, 0 AS actual_birthyear, 0 AS predicted_birthyear, 0 AS error_year
    WHERE 1 = 0
)
SELECT
    COUNT(*) AS n,
    AVG(error_year) AS mean_error,
    VAR_SAMP(error_year) AS var_error,
    STDDEV_SAMP(error_year) AS stddev_error,
    MIN(error_year) AS min_error,
    MAX(error_year) AS max_error
FROM sample;
```

### 3.2 百分位（P10/P25/P50/P75；nearest-rank）
```sql
WITH sample AS (
    /* 貼入某一組 rule 的 sample SQL */
    SELECT 1 AS target_personid, 0 AS actual_birthyear, 0 AS predicted_birthyear, 0 AS error_year
    WHERE 1 = 0
),
ordered AS (
    SELECT
        error_year,
        ROW_NUMBER() OVER (ORDER BY error_year) AS rn,
        COUNT(*) OVER () AS n
    FROM sample
),
pos AS (
    SELECT
        CAST(CEIL(0.10 * n) AS UNSIGNED) AS p10_rn,
        CAST(CEIL(0.25 * n) AS UNSIGNED) AS p25_rn,
        CAST(CEIL(0.50 * n) AS UNSIGNED) AS p50_rn,
        CAST(CEIL(0.75 * n) AS UNSIGNED) AS p75_rn
    FROM ordered
    LIMIT 1
)
SELECT
    MAX(CASE WHEN o.rn = p.p10_rn THEN o.error_year END) AS p10,
    MAX(CASE WHEN o.rn = p.p25_rn THEN o.error_year END) AS p25,
    MAX(CASE WHEN o.rn = p.p50_rn THEN o.error_year END) AS p50,
    MAX(CASE WHEN o.rn = p.p75_rn THEN o.error_year END) AS p75
FROM ordered o
CROSS JOIN pos p;
```

### 3.3 分布（1 年 bin 直方圖）
```sql
WITH sample AS (
    /* 貼入某一組 rule 的 sample SQL */
    SELECT 1 AS target_personid, 0 AS actual_birthyear, 0 AS predicted_birthyear, 0 AS error_year
    WHERE 1 = 0
)
SELECT
    error_year AS bin_error_year,
    COUNT(*) AS bin_count
FROM sample
GROUP BY error_year
ORDER BY error_year;
```

### 3.4 是否多峰（heuristic：local maxima 個數）
說明：
- 用 1 年 bin 直方圖
- 峰值定義：`bin_count > prev_count` 且 `bin_count >= next_count`
- 且 `bin_count` 至少達樣本量 `2%`（避免小噪聲）
- 若 `peak_count > 1`，判定「可能多峰」

```sql
WITH sample AS (
    /* 貼入某一組 rule 的 sample SQL */
    SELECT 1 AS target_personid, 0 AS actual_birthyear, 0 AS predicted_birthyear, 0 AS error_year
    WHERE 1 = 0
),
hist AS (
    SELECT
        error_year AS bin_error_year,
        COUNT(*) AS bin_count
    FROM sample
    GROUP BY error_year
),
hist2 AS (
    SELECT
        h.*,
        LAG(h.bin_count) OVER (ORDER BY h.bin_error_year) AS prev_count,
        LEAD(h.bin_count) OVER (ORDER BY h.bin_error_year) AS next_count,
        SUM(h.bin_count) OVER () AS total_n
    FROM hist h
),
peaks AS (
    SELECT *
    FROM hist2
    WHERE COALESCE(prev_count, 0) < bin_count
      AND COALESCE(next_count, 0) <= bin_count
      AND bin_count >= GREATEST(3, CEIL(total_n * 0.02))
)
SELECT
    COUNT(*) AS peak_count,
    CASE WHEN COUNT(*) > 1 THEN 1 ELSE 0 END AS has_multiple_peaks
FROM peaks;
```

## 4. 進度追蹤（逐組）

狀態值建議：
- `TODO`
- `RUNNING`
- `DONE`
- `BLOCKED`

| 組別 | 規則碼 | 假設 | sample SQL 完成 | 統計完成 | 結論 | 備註 |
|---|---|---|---|---|---|---|
| 配偶（非妾）出生年差 | `03` / `04` | 妻 = 夫 + 3 | DONE | DONE | 基線大致合理，但偏差不小 | `04` 為 propagation 版本，先驗證基礎差值 |
| 配偶（妾/側室）出生年差 | `17` / `18` | 妾 = 夫 + 3 | DONE | DONE | 樣本小，顯示偏差更大 | 使用妾關係碼集合 |
| 父子出生年差 | `11` / `12` | 子 = 父 + 30 | DONE | DONE | 初步可用，但存在多峰 | 均值誤差約 +2.80，建議後續分層（嫡/庶、時代、資料偏差） |
| 長子反推父 | `13` / `14` | 父 = 長子 - 30 | DONE | DONE | 基線合理，誤差略偏晚 | 等價於長子-父差值 |
| 長子反推母 | `15` / `16` | 母 = 長子 - 27 | DONE | DONE | 基線合理，樣本較小 | 等價於長子-母差值 |
| 年長兄弟差 | `19` / `20` | 本人 = 兄 + 2 | DONE | DONE | 基線偏差明顯（偏早） | 使用最近年長兄弟 |
| 年幼兄弟差 | `21` / `22` | 本人 = 弟 - 2 | DONE | DONE | 基線偏差明顯（偏晚） | 使用最近年幼兄弟 |
| 女婿反推父 | `23` / `24` | 父 = 長婿 - 27 | DONE | DONE | 基線可用，但分布較鋸齒 | 男性目標 |
| 女婿反推母 | `25` / `26` | 母 = 長婿 - 24 | DONE | DONE | 樣本極小，暫不下結論 | 女性目標 |
| 祖父推後代 | `27` / `28` | 後代 = 祖父 + 60 | DONE | DONE | 基線可用，但離散較大 | `kin_code = 62` |
| 進士登科推生年 | `05` / `06` | 本人 -30；妻 -27 | DONE | DONE | 本人/配偶組皆完成 | `06` 樣本中等，可參考 |
| 舉人推生年 | `07` / `08` | 本人 -27；妻 -24 | DONE | DONE | 本人/配偶組皆完成 | `08` 樣本較小（n=45） |
| 秀才推生年 | `09` / `10` | 本人 -21；妻 -18 | DONE | DONE | 本人/配偶組皆完成 | `09/10` 皆低樣本，僅初步觀察 |
| 僅卒年估算（男） | `29` | 生年 = 卒年 - 63 | DONE | DONE | 初步可用，但明顯多峰 | 誤差均值約 +2.23，需考慮分層 |
| 僅卒年估算（女） | `30` | 生年 = 卒年 - 56（程式） | DONE | DONE | 多峰明顯，建議分層 | 可作為基線；表描述目前仍寫 -55 |

## 5. 各組 sample SQL（原始 SQL）

說明：
- 以下 SQL 直接產生 `error_year`
- 一律排除 `birthyear=0` 的目標人物（因無法作真值）
- 若來源人物需用出生年作真值，也排除來源 `birthyear=0`
- 若關係存在多筆，會用與規則一致的聚合方式（`MIN/MAX`）選樣本

### 5.1 配偶（非妾）差值：`03 / 04`（妻 = 夫 + 3）
```sql
WITH sample AS (
    SELECT
        wife.c_personid AS target_personid,
        wife.c_birthyear AS actual_birthyear,
        husband.c_birthyear + 3 AS predicted_birthyear,
        wife.c_birthyear - (husband.c_birthyear + 3) AS error_year
    FROM BIOG_MAIN wife
    JOIN KIN_DATA kd
      ON kd.c_personid = wife.c_personid
     AND kd.c_kin_code = 134
    JOIN BIOG_MAIN husband
      ON husband.c_personid = kd.c_kin_id
    WHERE wife.c_birthyear IS NOT NULL
      AND wife.c_birthyear <> 0
      AND husband.c_birthyear IS NOT NULL
      AND husband.c_birthyear <> 0
      AND NOT EXISTS (
            SELECT 1
            FROM KIN_DATA kd_rev
            WHERE kd_rev.c_personid = kd.c_kin_id
              AND kd_rev.c_kin_id = kd.c_personid
              AND kd_rev.c_kin_code IN (168,163,344,467,585)
      )
)
SELECT * FROM sample;
```

### 5.2 配偶（妾/側室）差值：`17 / 18`（妾 = 夫 + 3）
```sql
WITH sample AS (
    SELECT
        wife.c_personid AS target_personid,
        wife.c_birthyear AS actual_birthyear,
        husband.c_birthyear + 3 AS predicted_birthyear,
        wife.c_birthyear - (husband.c_birthyear + 3) AS error_year
    FROM BIOG_MAIN wife
    JOIN KIN_DATA kd
      ON kd.c_personid = wife.c_personid
     AND kd.c_kin_code = 134
    JOIN BIOG_MAIN husband
      ON husband.c_personid = kd.c_kin_id
    WHERE wife.c_birthyear IS NOT NULL
      AND wife.c_birthyear <> 0
      AND husband.c_birthyear IS NOT NULL
      AND husband.c_birthyear <> 0
      AND EXISTS (
            SELECT 1
            FROM KIN_DATA kd_rev
            WHERE kd_rev.c_personid = kd.c_kin_id
              AND kd_rev.c_kin_id = kd.c_personid
              AND kd_rev.c_kin_code IN (168,163,344,467,585)
      )
)
SELECT * FROM sample;
```

### 5.3 父子差值：`11 / 12`（子 = 父 + 30）
```sql
WITH sample AS (
    SELECT
        child.c_personid AS target_personid,
        child.c_birthyear AS actual_birthyear,
        father.c_birthyear + 30 AS predicted_birthyear,
        child.c_birthyear - (father.c_birthyear + 30) AS error_year
    FROM BIOG_MAIN child
    JOIN KIN_DATA kd
      ON kd.c_personid = child.c_personid
     AND kd.c_kin_code = 75
    JOIN BIOG_MAIN father
      ON father.c_personid = kd.c_kin_id
    WHERE child.c_birthyear IS NOT NULL
      AND child.c_birthyear <> 0
      AND father.c_birthyear IS NOT NULL
      AND father.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 5.4 長子反推父：`13 / 14`（父 = 長子 - 30）
```sql
WITH sample AS (
    SELECT
        father.c_personid AS target_personid,
        father.c_birthyear AS actual_birthyear,
        agg.oldest_child_birthyear - 30 AS predicted_birthyear,
        father.c_birthyear - (agg.oldest_child_birthyear - 30) AS error_year
    FROM BIOG_MAIN father
    JOIN (
        SELECT
            kd.c_kin_id AS father_personid,
            MIN(child.c_birthyear) AS oldest_child_birthyear
        FROM KIN_DATA kd
        JOIN BIOG_MAIN child
          ON child.c_personid = kd.c_personid
        WHERE kd.c_kin_code = 75
          AND child.c_birthyear IS NOT NULL
          AND child.c_birthyear <> 0
        GROUP BY kd.c_kin_id
    ) agg
      ON agg.father_personid = father.c_personid
    WHERE father.c_birthyear IS NOT NULL
      AND father.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 5.5 長子反推母：`15 / 16`（母 = 長子 - 27）
```sql
WITH sample AS (
    SELECT
        mother.c_personid AS target_personid,
        mother.c_birthyear AS actual_birthyear,
        agg.oldest_child_birthyear - 27 AS predicted_birthyear,
        mother.c_birthyear - (agg.oldest_child_birthyear - 27) AS error_year
    FROM BIOG_MAIN mother
    JOIN (
        SELECT
            kd.c_kin_id AS mother_personid,
            MIN(child.c_birthyear) AS oldest_child_birthyear
        FROM KIN_DATA kd
        JOIN BIOG_MAIN child
          ON child.c_personid = kd.c_personid
        WHERE kd.c_kin_code = 111
          AND child.c_birthyear IS NOT NULL
          AND child.c_birthyear <> 0
        GROUP BY kd.c_kin_id
    ) agg
      ON agg.mother_personid = mother.c_personid
    WHERE mother.c_birthyear IS NOT NULL
      AND mother.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 5.6 年長兄弟：`19 / 20`（本人 = 最近年長兄弟 + 2）
```sql
WITH sample AS (
    SELECT
        person.c_personid AS target_personid,
        person.c_birthyear AS actual_birthyear,
        agg.youngest_older_brother_birthyear + 2 AS predicted_birthyear,
        person.c_birthyear - (agg.youngest_older_brother_birthyear + 2) AS error_year
    FROM BIOG_MAIN person
    JOIN (
        SELECT
            kd.c_personid AS target_personid,
            MAX(sib.c_birthyear) AS youngest_older_brother_birthyear
        FROM KIN_DATA kd
        JOIN BIOG_MAIN sib
          ON sib.c_personid = kd.c_kin_id
        WHERE kd.c_kin_code IN (125,165)
          AND sib.c_birthyear IS NOT NULL
          AND sib.c_birthyear <> 0
        GROUP BY kd.c_personid
    ) agg
      ON agg.target_personid = person.c_personid
    WHERE person.c_birthyear IS NOT NULL
      AND person.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 5.7 年幼兄弟：`21 / 22`（本人 = 最近年幼兄弟 - 2）
```sql
WITH sample AS (
    SELECT
        person.c_personid AS target_personid,
        person.c_birthyear AS actual_birthyear,
        agg.oldest_younger_brother_birthyear - 2 AS predicted_birthyear,
        person.c_birthyear - (agg.oldest_younger_brother_birthyear - 2) AS error_year
    FROM BIOG_MAIN person
    JOIN (
        SELECT
            kd.c_personid AS target_personid,
            MIN(sib.c_birthyear) AS oldest_younger_brother_birthyear
        FROM KIN_DATA kd
        JOIN BIOG_MAIN sib
          ON sib.c_personid = kd.c_kin_id
        WHERE kd.c_kin_code IN (126,166)
          AND sib.c_birthyear IS NOT NULL
          AND sib.c_birthyear <> 0
        GROUP BY kd.c_personid
    ) agg
      ON agg.target_personid = person.c_personid
    WHERE person.c_birthyear IS NOT NULL
      AND person.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 5.8 女婿反推父（男性目標）：`23 / 24`（父 = 長婿 - 27）
```sql
WITH sample AS (
    SELECT
        person.c_personid AS target_personid,
        person.c_birthyear AS actual_birthyear,
        agg.oldest_soninlaw_birthyear - 27 AS predicted_birthyear,
        person.c_birthyear - (agg.oldest_soninlaw_birthyear - 27) AS error_year
    FROM BIOG_MAIN person
    JOIN (
        SELECT
            kd.c_personid AS target_personid,
            MIN(soninlaw.c_birthyear) AS oldest_soninlaw_birthyear
        FROM KIN_DATA kd
        JOIN BIOG_MAIN soninlaw
          ON soninlaw.c_personid = kd.c_kin_id
        WHERE kd.c_kin_code IN (181,201,224,332)
          AND soninlaw.c_birthyear IS NOT NULL
          AND soninlaw.c_birthyear <> 0
        GROUP BY kd.c_personid
    ) agg
      ON agg.target_personid = person.c_personid
    WHERE person.c_birthyear IS NOT NULL
      AND person.c_birthyear <> 0
      AND person.c_female = 0
)
SELECT * FROM sample;
```

### 5.9 女婿反推母（女性目標）：`25 / 26`（母 = 長婿 - 24）
```sql
WITH sample AS (
    SELECT
        person.c_personid AS target_personid,
        person.c_birthyear AS actual_birthyear,
        agg.oldest_soninlaw_birthyear - 24 AS predicted_birthyear,
        person.c_birthyear - (agg.oldest_soninlaw_birthyear - 24) AS error_year
    FROM BIOG_MAIN person
    JOIN (
        SELECT
            kd.c_personid AS target_personid,
            MIN(soninlaw.c_birthyear) AS oldest_soninlaw_birthyear
        FROM KIN_DATA kd
        JOIN BIOG_MAIN soninlaw
          ON soninlaw.c_personid = kd.c_kin_id
        WHERE kd.c_kin_code IN (181,201,224,332)
          AND soninlaw.c_birthyear IS NOT NULL
          AND soninlaw.c_birthyear <> 0
        GROUP BY kd.c_personid
    ) agg
      ON agg.target_personid = person.c_personid
    WHERE person.c_birthyear IS NOT NULL
      AND person.c_birthyear <> 0
      AND person.c_female = 1
)
SELECT * FROM sample;
```

### 5.10 祖父推後代：`27 / 28`（後代 = 祖父 + 60）
```sql
WITH sample AS (
    SELECT
        descendant.c_personid AS target_personid,
        descendant.c_birthyear AS actual_birthyear,
        grandfather.c_birthyear + 60 AS predicted_birthyear,
        descendant.c_birthyear - (grandfather.c_birthyear + 60) AS error_year
    FROM BIOG_MAIN descendant
    JOIN KIN_DATA kd
      ON kd.c_personid = descendant.c_personid
     AND kd.c_kin_code = 62
    JOIN BIOG_MAIN grandfather
      ON grandfather.c_personid = kd.c_kin_id
    WHERE descendant.c_birthyear IS NOT NULL
      AND descendant.c_birthyear <> 0
      AND grandfather.c_birthyear IS NOT NULL
      AND grandfather.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 5.11 科舉（本人）推生年：`05 / 07 / 09`
分三組分開跑（進士/舉人/秀才），只需替換 `entry_type` 與 `offset_years`。

```sql
/* 範例：05 進士（entry_type=040101, offset=30） */
WITH sample AS (
    SELECT
        bm.c_personid AS target_personid,
        bm.c_birthyear AS actual_birthyear,
        agg.entry_year - 30 AS predicted_birthyear,
        bm.c_birthyear - (agg.entry_year - 30) AS error_year
    FROM BIOG_MAIN bm
    JOIN (
        SELECT
            ed.c_personid,
            MIN(ed.c_year) AS entry_year
        FROM ENTRY_DATA ed
        JOIN ENTRY_CODE_TYPE_REL ectr
          ON ectr.c_entry_code = ed.c_entry_code
         AND ectr.c_entry_type = '040101'
        WHERE ed.c_year > 0
        GROUP BY ed.c_personid
    ) agg
      ON agg.c_personid = bm.c_personid
    WHERE bm.c_birthyear IS NOT NULL
      AND bm.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 5.12 科舉（配偶）推生年：`06 / 08 / 10`
分三組分開跑（進士妻/舉人妻/秀才妻）。

```sql
/* 範例：06 妻據夫進士（entry_type=040101, offset=27） */
WITH sample AS (
    SELECT
        wife.c_personid AS target_personid,
        wife.c_birthyear AS actual_birthyear,
        agg.entry_year - 27 AS predicted_birthyear,
        wife.c_birthyear - (agg.entry_year - 27) AS error_year
    FROM BIOG_MAIN wife
    JOIN (
        SELECT
            kd.c_personid AS wife_personid,
            MIN(ed.c_year) AS entry_year
        FROM KIN_DATA kd
        JOIN ENTRY_DATA ed
          ON ed.c_personid = kd.c_kin_id
        JOIN ENTRY_CODE_TYPE_REL ectr
          ON ectr.c_entry_code = ed.c_entry_code
         AND ectr.c_entry_type = '040101'
        WHERE kd.c_kin_code = 134
          AND ed.c_year > 0
        GROUP BY kd.c_personid
    ) agg
      ON agg.wife_personid = wife.c_personid
    WHERE wife.c_birthyear IS NOT NULL
      AND wife.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 5.13 僅卒年估算（男）：`29`（生年 = 卒年 - 63）
```sql
WITH sample AS (
    SELECT
        bm.c_personid AS target_personid,
        bm.c_birthyear AS actual_birthyear,
        bm.c_deathyear - 63 AS predicted_birthyear,
        bm.c_birthyear - (bm.c_deathyear - 63) AS error_year
    FROM BIOG_MAIN bm
    WHERE bm.c_female = 0
      AND bm.c_birthyear IS NOT NULL
      AND bm.c_birthyear <> 0
      AND bm.c_deathyear IS NOT NULL
      AND bm.c_deathyear <> 0
)
SELECT * FROM sample;
```

### 5.14 僅卒年估算（女）：`30`（程式實作：生年 = 卒年 - 56）
```sql
WITH sample AS (
    SELECT
        bm.c_personid AS target_personid,
        bm.c_birthyear AS actual_birthyear,
        bm.c_deathyear - 56 AS predicted_birthyear,
        bm.c_birthyear - (bm.c_deathyear - 56) AS error_year
    FROM BIOG_MAIN bm
    WHERE bm.c_female = 1
      AND bm.c_birthyear IS NOT NULL
      AND bm.c_birthyear <> 0
      AND bm.c_deathyear IS NOT NULL
      AND bm.c_deathyear <> 0
)
SELECT * FROM sample;
```

## 6. 結果記錄模板（每組一段）

複製以下模板，逐組填寫：

```md
### [組名]（Rule xx/yy）
- 狀態：RUNNING / DONE
- 樣本數：
- 均值（error_year）：
- 方差（error_year）：
- 百分位：P10 / P25 / P50 / P75 =
- 峰值數（heuristic）：
- 是否多峰：
- 初步判斷：
- 備註（資料偏差/樣本定義/是否需分層）：

原始 SQL：
-- sample SQL

-- summary SQL

-- percentile SQL

-- histogram SQL

-- peak SQL
```

## 7. 後續工作建議（執行順序）

### 僅卒年估算（男）（Rule 29）
- 狀態：DONE
- 樣本數：`34037`
- 均值（error_year）：`2.2294`
- 方差（error_year）：`230.7027`
- 標準差（error_year）：`15.1889`
- 範圍：`[-404, 121]`
- 百分位：`P10=-16 / P25=-8 / P50=1 / P75=11`
- 峰值數（heuristic）：`8`
- 是否多峰：`是`
- 初步判斷：
  - 使用固定 `-63` 作為男性卒年估算的基線規則仍有一定合理性（中位誤差 `+1`，均值誤差 `+2.23`）。
  - 但分布明顯多峰，不適合視為單一常數能充分描述。
  - 存在極端離群值（最小 `-404`），後續應檢查資料品質或特殊歷史紀年情況。
- 分布摘要（1 年 bin，高頻 bins）：
  - `3: 990`, `-4: 981`, `-3: 956`, `0: 950`, `-6: 935`, `-1: 933`, `1: 923`, `2: 909`, `-5: 900`, `-2: 891`
- 峰值 bins（heuristic）：
  - `-11, -9, -6, -4, 0, 3, 5, 9`
- 備註：
  - 目前 heuristic 的峰值判定較敏感，會把「鋸齒狀局部高點」都算入；後續可加平滑（如 3-year bin）再判峰。

### 僅卒年估算（女）（Rule 30，程式實作 -56）
- 狀態：DONE
- 樣本數：`3330`
- 均值（error_year）：`3.1937`
- 方差（error_year）：`408.0619`
- 標準差（error_year）：`20.2005`
- 範圍：`[-68, 55]`
- 百分位：`P10=-22 / P25=-13 / P50=1 / P75=20`
- 峰值數（heuristic）：`4`
- 是否多峰：`是`
- 初步判斷：
  - 固定 `-56` 作為女性卒年估算規則可作為 baseline，但分布明顯多峰，且離散程度高於男性（方差/標準差均更大）。
  - 均值誤差 `+3.19` 顯示目前 `-56` 仍略偏早（預測出生年偏小），整體傾向把人物推得更老。
  - 此結果支持「女性需分層模型」的方向（例如 family signal 分層）。
- 分布摘要（1 年 bin，高頻 bins）：
  - `-3: 91`, `2: 82`, `-4: 74`, `-18: 72`, `-9: 71`, `-7: 66`, `-17: 64`, `-15: 64`, `23: 64`, `-12: 63`
- 峰值 bins（heuristic）：
  - `-18, -9, -3, 2`
- 備註：
  - 與前面女性年齡分布觀察一致，存在多峰現象；後續建議優先測試 `family_signal` 二分類版本。

### 父子出生年差（Rule 11/12，假設：子 = 父 + 30）
- 狀態：DONE
- 樣本數：`2627`
- 均值（error_year）：`2.7956`
- 方差（error_year）：`171.3356`
- 標準差（error_year）：`13.0895`
- 範圍：`[-143, 123]`
- 百分位：`P10=-10 / P25=-5 / P50=1 / P75=10`
- 峰值數（heuristic）：`7`
- 是否多峰：`是`
- 初步判斷：
  - `+30` 作為父子出生年差的 baseline 假設整體仍可用（中位誤差 `+1`）。
  - 均值誤差 `+2.80` 顯示規則略偏早（預測子女出生年偏小 / 把人推得略老）。
  - 分布有多峰現象，表示單一常數不足以覆蓋所有家庭結構與記錄偏差。
- 分布摘要（1 年 bin，高頻 bins）：
  - `1: 106`, `-5: 104`, `-1: 101`, `-2: 100`, `0: 100`, `-6: 94`, `2: 92`, `-7: 88`, `-4: 88`, `3: 88`
- 峰值 bins（heuristic）：
  - `-9, -5, -1, 1, 5, 8, 10`
- 備註：
  - 後續可再分層檢查：目標人物性別、朝代、是否只保留「親生父子」可信樣本（若能定義）。

### 配偶（非妾）出生年差（Rule 03/04，假設：妻 = 夫 + 3）
- 狀態：DONE
- 樣本數：`749`
- 均值（error_year）：`3.2577`
- 方差（error_year）：`105.3145`
- 標準差（error_year）：`10.2623`
- 範圍：`[-26, 126]`
- 百分位：`P10=-5 / P25=-3 / P50=1 / P75=7`
- 峰值數（heuristic）：`5`
- 是否多峰：`是`
- 初步判斷：
  - `+3` 作為非妾配偶差值假設方向正確，但均值誤差 `+3.26` 顯示規則整體仍偏早（預測妻生年偏小）。
  - 中位誤差 `+1`，表示作為 baseline 尚可；但分布並非單峰且有長尾（最大誤差 `126`）。
- 分布摘要（1 年 bin，高頻 bins）：
  - `-3: 99`, `-4: 52`, `-1: 49`, `-2: 46`, `0: 46`, `1: 36`, `2: 35`, `-5: 33`, `3: 31`, `7: 26`
- 峰值 bins（heuristic）：
  - `-3, -1, 5, 7, 12`
- 備註：
  - `-3` 為最強峰，說明資料中有一批配偶差值更接近「同歲或妻較年長」的情況，與固定 `+3` 假設有系統偏差。

### 配偶（妾/側室）出生年差（Rule 17/18，假設：妾 = 夫 + 3）
- 狀態：DONE
- 樣本數：`102`
- 均值（error_year）：`9.2157`
- 方差（error_year）：`173.0025`
- 標準差（error_year）：`13.1530`
- 範圍：`[-54, 43]`
- 百分位：`P10=-3 / P25=2 / P50=9 / P75=17`
- 峰值數（heuristic）：`8`
- 是否多峰：`是`（但樣本小，僅供參考）
- 初步判斷：
  - 妾/側室組相較非妾組誤差更大，且中位誤差 `+9`，顯示固定 `+3` 明顯偏早。
  - 若後續要優化規則，妾組應獨立估計偏移量，而不宜直接沿用非妾假設。
- 分布摘要（1 年 bin，高頻 bins）：
  - `1: 7`, `9: 7`, `7: 6`, `10: 5`, `-1: 4`, `4: 4`, `8: 4`, `-5: 3`, `2: 3`, `3: 3`
- 峰值 bins（heuristic）：
  - `-5, 1, 4, 7, 9, 18, 23, 27`
- 備註：
  - 由於 `n=102`，1 年 bin + 2% 閾值（約 3）會放大小峰；建議後續用 3 年 bin 或 KDE 再判峰。

### 長子反推父（Rule 13/14，假設：父 = 長子 - 30）
- 狀態：DONE
- 樣本數：`1977`
- 均值（error_year）：`-1.2853`
- 方差（error_year）：`164.7819`
- 標準差（error_year）：`12.8367`
- 範圍：`[-123, 143]`
- 百分位：`P10=-16 / P25=-8 / P50=0 / P75=6`
- 峰值數（heuristic）：`8`
- 是否多峰：`是`
- 初步判斷：
  - `父 = 長子 - 30` 作為基線假設整體合理（中位誤差 `0`）。
  - 均值誤差 `-1.29` 表示規則略偏晚（預測父生年偏大一些）。
  - 仍有明顯多峰與長尾，單一常數不能完全涵蓋。
- 分布摘要（1 年 bin，高頻 bins）：
  - `2: 89`, `5: 88`, `6: 83`, `0: 82`, `-1: 81`, `1: 80`, `10: 75`, `-2: 74`, `8: 74`, `7: 72`
- 峰值 bins（heuristic）：
  - `-11, -9, -5, 0, 2, 5, 8, 10`
- 備註：
  - 與 `11/12`（父子 +30）互相對照時，方向相近但誤差符號略相反，可能反映「使用長子」樣本選擇效應。

### 長子反推母（Rule 15/16，假設：母 = 長子 - 27）
- 狀態：DONE
- 樣本數：`193`
- 均值（error_year）：`1.9171`
- 方差（error_year）：`87.4514`
- 標準差（error_year）：`9.3515`
- 範圍：`[-28, 44]`
- 百分位：`P10=-9 / P25=-2 / P50=2 / P75=6`
- 峰值數（heuristic）：`7`
- 是否多峰：`是`（樣本較小，需保守解讀）
- 初步判斷：
  - `母 = 長子 - 27` 基線假設看起來可用（中位誤差 `+2`）。
  - 樣本量不大（`n=193`），目前結論應視為初步。
- 分布摘要（1 年 bin，高頻 bins）：
  - `2: 18`, `3: 13`, `6: 13`, `4: 11`, `5: 11`, `1: 10`, `8: 10`, `-1: 9`, `-2: 8`, `0: 8`
- 峰值 bins（heuristic）：
  - `-6, -1, 2, 6, 8, 10, 27`
- 備註：
  - `bin=27` 的小峰可能是小樣本噪聲或資料異常，後續建議人工抽樣檢查。

### 女婿反推父（Rule 23/24，假設：父 = 長婿 - 27）
- 狀態：DONE
- 樣本數：`393`
- 均值（error_year）：`-0.5751`
- 方差（error_year）：`245.9593`
- 標準差（error_year）：`15.6831`
- 範圍：`[-134, 50]`
- 百分位：`P10=-16 / P25=-8 / P50=0 / P75=8`
- 峰值數（heuristic）：`12`
- 是否多峰：`是`
- 初步判斷：
  - 作為 baseline，`-27` 對男性目標（岳父）整體表現尚可（中位誤差 `0`、均值接近 0）。
  - 但分布鋸齒感明顯、峰值數較多，顯示樣本中可能混合了多種家庭結構與資料品質情況。
- 分布摘要（1 年 bin，高頻 bins）：
  - `7: 18`, `-4: 17`, `8: 17`, `0: 16`, `-2: 15`, `4: 15`, `-3: 13`, `10: 13`, `-6: 12`, `-1: 12`
- 峰值 bins（heuristic）：
  - `-16, -14, -11, -8, -6, -4, -2, 0, 4, 7, 10, 15`
- 備註：
  - 可後續嘗試 3 年 bin 平滑後重做峰值判定，以減少鋸齒造成的過多局部峰。

### 女婿反推母（Rule 25/26，假設：母 = 長婿 - 24）
- 狀態：DONE
- 樣本數：`9`
- 均值（error_year）：`10.0000`
- 方差（error_year）：`743`
- 標準差（error_year）：`27.2580`
- 範圍：`[-23, 77]`
- 百分位：`P10=-23 / P25=0 / P50=6 / P75=12`
- 峰值數（heuristic）：`0`（樣本極小，不具解釋意義）
- 是否多峰：`否`（僅因樣本極小）
- 初步判斷：
  - 樣本量極小（`n=9`），暫時不適合根據此組調整規則假設。
  - 目前只能記錄為「資料不足」；若未來資料增長，再重新評估。
- 分布摘要（1 年 bin）：
  - `12: 2`，其餘 `-23, -3, 0, 2, 6, 7, 77` 各 `1`
- 峰值 bins（heuristic）：
  - 無（門檻未達）
- 備註：
  - 此組可在後續分析中標記為 `LOW_N`，避免影響整體規則調整決策。

### 年長兄弟差（Rule 19/20，假設：本人 = 最近年長兄弟 + 2）
- 狀態：DONE
- 樣本數：`815`
- 均值（error_year）：`6.0650`
- 方差（error_year）：`91.6997`
- 標準差（error_year）：`9.5760`
- 範圍：`[-121, 68]`
- 百分位：`P10=-1 / P25=1 / P50=4 / P75=10`
- 峰值數（heuristic）：`4`
- 是否多峰：`是`
- 初步判斷：
  - `+2` 對「最近年長兄弟」這組明顯偏小（均值誤差 `+6.07`、中位誤差 `+4`）。
  - 這表示實際與最近年長兄弟的差距，常常大於 2 年。
- 分布摘要（1 年 bin，高頻 bins）：
  - `0: 72`, `1: 72`, `3: 62`, `2: 58`, `-1: 50`, `5: 48`, `4: 46`, `6: 45`, `9: 33`, `8: 30`
- 峰值 bins（heuristic）：
  - `0, 3, 5, 9`
- 備註：
  - 這組很可能值得直接調整假設常數（例如由 `+2` 上調），或按家族規模/朝代分層。

### 年幼兄弟差（Rule 21/22，假設：本人 = 最近年幼兄弟 - 2）
- 狀態：DONE
- 樣本數：`802`
- 均值（error_year）：`-5.8566`
- 方差（error_year）：`87.4988`
- 標準差（error_year）：`9.3541`
- 範圍：`[-46, 121]`
- 百分位：`P10=-16 / P25=-10 / P50=-4 / P75=-1`
- 峰值數（heuristic）：`5`
- 是否多峰：`是`
- 初步判斷：
  - `-2` 對「最近年幼兄弟」這組同樣偏小（但在此表現為負誤差，均值 `-5.86`、中位 `-4`）。
  - 與 `19/20` 成對看很一致：`±2` 整體低估了兄弟間隔的常見值。
- 分布摘要（1 年 bin，高頻 bins）：
  - `0: 74`, `-1: 68`, `-3: 63`, `-2: 55`, `1: 53`, `-5: 50`, `-6: 47`, `-4: 46`, `-9: 34`, `-8: 28`
- 峰值 bins（heuristic）：
  - `-14, -9, -5, -3, 0`
- 備註：
  - 這組與 `19/20` 的結果支持一起重估「兄弟差」常數，而不是只改其中一邊。

### 科舉（本人）進士（Rule 05，假設：本人 = 進士年 - 30）
- 狀態：DONE（本人組）
- 樣本數：`17530`
- 均值（error_year）：`-1.8880`
- 方差（error_year）：`67.8853`
- 標準差（error_year）：`8.2393`
- 範圍：`[-458, 281]`
- 百分位：`P10=-10 / P25=-6 / P50=-1 / P75=3`
- 峰值數（heuristic）：`3`
- 是否多峰：`是`
- 初步判斷：
  - `-30` 對進士本人這組表現相當穩定（大樣本、標準差相對低）。
  - 中位誤差 `-1`、均值 `-1.89`，表示規則略偏晚（預測生年偏大）。
- 分布摘要（1 年 bin，高頻 bins）：
  - `-1: 1109`, `1: 1104`, `0: 1072`, `-4: 1034`, `-2: 1026`, `2: 1016`, `-3: 968`, `3: 967`, `4: 886`, `-5: 863`
- 峰值 bins（heuristic）：
  - `-4, -1, 1`
- 備註：
  - 存在極端離群值（如 `-458 / 281`），建議後續抽樣檢查是否為紀年/資料錄入異常。

### 科舉（本人）舉人（Rule 07，假設：本人 = 舉人年 - 27）
- 狀態：DONE（本人組）
- 樣本數：`2342`
- 均值（error_year）：`0.3753`
- 方差（error_year）：`1182.0013`
- 標準差（error_year）：`34.3802`
- 範圍：`[-263, 1582]`
- 百分位：`P10=-10 / P25=-4 / P50=1 / P75=5`
- 峰值數（heuristic）：`5`
- 是否多峰：`是`
- 初步判斷：
  - 中心位置其實不差（均值接近 0，中位 `1`），但離散度極大，顯示資料質量/定義混合問題遠大於假設常數本身。
  - 需要先處理離群值，再評估是否應調整 `-27`。
- 分布摘要（1 年 bin，高頻 bins）：
  - `2: 159`, `3: 152`, `4: 151`, `0: 149`, `6: 146`, `5: 142`, `1: 135`, `-1: 133`, `7: 107`, `-3: 94`
- 峰值 bins（heuristic）：
  - `-8, -3, 0, 2, 6`
- 備註：
  - `max_error=1582` 幾乎可以確定有異常資料；後續建議增加 winsorization 或先做資料清理再重算。

### 科舉（本人）秀才/生員（Rule 09，假設：本人 = 秀才年 - 21）
- 狀態：DONE（本人組）
- 樣本數：`90`
- 均值（error_year）：`7.5889`
- 方差（error_year）：`781.9077`
- 標準差（error_year）：`27.9626`
- 範圍：`[-26, 199]`
- 百分位：`P10=-8 / P25=0 / P50=3 / P75=7`
- 峰值數（heuristic）：`3`
- 是否多峰：`是`（樣本偏小）
- 初步判斷：
  - `-21` 在此樣本上偏小（均值 `+7.59`、中位 `+3`），可能低估了從秀才/生員到出生年的常見年差。
  - 但樣本量僅 `90`，且離群值影響大，需保守解讀。
- 分布摘要（1 年 bin，高頻 bins）：
  - `2: 13`, `3: 8`, `7: 8`, `5: 6`, `6: 6`, `1: 5`, `4: 5`, `8: 5`, `0: 4`, `-12: 2`
- 峰值 bins（heuristic）：
  - `2, 5, 7`
- 備註：
  - 配偶組（Rule 10）樣本可能更少，應與本人組一起標記為 `LOW_N / high uncertainty`。

### 科舉（配偶）妻據夫進士（Rule 06，假設：妻 = 進士年 - 27）
- 狀態：DONE
- 樣本數：`247`
- 均值（error_year）：`4.0567`
- 方差（error_year）：`136.6553`
- 標準差（error_year）：`11.6900`
- 範圍：`[-39, 37]`
- 百分位：`P10=-9 / P25=-3 / P50=4 / P75=10`
- 峰值數（heuristic）：`7`
- 是否多峰：`是`
- 初步判斷：
  - `-27`（即夫進士年 -27）對妻的估算偏早（均值 `+4.06`、中位 `+4`）。
  - 相較 `05`（本人進士）明顯更不穩定，表示「夫→妻」再推一步的假設誤差更大。
- 分布摘要（1 年 bin，高頻 bins）：
  - `1: 16`, `8: 16`, `4: 15`, `7: 15`, `9: 14`, `14: 9`, `-3: 8`, `0: 8`, `5: 8`, `10: 8`
- 峰值 bins（heuristic）：
  - `-8, -6, -3, 1, 4, 8, 14`
- 備註：
  - 這組與配偶 `+3` 規則結果方向一致（常數偏小，誤差偏正）。

### 科舉（配偶）妻據夫舉人（Rule 08，假設：妻 = 舉人年 - 24）
- 狀態：DONE
- 樣本數：`45`
- 均值（error_year）：`2.4000`
- 方差（error_year）：`97.9727`
- 標準差（error_year）：`9.8981`
- 範圍：`[-26, 22]`
- 百分位：`P10=-10 / P25=-5 / P50=4 / P75=9`
- 峰值數（heuristic）：`3`
- 是否多峰：`是`（樣本較小）
- 初步判斷：
  - 中位誤差 `+4` 顯示 `-24` 可能略偏小，但 `n=45`，結論僅供參考。
- 分布摘要（1 年 bin，高頻 bins）：
  - `0: 3`, `5: 3`, `8: 3`, `-12: 2`, `-10: 2`, `-8: 2`, `-5: 2`, `1: 2`, `4: 2`, `9: 2`
- 峰值 bins（heuristic）：
  - `0, 5, 8`
- 備註：
  - 可與 `06` 合併看趨勢（配偶科舉規則偏正），但不宜單獨調參。

### 科舉（配偶）妻據夫秀才/生員（Rule 10，假設：妻 = 秀才年 - 18）
- 狀態：DONE（LOW_N）
- 樣本數：`3`
- 均值（error_year）：`0.6667`
- 方差（error_year）：`24.3333`
- 標準差（error_year）：`4.9329`
- 範圍：`[-5, 4]`
- 百分位：`P10=-5 / P25=-5 / P50=3 / P75=4`
- 峰值數（heuristic）：`0`
- 是否多峰：`否`（僅因樣本極小）
- 初步判斷：
  - 樣本極小，暫不作規則調整依據。
- 分布摘要（1 年 bin）：
  - `-5:1, 3:1, 4:1`
- 峰值 bins（heuristic）：
  - 無
- 備註：
  - 建議標記 `LOW_N` 並忽略於參數調整決策。

### 祖父推後代（Rule 27/28，假設：後代 = 祖父 + 60）
- 狀態：DONE
- 樣本數：`591`
- 均值（error_year）：`0.9052`
- 方差（error_year）：`848.4588`
- 標準差（error_year）：`29.1283`
- 範圍：`[-219, 153]`
- 百分位：`P10=-18 / P25=-10 / P50=0 / P75=13`
- 峰值數（heuristic）：`8`
- 是否多峰：`是`
- 初步判斷：
  - `+60` 作為祖父→後代的粗略假設，中心位置尚可（均值接近 0、中位 `0`）。
  - 但離散度很大，說明這條規則本質上只能作低可信度 fallback。
- 分布摘要（1 年 bin，高頻 bins）：
  - `-6: 22`, `12: 18`, `-3: 17`, `-7: 16`, `-5: 16`, `-4: 16`, `1: 16`, `-13: 15`, `-12: 15`, `-8: 15`
- 峰值 bins（heuristic）：
  - `-13, -6, -3, 1, 3, 6, 8, 12`
- 備註：
  - 若未來重估此規則，較可能需要按「後代實際代際距離」進一步分層，而不是只用單一常數。

建議先驗證這些高影響規則：
1. `29/30`（僅卒年估算）
2. `11/12`（父子 +30）
3. `03/17`（配偶 +3，分非妾/妾）
4. `13/15`（長子反推父母）
5. `23/25`（女婿反推父母）

完成後再檢查：
- 科舉規則（`05~10`）
- 兄弟規則（`19~22`）
- 祖父規則（`27/28`）

## 8. 初步總結與調參建議（截至目前已驗證規則）

### 8.1 建議先維持（可作 baseline）
以下規則中心位置（均值/中位）整體尚可，雖然多數仍有多峰與長尾：

1. `13/14` 長子反推父（父 = 長子 - 30）
   - `mean_error=-1.29`, `p50=0`
2. `23/24` 女婿反推父（父 = 長婿 - 27）
   - `mean_error=-0.58`, `p50=0`
3. `27/28` 祖父推後代（後代 = 祖父 + 60）
   - `mean_error=+0.91`, `p50=0`
   - 但離散度大，僅適合作 fallback
4. `29` 僅卒年估算（男，-63）
   - `mean_error=+2.23`, `p50=1`
   - 可維持作 baseline，後續再考慮分層

### 8.2 明顯偏差（常數疑似偏小，優先檢討）
這些規則在目前樣本上呈現一致方向的系統偏差，較適合優先做「常數重估」或分層：

1. `19/20` 年長兄弟（+2）
   - `mean_error=+6.07`, `p50=4`
   - 顯示 `+2` 明顯偏小
2. `21/22` 年幼兄弟（-2）
   - `mean_error=-5.86`, `p50=-4`
   - 與 `19/20` 成對支持：兄弟差值常數整體偏小
3. `17/18` 妾/側室（夫 +3）
   - `mean_error=+9.22`, `p50=9`
   - 明顯不宜沿用非妾規則的直覺
4. `30` 僅卒年估算（女，程式 -56）
   - `mean_error=+3.19`, `p50=1`
   - 並且多峰明顯，優先考慮分層（如 `family_signal`）

### 8.3 可用但應分層（單一常數不足）
這些規則雖然中心位置不差或可接受，但多峰/離散度說明單一常數不夠：

1. `11/12` 父子 +30
   - `mean_error=+2.80`, `p50=1`
2. `03/04` 非妾配偶 +3
   - `mean_error=+3.26`, `p50=1`
3. `05` 進士本人 -30
   - 中心穩定，但存在離群值，建議先清理異常值
4. `07` 舉人本人 -27
   - 中心接近合理，但離群值極大（`max_error=1582`），先做資料清理

### 8.4 低樣本（暫不建議調參）
以下規則現階段只做觀察，不建議據此修改常數：

1. `25/26` 女婿反推母（女性目標）`n=9`
2. `09/10` 秀才/生員相關（尤其 `10` 僅 `n=3`）
3. `15/16` 長子反推母（`n=193`）
   - 雖非極低樣本，但仍屬中小樣本，建議保守
4. `08` 妻據夫舉人（`n=45`）

### 8.5 建議的下一步（不改程式碼版本）
先做一版「候選常數/分層方案」草案（文檔層）：

1. `兄弟差（19/21）`
   - 測試 `±4`、`±5`、`±6` 三組常數
   - 比較均值絕對值、IQR、峰值數
2. `女性卒年估算（30）`
   - 用 `family_signal` 二分類（你前面同意的定義）
   - 與單一 `-56` 比較誤差分布
3. `妾/側室（17/18）`
   - 單獨重估 `+3` 的替代常數（例如 `+6/+9`）
4. `舉人/秀才`
   - 先做離群值審查（特別是 `07`）
   - 再判斷是否需要調整常數

### 8.6 若要進一步量化（推薦）
為了避免只看均值，建議新增一個統一評分欄位（文檔即可）：

1. `MAE`（平均絕對誤差）
2. `MedianAE`（中位絕對誤差）
3. `IQR`
4. `OutlierRate`（例如 `|error_year| > 20` 的比例）

這些指標通常比均值/方差更適合直接比較規則常數是否改善。
