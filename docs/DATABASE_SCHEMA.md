# 數據庫 Schema 文檔

> 本文檔由 `php artisan cbdb:generate-schema-docs` 自動生成
> 生成時間：2026-04-20 13:15:57

## 目錄

- [MySQL/MariaDB Schema](#mysqlmariadb-schema)
- [SQLite Schema](#sqlite-schema)
- [Schema 差異對比](#schema-差異對比)

## MySQL/MariaDB Schema

### ADDRESSES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_addr_id` | int(11) | YES | NULL | Address ID; FK to ADDR_CODES.c_addr_id (not enforced). Multiple rows may exist per address for different time segments |
| `c_addr_cbd` | varchar(255) | YES | NULL | Legacy CBDB address code from ADDR_CODES |
| `c_name` | varchar(255) | YES | NULL | Romanized address name from ADDR_CODES.c_name |
| `c_name_chn` | varchar(255) | YES | NULL | Chinese address name from ADDR_CODES.c_name_chn |
| `c_admin_type` | varchar(255) | YES | NULL | Administrative unit type (e.g. zhou, fu, xian) from ADDR_CODES.c_admin_type |
| `c_firstyear` | smallint(6) | YES | NULL | First year this address was active (from ADDR_CODES) |
| `c_lastyear` | smallint(6) | YES | NULL | Last year this address was active (from ADDR_CODES) |
| `c_belongs_firstyear` | smallint(6) | YES | NULL | First year this hierarchy chain is valid; derived as max(addr_first, belongs_first) across all levels |
| `c_belongs_lastyear` | smallint(6) | YES | NULL | Last year this hierarchy chain is valid; derived as min(addr_last, belongs_last) across all levels |
| `x_coord` | double | YES | NULL | Longitude (x-coordinate) from ADDR_CODES |
| `y_coord` | double | YES | NULL | Latitude (y-coordinate) from ADDR_CODES |
| `belongs1_ID` | int(11) | YES | NULL | Level-1 parent address ID (immediate parent) |
| `belongs1_Name` | varchar(255) | YES | NULL | Level-1 parent romanized name |
| `belongs1_Name_chn` | varchar(255) | YES | NULL | Level-1 parent Chinese name |
| `belongs2_ID` | int(11) | YES | NULL | Level-2 parent address ID |
| `belongs2_Name` | varchar(255) | YES | NULL | Level-2 parent romanized name |
| `belongs2_Name_chn` | varchar(255) | YES | NULL | Level-2 parent Chinese name |
| `belongs3_ID` | int(11) | YES | NULL | Level-3 parent address ID |
| `belongs3_Name` | varchar(255) | YES | NULL | Level-3 parent romanized name |
| `belongs3_Name_chn` | varchar(255) | YES | NULL | Level-3 parent Chinese name |
| `belongs4_ID` | int(11) | YES | NULL | Level-4 parent address ID |
| `belongs4_Name` | varchar(255) | YES | NULL | Level-4 parent romanized name |
| `belongs4_Name_chn` | varchar(255) | YES | NULL | Level-4 parent Chinese name |
| `belongs5_ID` | int(11) | YES | NULL | Level-5 parent address ID |
| `belongs5_Name` | varchar(255) | YES | NULL | Level-5 parent romanized name |
| `belongs5_Name_chn` | varchar(255) | YES | NULL | Level-5 parent Chinese name |

**索引**:

- `c_addr_id_ADDRESSES_index`: (c_addr_id)

---

### ADDR_BELONGS_DATA 

**主鍵**: `c_addr_id`, `c_belongs_to`, `c_firstyear`, `c_lastyear`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_addr_id` | int(11) | NO | (NULL) |  |
| `c_belongs_to` | int(11) | NO | (NULL) |  |
| `c_firstyear` | smallint(6) | NO | (NULL) |  |
| `c_lastyear` | smallint(6) | NO | (NULL) |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_addr_id, c_belongs_to, c_firstyear, c_lastyear)
- `c_addr_id_ADDR_BELONGS_DATA_index`: (c_addr_id)
- `c_belongs_to`: (c_belongs_to)
- `c_source`: (c_source)

---

### ADDR_CODES 

**主鍵**: `c_addr_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_addr_id` | int(11) | NO | (NULL) |  |
| `c_name` | varchar(255) | YES | NULL |  |
| `c_name_chn` | varchar(255) | YES | NULL |  |
| `c_firstyear` | smallint(6) | YES | NULL |  |
| `c_lastyear` | smallint(6) | YES | NULL |  |
| `c_admin_type` | varchar(255) | YES | NULL |  |
| `c_admin_cat_code` | smallint(6) | NO | 0 |  |
| `x_coord` | double | YES | NULL |  |
| `y_coord` | double | YES | NULL |  |
| `CHGIS_PT_ID` | int(11) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_alt_names` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_addr_id)
- `c_addr_id_ADDR_CODES_index`: (c_addr_id)
- `fk_addr_codes_admin_cat_code`: (c_admin_cat_code)

---

### ADMIN_CAT_CODES 

**主鍵**: `c_admin_cat_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_admin_cat_code` | smallint(6) | NO | (NULL) |  |
| `c_admin_cat_py` | varchar(255) | YES | NULL |  |
| `c_admin_cat_hz` | varchar(255) | YES | NULL |  |
| `c_admin_cat_trans` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_admin_cat_code)

---

### ADMIN_CAT_CODE_TYPE_REL 

**主鍵**: `c_admin_cat_code`, `c_admin_cat_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_admin_cat_code` | smallint(6) | NO | (NULL) |  |
| `c_admin_cat_type_code` | varchar(255) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_admin_cat_code, c_admin_cat_type_code)
- `fk_admin_cat_type_code`: (c_admin_cat_type_code)

---

### ADMIN_CAT_TYPES 

**主鍵**: `c_admin_cat_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_admin_cat_type_code` | varchar(255) | NO | (NULL) |  |
| `c_admin_cat_type_hz` | varchar(255) | YES | NULL |  |
| `c_admin_cat_type_trans` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_admin_cat_type_code)

---

### ai_fill_logs 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | bigint(20) unsigned | NO | (NULL) |  [AUTO_INCREMENT] |
| `user_id` | bigint(20) unsigned | NO | (NULL) | 執行填充的用戶 ID |
| `c_personid` | int(11) | NO | (NULL) | 目標人物 ID |
| `category` | varchar(20) | NO | 'posting' |  |
| `route_name` | varchar(255) | NO | (NULL) | 路由名稱 |
| `route_url` | varchar(500) | NO | (NULL) | 頁面 URL 路徑 |
| `source_text` | text | NO | (NULL) | 用戶輸入的原始史料文本 |
| `ai_raw` | longtext | YES | NULL | AI 原始 JSON 回應（匹配前） |
| `ai_matched` | longtext | YES | NULL | AI 匹配後完整結果 JSON |
| `user_submitted` | longtext | YES | NULL | 用戶實際提交的表單數據 JSON |
| `success` | tinyint(1) | NO | 0 | AI 提取是否成功 |
| `error_message` | varchar(500) | YES | NULL | 錯誤訊息 |
| `execution_time_ms` | int(11) | YES | NULL | AI 處理耗時（毫秒） |
| `submitted_at` | timestamp | YES | NULL | 用戶提交表單的時間 |
| `created_at` | timestamp | YES | NULL |  |
| `updated_at` | timestamp | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (id)
- `ai_fill_logs_user_id_index`: (user_id)
- `ai_fill_logs_c_personid_index`: (c_personid)
- `ai_fill_logs_success_index`: (success)
- `ai_fill_logs_created_at_index`: (created_at)
- `ai_fill_logs_category_index`: (category)

---

### ALTNAME_CODES 

**主鍵**: `c_name_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_name_type_code` | smallint(6) | NO | (NULL) |  |
| `c_name_type_desc` | varchar(255) | YES | NULL |  |
| `c_name_type_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_name_type_code)
- `c_name_type_code_ALTNAME_CODES_index`: (c_name_type_code)

---

### ALTNAME_DATA 

**主鍵**: `c_personid`, `c_alt_name_chn`, `c_alt_name_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_alt_name` | varchar(255) | YES | NULL |  |
| `c_alt_name_chn` | varchar(255) | NO | (NULL) |  |
| `c_alt_name_type_code` | smallint(6) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | YES | 0 |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_alt_name_chn, c_alt_name_type_code, c_personid)
- `c_personid_ALTNAME_DATA_index`: (c_personid)
- `c_alt_name_type_code_ALTNAME_DATA_index`: (c_alt_name_type_code)
- `c_source`: (c_source)
- `idx_altname_code_source`: (c_alt_name_type_code, c_source)
- `idx_altname_person_seq`: (c_personid, c_sequence)

---

### APPOINTMENT_CODES 

**主鍵**: `c_appt_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_appt_code` | smallint(6) | NO | (NULL) |  |
| `c_appt_desc_chn` | varchar(255) | YES | NULL |  |
| `c_appt_desc` | varchar(255) | YES | NULL |  |
| `c_appt_desc_chn_alt` | varchar(255) | YES | NULL |  |
| `c_appt_desc_alt` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_appt_code)
- `c_appt_type_code_APPOINTMENT_TYPE_CODES_index`: (c_appt_code)

---

### APPOINTMENT_CODE_TYPE_REL 

**主鍵**: `c_appt_type_code`, `c_appt_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_appt_type_code` | varchar(255) | NO | (NULL) |  |
| `c_appt_code` | smallint(6) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_appt_code, c_appt_type_code)
- `c_appt_type_code`: (c_appt_type_code)

---

### APPOINTMENT_TYPES 

**主鍵**: `c_appt_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_appt_type_code` | varchar(255) | NO | (NULL) |  |
| `c_appt_type_desc` | varchar(255) | YES | NULL |  |
| `c_appt_type_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_appt_type_code)

---

### ASSOC_CODES 

**主鍵**: `c_assoc_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assoc_code` | smallint(6) | NO | (NULL) |  |
| `c_assoc_pair` | smallint(6) | YES | NULL |  |
| `c_assoc_pair2` | smallint(6) | YES | NULL |  |
| `c_assoc_desc` | varchar(255) | YES | NULL |  |
| `c_assoc_desc_chn` | varchar(255) | YES | NULL |  |
| `c_assoc_role_type` | varchar(255) | YES | NULL |  |
| `c_sortorder` | smallint(6) | YES | NULL |  |
| `c_example` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_assoc_code)
- `c_assoc_code_ASSOC_CODES_index`: (c_assoc_code)
- `c_assoc_pair2`: (c_assoc_pair2)

---

### ASSOC_CODE_TYPE_REL 

**主鍵**: `c_assoc_code`, `c_assoc_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assoc_code` | smallint(6) | NO | (NULL) |  |
| `c_assoc_type_code` | varchar(255) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_assoc_code, c_assoc_type_code)
- `c_assoc_code_ASSOC_CODE_TYPE_REL_index`: (c_assoc_code)
- `c_assoc_type_id_ASSOC_CODE_TYPE_REL_index`: (c_assoc_type_code)
- `c_assoc_type_id`: (c_assoc_type_code)

---

### ASSOC_DATA 

**主鍵**: `c_assoc_code`, `c_personid`, `c_kin_code`, `c_kin_id`, `c_assoc_id`, `c_assoc_kin_code`, `c_assoc_kin_id`, `c_assoc_first_year`, `c_text_title`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assoc_code` | smallint(6) | NO | (NULL) |  |
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_kin_code` | smallint(6) | NO | (NULL) |  |
| `c_kin_id` | int(11) | NO | (NULL) |  |
| `c_assoc_id` | int(11) | NO | (NULL) |  |
| `c_assoc_kin_code` | smallint(6) | NO | (NULL) |  |
| `c_assoc_kin_id` | int(11) | NO | (NULL) |  |
| `c_tertiary_personid` | int(11) | YES | NULL |  |
| `c_tertiary_type_notes` | longtext | YES | NULL |  |
| `c_assoc_count` | smallint(6) | NO | 1 |  |
| `c_sequence` | smallint(6) | YES | 0 |  |
| `c_assoc_first_year` | smallint(6) | NO | -9999 |  |
| `c_assoc_last_year` | smallint(6) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_assoc_fy_nh_code` | smallint(6) | YES | NULL |  |
| `c_assoc_fy_nh_year` | smallint(6) | YES | NULL |  |
| `c_assoc_fy_range` | smallint(6) | YES | NULL |  |
| `c_assoc_ly_nh_code` | smallint(6) | YES | NULL |  |
| `c_assoc_ly_nh_year` | smallint(6) | YES | NULL |  |
| `c_assoc_ly_range` | smallint(6) | YES | NULL |  |
| `c_addr_id` | int(11) | YES | NULL |  |
| `c_litgenre_code` | smallint(6) | YES | NULL |  |
| `c_occasion_code` | smallint(6) | YES | NULL |  |
| `c_topic_code` | smallint(6) | YES | NULL |  |
| `c_inst_code` | smallint(6) | YES | 0 |  |
| `c_inst_name_code` | smallint(6) | YES | 0 |  |
| `c_text_title` | varchar(255) | NO | '' |  |
| `c_assoc_claimer_id` | int(11) | YES | NULL |  |
| `c_assoc_fy_intercalary` | smallint(6) | YES | NULL |  |
| `c_assoc_fy_month` | smallint(6) | YES | NULL |  |
| `c_assoc_fy_day` | smallint(6) | YES | NULL |  |
| `c_assoc_fy_day_gz` | smallint(6) | YES | NULL |  |
| `c_assoc_ly_intercalary` | smallint(6) | YES | NULL |  |
| `c_assoc_ly_month` | smallint(6) | YES | NULL |  |
| `c_assoc_ly_day` | smallint(6) | YES | NULL |  |
| `c_assoc_ly_day_gz` | smallint(6) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_assoc_code, c_assoc_id, c_assoc_kin_code, c_assoc_kin_id, c_kin_code, c_kin_id, c_personid, c_text_title, c_assoc_first_year)
- `c_assoc_code_ASSOC_DATA_index`: (c_assoc_code)
- `c_personid_ASSOC_DATA_index`: (c_personid)
- `c_kin_code_ASSOC_DATA_index`: (c_kin_code)
- `c_kin_id_ASSOC_DATA_index`: (c_kin_id)
- `c_assoc_id_ASSOC_DATA_index`: (c_assoc_id)
- `c_assoc_kin_code_ASSOC_DATA_index`: (c_assoc_kin_code)
- `c_assoc_kin_id_ASSOC_DATA_index`: (c_assoc_kin_id)
- `c_tertiary_personid_ASSOC_DATA_index`: (c_tertiary_personid)
- `c_assoc_nh_code_ASSOC_DATA_index`: (c_assoc_fy_nh_code)
- `c_addr_id_ASSOC_DATA_index`: (c_addr_id)
- `c_litgenre_code_ASSOC_DATA_index`: (c_litgenre_code)
- `c_occasion_code_ASSOC_DATA_index`: (c_occasion_code)
- `c_topic_code_ASSOC_DATA_index`: (c_topic_code)
- `c_inst_code_ASSOC_DATA_index`: (c_inst_code)
- `c_inst_name_code_ASSOC_DATA_index`: (c_inst_name_code)
- `c_assoc_claimer_id_ASSOC_DATA_index`: (c_assoc_claimer_id)
- `c_assoc_day_gz`: (c_assoc_fy_day_gz)
- `c_assoc_range`: (c_assoc_fy_range)
- `c_source`: (c_source)
- `idx_assoc_person_seq`: (c_personid, c_sequence)

---

### ASSOC_TYPES 

**主鍵**: `c_assoc_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assoc_type_code` | varchar(255) | NO | (NULL) |  |
| `c_assoc_type_desc` | varchar(255) | YES | NULL |  |
| `c_assoc_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_assoc_type_parent_id` | varchar(255) | YES | NULL |  |
| `c_assoc_type_level` | smallint(6) | YES | NULL |  |
| `c_assoc_type_sortorder` | smallint(6) | YES | NULL |  |
| `c_assoc_type_short_desc` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_assoc_type_code)
- `c_assoc_type_id_ASSOC_TYPES_index`: (c_assoc_type_code)
- `c_assoc_type_parent_id_ASSOC_TYPES_index`: (c_assoc_type_parent_id)

---

### ASSUME_OFFICE_CODES 

**主鍵**: `c_assume_office_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assume_office_code` | smallint(6) | NO | (NULL) |  |
| `c_assume_office_desc_chn` | varchar(255) | YES | NULL |  |
| `c_assume_office_desc` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_assume_office_code)
- `c_assume_office_code_ASSUME_OFFICE_CODES_index`: (c_assume_office_code)

---

### audit_log 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | bigint(20) unsigned | NO | (NULL) |  [AUTO_INCREMENT] |
| `occurred_at` | datetime | NO | (NULL) | When the operation actually occurred |
| `created_at` | datetime | NO | (NULL) | When the audit log was written |
| `table_name` | varchar(64) | NO | (NULL) | Target business table |
| `operation` | varchar(16) | NO | (NULL) | INSERT/UPDATE/DELETE |
| `actor_type` | varchar(32) | NO | (NULL) | user/system/job/api_key |
| `actor_id` | varchar(128) | NO | (NULL) | Actor identifier in business layer |
| `operation_id` | char(26) | NO | (NULL) | Unique identifier of the operation |
| `row_pk` | longtext | NO | (NULL) | Primary key (supports composite key) |
| `row_pk_text` | varchar(512) | NO | (NULL) | Stable serialized primary key |
| `old_data` | longtext | YES | NULL | Full row before change |
| `new_data` | longtext | YES | NULL | Full row after change |

**索引**:

- `PRIMARY` (UNIQUE): (id)

---

### BIOG_ADDR_CODES 

**主鍵**: `c_addr_type`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_addr_type` | smallint(6) | NO | (NULL) |  |
| `c_addr_desc` | varchar(255) | YES | NULL |  |
| `c_addr_desc_chn` | varchar(255) | YES | NULL |  |
| `c_addr_note` | varchar(255) | YES | NULL |  |
| `c_index_addr_rank` | smallint(6) | YES | NULL |  |
| `c_index_addr_default_rank` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_addr_type)

---

### BIOG_ADDR_DATA 

**主鍵**: `c_personid`, `c_addr_id`, `c_addr_type`, `c_sequence`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_addr_id` | int(11) | NO | 0 |  |
| `c_addr_type` | smallint(6) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | NO | (NULL) |  |
| `c_firstyear` | smallint(6) | YES | NULL |  |
| `c_lastyear` | smallint(6) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_fy_nh_code` | smallint(6) | YES | NULL |  |
| `c_ly_nh_code` | smallint(6) | YES | NULL |  |
| `c_fy_nh_year` | smallint(6) | YES | NULL |  |
| `c_ly_nh_year` | smallint(6) | YES | NULL |  |
| `c_fy_range` | smallint(6) | YES | NULL |  |
| `c_ly_range` | smallint(6) | YES | NULL |  |
| `c_natal` | int(11) | YES | NULL |  |
| `c_fy_intercalary` | smallint(6) | YES | NULL |  |
| `c_ly_intercalary` | smallint(6) | YES | NULL |  |
| `c_fy_month` | smallint(6) | YES | NULL |  |
| `c_ly_month` | smallint(6) | YES | NULL |  |
| `c_fy_day` | smallint(6) | YES | NULL |  |
| `c_ly_day` | smallint(6) | YES | NULL |  |
| `c_fy_day_gz` | smallint(6) | YES | NULL |  |
| `c_ly_day_gz` | smallint(6) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_delete` | smallint(6) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_personid, c_addr_id, c_addr_type, c_sequence)
- `c_personid_BIOG_ADDR_DATA_index`: (c_personid)
- `c_addr_id_BIOG_ADDR_DATA_index`: (c_addr_id)
- `c_fy_nh_code_BIOG_ADDR_DATA_index`: (c_fy_nh_code)
- `c_ly_nh_code_BIOG_ADDR_DATA_index`: (c_ly_nh_code)
- `c_addr_type`: (c_addr_type)
- `c_fy_day_gz`: (c_fy_day_gz)
- `c_fy_range`: (c_fy_range)
- `c_ly_day_gz`: (c_ly_day_gz)
- `c_ly_range`: (c_ly_range)
- `c_source`: (c_source)
- `idx_biog_addr_person_seq`: (c_personid, c_sequence)

---

### BIOG_INST_CODES 

**主鍵**: `c_bi_role_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_bi_role_code` | smallint(6) | NO | (NULL) |  |
| `c_bi_role_desc` | varchar(255) | YES | NULL |  |
| `c_bi_role_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_bi_role_code)
- `c_bi_role_code_BIOG_INST_CODES_index`: (c_bi_role_code)

---

### BIOG_INST_DATA 

**主鍵**: `c_personid`, `c_inst_name_code`, `c_inst_code`, `c_bi_role_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_inst_name_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_code` | smallint(6) | NO | (NULL) |  |
| `c_bi_role_code` | smallint(6) | NO | (NULL) |  |
| `c_bi_begin_year` | smallint(6) | YES | NULL |  |
| `c_bi_by_nh_code` | smallint(6) | YES | NULL |  |
| `c_bi_by_nh_year` | smallint(6) | YES | NULL |  |
| `c_bi_by_range` | smallint(6) | YES | NULL |  |
| `c_bi_end_year` | smallint(6) | YES | NULL |  |
| `c_bi_ey_nh_code` | smallint(6) | YES | NULL |  |
| `c_bi_ey_nh_year` | smallint(6) | YES | NULL |  |
| `c_bi_ey_range` | smallint(6) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_bi_role_code, c_inst_code, c_inst_name_code, c_personid)
- `c_personid_BIOG_INST_DATA_index`: (c_personid)
- `c_inst_name_code_BIOG_INST_DATA_index`: (c_inst_name_code)
- `c_inst_code_BIOG_INST_DATA_index`: (c_inst_code)
- `c_bi_role_code_BIOG_INST_DATA_index`: (c_bi_role_code)
- `c_bi_by_nh_code_BIOG_INST_DATA_index`: (c_bi_by_nh_code)
- `c_bi_ey_nh_code_BIOG_INST_DATA_index`: (c_bi_ey_nh_code)
- `c_bi_by_range`: (c_bi_by_range)
- `c_bi_ey_range`: (c_bi_ey_range)
- `c_source`: (c_source)
- `idx_biog_inst_person_instcode`: (c_personid, c_inst_name_code, c_inst_code)

---

### BIOG_MAIN 

**主鍵**: `c_personid`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_name` | varchar(255) | YES | NULL | Hanyu Pinyin full name; auto-generated: c_surname + " " + c_mingzi |
| `c_name_chn` | varchar(255) | YES | NULL | Chinese full name; auto-generated: c_surname_chn + c_mingzi_chn (no space) |
| `c_index_year` | smallint(6) | YES | NULL |  |
| `c_index_year_type_code` | varchar(255) | YES | NULL |  |
| `c_index_year_source_id` | int(11) | YES | NULL |  |
| `c_female` | smallint(6) | YES | NULL |  |
| `c_index_addr_id` | int(11) | YES | 0 |  |
| `c_index_addr_type_code` | smallint(6) | YES | NULL |  |
| `c_ethnicity_code` | smallint(6) | YES | NULL |  |
| `c_household_status_code` | smallint(6) | YES | NULL |  |
| `c_tribe` | varchar(255) | YES | NULL |  |
| `c_birthyear` | smallint(6) | YES | NULL |  |
| `c_by_nh_code` | smallint(6) | YES | NULL |  |
| `c_by_nh_year` | smallint(6) | YES | NULL |  |
| `c_by_range` | smallint(6) | YES | NULL |  |
| `c_deathyear` | smallint(6) | YES | NULL |  |
| `c_dy_nh_code` | smallint(6) | YES | NULL |  |
| `c_dy_nh_year` | smallint(6) | YES | NULL |  |
| `c_dy_range` | smallint(6) | YES | NULL |  |
| `c_death_age` | smallint(6) | YES | NULL |  |
| `c_death_age_range` | smallint(6) | YES | NULL |  |
| `c_fl_earliest_year` | smallint(6) | YES | NULL |  |
| `c_fl_ey_nh_code` | smallint(6) | YES | NULL |  |
| `c_fl_ey_nh_year` | smallint(6) | YES | NULL |  |
| `c_fl_ey_notes` | longtext | YES | NULL |  |
| `c_fl_latest_year` | smallint(6) | YES | NULL |  |
| `c_fl_ly_nh_code` | smallint(6) | YES | NULL |  |
| `c_fl_ly_nh_year` | smallint(6) | YES | NULL |  |
| `c_fl_ly_notes` | longtext | YES | NULL |  |
| `c_surname` | varchar(255) | YES | NULL | Hanyu Pinyin romanization of the person's surname; auto-generated from c_surname_chn via pinyin lookup table |
| `c_surname_chn` | varchar(255) | YES | NULL | Chinese surname; split from c_name_chn by matching longest known surname in pinyin table |
| `c_mingzi` | varchar(255) | YES | NULL | Hanyu Pinyin romanization of the person's given name (excluding surname); auto-generated from c_mingzi_chn |
| `c_mingzi_chn` | varchar(255) | YES | NULL | Chinese given name (excluding surname); remainder of c_name_chn after surname extraction |
| `c_dy` | smallint(6) | YES | NULL |  |
| `c_choronym_code` | smallint(6) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_by_intercalary` | smallint(6) | YES | NULL |  |
| `c_dy_intercalary` | smallint(6) | YES | NULL |  |
| `c_by_month` | smallint(6) | YES | NULL |  |
| `c_dy_month` | smallint(6) | YES | NULL |  |
| `c_by_day` | smallint(6) | YES | NULL |  |
| `c_dy_day` | smallint(6) | YES | NULL |  |
| `c_by_day_gz` | smallint(6) | YES | NULL |  |
| `c_dy_day_gz` | smallint(6) | YES | NULL |  |
| `c_surname_proper` | varchar(255) | YES | NULL | Surname in the person's native language (non-Chinese), if applicable; user-editable |
| `c_mingzi_proper` | varchar(255) | YES | NULL | Given name in the person's native language (non-Chinese, excluding surname), if applicable; user-editable |
| `c_name_proper` | varchar(255) | YES | NULL | Full name in the person's native language; auto-generated: c_mingzi_proper + " " + c_surname_proper (given-name-first order) |
| `c_surname_rm` | varchar(255) | YES | NULL | Non-Pinyin romanization of the person's surname (e.g. Wade-Giles, McCune-Reischauer), if applicable; user-editable |
| `c_mingzi_rm` | varchar(255) | YES | NULL | Non-Pinyin romanization of the person's given name (excluding surname), if applicable; user-editable |
| `c_name_rm` | varchar(255) | YES | NULL | Non-Pinyin romanized full name; auto-generated: c_mingzi_rm + " " + c_surname_rm (given-name-first order) |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_personid)
- `c_personid_BIOG_MAIN_index`: (c_personid)
- `c_ethnicity_code_BIOG_MAIN_index`: (c_ethnicity_code)
- `c_household_status_code_BIOG_MAIN_index`: (c_household_status_code)
- `c_by_nh_code_BIOG_MAIN_index`: (c_by_nh_code)
- `c_dy_nh_code_BIOG_MAIN_index`: (c_dy_nh_code)
- `c_fl_ey_nh_code_BIOG_MAIN_index`: (c_fl_ey_nh_code)
- `c_fl_ly_nh_code_BIOG_MAIN_index`: (c_fl_ly_nh_code)
- `c_choronym_code_BIOG_MAIN_index`: (c_choronym_code)
- `c_by_day_gz`: (c_by_day_gz)
- `c_by_range`: (c_by_range)
- `c_death_age_range`: (c_death_age_range)
- `c_dy`: (c_dy)
- `c_dy_day_gz`: (c_dy_day_gz)
- `c_dy_range`: (c_dy_range)
- `BIOG_MAIN_ibfk_14`: (c_index_year_source_id)

---

### BIOG_SOURCE_DATA 

**主鍵**: `c_personid`, `c_textid`, `c_pages`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_textid` | int(11) | NO | (NULL) |  |
| `c_pages` | varchar(255) | NO | (NULL) |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_main_source` | smallint(6) | YES | NULL |  |
| `c_self_bio` | smallint(6) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_pages, c_personid, c_textid)
- `c_personid_BIOG_SOURCE_DATA_index`: (c_personid)
- `c_textid_BIOG_SOURCE_DATA_index`: (c_textid)
- `idx_biog_source_person_text`: (c_personid, c_textid)

---

### BIOG_TEXT_DATA 

**主鍵**: `c_textid`, `c_personid`, `c_role_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_textid` | int(11) | NO | (NULL) |  |
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_role_id` | smallint(6) | NO | (NULL) |  |
| `c_year` | smallint(6) | YES | NULL |  |
| `c_nh_code` | smallint(6) | YES | NULL |  |
| `c_nh_year` | smallint(6) | YES | NULL |  |
| `c_range_code` | smallint(6) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_personid, c_role_id, c_textid)
- `c_textid_TEXT_DATA_index`: (c_textid)
- `c_personid_TEXT_DATA_index`: (c_personid)
- `c_role_id_TEXT_DATA_index`: (c_role_id)
- `c_nh_code_TEXT_DATA_index`: (c_nh_code)
- `c_range_code_TEXT_DATA_index`: (c_range_code)
- `c_source`: (c_source)
- `idx_biog_text_person_text`: (c_personid, c_textid)

---

### CBDB__NAME_FTS 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | bigint(20) unsigned | NO | (NULL) |  [AUTO_INCREMENT] |
| `c_personid` | int(10) unsigned | NO | (NULL) |  |
| `name_type_code` | smallint(5) unsigned | YES | NULL |  |
| `name_type_desc` | varchar(32) | NO | (NULL) |  |
| `name_type_desc_chn` | varchar(32) | NO | (NULL) |  |
| `search_term` | varchar(100) | NO | (NULL) |  |
| `full_name` | varchar(100) | NO | (NULL) |  |
| `source` | varchar(32) | NO | (NULL) |  |
| `source_key` | varchar(255) | YES | NULL |  |
| `is_simplified` | tinyint(1) | NO | 0 |  |
| `created_at` | timestamp | YES | NULL |  |
| `updated_at` | timestamp | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (id)
- `idx_cbdb__name_search_term`: (search_term, c_personid)
- `idx_cbdb__name_person`: (c_personid)
- `idx_cbdb__name_type`: (name_type_code)

---

### CBDB__TRAD_SIMP_MAP 

**主鍵**: `trad_char`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `trad_char` | varbinary(4) | NO | (NULL) | 繁體字（UTF-8二進制） |
| `simp_char` | varbinary(4) | NO | (NULL) | 簡體字（UTF-8二進制） |

**索引**:

- `PRIMARY` (UNIQUE): (trad_char)

---

### CHORONYM_CODES 

**主鍵**: `c_choronym_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_choronym_code` | smallint(6) | NO | (NULL) |  |
| `c_choronym_desc` | varchar(255) | YES | NULL |  |
| `c_choronym_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_choronym_code)
- `c_choronym_code_CHORONYM_CODES_index`: (c_choronym_code)

---

### COPYMISSINGTABLES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `ID` | int(11) | YES | NULL |  |
| `TableName` | varchar(255) | YES | NULL |  |

---

### COPYTABLES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `TableName` | varchar(255) | YES | NULL |  |
| `NotProcessed` | smallint(6) | YES | NULL |  |

---

### COPYTABLESDEFAULT 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `ID` | int(11) | YES | NULL |  |
| `TableName` | varchar(255) | YES | NULL |  |

---

### COUNTRY_CODES 

**主鍵**: `c_country_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_country_code` | smallint(6) | NO | (NULL) |  |
| `c_country_desc` | varchar(255) | YES | NULL |  |
| `c_country_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_country_code)
- `c_country_code_COUNTRY_CODES_index`: (c_country_code)

---

### DYNASTIES 

**主鍵**: `c_dy`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_dy` | smallint(6) | NO | (NULL) |  |
| `c_dynasty` | varchar(255) | YES | NULL |  |
| `c_dynasty_chn` | varchar(255) | YES | NULL |  |
| `c_start` | smallint(6) | NO | 0 |  |
| `c_end` | smallint(6) | NO | 0 |  |
| `c_sort` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_dy)

---

### ENTRY_CODES 

**主鍵**: `c_entry_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_entry_code` | smallint(6) | NO | (NULL) |  |
| `c_entry_desc` | varchar(255) | NO | '' |  |
| `c_entry_desc_chn` | varchar(255) | NO | '' |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_entry_code)
- `c_entry_code_ENTRY_CODES_index`: (c_entry_code)

---

### ENTRY_CODE_TYPE_REL 

**主鍵**: `c_entry_code`, `c_entry_type`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_entry_code` | smallint(6) | NO | (NULL) |  |
| `c_entry_type` | varchar(255) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_entry_code, c_entry_type)
- `c_entry_code_ENTRY_CODE_TYPE_REL_index`: (c_entry_code)
- `c_entry_type`: (c_entry_type)

---

### ENTRY_DATA 

**主鍵**: `c_personid`, `c_entry_code`, `c_sequence`, `c_kin_code`, `c_kin_id`, `c_assoc_code`, `c_assoc_id`, `c_year`, `c_inst_code`, `c_inst_name_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_entry_code` | smallint(6) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | NO | (NULL) |  |
| `c_exam_rank` | varchar(255) | YES | NULL |  |
| `c_kin_code` | smallint(6) | NO | (NULL) |  |
| `c_kin_id` | int(11) | NO | (NULL) |  |
| `c_assoc_code` | smallint(6) | NO | (NULL) |  |
| `c_assoc_id` | int(11) | NO | (NULL) |  |
| `c_year` | smallint(6) | NO | (NULL) |  |
| `c_age` | smallint(6) | YES | NULL |  |
| `c_entry_nh_id` | smallint(6) | YES | NULL |  |
| `c_entry_nh_year` | smallint(6) | YES | NULL |  |
| `c_entry_dy` | smallint(6) | YES | NULL |  |
| `c_entry_range` | smallint(6) | YES | NULL |  |
| `c_inst_code` | smallint(6) | NO | 0 |  |
| `c_inst_name_code` | smallint(6) | NO | 0 |  |
| `c_exam_field` | varchar(255) | YES | NULL |  |
| `c_entry_addr_id` | int(11) | YES | NULL |  |
| `c_parental_status_code` | smallint(6) | YES | NULL |  |
| `c_attempt_count` | smallint(6) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_posting_notes` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_assoc_code, c_assoc_id, c_entry_code, c_inst_code, c_inst_name_code, c_kin_code, c_kin_id, c_personid, c_sequence, c_year)
- `c_personid_ENTRY_DATA_index`: (c_personid)
- `c_entry_code_ENTRY_DATA_index`: (c_entry_code)
- `c_kin_code_ENTRY_DATA_index`: (c_kin_code)
- `c_kin_id_ENTRY_DATA_index`: (c_kin_id)
- `c_assoc_code_ENTRY_DATA_index`: (c_assoc_code)
- `c_assoc_id_ENTRY_DATA_index`: (c_assoc_id)
- `c_inst_code_ENTRY_DATA_index`: (c_inst_code)
- `c_inst_name_code_ENTRY_DATA_index`: (c_inst_name_code)
- `c_entry_addr_id_ENTRY_DATA_index`: (c_entry_addr_id)
- `c_entry_range`: (c_entry_range)
- `c_source`: (c_source)
- `c_entry_nh_id_ENTRY_DATA_index`: (c_entry_nh_id)
- `c_parental_status_code_ENTRY_DATA_index`: (c_parental_status_code)
- `c_entry_dy_ENTRY_DATA_index`: (c_entry_dy)

---

### ENTRY_TYPES 

**主鍵**: `c_entry_type`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_entry_type` | varchar(255) | NO | (NULL) |  |
| `c_entry_type_desc` | varchar(255) | NO | '' |  |
| `c_entry_type_desc_chn` | varchar(255) | NO | '' |  |
| `c_entry_type_parent_id` | varchar(255) | YES | NULL |  |
| `c_entry_type_level` | smallint(6) | YES | NULL |  |
| `c_entry_type_sortorder` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_entry_type)
- `c_entry_type_parent_id_ENTRY_TYPES_index`: (c_entry_type_parent_id)

---

### ETHNICITY_TRIBE_CODES 

**主鍵**: `c_ethnicity_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_ethnicity_code` | smallint(6) | NO | (NULL) |  |
| `c_group_code` | smallint(6) | YES | NULL |  |
| `c_subgroup_code` | smallint(6) | YES | NULL |  |
| `c_altname_code` | smallint(6) | YES | NULL |  |
| `c_name_chn` | varchar(255) | YES | NULL |  |
| `c_name` | varchar(255) | YES | NULL |  |
| `c_ethno_legal_cat` | varchar(255) | YES | NULL |  |
| `c_romanized` | varchar(255) | YES | NULL |  |
| `c_surname` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_sortorder` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_ethnicity_code)
- `c_ethnicity_code_ETHNICITY_TRIBE_CODES_index`: (c_ethnicity_code)
- `c_group_code_ETHNICITY_TRIBE_CODES_index`: (c_group_code)
- `c_subgroup_code_ETHNICITY_TRIBE_CODES_index`: (c_subgroup_code)
- `c_altname_code_ETHNICITY_TRIBE_CODES_index`: (c_altname_code)

---

### EVENTS_ADDR 

**主鍵**: `c_event_code`, `c_personid`, `c_sequence`, `c_addr_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_event_code` | smallint(6) | NO | 0 |  |
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | NO | 0 |  |
| `c_addr_id` | int(11) | NO | (NULL) |  |
| `c_year` | smallint(6) | YES | NULL |  |
| `c_nh_code` | smallint(6) | YES | NULL |  |
| `c_nh_year` | smallint(6) | YES | NULL |  |
| `c_yr_range` | smallint(6) | YES | NULL |  |
| `c_intercalary` | smallint(6) | YES | NULL |  |
| `c_month` | smallint(6) | YES | NULL |  |
| `c_day` | smallint(6) | YES | NULL |  |
| `c_day_ganzhi` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_addr_id, c_personid, c_sequence, c_event_code)
- `c_personid_EVENTS_ADDR_index`: (c_personid)
- `c_addr_id_EVENTS_ADDR_index`: (c_addr_id)
- `c_nh_code_EVENTS_ADDR_index`: (c_nh_code)
- `c_day_ganzhi`: (c_day_ganzhi)
- `c_yr_range`: (c_yr_range)
- `c_sequence_EVENTS_ADDR_index`: (c_sequence)
- `c_event_code_EVENTS_ADDR_index`: (c_event_code)

---

### EVENTS_DATA 

**主鍵**: `c_personid`, `c_sequence`, `c_event_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | NO | 0 |  |
| `c_event_code` | smallint(6) | NO | (NULL) |  |
| `c_role` | varchar(255) | YES | NULL |  |
| `c_year` | smallint(6) | YES | NULL |  |
| `c_nh_code` | smallint(6) | YES | NULL |  |
| `c_nh_year` | smallint(6) | YES | NULL |  |
| `c_yr_range` | smallint(6) | YES | NULL |  |
| `c_intercalary` | smallint(6) | YES | NULL |  |
| `c_month` | smallint(6) | YES | NULL |  |
| `c_day` | smallint(6) | YES | NULL |  |
| `c_day_ganzhi` | smallint(6) | YES | NULL |  |
| `c_addr_id` | int(11) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_event` | longtext | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_personid, c_sequence, c_event_code)
- `c_personid_EVENTS_DATA_index`: (c_personid)
- `c_event_code_EVENTS_DATA_index`: (c_event_code)
- `c_nh_code_EVENTS_DATA_index`: (c_nh_code)
- `c_addr_id_EVENTS_DATA_index`: (c_addr_id)
- `c_day_ganzhi`: (c_day_ganzhi)
- `c_source`: (c_source)
- `c_yr_range`: (c_yr_range)

---

### EVENT_CODES 

**主鍵**: `c_event_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_event_code` | smallint(6) | NO | (NULL) |  |
| `c_event_name_chn` | varchar(255) | YES | NULL |  |
| `c_event_name` | varchar(255) | YES | NULL |  |
| `c_fy_yr` | smallint(6) | YES | NULL |  |
| `c_ly_yr` | smallint(6) | YES | NULL |  |
| `c_fy_nh_code` | smallint(6) | YES | NULL |  |
| `c_ly_nh_code` | smallint(6) | YES | NULL |  |
| `c_fy_nh_yr` | smallint(6) | YES | NULL |  |
| `c_ly_nh_yr` | smallint(6) | YES | NULL |  |
| `c_fy_intercalary` | smallint(6) | YES | NULL |  |
| `c_fy_month` | smallint(6) | YES | NULL |  |
| `c_ly_intercalary` | smallint(6) | YES | NULL |  |
| `c_ly_month` | smallint(6) | YES | NULL |  |
| `c_fy_range` | smallint(6) | YES | NULL |  |
| `c_ly_range` | smallint(6) | YES | NULL |  |
| `c_addr_id` | int(11) | YES | NULL |  |
| `c_dy` | smallint(6) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_event_notes` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_event_code)
- `c_event_code_EVENT_CODES_index`: (c_event_code)
- `c_fy_nh_code_EVENT_CODES_index`: (c_fy_nh_code)
- `c_ly_nh_code_EVENT_CODES_index`: (c_ly_nh_code)
- `c_addr_id_EVENT_CODES_index`: (c_addr_id)
- `c_dy`: (c_dy)
- `c_fy_range`: (c_fy_range)
- `c_ly_range`: (c_ly_range)
- `c_source`: (c_source)

---

### EXTANT_CODES 

**主鍵**: `c_extant_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_extant_code` | smallint(6) | NO | (NULL) |  |
| `c_extant_desc` | varchar(255) | YES | NULL |  |
| `c_extant_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_extant_code)
- `c_extant_code_EXTANT_CODES_index`: (c_extant_code)

---

### FOREIGNKEYS 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `AccessTblNm` | varchar(255) | YES | NULL |  |
| `AccessFldNm` | varchar(255) | YES | NULL |  |
| `ForeignKey` | varchar(255) | YES | NULL |  |
| `ForeignKeyBaseField` | varchar(255) | YES | NULL |  |
| `FKString` | varchar(255) | YES | NULL |  |
| `FKName` | varchar(255) | YES | NULL |  |
| `skip` | smallint(6) | YES | NULL |  |
| `IndexOnField` | varchar(255) | YES | NULL |  |
| `DataFormat` | varchar(255) | YES | NULL |  |
| `NULL_allowed` | smallint(6) | YES | NULL |  |

---

### FORMLABELS 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_form` | varchar(255) | YES | NULL |  |
| `c_label_id` | smallint(6) | YES | NULL |  |
| `c_english` | varchar(255) | YES | NULL |  |
| `c_jianti` | varchar(255) | YES | NULL |  |
| `c_fanti` | varchar(255) | YES | NULL |  |

**索引**:

- `c_label_id_FormLabels_index`: (c_label_id)

---

### GANZHI_CODES 

**主鍵**: `c_ganzhi_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_ganzhi_code` | smallint(6) | NO | (NULL) |  |
| `c_ganzhi_chn` | varchar(255) | NO | '' |  |
| `c_ganzhi_py` | varchar(255) | NO | '' |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_ganzhi_code)
- `c_ganzhi_code_GANZHI_CODES_index`: (c_ganzhi_code)

---

### HOUSEHOLD_STATUS_CODES 

**主鍵**: `c_household_status_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_household_status_code` | smallint(6) | NO | (NULL) |  |
| `c_household_status_desc` | varchar(255) | NO | '' |  |
| `c_household_status_desc_chn` | varchar(255) | NO | '' |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_household_status_code)
- `c_household_status_code_HOUSEHOLD_STATUS_CODES_index`: (c_household_status_code)

---

### INDEXYEAR_TYPE_CODES 

**主鍵**: `c_index_year_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_index_year_type_code` | varchar(191) | NO | '' |  |
| `c_index_year_type_desc` | varchar(255) | NO | '' |  |
| `c_index_year_type_hz` | varchar(255) | NO | '' |  |
| `c_notes` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_index_year_type_code)

---

### KINSHIP_CODES 

**主鍵**: `c_kincode`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_kincode` | smallint(6) | NO | (NULL) |  |
| `c_kin_pair1` | smallint(6) | NO | 0 |  |
| `c_kin_pair2` | smallint(6) | NO | 0 |  |
| `c_kin_pair_notes` | varchar(255) | YES | NULL |  |
| `c_kinrel_chn` | varchar(255) | NO | '' |  |
| `c_kinrel` | varchar(255) | NO | '' |  |
| `c_kinrel_alt` | varchar(255) | YES | NULL |  |
| `c_pick_sorting` | smallint(6) | YES | NULL |  |
| `c_upstep` | smallint(6) | NO | 0 |  |
| `c_dwnstep` | smallint(6) | NO | 0 |  |
| `c_marstep` | smallint(6) | NO | 0 |  |
| `c_colstep` | smallint(6) | NO | 0 |  |
| `c_kinrel_simplified` | varchar(255) | NO | '' |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_kincode)
- `c_kincode_KINSHIP_CODES_index`: (c_kincode)
- `c_kin_pair1`: (c_kin_pair1)
- `c_kin_pair2`: (c_kin_pair2)

---

### KIN_DATA 

**主鍵**: `c_personid`, `c_kin_id`, `c_kin_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_kin_id` | int(11) | NO | (NULL) |  |
| `c_kin_code` | smallint(6) | NO | (NULL) |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_autogen_notes` | longtext | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_kin_code, c_kin_id, c_personid)
- `c_personid_KIN_DATA_index`: (c_personid)
- `c_kin_id_KIN_DATA_index`: (c_kin_id)
- `c_kin_code_KIN_DATA_index`: (c_kin_code)
- `c_source`: (c_source)

---

### KIN_MOURNING 

**主鍵**: `c_kinrel`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_kinrel` | varchar(255) | NO | (NULL) |  |
| `c_kinrel_alt` | varchar(255) | YES | NULL |  |
| `c_kinrel_chn` | varchar(255) | YES | NULL |  |
| `c_mourning` | varchar(255) | YES | NULL |  |
| `c_mourning_chn` | varchar(255) | YES | NULL |  |
| `c_kindist` | varchar(255) | YES | NULL |  |
| `c_kintype` | varchar(255) | YES | NULL |  |
| `c_kintype_desc` | varchar(255) | YES | NULL |  |
| `c_kintype_desc_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_kinrel)

---

### KIN_MOURNING_STEPS 

**主鍵**: `c_kinrel`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_kinrel` | varchar(255) | NO | (NULL) |  |
| `c_upstep` | smallint(6) | NO | 0 |  |
| `c_dwnstep` | smallint(6) | NO | 0 |  |
| `c_marstep` | smallint(6) | NO | 0 |  |
| `c_colstep` | smallint(6) | NO | 0 |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_kinrel)

---

### LITERARYGENRE_CODES 

**主鍵**: `c_lit_genre_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_lit_genre_code` | smallint(6) | NO | (NULL) |  |
| `c_lit_genre_desc` | varchar(255) | NO | '' |  |
| `c_lit_genre_desc_chn` | varchar(255) | NO | '' |  |
| `c_sortorder` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_lit_genre_code)
- `c_lit_genre_code_LITERARYGENRE_CODES_index`: (c_lit_genre_code)

---

### MEASURE_CODES 

**主鍵**: `c_measure_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_measure_code` | smallint(6) | NO | (NULL) |  |
| `c_measure_desc` | varchar(255) | YES | NULL |  |
| `c_measure_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_measure_code)
- `c_measure_code_MEASURE_CODES_index`: (c_measure_code)

---

### MERGED_PERSON_DATA 

**主鍵**: `c_personid`, `c_merged_from_personid`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_merged_from_personid` | int(11) | NO | (NULL) |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_personid, c_merged_from_personid)
- `idx_merged_to_personid`: (c_merged_from_personid)
- `idx_source`: (c_source)

---

### migrations 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | int(10) unsigned | NO | (NULL) |  [AUTO_INCREMENT] |
| `migration` | varchar(255) | NO | (NULL) |  |
| `batch` | int(11) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (id)

---

### NIAN_HAO 

**主鍵**: `c_nianhao_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_nianhao_id` | smallint(6) | NO | (NULL) |  |
| `c_dy` | smallint(6) | YES | NULL |  |
| `c_dynasty_chn` | varchar(255) | YES | NULL |  |
| `c_nianhao_chn` | varchar(255) | YES | NULL |  |
| `c_nianhao_pin` | varchar(255) | YES | NULL |  |
| `c_firstyear` | smallint(6) | YES | NULL |  |
| `c_lastyear` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_nianhao_id)
- `c_nianhao_id_NIAN_HAO_index`: (c_nianhao_id)
- `c_dy`: (c_dy)

---

### nl_query_logs 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | bigint(20) unsigned | NO | (NULL) |  [AUTO_INCREMENT] |
| `user_id` | bigint(20) unsigned | YES | NULL | 用户 ID |
| `question` | text | NO | (NULL) | 用户的自然语言问题 |
| `generated_sql` | text | YES | NULL | 生成的 SQL 查询 |
| `explanation` | text | YES | NULL | 查询解释 |
| `llm_prompt` | text | YES | NULL | 发送给 LLM 的完整提示词 |
| `llm_response` | text | YES | NULL | LLM 的原始响应 |
| `success` | tinyint(1) | NO | 0 | 是否成功生成 |
| `error_message` | varchar(500) | YES | NULL | 错误信息 |
| `execution_time_ms` | int(11) | YES | NULL | 执行时间（毫秒） |
| `created_at` | timestamp | YES | NULL |  |
| `updated_at` | timestamp | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (id)
- `nl_query_logs_user_id_index`: (user_id)
- `nl_query_logs_success_index`: (success)
- `nl_query_logs_created_at_index`: (created_at)

---

### OCCASION_CODES 

**主鍵**: `c_occasion_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_occasion_code` | smallint(6) | NO | (NULL) |  |
| `c_occasion_desc` | varchar(255) | YES | NULL |  |
| `c_occasion_desc_chn` | varchar(255) | YES | NULL |  |
| `c_sortorder` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_occasion_code)
- `c_occasion_code_OCCASION_CODES_index`: (c_occasion_code)

---

### OFFICE_CATEGORIES 

**主鍵**: `c_office_category_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_office_category_id` | smallint(6) | NO | (NULL) |  |
| `c_category_desc` | varchar(255) | YES | NULL |  |
| `c_category_desc_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_office_category_id)
- `c_office_category_id_OFFICE_CATEGORIES_index`: (c_office_category_id)

---

### OFFICE_CODES 

**主鍵**: `c_office_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_office_id` | int(11) | NO | (NULL) |  |
| `c_dy` | smallint(6) | NO | 0 |  |
| `c_office_pinyin` | varchar(255) | YES | NULL |  |
| `c_office_chn` | varchar(255) | YES | NULL |  |
| `c_office_pinyin_alt` | varchar(255) | YES | NULL |  |
| `c_office_chn_alt` | varchar(255) | YES | NULL |  |
| `c_office_trans` | varchar(255) | YES | NULL |  |
| `c_office_trans_alt` | varchar(255) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_category_1` | varchar(255) | YES | NULL |  |
| `c_category_2` | varchar(255) | YES | NULL |  |
| `c_category_3` | varchar(255) | YES | NULL |  |
| `c_category_4` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_office_id)
- `c_office_id_OFFICE_CODES_index`: (c_office_id)
- `c_dy`: (c_dy)
- `c_source`: (c_source)

---

### OFFICE_CODE_TYPE_REL 

**主鍵**: `c_office_id`, `c_office_tree_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_office_id` | int(11) | NO | (NULL) |  |
| `c_office_tree_id` | varchar(255) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_office_id, c_office_tree_id)
- `c_office_id_OFFICE_CODE_TYPE_REL_index`: (c_office_id)
- `c_office_tree_id_OFFICE_CODE_TYPE_REL_index`: (c_office_tree_id)
- `c_office_tree_id`: (c_office_tree_id)

---

### OFFICE_TYPE_TREE 

**主鍵**: `c_office_type_node_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_office_type_node_id` | varchar(255) | NO | (NULL) |  |
| `c_office_type_desc` | varchar(255) | YES | NULL |  |
| `c_office_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_parent_id` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_office_type_node_id)
- `c_office_type_node_id_OFFICE_TYPE_TREE_index`: (c_office_type_node_id)
- `c_parent_id_OFFICE_TYPE_TREE_index`: (c_parent_id)
- `c_parent_id`: (c_parent_id)

---

### operations 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | int(10) unsigned | NO | (NULL) |  [AUTO_INCREMENT] |
| `user_id` | int(11) | NO | (NULL) |  |
| `c_personid` | int(11) | NO | (NULL) |  |
| `op_type` | smallint(6) | NO | (NULL) | 1.Popst(Create) 2.Put(Update 全部信息) 3. Patch(Update 部分属性) 4.Delete(Delete) |
| `resource` | varchar(255) | NO | (NULL) |  |
| `resource_id` | varchar(255) | NO | '' |  |
| `resource_data` | longtext | NO | (NULL) |  |
| `resource_original` | longtext | YES | NULL |  |
| `created_at` | timestamp | YES | NULL |  |
| `updated_at` | timestamp | YES | NULL |  |
| `crowdsourcing_status` | smallint(6) | NO | 0 |  |
| `rate` | smallint(6) | NO | 0 |  |

**索引**:

- `PRIMARY` (UNIQUE): (id)
- `c_personid`: (c_personid)

---

### PARENTAL_STATUS_CODES 

**主鍵**: `c_parental_status_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_parental_status_code` | smallint(6) | NO | (NULL) |  |
| `c_parental_status_desc` | varchar(255) | YES | NULL |  |
| `c_parental_status_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_parental_status_code)
- `c_parental_status_code_PARENTAL_STATUS_CODES_index`: (c_parental_status_code)

---

### password_resets 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `email` | varchar(255) | NO | (NULL) |  |
| `token` | varchar(255) | NO | (NULL) |  |
| `created_at` | timestamp | YES | NULL |  |

**索引**:

- `password_resets_email_index`: (email)

---

### personal_access_tokens 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | bigint(20) unsigned | NO | (NULL) |  [AUTO_INCREMENT] |
| `tokenable_type` | varchar(255) | NO | (NULL) |  |
| `tokenable_id` | bigint(20) unsigned | NO | (NULL) |  |
| `name` | varchar(255) | NO | (NULL) |  |
| `token` | varchar(64) | NO | (NULL) |  |
| `abilities` | text | YES | NULL |  |
| `last_used_at` | timestamp | YES | NULL |  |
| `expires_at` | timestamp | YES | NULL |  |
| `created_at` | timestamp | YES | NULL |  |
| `updated_at` | timestamp | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (id)
- `personal_access_tokens_token_unique` (UNIQUE): (token)
- `personal_access_tokens_tokenable_type_tokenable_id_index`: (tokenable_type, tokenable_id)

---

### pinyin 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | int(11) | NO | (NULL) |  [AUTO_INCREMENT] |
| `lastname_chn` | varchar(10) | YES | NULL |  |
| `lastname_pinyin` | varchar(30) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (id)

---

### POSSESSION_ACT_CODES 

**主鍵**: `c_possession_act_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_possession_act_code` | smallint(6) | NO | (NULL) |  |
| `c_possession_act_desc` | varchar(255) | YES | NULL |  |
| `c_possession_act_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_possession_act_code)
- `c_possession_act_code_POSSESSION_ACT_CODES_index`: (c_possession_act_code)

---

### POSSESSION_ADDR 

**主鍵**: `c_possession_record_id`, `c_personid`, `c_addr_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_possession_record_id` | int(11) | NO | (NULL) |  |
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_addr_id` | int(11) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_addr_id, c_personid, c_possession_record_id)
- `c_possession_record_id_POSSESSION_ADDR_index`: (c_possession_record_id)
- `c_personid_POSSESSION_ADDR_index`: (c_personid)
- `c_addr_id_POSSESSION_ADDR_index`: (c_addr_id)

---

### POSSESSION_DATA 

**主鍵**: `c_possession_record_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | YES | NULL |  |
| `c_possession_record_id` | int(11) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | YES | NULL |  |
| `c_possession_act_code` | smallint(6) | YES | NULL |  |
| `c_possession_desc` | varchar(255) | YES | NULL |  |
| `c_possession_desc_chn` | varchar(255) | YES | NULL |  |
| `c_quantity` | varchar(255) | YES | NULL |  |
| `c_measure_code` | smallint(6) | YES | NULL |  |
| `c_possession_yr` | smallint(6) | YES | NULL |  |
| `c_possession_nh_code` | smallint(6) | YES | NULL |  |
| `c_possession_nh_yr` | smallint(6) | YES | NULL |  |
| `c_possession_yr_range` | smallint(6) | YES | NULL |  |
| `c_addr_id` | int(11) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_possession_record_id)
- `c_personid_POSSESSION_DATA_index`: (c_personid)
- `c_possession_record_id_POSSESSION_DATA_index`: (c_possession_record_id)
- `c_possession_act_code_POSSESSION_DATA_index`: (c_possession_act_code)
- `c_measure_code_POSSESSION_DATA_index`: (c_measure_code)
- `c_possession_nh_code_POSSESSION_DATA_index`: (c_possession_nh_code)
- `c_addr_id_POSSESSION_DATA_index`: (c_addr_id)
- `c_possession_yr_range`: (c_possession_yr_range)
- `c_source`: (c_source)
- `possession_data_personid_sequence_idx`: (c_personid, c_sequence)

---

### POSTED_TO_ADDR_DATA 

**主鍵**: `c_posting_id`, `c_office_id`, `c_addr_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_posting_id` | int(11) | NO | (NULL) |  |
| `c_personid` | int(11) | YES | NULL |  |
| `c_office_id` | int(11) | NO | (NULL) |  |
| `c_addr_id` | int(11) | NO | (NULL) |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_addr_id, c_office_id, c_posting_id)
- `c_posting_id_POSTED_TO_ADDR_DATA_index`: (c_posting_id)
- `c_personid_POSTED_TO_ADDR_DATA_index`: (c_personid)
- `c_office_id_POSTED_TO_ADDR_DATA_index`: (c_office_id)
- `c_addr_id_POSTED_TO_ADDR_DATA_index`: (c_addr_id)

---

### POSTED_TO_OFFICE_DATA 

**主鍵**: `c_office_id`, `c_posting_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | YES | NULL |  |
| `c_office_id` | int(11) | NO | (NULL) |  |
| `c_posting_id` | int(11) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | YES | NULL |  |
| `c_firstyear` | smallint(6) | YES | NULL |  |
| `c_fy_nh_code` | smallint(6) | YES | NULL |  |
| `c_fy_nh_year` | smallint(6) | YES | NULL |  |
| `c_fy_range` | smallint(6) | YES | NULL |  |
| `c_lastyear` | smallint(6) | YES | NULL |  |
| `c_ly_nh_code` | smallint(6) | YES | NULL |  |
| `c_ly_nh_year` | smallint(6) | YES | NULL |  |
| `c_ly_range` | smallint(6) | YES | NULL |  |
| `c_assume_office_code` | smallint(6) | YES | NULL |  |
| `c_inst_code` | smallint(6) | YES | 0 |  |
| `c_inst_name_code` | smallint(6) | YES | 0 |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_office_id_backup` | int(11) | YES | NULL |  |
| `c_office_category_id` | smallint(6) | YES | NULL |  |
| `c_fy_intercalary` | smallint(6) | YES | NULL |  |
| `c_fy_month` | smallint(6) | YES | NULL |  |
| `c_ly_intercalary` | smallint(6) | YES | NULL |  |
| `c_ly_month` | smallint(6) | YES | NULL |  |
| `c_fy_day` | smallint(6) | YES | NULL |  |
| `c_ly_day` | smallint(6) | YES | NULL |  |
| `c_fy_day_gz` | smallint(6) | YES | NULL |  |
| `c_ly_day_gz` | smallint(6) | YES | NULL |  |
| `c_dy` | smallint(6) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_appt_code` | smallint(6) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_office_id, c_posting_id)
- `c_personid_POSTED_TO_OFFICE_DATA_index`: (c_personid)
- `c_office_id_POSTED_TO_OFFICE_DATA_index`: (c_office_id)
- `c_posting_id_POSTED_TO_OFFICE_DATA_index`: (c_posting_id)
- `c_fy_nh_code_POSTED_TO_OFFICE_DATA_index`: (c_fy_nh_code)
- `c_ly_nh_code_POSTED_TO_OFFICE_DATA_index`: (c_ly_nh_code)
- `c_assume_office_code_POSTED_TO_OFFICE_DATA_index`: (c_assume_office_code)
- `c_inst_code_POSTED_TO_OFFICE_DATA_index`: (c_inst_code)
- `c_inst_name_code_POSTED_TO_OFFICE_DATA_index`: (c_inst_name_code)
- `c_office_id_backup_POSTED_TO_OFFICE_DATA_index`: (c_office_id_backup)
- `c_office_category_id_POSTED_TO_OFFICE_DATA_index`: (c_office_category_id)
- `c_dy`: (c_dy)
- `c_fy_day_gz`: (c_fy_day_gz)
- `c_fy_range`: (c_fy_range)
- `c_ly_day_gz`: (c_ly_day_gz)
- `c_ly_range`: (c_ly_range)
- `c_source`: (c_source)
- `c_appt_code_POSTED_TO_OFFICE_DATA_index`: (c_appt_code)

---

### POSTING_DATA 

**主鍵**: `c_posting_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | YES | NULL |  |
| `c_posting_id` | int(11) | NO | (NULL) |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_posting_id)
- `c_personid_POSTING_DATA_index`: (c_personid)
- `c_posting_id_POSTING_DATA_index`: (c_posting_id)

---

### SCHOLARLYTOPIC_CODES 

**主鍵**: `c_topic_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_topic_code` | smallint(6) | NO | (NULL) |  |
| `c_topic_desc` | varchar(255) | YES | NULL |  |
| `c_topic_desc_chn` | varchar(255) | YES | NULL |  |
| `c_topic_type_code` | smallint(6) | YES | NULL |  |
| `c_topic_type_desc` | varchar(255) | YES | NULL |  |
| `c_topic_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_sortorder` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_topic_code)
- `c_topic_code_SCHOLARLYTOPIC_CODES_index`: (c_topic_code)
- `c_topic_type_code_SCHOLARLYTOPIC_CODES_index`: (c_topic_type_code)

---

### SOCIAL_INSTITUTION_ADDR 

**主鍵**: `c_inst_name_code`, `c_inst_code`, `c_inst_addr_type_code`, `c_inst_addr_id`, `inst_xcoord`, `inst_ycoord`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_name_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_addr_type_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_addr_begin_year` | smallint(6) | YES | NULL |  |
| `c_inst_addr_end_year` | smallint(6) | YES | NULL |  |
| `c_inst_addr_id` | int(11) | NO | (NULL) |  |
| `inst_xcoord` | double | NO | (NULL) |  |
| `inst_ycoord` | double | NO | (NULL) |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_inst_addr_id, c_inst_addr_type_code, c_inst_code, c_inst_name_code, inst_xcoord, inst_ycoord)
- `c_inst_name_code_SOCIAL_INSTITUTION_ADDR_index`: (c_inst_name_code)
- `c_inst_code_SOCIAL_INSTITUTION_ADDR_index`: (c_inst_code)
- `c_inst_addr_type_code_SOCIAL_INSTITUTION_ADDR_index`: (c_inst_addr_type_code)
- `c_inst_addr_id_SOCIAL_INSTITUTION_ADDR_index`: (c_inst_addr_id)
- `c_source`: (c_source)

---

### SOCIAL_INSTITUTION_ADDR_TYPES 

**主鍵**: `c_inst_addr_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_addr_type_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_addr_type_desc` | varchar(255) | YES | NULL |  |
| `c_inst_addr_type_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_inst_addr_type_code)
- `c_inst_addr_type_code_SOCIAL_INSTITUTION_ADDR_TYPES_index`: (c_inst_addr_type_code)

---

### SOCIAL_INSTITUTION_ALTNAME_CODES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_altname_type` | smallint(6) | YES | NULL |  |
| `c_inst_altname_desc` | varchar(255) | YES | NULL |  |
| `c_inst_altname_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

---

### SOCIAL_INSTITUTION_ALTNAME_DATA 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_name_code` | smallint(6) | YES | NULL |  |
| `c_inst_code` | smallint(6) | YES | NULL |  |
| `c_inst_altname_type` | smallint(6) | YES | NULL |  |
| `c_inst_altname_hz` | varchar(255) | YES | NULL |  |
| `c_inst_altname_py` | varchar(255) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |

**索引**:

- `c_inst_name_code_SOCIAL_INSTITUTION_ALTNAME_DATA_index`: (c_inst_name_code)
- `c_inst_code_SOCIAL_INSTITUTION_ALTNAME_DATA_index`: (c_inst_code)

---

### SOCIAL_INSTITUTION_CODES 

**主鍵**: `c_inst_name_code`, `c_inst_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_name_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_type_code` | smallint(6) | YES | NULL |  |
| `c_inst_begin_year` | smallint(6) | YES | NULL |  |
| `c_by_nianhao_code` | smallint(6) | YES | NULL |  |
| `c_by_nianhao_year` | smallint(6) | YES | NULL |  |
| `c_by_year_range` | smallint(6) | YES | NULL |  |
| `c_inst_begin_dy` | smallint(6) | YES | NULL |  |
| `c_inst_floruit_dy` | smallint(6) | YES | NULL |  |
| `c_inst_first_known_year` | smallint(6) | YES | NULL |  |
| `c_inst_end_year` | smallint(6) | YES | NULL |  |
| `c_ey_nianhao_code` | smallint(6) | YES | NULL |  |
| `c_ey_nianhao_year` | smallint(6) | YES | NULL |  |
| `c_ey_year_range` | smallint(6) | YES | NULL |  |
| `c_inst_end_dy` | smallint(6) | YES | NULL |  |
| `c_inst_last_known_year` | smallint(6) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_inst_code, c_inst_name_code)
- `c_inst_name_code_SOCIAL_INSTITUTION_CODES_index`: (c_inst_name_code)
- `c_inst_code_SOCIAL_INSTITUTION_CODES_index`: (c_inst_code)
- `c_inst_type_code_SOCIAL_INSTITUTION_CODES_index`: (c_inst_type_code)
- `c_by_nianhao_code_SOCIAL_INSTITUTION_CODES_index`: (c_by_nianhao_code)
- `c_ey_nianhao_code_SOCIAL_INSTITUTION_CODES_index`: (c_ey_nianhao_code)
- `c_by_year_range`: (c_by_year_range)
- `c_ey_year_range`: (c_ey_year_range)
- `c_inst_begin_dy`: (c_inst_begin_dy)
- `c_inst_floruit_dy`: (c_inst_floruit_dy)
- `c_source`: (c_source)

---

### SOCIAL_INSTITUTION_NAME_CODES 

**主鍵**: `c_inst_name_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_name_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_name_hz` | varchar(255) | NO | '' |  |
| `c_inst_name_py` | varchar(255) | NO | '' |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_inst_name_code)
- `c_inst_name_code_SOCIAL_INSTITUTION_NAME_CODES_index`: (c_inst_name_code)

---

### SOCIAL_INSTITUTION_TYPES 

**主鍵**: `c_inst_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_type_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_type_py` | varchar(255) | YES | NULL |  |
| `c_inst_type_hz` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_inst_type_code)
- `c_inst_type_code_SOCIAL_INSTITUTION_TYPES_index`: (c_inst_type_code)

---

### STATUS_CODES 

**主鍵**: `c_status_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_status_code` | smallint(6) | NO | (NULL) |  |
| `c_status_desc` | varchar(255) | NO | '' |  |
| `c_status_desc_chn` | varchar(255) | NO | '' |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_status_code)
- `c_status_code_STATUS_CODES_index`: (c_status_code)

---

### STATUS_CODE_TYPE_REL 

**主鍵**: `c_status_code`, `c_status_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_status_code` | smallint(6) | NO | (NULL) |  |
| `c_status_type_code` | varchar(255) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_status_code, c_status_type_code)
- `c_status_code_STATUS_CODE_TYPE_REL_index`: (c_status_code)
- `c_status_type_code_STATUS_CODE_TYPE_REL_index`: (c_status_type_code)
- `c_status_type_code`: (c_status_type_code)

---

### STATUS_DATA 

**主鍵**: `c_personid`, `c_sequence`, `c_status_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | NO | (NULL) |  |
| `c_status_code` | smallint(6) | NO | (NULL) |  |
| `c_firstyear` | smallint(6) | YES | NULL |  |
| `c_fy_nh_code` | smallint(6) | YES | NULL |  |
| `c_fy_nh_year` | smallint(6) | YES | NULL |  |
| `c_fy_range` | smallint(6) | YES | NULL |  |
| `c_lastyear` | smallint(6) | YES | NULL |  |
| `c_ly_nh_code` | smallint(6) | YES | NULL |  |
| `c_ly_nh_year` | smallint(6) | YES | NULL |  |
| `c_ly_range` | smallint(6) | YES | NULL |  |
| `c_supplement` | varchar(255) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_personid, c_sequence, c_status_code)
- `c_personid_STATUS_DATA_index`: (c_personid)
- `c_status_code_STATUS_DATA_index`: (c_status_code)
- `c_fy_nh_code_STATUS_DATA_index`: (c_fy_nh_code)
- `c_ly_nh_code_STATUS_DATA_index`: (c_ly_nh_code)
- `c_fy_range`: (c_fy_range)
- `c_ly_range`: (c_ly_range)
- `c_source`: (c_source)

---

### STATUS_TYPES 

**主鍵**: `c_status_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_status_type_code` | varchar(255) | NO | (NULL) |  |
| `c_status_type_desc` | varchar(255) | YES | NULL |  |
| `c_status_type_chn` | varchar(255) | YES | NULL |  |
| `c_status_type_parent_code` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_status_type_code)
- `c_status_type_code_STATUS_TYPES_index`: (c_status_type_code)

---

### TABLESFIELDS 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `RowNum` | int(11) | YES | NULL |  |
| `DumpTblNm` | varchar(255) | YES | NULL |  |
| `DumpFldNm` | varchar(255) | YES | NULL |  |
| `AccessTblNm` | varchar(255) | YES | NULL |  |
| `AccessFldNm` | varchar(255) | YES | NULL |  |
| `IndexOnField` | varchar(255) | YES | NULL |  |
| `DataFormat` | varchar(255) | YES | NULL |  |
| `NULL_allowed` | smallint(6) | YES | NULL |  |
| `ForeignKey` | varchar(255) | YES | NULL |  |
| `ForeignKeyBaseField` | varchar(255) | YES | NULL |  |

---

### TABLESFIELDSCHANGES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `TableName` | varchar(255) | YES | NULL |  |
| `FieldName` | varchar(255) | YES | NULL |  |
| `Change` | varchar(255) | YES | NULL |  |
| `ChangeDate` | varchar(255) | YES | NULL |  |
| `ChangeNotes` | varchar(255) | YES | NULL |  |

---

### TEXT_BIBLCAT_CODES 

**主鍵**: `c_text_cat_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_text_cat_code` | smallint(6) | NO | (NULL) |  |
| `c_text_cat_desc` | varchar(255) | NO | '' |  |
| `c_text_cat_desc_chn` | varchar(255) | NO | '' |  |
| `c_text_cat_pinyin` | varchar(255) | NO | '' |  |
| `c_text_cat_parent_id` | varchar(255) | YES | NULL |  |
| `c_text_cat_level` | varchar(255) | YES | NULL |  |
| `c_text_cat_sortorder` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_text_cat_code)
- `c_text_cat_code_TEXT_BIBLCAT_CODES_index`: (c_text_cat_code)

---

### TEXT_BIBLCAT_CODE_TYPE_REL 

**主鍵**: `c_text_cat_code`, `c_text_cat_type_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_text_cat_code` | smallint(6) | NO | (NULL) |  |
| `c_text_cat_type_id` | varchar(255) | NO | (NULL) |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_text_cat_code, c_text_cat_type_id)
- `c_text_cat_code_TEXT_BIBLCAT_CODE_TYPE_REL_index`: (c_text_cat_code)
- `c_text_cat_type_id_TEXT_BIBLCAT_CODE_TYPE_REL_index`: (c_text_cat_type_id)
- `c_text_cat_type_id`: (c_text_cat_type_id)

---

### TEXT_BIBLCAT_TYPES 

**主鍵**: `c_text_cat_type_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_text_cat_type_id` | varchar(255) | NO | (NULL) |  |
| `c_text_cat_type_desc` | varchar(255) | YES | NULL |  |
| `c_text_cat_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_text_cat_type_parent_id` | varchar(255) | YES | NULL |  |
| `c_text_cat_type_level` | smallint(6) | YES | NULL |  |
| `c_text_cat_type_sortorder` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_text_cat_type_id)
- `c_text_cat_type_id_TEXT_BIBLCAT_TYPES_index`: (c_text_cat_type_id)
- `c_text_cat_type_parent_id_TEXT_BIBLCAT_TYPES_index`: (c_text_cat_type_parent_id)

---

### TEXT_CODES 

**主鍵**: `c_textid`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_textid` | int(11) | NO | (NULL) |  |
| `c_title_chn` | varchar(255) | YES | NULL |  |
| `c_title` | varchar(255) | YES | NULL |  |
| `c_title_trans` | varchar(255) | YES | NULL |  |
| `c_text_type_id` | varchar(128) | YES | NULL |  |
| `c_text_year` | smallint(6) | YES | NULL |  |
| `c_text_nh_code` | smallint(6) | YES | NULL |  |
| `c_text_nh_year` | smallint(6) | YES | NULL |  |
| `c_text_range_code` | smallint(6) | YES | NULL |  |
| `c_bibl_cat_code` | smallint(6) | YES | 0 |  |
| `c_extant` | smallint(6) | YES | NULL |  |
| `c_text_country` | smallint(6) | YES | NULL |  |
| `c_text_dy` | smallint(6) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_url_api` | varchar(255) | YES | NULL |  |
| `c_url_api_coda` | varchar(255) | YES | NULL |  |
| `c_url_homepage` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_title_alt_chn` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_textid)
- `c_textid_TEXT_CODES_index`: (c_textid)
- `c_text_type_id_TEXT_CODES_index`: (c_text_type_id)
- `c_text_nh_code_TEXT_CODES_index`: (c_text_nh_code)
- `c_text_range_code_TEXT_CODES_index`: (c_text_range_code)
- `c_bibl_cat_code_TEXT_CODES_index`: (c_bibl_cat_code)
- `c_extant`: (c_extant)
- `c_source`: (c_source)
- `c_text_country`: (c_text_country)
- `c_text_dy`: (c_text_dy)

---

### TEXT_INSTANCE_DATA 

**主鍵**: `c_textid`, `c_text_edition_id`, `c_text_instance_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_textid` | int(11) | NO | (NULL) |  |
| `c_text_edition_id` | smallint(6) | NO | (NULL) |  |
| `c_text_instance_id` | smallint(6) | NO | (NULL) |  |
| `c_instance_title_chn` | varchar(255) | YES | NULL |  |
| `c_instance_title` | varchar(255) | YES | NULL |  |
| `c_instance_title_trans` | varchar(255) | YES | NULL |  |
| `c_part_of_instance` | int(11) | YES | NULL |  |
| `c_part_of_instance_notes` | varchar(255) | YES | NULL |  |
| `c_pub_country` | smallint(6) | YES | NULL |  |
| `c_pub_dy` | smallint(6) | YES | NULL |  |
| `c_pub_year` | smallint(6) | YES | NULL |  |
| `c_pub_nh_code` | smallint(6) | YES | NULL |  |
| `c_pub_nh_year` | smallint(6) | YES | NULL |  |
| `c_pub_range_code` | smallint(6) | YES | NULL |  |
| `c_pub_loc` | varchar(255) | YES | NULL |  |
| `c_publisher` | varchar(255) | YES | NULL |  |
| `c_print` | varchar(255) | YES | NULL |  |
| `c_pub_notes` | varchar(255) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_extant` | smallint(6) | YES | NULL |  |
| `c_url_api` | varchar(255) | YES | NULL |  |
| `c_url_homepage` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_number` | varchar(255) | YES | NULL |  |
| `c_counter` | varchar(255) | YES | NULL |  |
| `c_title_alt_chn` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | NULL |  |
| `c_modified_date` | datetime | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_textid, c_text_edition_id, c_text_instance_id)
- `c_textid_TEXT_CODES_index`: (c_textid)
- `c_pub_nh_code_TEXT_CODES_index`: (c_pub_nh_code)
- `c_pub_range_code_TEXT_CODES_index`: (c_pub_range_code)
- `c_pub_country`: (c_pub_country)
- `c_pub_dy`: (c_pub_dy)
- `c_source`: (c_source)
- `c_text_edition_id`: (c_text_edition_id)
- `c_text_instance_id`: (c_text_instance_id)

---

### TEXT_ROLE_CODES 

**主鍵**: `c_role_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_role_id` | smallint(6) | NO | (NULL) |  |
| `c_role_desc` | varchar(255) | YES | NULL |  |
| `c_role_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_role_id)
- `c_role_id_TEXT_ROLE_CODES_index`: (c_role_id)

---

### TEXT_TYPE 

**主鍵**: `c_text_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_text_type_code` | varchar(255) | NO | (NULL) |  |
| `c_text_type_desc` | varchar(255) | YES | NULL |  |
| `c_text_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_text_type_parent_id` | varchar(255) | YES | NULL |  |
| `c_text_type_level` | smallint(6) | YES | NULL |  |
| `c_text_type_sortorder` | smallint(6) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_text_type_code)
- `c_text_type_code_TEXT_TYPE_index`: (c_text_type_code)
- `c_text_type_parent_id_TEXT_TYPE_index`: (c_text_type_parent_id)

---

### users 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | int(10) unsigned | NO | (NULL) |  [AUTO_INCREMENT] |
| `name` | varchar(255) | NO | (NULL) |  |
| `email` | varchar(255) | NO | (NULL) |  |
| `password` | varchar(255) | NO | (NULL) |  |
| `institution` | varchar(255) | YES | NULL |  |
| `avatar` | varchar(255) | NO | 'avatar5.png' |  |
| `settings` | longtext | YES | NULL |  |
| `confirmation_token` | varchar(255) | NO | (NULL) |  |
| `is_active` | smallint(6) | NO | 0 | 0 未验证， 2 激活邮件， 1 有编辑权限 |
| `is_admin` | smallint(6) | NO | 0 |  |
| `remember_token` | varchar(100) | YES | NULL |  |
| `created_at` | timestamp | YES | NULL |  |
| `updated_at` | timestamp | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (id)
- `users_email_unique` (UNIQUE): (email)

---

### View_BiogInstData （視圖）

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | NO | (NULL) |  |
| `c_name` | varchar(255) | YES | NULL | Hanyu Pinyin full name; auto-generated: c_surname + " " + c_mingzi |
| `c_name_chn` | varchar(255) | YES | NULL | Chinese full name; auto-generated: c_surname_chn + c_mingzi_chn (no space) |
| `c_inst_name_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_code` | smallint(6) | NO | (NULL) |  |
| `c_inst_name_hz` | varchar(255) | NO | '' |  |
| `c_inst_name_py` | varchar(255) | NO | '' |  |
| `c_bi_role_code` | smallint(6) | NO | (NULL) |  |
| `c_bi_role_desc` | varchar(255) | YES | NULL |  |
| `c_bi_role_chn` | varchar(255) | YES | NULL |  |
| `c_bi_begin_year` | smallint(6) | YES | NULL |  |
| `c_bi_by_nh_code` | smallint(6) | YES | NULL |  |
| `c_bi_by_nh_chn` | varchar(255) | YES | NULL |  |
| `c_bi_by_nh_py` | varchar(255) | YES | NULL |  |
| `c_bi_by_nh_year` | smallint(6) | YES | NULL |  |
| `c_bi_by_range` | smallint(6) | YES | NULL |  |
| `c_bi_by_range_desc` | varchar(255) | YES | NULL |  |
| `c_bi_by_range_chn` | varchar(255) | YES | NULL |  |
| `c_bi_end_year` | smallint(6) | YES | NULL |  |
| `c_bi_ey_nh_code` | smallint(6) | YES | NULL |  |
| `c_bi_ey_nh_chn` | varchar(255) | YES | NULL |  |
| `c_bi_ey_nh_py` | varchar(255) | YES | NULL |  |
| `c_bi_ey_nh_year` | smallint(6) | YES | NULL |  |
| `c_bi_ey_range` | smallint(6) | YES | NULL |  |
| `c_bi_ey_range_desc` | varchar(255) | YES | NULL |  |
| `c_bi_ey_range_chn` | varchar(255) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_source_chn` | varchar(255) | YES | NULL |  |
| `c_source_py` | varchar(255) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_inst_addr_id` | int(11) | YES | (NULL) |  |
| `c_inst_addr_type_code` | smallint(6) | YES | (NULL) |  |
| `inst_xcoord` | double | YES | (NULL) |  |
| `inst_ycoord` | double | YES | (NULL) |  |

---

### View_PossessionsData （視圖）

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | int(11) | YES | NULL |  |
| `c_possession_record_id` | int(11) | NO | (NULL) |  |
| `c_sequence` | smallint(6) | YES | NULL |  |
| `c_possession_act_code` | smallint(6) | YES | NULL |  |
| `c_possession_act_desc` | varchar(255) | YES | NULL |  |
| `c_possession_act_desc_chn` | varchar(255) | YES | NULL |  |
| `c_possession_desc` | varchar(255) | YES | NULL |  |
| `c_possession_desc_chn` | varchar(255) | YES | NULL |  |
| `c_quantity` | varchar(255) | YES | NULL |  |
| `c_measure_code` | smallint(6) | YES | NULL |  |
| `c_measure_desc` | varchar(255) | YES | NULL |  |
| `c_measure_desc_chn` | varchar(255) | YES | NULL |  |
| `c_possession_yr` | smallint(6) | YES | NULL |  |
| `c_possession_nh_code` | smallint(6) | YES | NULL |  |
| `c_nianhao_chn` | varchar(255) | YES | NULL |  |
| `c_nianhao_pin` | varchar(255) | YES | NULL |  |
| `c_possession_nh_yr` | smallint(6) | YES | NULL |  |
| `c_possession_yr_range` | smallint(6) | YES | NULL |  |
| `c_range` | varchar(255) | YES | NULL |  |
| `c_range_chn` | varchar(255) | YES | NULL |  |
| `c_source` | int(11) | YES | NULL |  |
| `c_title_chn` | varchar(255) | YES | NULL |  |
| `c_title` | varchar(255) | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | NULL |  |
| `c_addr_id` | int(11) | YES | (NULL) |  |

---

### YEAR_RANGE_CODES 

**主鍵**: `c_range_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_range_code` | smallint(6) | NO | (NULL) |  |
| `c_range` | varchar(255) | YES | NULL |  |
| `c_range_chn` | varchar(255) | YES | NULL |  |
| `c_approx` | varchar(255) | YES | NULL |  |
| `c_approx_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `PRIMARY` (UNIQUE): (c_range_code)
- `c_range_code_YEAR_RANGE_CODES_index`: (c_range_code)

---

## SQLite Schema

### ADDRESSES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_addr_id` | INTEGER | YES | NULL |  |
| `c_addr_cbd` | varchar(255) | YES | NULL |  |
| `c_name` | varchar(255) | YES | NULL |  |
| `c_name_chn` | varchar(255) | YES | NULL |  |
| `c_admin_type` | varchar(255) | YES | NULL |  |
| `c_firstyear` | INTEGER | YES | NULL |  |
| `c_lastyear` | INTEGER | YES | NULL |  |
| `x_coord` | double | YES | NULL |  |
| `y_coord` | double | YES | NULL |  |
| `belongs1_ID` | INTEGER | YES | NULL |  |
| `belongs1_Name` | varchar(255) | YES | NULL |  |
| `belongs2_ID` | INTEGER | YES | NULL |  |
| `belongs2_Name` | varchar(255) | YES | NULL |  |
| `belongs3_ID` | INTEGER | YES | NULL |  |
| `belongs3_Name` | varchar(255) | YES | NULL |  |
| `belongs4_ID` | INTEGER | YES | NULL |  |
| `belongs4_Name` | varchar(255) | YES | NULL |  |
| `belongs5_ID` | INTEGER | YES | NULL |  |
| `belongs5_Name` | varchar(255) | YES | NULL |  |
| `c_belongs_firstyear` | INTEGER | YES | (NULL) |  |
| `c_belongs_lastyear` | INTEGER | YES | (NULL) |  |
| `belongs1_Name_chn` | varchar | YES | (NULL) |  |
| `belongs2_Name_chn` | varchar | YES | (NULL) |  |
| `belongs3_Name_chn` | varchar | YES | (NULL) |  |
| `belongs4_Name_chn` | varchar | YES | (NULL) |  |
| `belongs5_Name_chn` | varchar | YES | (NULL) |  |

---

### ADDR_BELONGS_DATA 

**主鍵**: `c_addr_id`, `c_belongs_to`, `c_firstyear`, `c_lastyear`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_addr_id` | INTEGER | NO | (NULL) |  |
| `c_belongs_to` | INTEGER | NO | (NULL) |  |
| `c_firstyear` | INTEGER | NO | (NULL) |  |
| `c_lastyear` | INTEGER | NO | (NULL) |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar | YES | (NULL) |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_by` | varchar | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_ADDR_BELONGS_DATA_1` (UNIQUE): (c_addr_id, c_belongs_to, c_firstyear, c_lastyear)

---

### ADDR_CODES 

**主鍵**: `c_addr_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_addr_id` | INTEGER | NO | (NULL) |  |
| `c_name` | varchar(255) | YES | NULL |  |
| `c_name_chn` | varchar(255) | YES | NULL |  |
| `c_firstyear` | INTEGER | YES | NULL |  |
| `c_lastyear` | INTEGER | YES | NULL |  |
| `c_admin_type` | varchar(255) | YES | NULL |  |
| `x_coord` | double | YES | NULL |  |
| `y_coord` | double | YES | NULL |  |
| `CHGIS_PT_ID` | INTEGER | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_alt_names` | varchar(255) | YES | NULL |  |
| `c_admin_cat_code` | INTEGER | NO | '0' |  |

---

### ADMIN_CAT_CODES 

**主鍵**: `c_admin_cat_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_admin_cat_code` | INTEGER | NO | (NULL) |  |
| `c_admin_cat_py` | varchar | YES | (NULL) |  |
| `c_admin_cat_hz` | varchar | YES | (NULL) |  |
| `c_admin_cat_trans` | varchar | YES | (NULL) |  |
| `c_notes` | TEXT | YES | (NULL) |  |

---

### ADMIN_CAT_CODE_TYPE_REL 

**主鍵**: `c_admin_cat_code`, `c_admin_cat_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_admin_cat_code` | INTEGER | NO | (NULL) |  |
| `c_admin_cat_type_code` | varchar | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_ADMIN_CAT_CODE_TYPE_REL_1` (UNIQUE): (c_admin_cat_code, c_admin_cat_type_code)

---

### ADMIN_CAT_TYPES 

**主鍵**: `c_admin_cat_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_admin_cat_type_code` | varchar | NO | (NULL) |  |
| `c_admin_cat_type_hz` | varchar | YES | (NULL) |  |
| `c_admin_cat_type_trans` | varchar | YES | (NULL) |  |
| `c_notes` | TEXT | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_ADMIN_CAT_TYPES_1` (UNIQUE): (c_admin_cat_type_code)

---

### ALTNAME_CODES 

**主鍵**: `c_name_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_name_type_code` | INTEGER | NO | (NULL) |  |
| `c_name_type_desc` | varchar(255) | YES | NULL |  |
| `c_name_type_desc_chn` | varchar(255) | YES | NULL |  |

---

### ALTNAME_DATA 

**主鍵**: `c_personid`, `c_alt_name_chn`, `c_alt_name_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_alt_name` | varchar(255) | YES | NULL |  |
| `c_alt_name_chn` | varchar(255) | NO | (NULL) |  |
| `c_alt_name_type_code` | INTEGER | NO | (NULL) |  |
| `c_sequence` | INTEGER | YES | '0' |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `idx_altname_person_seq`: (c_personid, c_sequence)
- `idx_altname_code_source`: (c_alt_name_type_code, c_source)
- `sqlite_autoindex_ALTNAME_DATA_1` (UNIQUE): (c_alt_name_chn, c_alt_name_type_code, c_personid)

---

### APPOINTMENT_CODES 

**主鍵**: `c_appt_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_appt_code` | INTEGER | NO | (NULL) |  |
| `c_appt_desc_chn` | varchar(255) | YES | NULL |  |
| `c_appt_desc` | varchar(255) | YES | NULL |  |
| `c_appt_desc_chn_alt` | varchar(255) | YES | NULL |  |
| `c_appt_desc_alt` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |

---

### APPOINTMENT_CODE_TYPE_REL 

**主鍵**: `c_appt_type_code`, `c_appt_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_appt_type_code` | varchar(255) | NO | (NULL) |  |
| `c_appt_code` | INTEGER | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_APPOINTMENT_CODE_TYPE_REL_1` (UNIQUE): (c_appt_code, c_appt_type_code)

---

### APPOINTMENT_TYPES 

**主鍵**: `c_appt_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_appt_type_code` | varchar(255) | NO | (NULL) |  |
| `c_appt_type_desc` | varchar(255) | YES | NULL |  |
| `c_appt_type_desc_chn` | varchar(255) | YES | NULL |  |

**索引**:

- `sqlite_autoindex_APPOINTMENT_TYPES_1` (UNIQUE): (c_appt_type_code)

---

### ASSOC_CODES 

**主鍵**: `c_assoc_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assoc_code` | INTEGER | NO | (NULL) |  |
| `c_assoc_pair` | INTEGER | YES | NULL |  |
| `c_assoc_pair2` | INTEGER | YES | NULL |  |
| `c_assoc_desc` | varchar(255) | YES | NULL |  |
| `c_assoc_desc_chn` | varchar(255) | YES | NULL |  |
| `c_assoc_role_type` | varchar(255) | YES | NULL |  |
| `c_sortorder` | INTEGER | YES | NULL |  |
| `c_example` | varchar(255) | YES | NULL |  |

---

### ASSOC_CODE_TYPE_REL 

**主鍵**: `c_assoc_code`, `c_assoc_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assoc_code` | INTEGER | NO | (NULL) |  |
| `c_assoc_type_code` | varchar(255) | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_ASSOC_CODE_TYPE_REL_1` (UNIQUE): (c_assoc_code, c_assoc_type_code)

---

### ASSOC_DATA 

**主鍵**: `c_assoc_code`, `c_personid`, `c_kin_code`, `c_kin_id`, `c_assoc_id`, `c_assoc_kin_code`, `c_assoc_kin_id`, `c_assoc_first_year`, `c_text_title`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assoc_code` | INTEGER | NO | (NULL) |  |
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_kin_code` | INTEGER | NO | (NULL) |  |
| `c_kin_id` | INTEGER | NO | (NULL) |  |
| `c_assoc_id` | INTEGER | NO | (NULL) |  |
| `c_assoc_kin_code` | INTEGER | NO | (NULL) |  |
| `c_assoc_kin_id` | INTEGER | NO | (NULL) |  |
| `c_tertiary_personid` | INTEGER | YES | NULL |  |
| `c_tertiary_type_notes` | longtext | YES | (NULL) |  |
| `c_assoc_count` | INTEGER | NO | '1' |  |
| `c_sequence` | INTEGER | YES | '0' |  |
| `c_assoc_first_year` | INTEGER | NO | '-9999' |  |
| `c_assoc_last_year` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_assoc_fy_nh_code` | INTEGER | YES | NULL |  |
| `c_assoc_fy_nh_year` | INTEGER | YES | NULL |  |
| `c_assoc_fy_range` | INTEGER | YES | NULL |  |
| `c_assoc_ly_nh_code` | INTEGER | YES | NULL |  |
| `c_assoc_ly_nh_year` | INTEGER | YES | NULL |  |
| `c_assoc_ly_range` | INTEGER | YES | NULL |  |
| `c_addr_id` | INTEGER | YES | NULL |  |
| `c_litgenre_code` | INTEGER | YES | NULL |  |
| `c_occasion_code` | INTEGER | YES | NULL |  |
| `c_topic_code` | INTEGER | YES | NULL |  |
| `c_inst_code` | INTEGER | YES | '0' |  |
| `c_inst_name_code` | INTEGER | YES | '0' |  |
| `c_text_title` | varchar(255) | NO | '' |  |
| `c_assoc_claimer_id` | INTEGER | YES | NULL |  |
| `c_assoc_fy_intercalary` | INTEGER | YES | NULL |  |
| `c_assoc_fy_month` | INTEGER | YES | NULL |  |
| `c_assoc_fy_day` | INTEGER | YES | NULL |  |
| `c_assoc_fy_day_gz` | INTEGER | YES | NULL |  |
| `c_assoc_ly_intercalary` | INTEGER | YES | NULL |  |
| `c_assoc_ly_month` | INTEGER | YES | NULL |  |
| `c_assoc_ly_day` | INTEGER | YES | NULL |  |
| `c_assoc_ly_day_gz` | INTEGER | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `idx_assoc_person_seq`: (c_personid, c_sequence)
- `sqlite_autoindex_ASSOC_DATA_1` (UNIQUE): (c_assoc_code, c_assoc_id, c_assoc_kin_code, c_assoc_kin_id, c_kin_code, c_kin_id, c_personid, c_text_title, c_assoc_first_year)

---

### ASSOC_TYPES 

**主鍵**: `c_assoc_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assoc_type_code` | varchar(255) | NO | (NULL) |  |
| `c_assoc_type_desc` | varchar(255) | YES | NULL |  |
| `c_assoc_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_assoc_type_parent_id` | varchar(255) | YES | NULL |  |
| `c_assoc_type_level` | INTEGER | YES | NULL |  |
| `c_assoc_type_sortorder` | INTEGER | YES | NULL |  |
| `c_assoc_type_short_desc` | varchar(255) | YES | NULL |  |

**索引**:

- `sqlite_autoindex_ASSOC_TYPES_1` (UNIQUE): (c_assoc_type_code)

---

### ASSUME_OFFICE_CODES 

**主鍵**: `c_assume_office_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_assume_office_code` | INTEGER | NO | (NULL) |  |
| `c_assume_office_desc_chn` | varchar(255) | YES | NULL |  |
| `c_assume_office_desc` | varchar(255) | YES | NULL |  |

---

### BIOG_ADDR_CODES 

**主鍵**: `c_addr_type`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_addr_type` | INTEGER | NO | (NULL) |  |
| `c_addr_desc` | varchar(255) | YES | NULL |  |
| `c_addr_desc_chn` | varchar(255) | YES | NULL |  |
| `c_addr_note` | varchar(255) | YES | NULL |  |
| `c_index_addr_rank` | INTEGER | YES | NULL |  |
| `c_index_addr_default_rank` | INTEGER | YES | NULL |  |

---

### BIOG_ADDR_DATA 

**主鍵**: `c_personid`, `c_addr_id`, `c_addr_type`, `c_sequence`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_addr_id` | INTEGER | NO | '0' |  |
| `c_addr_type` | INTEGER | NO | (NULL) |  |
| `c_sequence` | INTEGER | NO | (NULL) |  |
| `c_firstyear` | INTEGER | YES | NULL |  |
| `c_lastyear` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_fy_nh_code` | INTEGER | YES | NULL |  |
| `c_ly_nh_code` | INTEGER | YES | NULL |  |
| `c_fy_nh_year` | INTEGER | YES | NULL |  |
| `c_ly_nh_year` | INTEGER | YES | NULL |  |
| `c_fy_range` | INTEGER | YES | NULL |  |
| `c_ly_range` | INTEGER | YES | NULL |  |
| `c_natal` | INTEGER | YES | NULL |  |
| `c_fy_intercalary` | INTEGER | YES | NULL |  |
| `c_ly_intercalary` | INTEGER | YES | NULL |  |
| `c_fy_month` | INTEGER | YES | NULL |  |
| `c_ly_month` | INTEGER | YES | NULL |  |
| `c_fy_day` | INTEGER | YES | NULL |  |
| `c_ly_day` | INTEGER | YES | NULL |  |
| `c_fy_day_gz` | INTEGER | YES | NULL |  |
| `c_ly_day_gz` | INTEGER | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_delete` | INTEGER | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `idx_biog_addr_person_seq`: (c_personid, c_sequence)
- `sqlite_autoindex_BIOG_ADDR_DATA_1` (UNIQUE): (c_personid, c_addr_id, c_addr_type, c_sequence)

---

### BIOG_INST_CODES 

**主鍵**: `c_bi_role_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_bi_role_code` | INTEGER | NO | (NULL) |  |
| `c_bi_role_desc` | varchar(255) | YES | NULL |  |
| `c_bi_role_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

---

### BIOG_INST_DATA 

**主鍵**: `c_personid`, `c_inst_name_code`, `c_inst_code`, `c_bi_role_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_inst_name_code` | INTEGER | NO | (NULL) |  |
| `c_inst_code` | INTEGER | NO | (NULL) |  |
| `c_bi_role_code` | INTEGER | NO | (NULL) |  |
| `c_bi_begin_year` | INTEGER | YES | NULL |  |
| `c_bi_by_nh_code` | INTEGER | YES | NULL |  |
| `c_bi_by_nh_year` | INTEGER | YES | NULL |  |
| `c_bi_by_range` | INTEGER | YES | NULL |  |
| `c_bi_end_year` | INTEGER | YES | NULL |  |
| `c_bi_ey_nh_code` | INTEGER | YES | NULL |  |
| `c_bi_ey_nh_year` | INTEGER | YES | NULL |  |
| `c_bi_ey_range` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `idx_biog_inst_person_instcode`: (c_personid, c_inst_name_code, c_inst_code)
- `sqlite_autoindex_BIOG_INST_DATA_1` (UNIQUE): (c_bi_role_code, c_inst_code, c_inst_name_code, c_personid)

---

### BIOG_MAIN 

**主鍵**: `c_personid`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_name` | varchar(255) | YES | NULL |  |
| `c_name_chn` | varchar(255) | YES | NULL |  |
| `c_index_year` | INTEGER | YES | NULL |  |
| `c_index_year_type_code` | varchar(255) | YES | NULL |  |
| `c_index_year_source_id` | INTEGER | YES | NULL |  |
| `c_female` | INTEGER | YES | NULL |  |
| `c_index_addr_id` | INTEGER | YES | '0' |  |
| `c_index_addr_type_code` | INTEGER | YES | NULL |  |
| `c_ethnicity_code` | INTEGER | YES | NULL |  |
| `c_household_status_code` | INTEGER | YES | NULL |  |
| `c_tribe` | varchar(255) | YES | NULL |  |
| `c_birthyear` | INTEGER | YES | NULL |  |
| `c_by_nh_code` | INTEGER | YES | NULL |  |
| `c_by_nh_year` | INTEGER | YES | NULL |  |
| `c_by_range` | INTEGER | YES | NULL |  |
| `c_deathyear` | INTEGER | YES | NULL |  |
| `c_dy_nh_code` | INTEGER | YES | NULL |  |
| `c_dy_nh_year` | INTEGER | YES | NULL |  |
| `c_dy_range` | INTEGER | YES | NULL |  |
| `c_death_age` | INTEGER | YES | NULL |  |
| `c_death_age_range` | INTEGER | YES | NULL |  |
| `c_fl_earliest_year` | INTEGER | YES | NULL |  |
| `c_fl_ey_nh_code` | INTEGER | YES | NULL |  |
| `c_fl_ey_nh_year` | INTEGER | YES | NULL |  |
| `c_fl_ey_notes` | longtext | YES | (NULL) |  |
| `c_fl_latest_year` | INTEGER | YES | NULL |  |
| `c_fl_ly_nh_code` | INTEGER | YES | NULL |  |
| `c_fl_ly_nh_year` | INTEGER | YES | NULL |  |
| `c_fl_ly_notes` | longtext | YES | (NULL) |  |
| `c_surname` | varchar(255) | YES | NULL |  |
| `c_surname_chn` | varchar(255) | YES | NULL |  |
| `c_mingzi` | varchar(255) | YES | NULL |  |
| `c_mingzi_chn` | varchar(255) | YES | NULL |  |
| `c_dy` | INTEGER | YES | NULL |  |
| `c_choronym_code` | INTEGER | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_by_intercalary` | INTEGER | YES | NULL |  |
| `c_dy_intercalary` | INTEGER | YES | NULL |  |
| `c_by_month` | INTEGER | YES | NULL |  |
| `c_dy_month` | INTEGER | YES | NULL |  |
| `c_by_day` | INTEGER | YES | NULL |  |
| `c_dy_day` | INTEGER | YES | NULL |  |
| `c_by_day_gz` | INTEGER | YES | NULL |  |
| `c_dy_day_gz` | INTEGER | YES | NULL |  |
| `c_surname_proper` | varchar(255) | YES | NULL |  |
| `c_mingzi_proper` | varchar(255) | YES | NULL |  |
| `c_name_proper` | varchar(255) | YES | NULL |  |
| `c_surname_rm` | varchar(255) | YES | NULL |  |
| `c_mingzi_rm` | varchar(255) | YES | NULL |  |
| `c_name_rm` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

---

### BIOG_SOURCE_DATA 

**主鍵**: `c_personid`, `c_textid`, `c_pages`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_textid` | INTEGER | NO | (NULL) |  |
| `c_pages` | varchar(255) | NO | (NULL) |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_main_source` | INTEGER | YES | NULL |  |
| `c_self_bio` | INTEGER | YES | NULL |  |
| `c_created_by` | varchar | YES | (NULL) |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_by` | varchar | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `idx_biog_source_person_text`: (c_personid, c_textid)
- `sqlite_autoindex_BIOG_SOURCE_DATA_1` (UNIQUE): (c_pages, c_personid, c_textid)

---

### BIOG_TEXT_DATA 

**主鍵**: `c_textid`, `c_personid`, `c_role_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_textid` | INTEGER | NO | (NULL) |  |
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_role_id` | INTEGER | NO | (NULL) |  |
| `c_year` | INTEGER | YES | NULL |  |
| `c_nh_code` | INTEGER | YES | NULL |  |
| `c_nh_year` | INTEGER | YES | NULL |  |
| `c_range_code` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `idx_biog_text_person_text`: (c_personid, c_textid)
- `sqlite_autoindex_BIOG_TEXT_DATA_1` (UNIQUE): (c_personid, c_role_id, c_textid)

---

### CBDB__NAME_FTS 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | NO | (NULL) |  |
| `c_personid` | INTEGER | NO | (NULL) |  |
| `name_type_code` | INTEGER | YES | (NULL) |  |
| `name_type_desc` | varchar | NO | (NULL) |  |
| `name_type_desc_chn` | varchar | NO | (NULL) |  |
| `search_term` | varchar | NO | (NULL) |  |
| `full_name` | varchar | NO | (NULL) |  |
| `source` | varchar | NO | (NULL) |  |
| `source_key` | varchar | YES | (NULL) |  |
| `is_simplified` | tinyint(1) | NO | '0' |  |
| `created_at` | datetime | YES | (NULL) |  |
| `updated_at` | datetime | YES | (NULL) |  |

**索引**:

- `idx_cbdb__name_type`: (name_type_code)
- `idx_cbdb__name_person`: (c_personid)
- `idx_cbdb__name_search_term`: (search_term, c_personid)

---

### CBDB__TRAD_SIMP_MAP 

**主鍵**: `trad_char`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `trad_char` | VARBINARY(4) | NO | (NULL) |  |
| `simp_char` | VARBINARY(4) | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_CBDB__TRAD_SIMP_MAP_1` (UNIQUE): (trad_char)

---

### CHORONYM_CODES 

**主鍵**: `c_choronym_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_choronym_code` | INTEGER | NO | (NULL) |  |
| `c_choronym_desc` | varchar(255) | YES | NULL |  |
| `c_choronym_chn` | varchar(255) | YES | NULL |  |

---

### COPYMISSINGTABLES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `ID` | INTEGER | YES | NULL |  |
| `TableName` | varchar(255) | YES | NULL |  |

---

### COPYTABLES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `TableName` | varchar(255) | YES | NULL |  |
| `NotProcessed` | INTEGER | YES | NULL |  |

---

### COPYTABLESDEFAULT 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `ID` | INTEGER | YES | NULL |  |
| `TableName` | varchar(255) | YES | NULL |  |

---

### COUNTRY_CODES 

**主鍵**: `c_country_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_country_code` | INTEGER | NO | (NULL) |  |
| `c_country_desc` | varchar(255) | YES | NULL |  |
| `c_country_desc_chn` | varchar(255) | YES | NULL |  |

---

### DYNASTIES 

**主鍵**: `c_dy`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_dy` | INTEGER | NO | (NULL) |  |
| `c_dynasty` | varchar(255) | YES | NULL |  |
| `c_dynasty_chn` | varchar(255) | YES | NULL |  |
| `c_start` | INTEGER | NO | '0' |  |
| `c_end` | INTEGER | NO | '0' |  |
| `c_sort` | INTEGER | YES | NULL |  |

---

### ENTRY_CODES 

**主鍵**: `c_entry_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_entry_code` | INTEGER | NO | (NULL) |  |
| `c_entry_desc` | varchar | NO | '' |  |
| `c_entry_desc_chn` | varchar | NO | '' |  |

---

### ENTRY_CODE_TYPE_REL 

**主鍵**: `c_entry_code`, `c_entry_type`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_entry_code` | INTEGER | NO | (NULL) |  |
| `c_entry_type` | varchar(255) | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_ENTRY_CODE_TYPE_REL_1` (UNIQUE): (c_entry_code, c_entry_type)

---

### ENTRY_DATA 

**主鍵**: `c_personid`, `c_entry_code`, `c_sequence`, `c_kin_code`, `c_kin_id`, `c_assoc_code`, `c_assoc_id`, `c_year`, `c_inst_code`, `c_inst_name_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_entry_code` | INTEGER | NO | (NULL) |  |
| `c_sequence` | INTEGER | NO | (NULL) |  |
| `c_exam_rank` | varchar(255) | YES | NULL |  |
| `c_kin_code` | INTEGER | NO | (NULL) |  |
| `c_kin_id` | INTEGER | NO | (NULL) |  |
| `c_assoc_code` | INTEGER | NO | (NULL) |  |
| `c_assoc_id` | INTEGER | NO | (NULL) |  |
| `c_year` | INTEGER | NO | (NULL) |  |
| `c_age` | INTEGER | YES | NULL |  |
| `c_entry_nh_id` | INTEGER | YES | NULL |  |
| `c_entry_nh_year` | INTEGER | YES | NULL |  |
| `c_entry_range` | INTEGER | YES | NULL |  |
| `c_inst_code` | INTEGER | NO | '0' |  |
| `c_inst_name_code` | INTEGER | NO | '0' |  |
| `c_exam_field` | varchar(255) | YES | NULL |  |
| `c_entry_addr_id` | INTEGER | YES | NULL |  |
| `c_parental_status_code` | INTEGER | YES | NULL |  |
| `c_attempt_count` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_posting_notes` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |
| `c_entry_dy` | INTEGER | YES | (NULL) |  |

**索引**:

- `c_entry_dy_ENTRY_DATA_index`: (c_entry_dy)
- `sqlite_autoindex_ENTRY_DATA_1` (UNIQUE): (c_assoc_code, c_assoc_id, c_entry_code, c_inst_code, c_inst_name_code, c_kin_code, c_kin_id, c_personid, c_sequence, c_year)

---

### ENTRY_TYPES 

**主鍵**: `c_entry_type`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_entry_type` | varchar(255) | NO | (NULL) |  |
| `c_entry_type_desc` | varchar | NO | '' |  |
| `c_entry_type_desc_chn` | varchar | NO | '' |  |
| `c_entry_type_parent_id` | varchar(255) | YES | NULL |  |
| `c_entry_type_level` | double | YES | NULL |  |
| `c_entry_type_sortorder` | double | YES | NULL |  |

**索引**:

- `sqlite_autoindex_ENTRY_TYPES_1` (UNIQUE): (c_entry_type)

---

### ETHNICITY_TRIBE_CODES 

**主鍵**: `c_ethnicity_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_ethnicity_code` | INTEGER | NO | (NULL) |  |
| `c_group_code` | INTEGER | YES | NULL |  |
| `c_subgroup_code` | INTEGER | YES | NULL |  |
| `c_altname_code` | INTEGER | YES | NULL |  |
| `c_name_chn` | varchar(255) | YES | NULL |  |
| `c_name` | varchar(255) | YES | NULL |  |
| `c_ethno_legal_cat` | varchar(255) | YES | NULL |  |
| `c_romanized` | varchar(255) | YES | NULL |  |
| `c_surname` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_sortorder` | INTEGER | YES | NULL |  |

---

### EVENTS_ADDR 

**主鍵**: `c_personid`, `c_sequence`, `c_event_code`, `c_addr_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INT | NO | (NULL) |  |
| `c_sequence` | SMALLINT | NO | 0 |  |
| `c_event_code` | INT | NO | 0 |  |
| `c_addr_id` | INT | NO | (NULL) |  |
| `c_year` | SMALLINT | YES | NULL |  |
| `c_nh_code` | SMALLINT | YES | NULL |  |
| `c_nh_year` | SMALLINT | YES | NULL |  |
| `c_yr_range` | SMALLINT | YES | NULL |  |
| `c_intercalary` | SMALLINT | YES | NULL |  |
| `c_month` | SMALLINT | YES | NULL |  |
| `c_day` | SMALLINT | YES | NULL |  |
| `c_day_ganzhi` | SMALLINT | YES | NULL |  |

**索引**:

- `c_yr_range_EVENTS_ADDR_index`: (c_yr_range)
- `c_day_ganzhi_EVENTS_ADDR_index`: (c_day_ganzhi)
- `c_nh_code_EVENTS_ADDR_index`: (c_nh_code)
- `c_addr_id_EVENTS_ADDR_index`: (c_addr_id)
- `c_event_code_EVENTS_ADDR_index`: (c_event_code)
- `c_sequence_EVENTS_ADDR_index`: (c_sequence)
- `c_personid_EVENTS_ADDR_index`: (c_personid)
- `sqlite_autoindex_EVENTS_ADDR_1` (UNIQUE): (c_addr_id, c_personid, c_sequence, c_event_code)

---

### EVENTS_DATA 

**主鍵**: `c_personid`, `c_sequence`, `c_event_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INT | NO | (NULL) |  |
| `c_sequence` | SMALLINT | NO | 0 |  |
| `c_event_code` | INT | NO | 0 |  |
| `c_role` | VARCHAR(255) | YES | NULL |  |
| `c_year` | SMALLINT | YES | NULL |  |
| `c_nh_code` | SMALLINT | YES | NULL |  |
| `c_nh_year` | SMALLINT | YES | NULL |  |
| `c_yr_range` | SMALLINT | YES | NULL |  |
| `c_intercalary` | SMALLINT | YES | NULL |  |
| `c_month` | SMALLINT | YES | NULL |  |
| `c_day` | SMALLINT | YES | NULL |  |
| `c_day_ganzhi` | SMALLINT | YES | NULL |  |
| `c_addr_id` | INT | YES | NULL |  |
| `c_source` | INT | YES | NULL |  |
| `c_pages` | VARCHAR(255) | YES | NULL |  |
| `c_event` | TEXT | YES | NULL |  |
| `c_notes` | VARCHAR(255) | YES | NULL |  |
| `c_created_by` | VARCHAR(255) | YES | NULL |  |
| `c_created_date` | VARCHAR(255) | YES | NULL |  |
| `c_modified_by` | VARCHAR(255) | YES | NULL |  |
| `c_modified_date` | VARCHAR(255) | YES | NULL |  |

**索引**:

- `c_yr_range_EVENTS_DATA_index`: (c_yr_range)
- `c_source_EVENTS_DATA_index`: (c_source)
- `c_day_ganzhi_EVENTS_DATA_index`: (c_day_ganzhi)
- `c_addr_id_EVENTS_DATA_index`: (c_addr_id)
- `c_nh_code_EVENTS_DATA_index`: (c_nh_code)
- `c_event_code_EVENTS_DATA_index`: (c_event_code)
- `c_personid_EVENTS_DATA_index`: (c_personid)
- `sqlite_autoindex_EVENTS_DATA_1` (UNIQUE): (c_personid, c_sequence, c_event_code)

---

### EVENT_CODES 

**主鍵**: `c_event_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_event_code` | INTEGER | NO | (NULL) |  |
| `c_event_name_chn` | varchar(255) | YES | NULL |  |
| `c_event_name` | varchar(255) | YES | NULL |  |
| `c_fy_yr` | INTEGER | YES | NULL |  |
| `c_ly_yr` | INTEGER | YES | NULL |  |
| `c_fy_nh_code` | INTEGER | YES | NULL |  |
| `c_ly_nh_code` | INTEGER | YES | NULL |  |
| `c_fy_nh_yr` | INTEGER | YES | NULL |  |
| `c_ly_nh_yr` | INTEGER | YES | NULL |  |
| `c_fy_intercalary` | INTEGER | YES | NULL |  |
| `c_fy_month` | INTEGER | YES | NULL |  |
| `c_ly_intercalary` | INTEGER | YES | NULL |  |
| `c_ly_month` | INTEGER | YES | NULL |  |
| `c_fy_range` | INTEGER | YES | NULL |  |
| `c_ly_range` | INTEGER | YES | NULL |  |
| `c_addr_id` | INTEGER | YES | NULL |  |
| `c_dy` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_event_notes` | varchar(255) | YES | NULL |  |

---

### EXTANT_CODES 

**主鍵**: `c_extant_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_extant_code` | INTEGER | NO | (NULL) |  |
| `c_extant_desc` | varchar(255) | YES | NULL |  |
| `c_extant_desc_chn` | varchar(255) | YES | NULL |  |

---

### FOREIGNKEYS 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `AccessTblNm` | varchar(255) | YES | NULL |  |
| `AccessFldNm` | varchar(255) | YES | NULL |  |
| `ForeignKey` | varchar(255) | YES | NULL |  |
| `ForeignKeyBaseField` | varchar(255) | YES | NULL |  |
| `FKString` | varchar(255) | YES | NULL |  |
| `FKName` | varchar(255) | YES | NULL |  |
| `skip` | INTEGER | YES | NULL |  |
| `IndexOnField` | varchar(255) | YES | NULL |  |
| `DataFormat` | varchar(255) | YES | NULL |  |
| `NULL_allowed` | INTEGER | YES | NULL |  |

---

### FORMLABELS 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_form` | varchar(255) | YES | NULL |  |
| `c_label_id` | INTEGER | YES | NULL |  |
| `c_english` | varchar(255) | YES | NULL |  |
| `c_jianti` | varchar(255) | YES | NULL |  |
| `c_fanti` | varchar(255) | YES | NULL |  |

---

### GANZHI_CODES 

**主鍵**: `c_ganzhi_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_ganzhi_code` | INTEGER | NO | (NULL) |  |
| `c_ganzhi_chn` | varchar | NO | '' |  |
| `c_ganzhi_py` | varchar | NO | '' |  |

---

### HOUSEHOLD_STATUS_CODES 

**主鍵**: `c_household_status_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_household_status_code` | INTEGER | NO | (NULL) |  |
| `c_household_status_desc` | varchar | NO | '' |  |
| `c_household_status_desc_chn` | varchar | NO | '' |  |

---

### INDEXYEAR_TYPE_CODES 

**主鍵**: `c_index_year_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_index_year_type_code` | varchar | NO | '' |  |
| `c_index_year_type_desc` | varchar | NO | '' |  |
| `c_index_year_type_hz` | varchar | NO | '' |  |
| `c_notes` | varchar(255) | YES | NULL |  |

**索引**:

- `sqlite_autoindex_INDEXYEAR_TYPE_CODES_1` (UNIQUE): (c_index_year_type_code)

---

### KINSHIP_CODES 

**主鍵**: `c_kincode`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_kincode` | INTEGER | NO | (NULL) |  |
| `c_kin_pair1` | INTEGER | NO | '0' |  |
| `c_kin_pair2` | INTEGER | NO | '0' |  |
| `c_kin_pair_notes` | varchar(255) | YES | NULL |  |
| `c_kinrel_chn` | varchar | NO | '' |  |
| `c_kinrel` | varchar | NO | '' |  |
| `c_kinrel_alt` | varchar(255) | YES | NULL |  |
| `c_pick_sorting` | INTEGER | YES | NULL |  |
| `c_upstep` | INTEGER | NO | '0' |  |
| `c_dwnstep` | INTEGER | NO | '0' |  |
| `c_marstep` | INTEGER | NO | '0' |  |
| `c_colstep` | INTEGER | NO | '0' |  |
| `c_kinrel_simplified` | varchar | NO | '' |  |

---

### KIN_DATA 

**主鍵**: `c_personid`, `c_kin_id`, `c_kin_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_kin_id` | INTEGER | NO | (NULL) |  |
| `c_kin_code` | INTEGER | NO | (NULL) |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_autogen_notes` | longtext | YES | (NULL) |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_KIN_DATA_1` (UNIQUE): (c_kin_code, c_kin_id, c_personid)

---

### KIN_MOURNING 

**主鍵**: `c_kinrel`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_kinrel` | varchar(255) | NO | (NULL) |  |
| `c_kinrel_alt` | varchar(255) | YES | NULL |  |
| `c_kinrel_chn` | varchar(255) | YES | NULL |  |
| `c_mourning` | varchar(255) | YES | NULL |  |
| `c_mourning_chn` | varchar(255) | YES | NULL |  |
| `c_kindist` | varchar(255) | YES | NULL |  |
| `c_kintype` | varchar(255) | YES | NULL |  |
| `c_kintype_desc` | varchar(255) | YES | NULL |  |
| `c_kintype_desc_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

**索引**:

- `sqlite_autoindex_KIN_MOURNING_1` (UNIQUE): (c_kinrel)

---

### KIN_MOURNING_STEPS 

**主鍵**: `c_kinrel`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_kinrel` | varchar(255) | NO | (NULL) |  |
| `c_upstep` | INTEGER | NO | '0' |  |
| `c_dwnstep` | INTEGER | NO | '0' |  |
| `c_marstep` | INTEGER | NO | '0' |  |
| `c_colstep` | INTEGER | NO | '0' |  |

**索引**:

- `sqlite_autoindex_KIN_MOURNING_STEPS_1` (UNIQUE): (c_kinrel)

---

### LITERARYGENRE_CODES 

**主鍵**: `c_lit_genre_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_lit_genre_code` | INTEGER | NO | (NULL) |  |
| `c_lit_genre_desc` | varchar | NO | '' |  |
| `c_lit_genre_desc_chn` | varchar | NO | '' |  |
| `c_sortorder` | INTEGER | NO | '0' |  |

---

### MEASURE_CODES 

**主鍵**: `c_measure_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_measure_code` | INTEGER | NO | (NULL) |  |
| `c_measure_desc` | varchar(255) | YES | NULL |  |
| `c_measure_desc_chn` | varchar(255) | YES | NULL |  |

---

### MERGED_PERSON_DATA 

**主鍵**: `c_personid`, `c_merged_from_personid`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_merged_from_personid` | INTEGER | NO | (NULL) |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_MERGED_PERSON_DATA_1` (UNIQUE): (c_personid, c_merged_from_personid)

---

### NIAN_HAO 

**主鍵**: `c_nianhao_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_nianhao_id` | INTEGER | NO | (NULL) |  |
| `c_dy` | INTEGER | YES | NULL |  |
| `c_dynasty_chn` | varchar(255) | YES | NULL |  |
| `c_nianhao_chn` | varchar(255) | YES | NULL |  |
| `c_nianhao_pin` | varchar(255) | YES | NULL |  |
| `c_firstyear` | INTEGER | YES | NULL |  |
| `c_lastyear` | INTEGER | YES | NULL |  |

---

### OCCASION_CODES 

**主鍵**: `c_occasion_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_occasion_code` | INTEGER | NO | (NULL) |  |
| `c_occasion_desc` | varchar(255) | YES | NULL |  |
| `c_occasion_desc_chn` | varchar(255) | YES | NULL |  |
| `c_sortorder` | INTEGER | YES | NULL |  |

---

### OFFICE_CATEGORIES 

**主鍵**: `c_office_category_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_office_category_id` | INTEGER | NO | (NULL) |  |
| `c_category_desc` | varchar(255) | YES | NULL |  |
| `c_category_desc_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

---

### OFFICE_CODES 

**主鍵**: `c_office_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_office_id` | INTEGER | NO | (NULL) |  |
| `c_dy` | INTEGER | NO | '0' |  |
| `c_office_pinyin` | varchar(255) | YES | NULL |  |
| `c_office_chn` | varchar(255) | YES | NULL |  |
| `c_office_pinyin_alt` | varchar(255) | YES | NULL |  |
| `c_office_chn_alt` | varchar(255) | YES | NULL |  |
| `c_office_trans` | varchar(255) | YES | NULL |  |
| `c_office_trans_alt` | varchar(255) | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_category_1` | varchar(255) | YES | NULL |  |
| `c_category_2` | varchar(255) | YES | NULL |  |
| `c_category_3` | varchar(255) | YES | NULL |  |
| `c_category_4` | varchar(255) | YES | NULL |  |

---

### OFFICE_CODE_TYPE_REL 

**主鍵**: `c_office_id`, `c_office_tree_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_office_id` | INTEGER | NO | (NULL) |  |
| `c_office_tree_id` | varchar(255) | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_OFFICE_CODE_TYPE_REL_1` (UNIQUE): (c_office_id, c_office_tree_id)

---

### OFFICE_TYPE_TREE 

**主鍵**: `c_office_type_node_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_office_type_node_id` | varchar(255) | NO | (NULL) |  |
| `c_office_type_desc` | varchar(255) | YES | NULL |  |
| `c_office_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_parent_id` | varchar(255) | YES | NULL |  |

**索引**:

- `sqlite_autoindex_OFFICE_TYPE_TREE_1` (UNIQUE): (c_office_type_node_id)

---

### PARENTAL_STATUS_CODES 

**主鍵**: `c_parental_status_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_parental_status_code` | INTEGER | NO | (NULL) |  |
| `c_parental_status_desc` | varchar(255) | YES | NULL |  |
| `c_parental_status_desc_chn` | varchar(255) | YES | NULL |  |

---

### POSSESSION_ACT_CODES 

**主鍵**: `c_possession_act_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_possession_act_code` | INTEGER | NO | (NULL) |  |
| `c_possession_act_desc` | varchar(255) | YES | NULL |  |
| `c_possession_act_desc_chn` | varchar(255) | YES | NULL |  |

---

### POSSESSION_ADDR 

**主鍵**: `c_possession_record_id`, `c_personid`, `c_addr_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_possession_record_id` | INTEGER | NO | (NULL) |  |
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_addr_id` | INTEGER | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_POSSESSION_ADDR_1` (UNIQUE): (c_addr_id, c_personid, c_possession_record_id)

---

### POSSESSION_DATA 

**主鍵**: `c_possession_record_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | YES | NULL |  |
| `c_possession_record_id` | INTEGER | NO | (NULL) |  |
| `c_sequence` | INTEGER | YES | NULL |  |
| `c_possession_act_code` | INTEGER | YES | NULL |  |
| `c_possession_desc` | varchar(255) | YES | NULL |  |
| `c_possession_desc_chn` | varchar(255) | YES | NULL |  |
| `c_quantity` | varchar(255) | YES | NULL |  |
| `c_measure_code` | INTEGER | YES | NULL |  |
| `c_possession_yr` | INTEGER | YES | NULL |  |
| `c_possession_nh_code` | INTEGER | YES | NULL |  |
| `c_possession_nh_yr` | INTEGER | YES | NULL |  |
| `c_possession_yr_range` | INTEGER | YES | NULL |  |
| `c_addr_id` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `possession_data_personid_sequence_idx`: (c_personid, c_sequence)

---

### POSTED_TO_ADDR_DATA 

**主鍵**: `c_posting_id`, `c_office_id`, `c_addr_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_posting_id` | INTEGER | NO | (NULL) |  |
| `c_personid` | INTEGER | YES | NULL |  |
| `c_office_id` | INTEGER | NO | (NULL) |  |
| `c_addr_id` | INTEGER | NO | (NULL) |  |
| `c_created_by` | varchar | YES | (NULL) |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_by` | varchar | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_POSTED_TO_ADDR_DATA_1` (UNIQUE): (c_addr_id, c_office_id, c_posting_id)

---

### POSTED_TO_OFFICE_DATA 

**主鍵**: `c_office_id`, `c_posting_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | YES | NULL |  |
| `c_office_id` | INTEGER | NO | (NULL) |  |
| `c_posting_id` | INTEGER | NO | (NULL) |  |
| `c_sequence` | INTEGER | YES | NULL |  |
| `c_firstyear` | INTEGER | YES | NULL |  |
| `c_fy_nh_code` | INTEGER | YES | NULL |  |
| `c_fy_nh_year` | INTEGER | YES | NULL |  |
| `c_fy_range` | INTEGER | YES | NULL |  |
| `c_lastyear` | INTEGER | YES | NULL |  |
| `c_ly_nh_code` | INTEGER | YES | NULL |  |
| `c_ly_nh_year` | INTEGER | YES | NULL |  |
| `c_ly_range` | INTEGER | YES | NULL |  |
| `c_assume_office_code` | INTEGER | YES | NULL |  |
| `c_inst_code` | INTEGER | YES | '0' |  |
| `c_inst_name_code` | INTEGER | YES | '0' |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_office_id_backup` | INTEGER | YES | NULL |  |
| `c_office_category_id` | INTEGER | YES | NULL |  |
| `c_fy_intercalary` | INTEGER | YES | NULL |  |
| `c_fy_month` | INTEGER | YES | NULL |  |
| `c_ly_intercalary` | INTEGER | YES | NULL |  |
| `c_ly_month` | INTEGER | YES | NULL |  |
| `c_fy_day` | INTEGER | YES | NULL |  |
| `c_ly_day` | INTEGER | YES | NULL |  |
| `c_fy_day_gz` | INTEGER | YES | NULL |  |
| `c_ly_day_gz` | INTEGER | YES | NULL |  |
| `c_dy` | INTEGER | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_appt_code` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_POSTED_TO_OFFICE_DATA_1` (UNIQUE): (c_office_id, c_posting_id)

---

### POSTING_DATA 

**主鍵**: `c_posting_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | YES | NULL |  |
| `c_posting_id` | INTEGER | NO | (NULL) |  |
| `c_created_by` | varchar | YES | (NULL) |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_by` | varchar | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

---

### SCHOLARLYTOPIC_CODES 

**主鍵**: `c_topic_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_topic_code` | INTEGER | NO | (NULL) |  |
| `c_topic_desc` | varchar(255) | YES | NULL |  |
| `c_topic_desc_chn` | varchar(255) | YES | NULL |  |
| `c_topic_type_code` | INTEGER | YES | NULL |  |
| `c_topic_type_desc` | varchar(255) | YES | NULL |  |
| `c_topic_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_sortorder` | INTEGER | YES | NULL |  |

---

### SOCIAL_INSTITUTION_ADDR 

**主鍵**: `c_inst_name_code`, `c_inst_code`, `c_inst_addr_type_code`, `c_inst_addr_id`, `inst_xcoord`, `inst_ycoord`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_name_code` | INTEGER | NO | (NULL) |  |
| `c_inst_code` | INTEGER | NO | (NULL) |  |
| `c_inst_addr_type_code` | INTEGER | NO | (NULL) |  |
| `c_inst_addr_begin_year` | INTEGER | YES | NULL |  |
| `c_inst_addr_end_year` | INTEGER | YES | NULL |  |
| `c_inst_addr_id` | INTEGER | NO | (NULL) |  |
| `inst_xcoord` | double | NO | (NULL) |  |
| `inst_ycoord` | double | NO | (NULL) |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_SOCIAL_INSTITUTION_ADDR_1` (UNIQUE): (c_inst_addr_id, c_inst_addr_type_code, c_inst_code, c_inst_name_code, inst_xcoord, inst_ycoord)

---

### SOCIAL_INSTITUTION_ADDR_TYPES 

**主鍵**: `c_inst_addr_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_addr_type_code` | INTEGER | NO | (NULL) |  |
| `c_inst_addr_type_desc` | varchar(255) | YES | NULL |  |
| `c_inst_addr_type_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

---

### SOCIAL_INSTITUTION_ALTNAME_CODES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_altname_type` | INTEGER | YES | NULL |  |
| `c_inst_altname_desc` | varchar(255) | YES | NULL |  |
| `c_inst_altname_chn` | varchar(255) | YES | NULL |  |
| `c_notes` | varchar(255) | YES | NULL |  |

---

### SOCIAL_INSTITUTION_ALTNAME_DATA 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_name_code` | INTEGER | YES | NULL |  |
| `c_inst_code` | INTEGER | YES | NULL |  |
| `c_inst_altname_type` | INTEGER | YES | NULL |  |
| `c_inst_altname_hz` | varchar(255) | YES | NULL |  |
| `c_inst_altname_py` | varchar(255) | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |

---

### SOCIAL_INSTITUTION_CODES 

**主鍵**: `c_inst_name_code`, `c_inst_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_name_code` | INTEGER | NO | (NULL) |  |
| `c_inst_code` | INTEGER | NO | (NULL) |  |
| `c_inst_type_code` | INTEGER | YES | NULL |  |
| `c_inst_begin_year` | INTEGER | YES | NULL |  |
| `c_by_nianhao_code` | INTEGER | YES | NULL |  |
| `c_by_nianhao_year` | INTEGER | YES | NULL |  |
| `c_by_year_range` | INTEGER | YES | NULL |  |
| `c_inst_begin_dy` | INTEGER | YES | NULL |  |
| `c_inst_floruit_dy` | INTEGER | YES | NULL |  |
| `c_inst_first_known_year` | INTEGER | YES | NULL |  |
| `c_inst_end_year` | INTEGER | YES | NULL |  |
| `c_ey_nianhao_code` | INTEGER | YES | NULL |  |
| `c_ey_nianhao_year` | INTEGER | YES | NULL |  |
| `c_ey_year_range` | INTEGER | YES | NULL |  |
| `c_inst_end_dy` | INTEGER | YES | NULL |  |
| `c_inst_last_known_year` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_SOCIAL_INSTITUTION_CODES_1` (UNIQUE): (c_inst_code, c_inst_name_code)

---

### SOCIAL_INSTITUTION_NAME_CODES 

**主鍵**: `c_inst_name_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_name_code` | INTEGER | NO | (NULL) |  |
| `c_inst_name_hz` | varchar | NO | '' |  |
| `c_inst_name_py` | varchar | NO | '' |  |

---

### SOCIAL_INSTITUTION_TYPES 

**主鍵**: `c_inst_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_inst_type_code` | INTEGER | NO | (NULL) |  |
| `c_inst_type_py` | varchar(255) | YES | NULL |  |
| `c_inst_type_hz` | varchar(255) | YES | NULL |  |

---

### STATUS_CODES 

**主鍵**: `c_status_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_status_code` | INTEGER | NO | (NULL) |  |
| `c_status_desc` | varchar | NO | '' |  |
| `c_status_desc_chn` | varchar | NO | '' |  |

---

### STATUS_CODE_TYPE_REL 

**主鍵**: `c_status_code`, `c_status_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_status_code` | INTEGER | NO | (NULL) |  |
| `c_status_type_code` | varchar(255) | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_STATUS_CODE_TYPE_REL_1` (UNIQUE): (c_status_code, c_status_type_code)

---

### STATUS_DATA 

**主鍵**: `c_personid`, `c_sequence`, `c_status_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | NO | (NULL) |  |
| `c_sequence` | INTEGER | NO | (NULL) |  |
| `c_status_code` | INTEGER | NO | (NULL) |  |
| `c_firstyear` | INTEGER | YES | NULL |  |
| `c_fy_nh_code` | INTEGER | YES | NULL |  |
| `c_fy_nh_year` | INTEGER | YES | NULL |  |
| `c_fy_range` | INTEGER | YES | NULL |  |
| `c_lastyear` | INTEGER | YES | NULL |  |
| `c_ly_nh_code` | INTEGER | YES | NULL |  |
| `c_ly_nh_year` | INTEGER | YES | NULL |  |
| `c_ly_range` | INTEGER | YES | NULL |  |
| `c_supplement` | varchar(255) | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_STATUS_DATA_1` (UNIQUE): (c_personid, c_sequence, c_status_code)

---

### STATUS_TYPES 

**主鍵**: `c_status_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_status_type_code` | varchar(255) | NO | (NULL) |  |
| `c_status_type_desc` | varchar(255) | YES | NULL |  |
| `c_status_type_chn` | varchar(255) | YES | NULL |  |
| `c_status_type_parent_code` | varchar(255) | YES | NULL |  |

**索引**:

- `sqlite_autoindex_STATUS_TYPES_1` (UNIQUE): (c_status_type_code)

---

### TABLESFIELDS 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `RowNum` | INTEGER | YES | NULL |  |
| `DumpTblNm` | varchar(255) | YES | NULL |  |
| `DumpFldNm` | varchar(255) | YES | NULL |  |
| `AccessTblNm` | varchar(255) | YES | NULL |  |
| `AccessFldNm` | varchar(255) | YES | NULL |  |
| `IndexOnField` | varchar(255) | YES | NULL |  |
| `DataFormat` | varchar(255) | YES | NULL |  |
| `NULL_allowed` | INTEGER | YES | NULL |  |
| `ForeignKey` | varchar(255) | YES | NULL |  |
| `ForeignKeyBaseField` | varchar(255) | YES | NULL |  |

---

### TABLESFIELDSCHANGES 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `TableName` | varchar(255) | YES | NULL |  |
| `FieldName` | varchar(255) | YES | NULL |  |
| `Change` | varchar(255) | YES | NULL |  |
| `ChangeDate` | varchar(255) | YES | NULL |  |
| `ChangeNotes` | varchar(255) | YES | NULL |  |

---

### TEXT_BIBLCAT_CODES 

**主鍵**: `c_text_cat_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_text_cat_code` | INTEGER | NO | (NULL) |  |
| `c_text_cat_desc` | varchar(255) | NO | '' |  |
| `c_text_cat_desc_chn` | varchar(255) | NO | '' |  |
| `c_text_cat_pinyin` | varchar(255) | NO | '' |  |
| `c_text_cat_parent_id` | varchar(255) | YES | NULL |  |
| `c_text_cat_level` | varchar(255) | YES | NULL |  |
| `c_text_cat_sortorder` | INTEGER | YES | NULL |  |

---

### TEXT_BIBLCAT_CODE_TYPE_REL 

**主鍵**: `c_text_cat_code`, `c_text_cat_type_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_text_cat_code` | INTEGER | NO | (NULL) |  |
| `c_text_cat_type_id` | varchar(255) | NO | (NULL) |  |

**索引**:

- `sqlite_autoindex_TEXT_BIBLCAT_CODE_TYPE_REL_1` (UNIQUE): (c_text_cat_code, c_text_cat_type_id)

---

### TEXT_BIBLCAT_TYPES 

**主鍵**: `c_text_cat_type_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_text_cat_type_id` | varchar(255) | NO | (NULL) |  |
| `c_text_cat_type_desc` | varchar(255) | YES | NULL |  |
| `c_text_cat_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_text_cat_type_parent_id` | varchar(255) | YES | NULL |  |
| `c_text_cat_type_level` | INTEGER | YES | NULL |  |
| `c_text_cat_type_sortorder` | INTEGER | YES | NULL |  |

**索引**:

- `sqlite_autoindex_TEXT_BIBLCAT_TYPES_1` (UNIQUE): (c_text_cat_type_id)

---

### TEXT_CODES 

**主鍵**: `c_textid`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_textid` | INTEGER | NO | (NULL) |  |
| `c_title_chn` | varchar(255) | YES | NULL |  |
| `c_title` | varchar(255) | YES | NULL |  |
| `c_title_trans` | varchar(255) | YES | NULL |  |
| `c_text_type_id` | varchar(128) | YES | NULL |  |
| `c_text_year` | INTEGER | YES | NULL |  |
| `c_text_nh_code` | INTEGER | YES | NULL |  |
| `c_text_nh_year` | INTEGER | YES | NULL |  |
| `c_text_range_code` | INTEGER | YES | NULL |  |
| `c_bibl_cat_code` | INTEGER | YES | '0' |  |
| `c_extant` | INTEGER | YES | NULL |  |
| `c_text_country` | INTEGER | YES | NULL |  |
| `c_text_dy` | INTEGER | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_url_api` | varchar(255) | YES | NULL |  |
| `c_url_api_coda` | varchar(255) | YES | NULL |  |
| `c_url_homepage` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_title_alt_chn` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

---

### TEXT_INSTANCE_DATA 

**主鍵**: `c_textid`, `c_text_edition_id`, `c_text_instance_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_textid` | INTEGER | NO | (NULL) |  |
| `c_text_edition_id` | INTEGER | NO | (NULL) |  |
| `c_text_instance_id` | INTEGER | NO | (NULL) |  |
| `c_instance_title_chn` | varchar(255) | YES | NULL |  |
| `c_instance_title` | varchar(255) | YES | NULL |  |
| `c_instance_title_trans` | varchar(255) | YES | NULL |  |
| `c_part_of_instance` | INTEGER | YES | NULL |  |
| `c_part_of_instance_notes` | varchar(255) | YES | NULL |  |
| `c_pub_country` | INTEGER | YES | NULL |  |
| `c_pub_dy` | INTEGER | YES | NULL |  |
| `c_pub_year` | varchar(255) | YES | NULL |  |
| `c_pub_nh_code` | INTEGER | YES | NULL |  |
| `c_pub_nh_year` | INTEGER | YES | NULL |  |
| `c_pub_range_code` | INTEGER | YES | NULL |  |
| `c_pub_loc` | varchar(255) | YES | NULL |  |
| `c_publisher` | varchar(255) | YES | NULL |  |
| `c_print` | varchar(255) | YES | NULL |  |
| `c_pub_notes` | varchar(255) | YES | NULL |  |
| `c_source` | INTEGER | YES | NULL |  |
| `c_pages` | varchar(255) | YES | NULL |  |
| `c_extant` | INTEGER | YES | NULL |  |
| `c_url_api` | varchar(255) | YES | NULL |  |
| `c_url_homepage` | varchar(255) | YES | NULL |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_number` | varchar(255) | YES | NULL |  |
| `c_counter` | varchar(255) | YES | NULL |  |
| `c_title_alt_chn` | varchar(255) | YES | NULL |  |
| `c_created_by` | varchar(255) | YES | NULL |  |
| `c_modified_by` | varchar(255) | YES | NULL |  |
| `c_created_date` | datetime | YES | (NULL) |  |
| `c_modified_date` | datetime | YES | (NULL) |  |

**索引**:

- `sqlite_autoindex_TEXT_INSTANCE_DATA_1` (UNIQUE): (c_textid, c_text_edition_id, c_text_instance_id)

---

### TEXT_ROLE_CODES 

**主鍵**: `c_role_id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_role_id` | INTEGER | NO | (NULL) |  |
| `c_role_desc` | varchar(255) | YES | NULL |  |
| `c_role_desc_chn` | varchar(255) | YES | NULL |  |

---

### TEXT_TYPE 

**主鍵**: `c_text_type_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_text_type_code` | varchar(255) | NO | (NULL) |  |
| `c_text_type_desc` | varchar(255) | YES | NULL |  |
| `c_text_type_desc_chn` | varchar(255) | YES | NULL |  |
| `c_text_type_parent_id` | varchar(255) | YES | NULL |  |
| `c_text_type_level` | INTEGER | YES | NULL |  |
| `c_text_type_sortorder` | INTEGER | YES | NULL |  |

**索引**:

- `sqlite_autoindex_TEXT_TYPE_1` (UNIQUE): (c_text_type_code)

---

### View_BiogInstData （視圖）

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | YES | (NULL) |  |
| `c_name` | varchar(255) | YES | (NULL) |  |
| `c_name_chn` | varchar(255) | YES | (NULL) |  |
| `c_inst_name_code` | INTEGER | YES | (NULL) |  |
| `c_inst_code` | INTEGER | YES | (NULL) |  |
| `c_inst_name_hz` | varchar | YES | (NULL) |  |
| `c_inst_name_py` | varchar | YES | (NULL) |  |
| `c_bi_role_code` | INTEGER | YES | (NULL) |  |
| `c_bi_role_desc` | varchar(255) | YES | (NULL) |  |
| `c_bi_role_chn` | varchar(255) | YES | (NULL) |  |
| `c_bi_begin_year` | INTEGER | YES | (NULL) |  |
| `c_bi_by_nh_code` | INTEGER | YES | (NULL) |  |
| `c_bi_by_nh_chn` | varchar(255) | YES | (NULL) |  |
| `c_bi_by_nh_py` | varchar(255) | YES | (NULL) |  |
| `c_bi_by_nh_year` | INTEGER | YES | (NULL) |  |
| `c_bi_by_range` | INTEGER | YES | (NULL) |  |
| `c_bi_by_range_desc` | varchar(255) | YES | (NULL) |  |
| `c_bi_by_range_chn` | varchar(255) | YES | (NULL) |  |
| `c_bi_end_year` | INTEGER | YES | (NULL) |  |
| `c_bi_ey_nh_code` | INTEGER | YES | (NULL) |  |
| `c_bi_ey_nh_chn` | varchar(255) | YES | (NULL) |  |
| `c_bi_ey_nh_py` | varchar(255) | YES | (NULL) |  |
| `c_bi_ey_nh_year` | INTEGER | YES | (NULL) |  |
| `c_bi_ey_range` | INTEGER | YES | (NULL) |  |
| `c_bi_ey_range_desc` | varchar(255) | YES | (NULL) |  |
| `c_bi_ey_range_chn` | varchar(255) | YES | (NULL) |  |
| `c_source` | INTEGER | YES | (NULL) |  |
| `c_source_chn` | varchar(255) | YES | (NULL) |  |
| `c_source_py` | varchar(255) | YES | (NULL) |  |
| `c_pages` | varchar(255) | YES | (NULL) |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_inst_addr_id` | INTEGER | YES | (NULL) |  |
| `c_inst_addr_type_code` | INTEGER | YES | (NULL) |  |
| `inst_xcoord` | double | YES | (NULL) |  |
| `inst_ycoord` | double | YES | (NULL) |  |

---

### View_PossessionsData （視圖）

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_personid` | INTEGER | YES | (NULL) |  |
| `c_possession_record_id` | INTEGER | YES | (NULL) |  |
| `c_sequence` | INTEGER | YES | (NULL) |  |
| `c_possession_act_code` | INTEGER | YES | (NULL) |  |
| `c_possession_act_desc` | varchar(255) | YES | (NULL) |  |
| `c_possession_act_desc_chn` | varchar(255) | YES | (NULL) |  |
| `c_possession_desc` | varchar(255) | YES | (NULL) |  |
| `c_possession_desc_chn` | varchar(255) | YES | (NULL) |  |
| `c_quantity` | varchar(255) | YES | (NULL) |  |
| `c_measure_code` | INTEGER | YES | (NULL) |  |
| `c_measure_desc` | varchar(255) | YES | (NULL) |  |
| `c_measure_desc_chn` | varchar(255) | YES | (NULL) |  |
| `c_possession_yr` | INTEGER | YES | (NULL) |  |
| `c_possession_nh_code` | INTEGER | YES | (NULL) |  |
| `c_nianhao_chn` | varchar(255) | YES | (NULL) |  |
| `c_nianhao_pin` | varchar(255) | YES | (NULL) |  |
| `c_possession_nh_yr` | INTEGER | YES | (NULL) |  |
| `c_possession_yr_range` | INTEGER | YES | (NULL) |  |
| `c_range` | varchar(255) | YES | (NULL) |  |
| `c_range_chn` | varchar(255) | YES | (NULL) |  |
| `c_source` | INTEGER | YES | (NULL) |  |
| `c_title_chn` | varchar(255) | YES | (NULL) |  |
| `c_title` | varchar(255) | YES | (NULL) |  |
| `c_pages` | varchar(255) | YES | (NULL) |  |
| `c_notes` | longtext | YES | (NULL) |  |
| `c_addr_id` | INTEGER | YES | (NULL) |  |

---

### YEAR_RANGE_CODES 

**主鍵**: `c_range_code`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `c_range_code` | INTEGER | NO | (NULL) |  |
| `c_range` | varchar(255) | YES | NULL |  |
| `c_range_chn` | varchar(255) | YES | NULL |  |
| `c_approx` | varchar(255) | YES | NULL |  |
| `c_approx_chn` | varchar(255) | YES | NULL |  |

---

### ai_fill_logs 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | NO | (NULL) |  |
| `user_id` | INTEGER | NO | (NULL) |  |
| `c_personid` | INTEGER | NO | (NULL) |  |
| `route_name` | varchar | NO | (NULL) |  |
| `route_url` | varchar | NO | (NULL) |  |
| `source_text` | TEXT | NO | (NULL) |  |
| `ai_raw` | TEXT | YES | (NULL) |  |
| `ai_matched` | TEXT | YES | (NULL) |  |
| `user_submitted` | TEXT | YES | (NULL) |  |
| `success` | tinyint(1) | NO | '0' |  |
| `error_message` | varchar | YES | (NULL) |  |
| `execution_time_ms` | INTEGER | YES | (NULL) |  |
| `submitted_at` | datetime | YES | (NULL) |  |
| `created_at` | datetime | YES | (NULL) |  |
| `updated_at` | datetime | YES | (NULL) |  |
| `category` | varchar | NO | 'posting' |  |

**索引**:

- `ai_fill_logs_category_index`: (category)
- `ai_fill_logs_created_at_index`: (created_at)
- `ai_fill_logs_success_index`: (success)
- `ai_fill_logs_c_personid_index`: (c_personid)
- `ai_fill_logs_user_id_index`: (user_id)

---

### audit_log 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | NO | (NULL) |  |
| `occurred_at` | datetime | NO | (NULL) |  |
| `created_at` | datetime | NO | (NULL) |  |
| `table_name` | varchar | NO | (NULL) |  |
| `operation` | varchar | NO | (NULL) |  |
| `actor_type` | varchar | NO | (NULL) |  |
| `actor_id` | varchar | NO | (NULL) |  |
| `operation_id` | varchar | NO | (NULL) |  |
| `row_pk` | TEXT | NO | (NULL) |  |
| `row_pk_text` | varchar | NO | (NULL) |  |
| `old_data` | TEXT | YES | (NULL) |  |
| `new_data` | TEXT | YES | (NULL) |  |

---

### migrations 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | NO | (NULL) |  |
| `migration` | varchar | NO | (NULL) |  |
| `batch` | INTEGER | NO | (NULL) |  |

---

### nl_query_logs 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | NO | (NULL) |  |
| `user_id` | INTEGER | YES | (NULL) |  |
| `question` | TEXT | NO | (NULL) |  |
| `generated_sql` | TEXT | YES | (NULL) |  |
| `explanation` | TEXT | YES | (NULL) |  |
| `llm_prompt` | TEXT | YES | (NULL) |  |
| `llm_response` | TEXT | YES | (NULL) |  |
| `success` | tinyint(1) | NO | '0' |  |
| `error_message` | varchar | YES | (NULL) |  |
| `execution_time_ms` | INTEGER | YES | (NULL) |  |
| `created_at` | datetime | YES | (NULL) |  |
| `updated_at` | datetime | YES | (NULL) |  |

**索引**:

- `nl_query_logs_created_at_index`: (created_at)
- `nl_query_logs_success_index`: (success)
- `nl_query_logs_user_id_index`: (user_id)

---

### operations 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | YES | (NULL) |  |
| `user_id` | INTEGER | NO | (NULL) |  |
| `c_personid` | INTEGER | NO | (NULL) |  |
| `op_type` | INTEGER | NO | (NULL) |  |
| `resource` | varchar(255) | NO | (NULL) |  |
| `resource_id` | varchar(255) | NO | '' |  |
| `resource_data` | longtext | NO | (NULL) |  |
| `resource_original` | longtext | YES | (NULL) |  |
| `created_at` | timestamp | YES | NULL |  |
| `updated_at` | timestamp | YES | NULL |  |
| `crowdsourcing_status` | INTEGER | NO | '0' |  |
| `rate` | INTEGER | NO | '0' |  |

---

### password_resets 

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `email` | varchar(255) | NO | (NULL) |  |
| `token` | varchar(255) | NO | (NULL) |  |
| `created_at` | timestamp | YES | NULL |  |

---

### personal_access_tokens 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | NO | (NULL) |  |
| `tokenable_type` | varchar | NO | (NULL) |  |
| `tokenable_id` | INTEGER | NO | (NULL) |  |
| `name` | varchar | NO | (NULL) |  |
| `token` | varchar | NO | (NULL) |  |
| `abilities` | TEXT | YES | (NULL) |  |
| `last_used_at` | datetime | YES | (NULL) |  |
| `expires_at` | datetime | YES | (NULL) |  |
| `created_at` | datetime | YES | (NULL) |  |
| `updated_at` | datetime | YES | (NULL) |  |

**索引**:

- `personal_access_tokens_token_unique` (UNIQUE): (token)
- `personal_access_tokens_tokenable_type_tokenable_id_index`: (tokenable_type, tokenable_id)

---

### pinyin 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | YES | (NULL) |  |
| `lastname_chn` | varchar(10) | YES | NULL |  |
| `lastname_pinyin` | varchar(30) | YES | NULL |  |

---

### users 

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | INTEGER | YES | (NULL) |  |
| `name` | varchar(255) | NO | (NULL) |  |
| `email` | varchar(255) | NO | (NULL) |  |
| `password` | varchar(255) | NO | (NULL) |  |
| `institution` | varchar(255) | YES | NULL |  |
| `avatar` | varchar(255) | NO | 'avatar0.png' |  |
| `settings` | longtext | YES | (NULL) |  |
| `confirmation_token` | varchar(255) | NO | (NULL) |  |
| `is_active` | INTEGER | NO | '0' |  |
| `is_admin` | INTEGER | NO | '0' |  |
| `remember_token` | varchar(100) | YES | NULL |  |
| `created_at` | timestamp | YES | NULL |  |
| `updated_at` | timestamp | YES | NULL |  |

**索引**:

- `sqlite_autoindex_users_1` (UNIQUE): (email)

---

## Schema 差異對比

> ✅ 兩個數據庫的 Schema 結構一致

