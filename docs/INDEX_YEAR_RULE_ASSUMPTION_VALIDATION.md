# Index Year 規則假設驗證報告

版本：`v1.0`  
性質：最終報告
資料庫：MariaDB/MySQL

## 1. 說明

本報告整理 `cbdb:rebuild-index-year` 已驗證規則的假設表現，採用統一誤差定義：

- `error_year = actual_birthyear - predicted_birthyear`

解讀：
- `error_year = 0`：規則假設與真實生年一致
- `error_year > 0`：規則預測生年偏早（把人物推得更老）
- `error_year < 0`：規則預測生年偏晚（把人物推得更年輕）

每組規則均報告：
- 樣本數 `n`
- 均值、方差、標準差
- 範圍（min/max）
- 百分位（`P10/P25/P50/P75`）
- 分布摘要（top bins）
- 是否多峰（heuristic）

## 2. 通用統計 SQL（共用）

以下 SQL 用於每個 rule sample 的統計（sample SQL 在各節提供）。

### 2.1 基本統計（均值/方差/標準差/範圍）
```sql
WITH sample AS (
    /* 各規則 sample SQL */
    SELECT 1 AS error_year WHERE 1 = 0
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

### 2.2 百分位（nearest-rank：P10/P25/P50/P75）
```sql
WITH sample AS (
    /* 各規則 sample SQL */
    SELECT 1 AS error_year WHERE 1 = 0
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

### 2.3 多峰（heuristic）
- 使用 1 年 bin 直方圖
- 峰值條件：`bin_count > prev_count` 且 `bin_count >= next_count`
- 且 `bin_count >= max(3, ceil(total_n * 0.02))`

```sql
WITH sample AS (
    /* 各規則 sample SQL */
    SELECT 1 AS error_year WHERE 1 = 0
),
hist AS (
    SELECT error_year AS bin_error_year, COUNT(*) AS bin_count
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

## 3. Rule 29（男）僅卒年估算：`birthyear = deathyear - 63`

### SQL
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

### 結果
- `n = 34037`
- `mean_error = +2.2294`
- `var_error = 230.7027`
- `stddev_error = 15.1889`
- `min/max = -404 / 121`
- `P10/P25/P50/P75 = -16 / -8 / 1 / 11`
- `peak_count = 8`（多峰）
- 分布摘要（top bins）
  - `3: 990`, `-4: 981`, `-3: 956`, `0: 950`, `-6: 935`, `-1: 933`, `1: 923`, `2: 909`, `-5: 900`, `-2: 891`

### 分析
- `-63` 可作男性卒年估算的 baseline（`P50=1`，中心位置尚可）。
- 分布多峰且有長尾，說明單一常數只能作粗略 fallback。
- 存在極端離群值（如 `-404`），後續宜抽樣檢查資料品質。

## 4. Rule 30（女）僅卒年估算：`birthyear = deathyear - 56`（程式實作）

### SQL
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

### 結果
- `n = 3330`
- `mean_error = +3.1937`
- `var_error = 408.0619`
- `stddev_error = 20.2005`
- `min/max = -68 / 55`
- `P10/P25/P50/P75 = -22 / -13 / 1 / 20`
- `peak_count = 4`（多峰）
- 分布摘要（top bins）
  - `-3: 91`, `2: 82`, `-4: 74`, `-18: 72`, `-9: 71`, `-7: 66`, `-17: 64`, `-15: 64`, `23: 64`, `-12: 63`

### 分析
- `-56` 可作 baseline，但誤差明顯多峰且離散度高於男性。
- 中心位置偏正（`mean=+3.19`），表示規則傾向把人物推得偏老。
- 建議優先改為分層模型（例如 `family_signal` 二分類）。

## 5. Rule 11/12 父子差（假設：子 = 父 + 30）

### SQL
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

### 結果
- `n = 2627`
- `mean_error = +2.7956`
- `var_error = 171.3356`
- `stddev_error = 13.0895`
- `min/max = -143 / 123`
- `P10/P25/P50/P75 = -10 / -5 / 1 / 10`
- `peak_count = 7`（多峰）
- 分布摘要（top bins）
  - `1: 106`, `-5: 104`, `-1: 101`, `-2: 100`, `0: 100`, `-6: 94`, `2: 92`, `-7: 88`, `-4: 88`, `3: 88`

### 分析
- `+30` 作為父子差 baseline 整體可用（`P50=1`）。
- 均值偏正，顯示規則略偏早（把子女推得略老）。
- 多峰明顯，後續適合按朝代/性別/資料可靠度分層。

## 6. Rule 13/14 長子反推父（假設：父 = 長子 - 30）

### SQL
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
        JOIN BIOG_MAIN child ON child.c_personid = kd.c_personid
        WHERE kd.c_kin_code = 75
          AND child.c_birthyear IS NOT NULL
          AND child.c_birthyear <> 0
        GROUP BY kd.c_kin_id
    ) agg ON agg.father_personid = father.c_personid
    WHERE father.c_birthyear IS NOT NULL
      AND father.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 結果
- `n = 1977`
- `mean_error = -1.2853`
- `var_error = 164.7819`
- `stddev_error = 12.8367`
- `min/max = -123 / 143`
- `P10/P25/P50/P75 = -16 / -8 / 0 / 6`
- `peak_count = 8`（多峰）

### 分析
- 中心位置良好（`P50=0`），`-30` 可保留為 baseline。
- 均值略負，表示規則稍微把父親推得偏年輕。
- 與 `11/12` 互相對照時，顯示「父子 +30 / 長子 -30」整體相容，但都存在混合分布。

## 7. Rule 15/16 長子反推母（假設：母 = 長子 - 27）

### SQL
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
        JOIN BIOG_MAIN child ON child.c_personid = kd.c_personid
        WHERE kd.c_kin_code = 111
          AND child.c_birthyear IS NOT NULL
          AND child.c_birthyear <> 0
        GROUP BY kd.c_kin_id
    ) agg ON agg.mother_personid = mother.c_personid
    WHERE mother.c_birthyear IS NOT NULL
      AND mother.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 結果
- `n = 193`
- `mean_error = +1.9171`
- `var_error = 87.4514`
- `stddev_error = 9.3515`
- `min/max = -28 / 44`
- `P10/P25/P50/P75 = -9 / -2 / 2 / 6`
- `peak_count = 7`（樣本較小）

### 分析
- `-27` 對母系版本看起來可用（`P50=2`）。
- 樣本量偏小（`n=193`），應保守解讀。
- 暫不建議單獨調參，可先保留。

## 8. Rule 03/04 非妾配偶（假設：妻 = 夫 + 3）

### SQL
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

### 結果
- `n = 749`
- `mean_error = +3.2577`
- `var_error = 105.3145`
- `stddev_error = 10.2623`
- `min/max = -26 / 126`
- `P10/P25/P50/P75 = -5 / -3 / 1 / 7`
- `peak_count = 5`（多峰）
- top bins：`-3:99`, `-4:52`, `-1:49`, `-2:46`, `0:46`, `1:36`, `2:35` ...

### 分析
- `+3` 作為非妾配偶差值假設方向正確，但偏小（均值偏正）。
- `-3` 為強峰，提示存在一群樣本更接近「同歲或妻年長」情況。
- 若要優化配偶規則，應先分非妾/妾再考慮重估常數。

## 9. Rule 17/18 妾/側室配偶（假設：妾 = 夫 + 3）

### SQL
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

### 結果
- `n = 102`
- `mean_error = +9.2157`
- `var_error = 173.0025`
- `stddev_error = 13.1530`
- `min/max = -54 / 43`
- `P10/P25/P50/P75 = -3 / 2 / 9 / 17`
- `peak_count = 8`（樣本小、噪聲大）

### 分析
- 妾/側室組相對非妾組偏差明顯更大，`+3` 幾乎可以確定偏小。
- `P50=9` 顯示此規則應單獨重估，不宜沿用非妾假設。
- `n=102` 不算太小，但 1-year bin 峰值判定仍偏敏感，建議後續用 3-year bin 再看。

## 10. Rule 19/20 年長兄弟（假設：本人 = 最近年長兄弟 + 2）

### SQL
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
        JOIN BIOG_MAIN sib ON sib.c_personid = kd.c_kin_id
        WHERE kd.c_kin_code IN (125,165)
          AND sib.c_birthyear IS NOT NULL
          AND sib.c_birthyear <> 0
        GROUP BY kd.c_personid
    ) agg ON agg.target_personid = person.c_personid
    WHERE person.c_birthyear IS NOT NULL
      AND person.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 結果
- `n = 815`
- `mean_error = +6.0650`
- `var_error = 91.6997`
- `stddev_error = 9.5760`
- `min/max = -121 / 68`
- `P10/P25/P50/P75 = -1 / 1 / 4 / 10`
- `peak_count = 4`（多峰）
- top bins：`0:72`, `1:72`, `3:62`, `2:58`, `-1:50`, `5:48` ...

### 分析
- `+2` 明顯偏小（`mean=+6.07`, `P50=4`）。
- 這是目前最清楚可見的「常數需重估」候選之一。
- 建議與 `21/22` 配對一起重估（避免只改單邊）。

## 11. Rule 21/22 年幼兄弟（假設：本人 = 最近年幼兄弟 - 2）

### SQL
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
        JOIN BIOG_MAIN sib ON sib.c_personid = kd.c_kin_id
        WHERE kd.c_kin_code IN (126,166)
          AND sib.c_birthyear IS NOT NULL
          AND sib.c_birthyear <> 0
        GROUP BY kd.c_personid
    ) agg ON agg.target_personid = person.c_personid
    WHERE person.c_birthyear IS NOT NULL
      AND person.c_birthyear <> 0
)
SELECT * FROM sample;
```

### 結果
- `n = 802`
- `mean_error = -5.8566`
- `var_error = 87.4988`
- `stddev_error = 9.3541`
- `min/max = -46 / 121`
- `P10/P25/P50/P75 = -16 / -10 / -4 / -1`
- `peak_count = 5`（多峰）
- top bins：`0:74`, `-1:68`, `-3:63`, `-2:55`, `1:53`, `-5:50` ...

### 分析
- 與 `19/20` 完全呼應：`-2` 也偏小（表現為負誤差）。
- 兩組一起看，兄弟差值常數應成對上調（例如測試 `±4/±5/±6`）。

## 12. Rule 23/24 女婿反推父（假設：父 = 長婿 - 27）

### SQL
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
        JOIN BIOG_MAIN soninlaw ON soninlaw.c_personid = kd.c_kin_id
        WHERE kd.c_kin_code IN (181,201,224,332)
          AND soninlaw.c_birthyear IS NOT NULL
          AND soninlaw.c_birthyear <> 0
        GROUP BY kd.c_personid
    ) agg ON agg.target_personid = person.c_personid
    WHERE person.c_birthyear IS NOT NULL
      AND person.c_birthyear <> 0
      AND person.c_female = 0
)
SELECT * FROM sample;
```

### 結果
- `n = 393`
- `mean_error = -0.5751`
- `var_error = 245.9593`
- `stddev_error = 15.6831`
- `min/max = -134 / 50`
- `P10/P25/P50/P75 = -16 / -8 / 0 / 8`
- `peak_count = 12`（鋸齒、多峰）

### 分析
- 中心位置很好（`P50=0`，均值接近 0），可保留作 baseline。
- 峰值數較多，建議使用更粗 bin（如 3-year）再判峰，避免過度解讀鋸齒。

## 13. Rule 25/26 女婿反推母（假設：母 = 長婿 - 24）

### SQL
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
        JOIN BIOG_MAIN soninlaw ON soninlaw.c_personid = kd.c_kin_id
        WHERE kd.c_kin_code IN (181,201,224,332)
          AND soninlaw.c_birthyear IS NOT NULL
          AND soninlaw.c_birthyear <> 0
        GROUP BY kd.c_personid
    ) agg ON agg.target_personid = person.c_personid
    WHERE person.c_birthyear IS NOT NULL
      AND person.c_birthyear <> 0
      AND person.c_female = 1
)
SELECT * FROM sample;
```

### 結果
- `n = 9`
- `mean_error = +10.0000`
- `var_error = 743`
- `stddev_error = 27.2580`
- `min/max = -23 / 77`
- `P10/P25/P50/P75 = -23 / 0 / 6 / 12`
- `peak_count = 0`（僅因樣本極小）

### 分析
- 樣本極小，不適合作為調參依據。
- 保留現狀，標記 `LOW_N`。

## 14. Rule 05/07/09 科舉（本人）

### 14.1 Rule 05（進士，假設：本人 = 進士年 - 30）

#### SQL
```sql
WITH sample AS (
    SELECT
        bm.c_personid AS target_personid,
        bm.c_birthyear AS actual_birthyear,
        agg.entry_year - 30 AS predicted_birthyear,
        bm.c_birthyear - (agg.entry_year - 30) AS error_year
    FROM BIOG_MAIN bm
    JOIN (
        SELECT ed.c_personid, MIN(ed.c_year) AS entry_year
        FROM ENTRY_DATA ed
        JOIN ENTRY_CODE_TYPE_REL ectr
          ON ectr.c_entry_code = ed.c_entry_code
         AND ectr.c_entry_type = '040101'
        WHERE ed.c_year > 0
        GROUP BY ed.c_personid
    ) agg ON agg.c_personid = bm.c_personid
    WHERE bm.c_birthyear IS NOT NULL
      AND bm.c_birthyear <> 0
)
SELECT * FROM sample;
```

#### 結果
- `n = 17530`
- `mean_error = -1.8880`
- `var_error = 67.8853`
- `stddev_error = 8.2393`
- `min/max = -458 / 281`
- `P10/P25/P50/P75 = -10 / -6 / -1 / 3`
- `peak_count = 3`

#### 分析
- `-30` 在大樣本上表現穩定，中心位置接近合理。
- 適合保留為 baseline；優先處理離群值而非先改常數。

### 14.2 Rule 07（舉人，假設：本人 = 舉人年 - 27）

#### SQL
```sql
WITH sample AS (
    SELECT
        bm.c_personid AS target_personid,
        bm.c_birthyear AS actual_birthyear,
        agg.entry_year - 27 AS predicted_birthyear,
        bm.c_birthyear - (agg.entry_year - 27) AS error_year
    FROM BIOG_MAIN bm
    JOIN (
        SELECT ed.c_personid, MIN(ed.c_year) AS entry_year
        FROM ENTRY_DATA ed
        JOIN ENTRY_CODE_TYPE_REL ectr
          ON ectr.c_entry_code = ed.c_entry_code
         AND ectr.c_entry_type = '040102'
        WHERE ed.c_year > 0
        GROUP BY ed.c_personid
    ) agg ON agg.c_personid = bm.c_personid
    WHERE bm.c_birthyear IS NOT NULL
      AND bm.c_birthyear <> 0
)
SELECT * FROM sample;
```

#### 結果
- `n = 2342`
- `mean_error = +0.3753`
- `var_error = 1182.0013`
- `stddev_error = 34.3802`
- `min/max = -263 / 1582`
- `P10/P25/P50/P75 = -10 / -4 / 1 / 5`
- `peak_count = 5`

#### 分析
- 中心位置其實不差，但離散度極大。
- 優先問題不是常數，而是資料異常/混合定義（`max_error=1582`）。
- 建議先清理離群值，再評估是否調整 `-27`。

### 14.3 Rule 09（秀才/生員，假設：本人 = 秀才年 - 21）

#### SQL
```sql
WITH sample AS (
    SELECT
        bm.c_personid AS target_personid,
        bm.c_birthyear AS actual_birthyear,
        agg.entry_year - 21 AS predicted_birthyear,
        bm.c_birthyear - (agg.entry_year - 21) AS error_year
    FROM BIOG_MAIN bm
    JOIN (
        SELECT ed.c_personid, MIN(ed.c_year) AS entry_year
        FROM ENTRY_DATA ed
        JOIN ENTRY_CODE_TYPE_REL ectr
          ON ectr.c_entry_code = ed.c_entry_code
         AND ectr.c_entry_type = '040103'
        WHERE ed.c_year > 0
        GROUP BY ed.c_personid
    ) agg ON agg.c_personid = bm.c_personid
    WHERE bm.c_birthyear IS NOT NULL
      AND bm.c_birthyear <> 0
)
SELECT * FROM sample;
```

#### 結果
- `n = 90`
- `mean_error = +7.5889`
- `var_error = 781.9077`
- `stddev_error = 27.9626`
- `min/max = -26 / 199`
- `P10/P25/P50/P75 = -8 / 0 / 3 / 7`
- `peak_count = 3`

#### 分析
- 樣本偏小且離群值影響大。
- 在當前樣本上 `-21` 偏小（誤差偏正），但不建議直接調參。

## 15. Rule 06/08/10 科舉（配偶）

### 15.1 Rule 06（妻據夫進士，假設：妻 = 進士年 - 27）

#### SQL
```sql
WITH sample AS (
    SELECT
        wife.c_personid AS target_personid,
        wife.c_birthyear AS actual_birthyear,
        agg.entry_year - 27 AS predicted_birthyear,
        wife.c_birthyear - (agg.entry_year - 27) AS error_year
    FROM BIOG_MAIN wife
    JOIN (
        SELECT kd.c_personid AS wife_personid, MIN(ed.c_year) AS entry_year
        FROM KIN_DATA kd
        JOIN ENTRY_DATA ed ON ed.c_personid = kd.c_kin_id
        JOIN ENTRY_CODE_TYPE_REL ectr
          ON ectr.c_entry_code = ed.c_entry_code
         AND ectr.c_entry_type = '040101'
        WHERE kd.c_kin_code = 134
          AND ed.c_year > 0
        GROUP BY kd.c_personid
    ) agg ON agg.wife_personid = wife.c_personid
    WHERE wife.c_birthyear IS NOT NULL
      AND wife.c_birthyear <> 0
)
SELECT * FROM sample;
```

#### 結果
- `n = 247`
- `mean_error = +4.0567`
- `var_error = 136.6553`
- `stddev_error = 11.6900`
- `min/max = -39 / 37`
- `P10/P25/P50/P75 = -9 / -3 / 4 / 10`
- `peak_count = 7`

#### 分析
- 與 `05`（本人進士）相比，配偶版本明顯偏正，代表估計偏早。
- 適合作為「可用但應分層/再調整」規則。

### 15.2 Rule 08（妻據夫舉人，假設：妻 = 舉人年 - 24）

#### SQL
```sql
WITH sample AS (
    SELECT
        wife.c_personid AS target_personid,
        wife.c_birthyear AS actual_birthyear,
        agg.entry_year - 24 AS predicted_birthyear,
        wife.c_birthyear - (agg.entry_year - 24) AS error_year
    FROM BIOG_MAIN wife
    JOIN (
        SELECT kd.c_personid AS wife_personid, MIN(ed.c_year) AS entry_year
        FROM KIN_DATA kd
        JOIN ENTRY_DATA ed ON ed.c_personid = kd.c_kin_id
        JOIN ENTRY_CODE_TYPE_REL ectr
          ON ectr.c_entry_code = ed.c_entry_code
         AND ectr.c_entry_type = '040102'
        WHERE kd.c_kin_code = 134
          AND ed.c_year > 0
        GROUP BY kd.c_personid
    ) agg ON agg.wife_personid = wife.c_personid
    WHERE wife.c_birthyear IS NOT NULL
      AND wife.c_birthyear <> 0
)
SELECT * FROM sample;
```

#### 結果
- `n = 45`
- `mean_error = +2.4000`
- `var_error = 97.9727`
- `stddev_error = 9.8981`
- `min/max = -26 / 22`
- `P10/P25/P50/P75 = -10 / -5 / 4 / 9`
- `peak_count = 3`

#### 分析
- 樣本偏小，僅能說明方向上可能略偏正。
- 暫不建議單獨調整 `-24`。

### 15.3 Rule 10（妻據夫秀才/生員，假設：妻 = 秀才年 - 18）

#### SQL
```sql
WITH sample AS (
    SELECT
        wife.c_personid AS target_personid,
        wife.c_birthyear AS actual_birthyear,
        agg.entry_year - 18 AS predicted_birthyear,
        wife.c_birthyear - (agg.entry_year - 18) AS error_year
    FROM BIOG_MAIN wife
    JOIN (
        SELECT kd.c_personid AS wife_personid, MIN(ed.c_year) AS entry_year
        FROM KIN_DATA kd
        JOIN ENTRY_DATA ed ON ed.c_personid = kd.c_kin_id
        JOIN ENTRY_CODE_TYPE_REL ectr
          ON ectr.c_entry_code = ed.c_entry_code
         AND ectr.c_entry_type = '040103'
        WHERE kd.c_kin_code = 134
          AND ed.c_year > 0
        GROUP BY kd.c_personid
    ) agg ON agg.wife_personid = wife.c_personid
    WHERE wife.c_birthyear IS NOT NULL
      AND wife.c_birthyear <> 0
)
SELECT * FROM sample;
```

#### 結果
- `n = 3`
- `mean_error = +0.6667`
- `var_error = 24.3333`
- `stddev_error = 4.9329`
- `min/max = -5 / 4`
- `P10/P25/P50/P75 = -5 / -5 / 3 / 4`
- `peak_count = 0`（極小樣本）

#### 分析
- 樣本極小，不可用於調參。
- 標記 `LOW_N`，暫時忽略。

## 16. Rule 27/28 祖父推後代（假設：後代 = 祖父 + 60）

### SQL
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

### 結果
- `n = 591`
- `mean_error = +0.9052`
- `var_error = 848.4588`
- `stddev_error = 29.1283`
- `min/max = -219 / 153`
- `P10/P25/P50/P75 = -18 / -10 / 0 / 13`
- `peak_count = 8`
- top bins：`-6:22`, `12:18`, `-3:17`, `-7:16`, `-5:16`, `-4:16`, `1:16` ...

### 分析
- `+60` 的中心位置尚可，但離散度很大。
- 適合當 fallback，不適合高可信度推斷。

## 17. 總結（可用於下一步決策）

### 17.1 優先檢討（系統偏差明顯）
1. `19/20`, `21/22`（兄弟差 `±2`）
   - 成對顯示常數偏小，建議一起重估（例如測 `±4/±5/±6`）
2. `17/18`（妾/側室 `+3`）
   - 中位誤差高，應單獨重估
3. `30`（女性卒年估算 `-56`）
   - 多峰明顯，優先做分層模型（如 `family_signal`）

### 17.2 先清理資料再決定是否調參
1. `07`（舉人本人 `-27`）
   - 中心位置尚可，但離群值過大（`max_error=1582`）
2. `05`（進士本人 `-30`）
   - 大樣本穩定，但也有極端離群值

### 17.3 可暫時維持（baseline）
- `11/12`（父子 +30）
- `13/14`（長子反推父 -30）
- `15/16`（長子反推母 -27，樣本較小但可先保留）
- `23/24`（女婿反推父 -27）
- `27/28`（祖父 +60，作 fallback）
- `29`（男性卒年 -63，作 baseline）

### 17.4 低樣本（暫不調參）
- `25/26`（女婿反推母）
- `08`（妻據夫舉人）
- `09/10`（秀才/生員相關，尤其 `10`）

## 18. 建議的下一步分析

1. 兄弟差常數試算（`±4/±5/±6`）
   - 比較 `mean_error`, `P50`, `MAE`, `IQR`, `OutlierRate`
2. `Rule 30` 分層對比
   - 單一 `-56` vs `family_signal` 二分類
3. 舉人/秀才資料清理
   - 先檢查極端離群值來源（紀年、誤錄、特殊樣本）

