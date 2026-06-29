# Database-wide Pinyin `v` → `ü` Normalization Plan

> Status: Draft plan (for discussion)
> Branch: `feature/pinyin-v-to-umlaut-migration`
> Related PR: [#1086](https://github.com/cbdb-project/cbdb-online-main-server/pull/1086) (fixes only the generation dictionary in `app/Models/Pinyin.php`)
> 中文版本: [PINYIN_V_TO_UMLAUT_MIGRATION.md](./PINYIN_V_TO_UMLAUT_MIGRATION.md)

## 0. Background and Agreed Decisions

The CBDB pinyin convention has long used `v` in place of `ü` (e.g. `呂 = Lv`, `閭丘 = Lvqiu`, `耶律 = Yelv`). Following the email discussion among Frank Lin, Song Chen, Hongsu, Michael Fuller, and Peter Bol, the following decisions were reached:

1. **`ü` is the single canonical form**; pinyin fields across the database should no longer use `v` in its place (per the *Scheme for the Chinese Phonetic Alphabet / Hanyu Pinyin*).
2. **`v` is accepted on input**: during manual entry and search, `v` is automatically converted to `ü` for keyboard convenience.
3. **The old `v` forms are kept as an "alternative romanization" alias** for search compatibility, using Michael's proposed `ALTNAME_DATA` alias code **22 (alternative romanization)**. This avoids treating the `v` form as a real name and keeps it out of `c_notes`.
4. **All data changes must be audited**, performed through an audited batch process (Repository / Service + `AuditLogService`); **raw SQL `UPDATE` is not allowed**.

> PR #1086 only addresses the "generation dictionary", i.e. it *prevents future `v`* from being generated. **Cleaning up the existing data is the main body of this plan**, and it must cover all pinyin fields beyond personal names (place names, office titles, dynasties, reign periods, ethnicities, etc.).

## 1. Pinyin Detection Rule (Core)

In Hanyu Pinyin, `ü` appears only after the initials `l` / `n`. The exhaustive set of affected syllables is just four:

| `v` form | Correct pinyin |
|----------|----------------|
| `lv`     | `lü`           |
| `lve`    | `lüe`          |
| `nv`     | `nü`           |
| `nve`    | `nüe`          |

Therefore **only these four substrings need conversion** (handle each case separately: `Lv/Lü`, `Lve/Lüe`, `Nv/Nü`, `Nve/Nüe`).

### Cases that must be excluded (non-pinyin `v`)
- Western / foreign-language romanizations: `Silva`, `Calvin`, `Melvin`, `Sylvia`, `Vasco`, `Verbiest`, … here the `v` is not a `ü` following the initials `l/n`.
- Detection methods (either or both):
  - **Syllable-pattern matching**: convert only when a syllable token (delimited by whitespace or string boundary) **equals or begins with** `lv` / `lve` / `nv` / `nve` (followed by a vowel / syllable boundary). This avoids damaging `Silva` (`lv` is preceded by `Si`, not at a syllable start).
  - **Manual allow-list review**: the scan phase produces a list of suspect rows for human confirmation (aligned with Frank's [Google Sheet](https://docs.google.com/spreadsheets/d/19SOyBtA8cKE9aq_hIkxRiT-e2i6f5bFDIY_TcNAn57I)).

> Note: CBDB pinyin is mostly whitespace-delimited by syllable (e.g. `Lü Mengzheng`, `Yelü`), but joined forms such as `Yelv` exist, so intra-token syllable boundaries must also be handled. In implementation, use a regex anchored on `l/n + v(e)` with a non-letter to the left and a vowel or boundary to the right.

## 2. Scope Inventory (all pinyin fields, database-wide)

> Pinyin fields are not limited to name-related columns; **place names, office titles, dynasties, reign periods, ethnicities, and other code tables also contain `v`**, and this plan handles them together.

> The column list below has been verified one by one against `docs/DATABASE_SCHEMA.md` (the MySQL and SQLite halves agree). The scan phase should still re-confirm the actual data, but the column names themselves are settled.

### A. Hanyu Pinyin fields (to be converted)
| Table | Column | Notes |
|-------|--------|-------|
| `BIOG_MAIN` | `c_name`, `c_surname`, `c_mingzi` | Hanyu Pinyin; **auto-generated from a pinyin source** (see §5: `c_surname` from the DB `pinyin` table, `c_mingzi` from `Pinyin::$dic`, `c_name` = the two concatenated). **Should not be UPDATE-d directly**; fix the source and regenerate (see §4.3). |
| `ALTNAME_DATA` | `c_alt_name` | Person alt-name romanization (`c_alt_name_chn` is the Chinese; composite primary key) |
| `ADDR_CODES` | `c_name` | Place-name romanization (`c_name_chn` is the Chinese) |
| `ADDRESSES` (derived) | `c_name`, `belongs1_Name`…`belongs5_Name` | Derived from `ADDR_CODES.c_name`; **rebuild after changing the source, do not edit directly** (see §4.4) |
| `OFFICE_CODES` | `c_office_pinyin`, `c_office_pinyin_alt` | Office-title pinyin |
| `ETHNICITY_TRIBE_CODES` | `c_name`, `c_romanized`, `c_surname` | Ethnicity romanization |
| `DYNASTIES` | `c_dynasty` | Dynasty romanization (in practice almost no `lv/nv`, still scanned) |
| `NIAN_HAO` | `c_nianhao_pin` | Reign-period pinyin (`c_nianhao_chn` is the Chinese) |
| `CHORONYM_CODES` | `c_choronym_desc` | Choronym romanization (`c_choronym_chn` is the Chinese) |
| `TEXT_CODES` | `c_title` | Book-title romanization (`c_title_chn` Chinese; `c_title_trans` is a translation, not converted) |
| `TEXT_INSTANCE_DATA` | `c_instance_title` | Edition/instance title romanization |
| `TEXT_BIBLCAT_CODES` | `c_text_cat_pinyin` | Bibliographic-category pinyin |
| `GANZHI_CODES` | `c_ganzhi_py` | Ganzhi pinyin (the 60 ganzhi contain no `lü/nü`, expected 0 rows, still scanned) |
| `SOCIAL_INSTITUTION_NAME_CODES` | `c_inst_name_py` | Social-institution name pinyin |
| `SOCIAL_INSTITUTION_TYPES` | `c_inst_type_py` | Social-institution type pinyin |
| `SOCIAL_INSTITUTION_ALTNAME_DATA` | `c_inst_altname_py` | Social-institution alt-name pinyin |
| `ADMIN_CAT_CODES` | `c_admin_cat_py` | Administrative-category pinyin (`c_admin_cat_trans` is a translation, not converted) |

> During the scan, enumerate columns automatically by the rule "any column name ending in `_py` / `_pinyin`, or flagged as romanized in the schema", so the table above does not silently miss future columns.

### B. Non-Hanyu-Pinyin romanization fields (left untouched by default; require a separate decision)
| Table | Column | Reason |
|-------|--------|--------|
| `BIOG_MAIN` | `c_name_rm`, `c_surname_rm`, `c_mingzi_rm` | **Non-pinyin romanizations such as Wade-Giles / McCune-Reischauer**, user-editable. Their use of `ü` differs from Hanyu Pinyin, so they are **not part of this automatic conversion**. |
| `BIOG_MAIN` | `c_name_proper`, `c_surname_proper`, `c_mingzi_proper` | The person's native (non-Chinese) name in Latin script; may contain a genuine `v`. **Not pinyin, not converted.** |

### C. Never converted
- `v` confirmed to belong to a Western / foreign name (see the exclusion rule in §1).
- Translation fields (not romanizations): `OFFICE_CODES.c_office_trans` / `c_office_trans_alt`, `TEXT_CODES.c_title_trans`, `ADMIN_CAT_CODES.c_admin_cat_trans`, and similar English-translation columns.

## 3. Phase 1: Inventory Scan (read-only, no data changes)

Goal: before touching any data, clearly establish "which columns and which rows across the database contain `v`, and of those which are pinyin and which are Western names".

- Add a read-only artisan command: `php artisan cbdb:scan-pinyin-v`
  - Scans every candidate table/column in §2-A (the list maintained in a config inside the command, for easy extension).
  - For each `table.column`, reports: count of rows containing `v`, an automatic classification by syllable rule (likely pinyin / likely Western name), and sample rows.
  - Outputs a CSV report (under `storage/app/pinyin-v-scan/`) for human review and alignment with Frank's Google Sheet.
- This phase **writes no data** and is safe to run against production.
- Acceptance: the report covers all candidate columns; the Western-name misclassification rate is acceptable under spot-checking.

## 4. Phase 2: Data Changes with Audit Logging

> Design principle: **every business-data change writes one `audit_log` row within the same transaction**; raw SQL must not be used to change business data while bypassing the audit. (Sole exception: the derived table `ADDRESSES` is rebuilt wholesale by the existing rebuild command — a technical regeneration, not separately audited; see §4.4.)

### 4.1 Existing audit infrastructure (usage and limitations)
- `app/Services/AuditLogService.php` `write()` / `logChange()`:
  - Parameters: `table`, `operation`, `rowPk` (**required, not nullable**), `oldData`, `newData`, `actorType`, `actorId`, `operationId`, `occurredAt`, `createdAt`.
  - Auto-generates `operation_id` via `Str::ulid()` (a batch may pass **the same** `operationId` to tag the whole batch, easing later batch trace/rollback).
  - Updates `person_change_index` automatically via `DB::afterCommit`, so person-related table changes are detected with no extra work.
- **Important: `AuditLogService` only writes the audit; it does not perform the data UPDATE itself.** The conversion command must therefore do both "write the data + call `write()`" within the same transaction.
- **Code tables have no ready-made "UPDATE + audit" path**: all 35 existing `AuditLogService` call sites are in person / sub-resource write flows (BiogMain, altname, address, …) and NianHao. Tables like `ADDR_CODES`, `OFFICE_CODES`, `DYNASTIES`, `ETHNICITY_TRIBE_CODES` only have bare Eloquent models with `$guarded=[]` and **no** repository that pairs the write with an audit. So this command must use `Model::update()` / `save()` itself and then call `AuditLogService::write()` per row — **this is a new flow to build**, not reuse of existing infrastructure.
- `audit_log` is **append-only**; rollback is done by writing a reverse `UPDATE` (new→old) as a new row, never by deleting audit rows.

### 4.2 Conversion command design
- Add an artisan command: `php artisan cbdb:convert-pinyin-v [--dry-run] [--table=] [--limit=]` (naming/signature aligned with existing `cbdb:*` conventions).
  - Defaults to `--dry-run`: only prints the diff to be applied, writes nothing.
  - Per-row change flow (within one DB transaction):
    1. Compute the `new` value per the syllable rule; skip if unchanged from `old` or judged a Western name.
    2. Resolve `rowPk`: the command needs a built-in "per-table primary key map" and builds `rowPk` per each table's actual primary key. The primary keys of the affected tables have been verified as follows:
       - **Composite primary key (2 tables)**:
         - `ALTNAME_DATA` (3 keys: `c_alt_name_chn`, `c_alt_name_type_code`, `c_personid`) — **registered** in `CompositePrimaryKey::SCHEMAS`; `App\Support\CompositePrimaryKey` can be used.
         - `TEXT_INSTANCE_DATA` (3 keys: `c_textid`, `c_text_edition_id`, `c_text_instance_id`) — **not registered** in SCHEMAS; build `rowPk` manually.
       - **Single primary key**: `ADDR_CODES.c_addr_id`, `OFFICE_CODES.c_office_id`, `DYNASTIES.c_dy`, `ETHNICITY_TRIBE_CODES.c_ethnicity_code`, `NIAN_HAO.c_nianhao_id`, `CHORONYM_CODES.c_choronym_code`, `TEXT_CODES.c_textid`, `TEXT_BIBLCAT_CODES.c_text_cat_code`, `GANZHI_CODES.c_ganzhi_code`, `SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_code`, `SOCIAL_INSTITUTION_TYPES.c_inst_type_code`, `ADMIN_CAT_CODES.c_admin_cat_code` (none registered in SCHEMAS; build `rowPk` manually).
       - **No primary key (special case, must be handled separately)**: `SOCIAL_INSTITUTION_ALTNAME_DATA` **has no PRIMARY KEY** (only two ordinary indexes on `c_inst_code` / `c_inst_name_code`; all columns nullable). Since `AuditLogService::write()` requires `rowPk`, **this table cannot go through per-row audited conversion**. Choose one of: (a) build a synthetic `rowPk` from a uniquely-identifying column combination (first confirm whether `c_inst_code` + `c_inst_name_code` + `c_inst_altname_type` etc. is unique), or (b) exclude it from automatic conversion and handle it manually with separate logging. Apply the same principle to any other no-PK tables found during the scan.
    3. Change the pinyin column via Eloquent `Model::update()` (**not raw SQL**).
    4. `AuditLogService::write()` records `operation='UPDATE'`, `actorType='system'`, `actorId='pinyin-v-migration'`, a shared `operationId` for the whole batch, and `oldData`/`newData` containing only the affected columns.
  - Batched, resumable (track a processed watermark), and interruptible.
- Acceptance: the `--dry-run` diff is human-reviewed; after the real run, `audit_log` can fully reconstruct every change; spot-checks confirm correct conversion and no Western-name damage.

### 4.3 `BIOG_MAIN` pinyin fields: "regenerate" rather than "edit directly"
- `c_name`/`c_surname`/`c_mingzi` are auto-generated from the DB `pinyin` table (surname) and `Pinyin::$dic` (given name). A direct UPDATE risks being overwritten later by any edit that triggers `BiogMainRepository::auto_pinyin()` (depending on whether the source has been fixed).
- Correct order: **first** complete the source fixes in §5 (merge PR #1086 + change surnames in the DB `pinyin` table to `ü`), **then** regenerate pinyin for affected persons and write back with audit; this avoids "fixed-then-overwritten-by-generation". `ALTNAME_DATA` and the code tables are not auto-generated, so convert them directly per §4.2.

### 4.4 Rebuilding the derived table `ADDRESSES`
- Do not edit `ADDRESSES` directly; after changing `ADDR_CODES.c_name`, rebuild it with the existing `php artisan cbdb:regenerate-addresses-table` (`app/Console/Commands/RegenerateAddresses.php`, supports `--dry-run`).
- **Note: that command currently uses raw `TEMPORARY TABLE` + `INSERT…SELECT`, which is MySQL/MariaDB-only** and cannot run on SQLite. This conflicts with the portability principle in §6; this plan keeps it as-is (production is MariaDB) but must flag the limitation in the doc and the command help, and must not run this step in a SQLite environment.

### 4.5 Alias compatibility (persons only) and prerequisites for code 22
- Per the agreement, for affected persons the original `v` form is written into `ALTNAME_DATA` as code **22 (alternative romanization)** for search compatibility, also recorded via `AuditLogService`. Code tables (place names, office titles, etc.) **get no alias**; their search compatibility relies on application-layer normalization (see §5).
- **Prerequisite (must be done first)**: `ALTNAME_CODES` (PK `c_name_type_code`) currently **has no seed** in the repo, so it cannot be confirmed that code 22 exists with the meaning "alternative romanization". `ALTNAME_DATA.c_alt_name_type_code` has an FK to `ALTNAME_CODES`, and **writing an unknown code will be rejected by the FK**. So code 22 (with Chinese/English descriptions) must be confirmed/created first.
- **PK constraint**: the `ALTNAME_DATA` primary key is `(c_alt_name_chn, c_alt_name_type_code, c_personid)` and **does not include** `c_alt_name`. So writing a code-22 alias requires also supplying the Chinese name `c_alt_name_chn` (not just the romanization string), and there can be only one code-22 row per (Chinese name, person). The implementation must decide what `c_alt_name_chn` is set to (typically the person's corresponding Chinese name).

## 5. Phase 3: Code Changes (last step)

1. **Merge the generation dictionary**: merge PR #1086 (`app/Models/Pinyin.php` `$dic`: `lv/lve/nv → lü/lüe/nü`) and update the relevant surnames in the DB `pinyin` table.
   - Verified: the only `v`-containing values in `$dic` are `lv`, `lve`, `nv` — no other stray `v`; **`nve` is not present in the dictionary** (no character maps to `nve`), so there is nothing to fix for `nve` on the generation side. `nve → nüe` only matters for input/search normalization (a user may type `nve`).
   - PR #1086 is not yet merged into this branch (the working tree still has `v`); confirm during merge.
2. **Input normalization**: add the `v → ü` rule at the unified "Chinese → pinyin" generation entry points. Verified entry points:
   - Main path `BiogMainRepository::auto_pinyin()` (person create/update; `c_surname` looks up the DB `pinyin` table, `c_mingzi` goes through `Pinyin::getPinyin()`).
   - `ApiController::buildPinyinWord()` / `searchPinyin()` (the "Generate Pinyin" button, `GET api/search/pinyin`).
   - **Batch importers have their own separate `buildPinyin()`**: `AdminBatchLoadOfficesController`, `AdminBatchLoadBookTitlesController`, `AdminBatchLoadSocialInstitutesController` each have one and must be updated individually to avoid uncovered paths.
   - These paths already call `VariantCharNormalizer::normalize()` before generation, which is the natural place to add `v → ü` normalization.
   - The repo currently **has no `v→ü` normalization helper** (searching the whole repo for `ü`/`lü`/`nü` returns 0 hits); a shared helper must be created for both the generation side and the search side.
3. **Search compatibility**: normalize the query string as well (Michael's option ②) so that a user typing `v` still matches `ü` data; personal names additionally have the code-22 alias as a second safeguard. Verified scope:
   - Pinyin LIKE matching is in `app/Services/PersonBrowserService.php` (the LIKE fallback queries over `c_name`/`c_surname`/`c_mingzi` etc.) — this is where query-side normalization is needed.
   - **The FTS index (`NameSearchIndexService` / `CBDB__NAME_FTS`) is built from the Chinese `c_name_chn`, not pinyin**, so it needs no `v→ü`; search compatibility targets only the pinyin LIKE path — do not touch the FTS.
4. **Regression tests**: pinyin generation (including the three batch `buildPinyin`), input normalization, pinyin LIKE search compatibility, audit logging, composite-key `rowPk` resolution (`ALTNAME_DATA` / `TEXT_INSTANCE_DATA`), the no-PK special case, and Western-name exclusion — all covered.
5. **Compatibility**: confirm `ü` (U+00FC) works in MariaDB (utf8mb4) and SQLite; Michael has confirmed `ü` is a legal non-ASCII character (0xFC) and is not among the illegal characters cleaned up earlier.
6. **Re-expose the `pinyin` table in the codes UI (the final step of this plan)**: the `pinyin` table (surname-pinyin lookup) is currently hidden from the `/codes` index list by the `ui_hidden` array in `config/codes.php` (which contains `'pinyin'`). Once the preceding steps are complete and the pinyin data has been normalized to `ü`, remove `'pinyin'` from `ui_hidden` so it reappears in the codes table list for viewing/maintenance.
   - `pinyin` is already in the `tables` allow-list in `config/codes.php` (label "surname pinyin lookup table"), so only the one `ui_hidden` entry needs removing — no other configuration changes.
   - Why this is the last step: only re-expose it after both the data and the generation dictionary are on `ü` and display is confirmed correct, to avoid users seeing a transitional state that still contains `v`.
   - Background design: see `docs/CODES_BOOLEAN_FILTER_DESIGN.md` §9.1 (`ui_hidden` affects only the index list, not direct access to `/codes/{table}` nor the Query Playground / NL / MCP allow-lists).

## 6. Risks and Cautions

- **Western-name damage** (Silva/Calvin…): guarded by the syllable rule + manual review; also note that `c_*_proper` (native names) may contain a genuine `v` and must not be converted.
- **Wade-Giles and other non-pinyin fields** (`c_*_rm`): untouched this round, to avoid breaking a separate romanization system.
- **`BIOG_MAIN` generation overwrite**: name pinyin is auto-generated, so fix the source before regenerating, or a direct edit will be overwritten by later edits (§4.3).
- **Code-22 FK prerequisite**: code 22 must be created in `ALTNAME_CODES` before writing code-22 rows in `ALTNAME_DATA`, or the FK will reject it (§4.5).
- **`ADDRESSES` rebuild is MySQL-only**: `cbdb:regenerate-addresses-table` uses raw SQL / temporary tables and cannot run on SQLite (§4.4).
- **Derived table / view consistency**: change only the source tables and rebuild the derived data.
- **Database portability**: the new scan/convert commands must respect `is_mysql()`/`is_sqlite()`; do not use SQLite-unsupported syntax in raw SQL (the existing `ADDRESSES` rebuild command is a known exception).

## 7. Execution Order and To-do Ledger

> Ordering note: **fix the pinyin source first (generation dictionary + DB pinyin table), then regenerate `BIOG_MAIN`**, otherwise direct edits will be overwritten by generation (§4.3).

- [ ] Phase 1: `cbdb:scan-pinyin-v` read-only scan command + report; confirm the complete list of affected tables/columns (covering all of §2-A)
- [ ] Align the column list and the Western-name exclusion list with Frank's Google Sheet
- [ ] Source fix: merge PR #1086 (`Pinyin::$dic`) + update surnames in the DB `pinyin` table to `ü`
- [ ] Prerequisite: confirm/create `ALTNAME_CODES` code 22 "alternative romanization" (needed for the FK, §4.5)
- [ ] Phase 2: `cbdb:convert-pinyin-v --dry-run` conversion command (`Model::update()` + `AuditLogService`, no raw SQL) — handle all §2-A tables, including the composite-key tables (`ALTNAME_DATA`, `TEXT_INSTANCE_DATA`) and the single-key code tables
- [ ] Phase 2: special case `SOCIAL_INSTITUTION_ALTNAME_DATA` (no PK) — synthetic identifier or manual handling (§4.2)
- [ ] Phase 2: **regenerate** pinyin for affected `BIOG_MAIN` persons (after the source fix) and write back with audit (§4.3)
- [ ] Phase 2: after changing `ADDR_CODES`, rebuild `ADDRESSES` with `cbdb:regenerate-addresses-table` (MySQL only, §4.4)
- [ ] Phase 2: write code-22 aliases for persons (search compatibility, with Chinese name `c_alt_name_chn`)
- [ ] Phase 2: dry-run review → real run → audit reconstruction verification
- [ ] Phase 3: build a shared `v→ü` normalize helper; hook it into the generation side (`auto_pinyin` + the three batch `buildPinyin`) and the search side (`PersonBrowserService` pinyin LIKE)
- [ ] Phase 3: regression tests (generation / normalization / search / audit / rowPk / Western-name exclusion)
- [ ] Doc sync: `CHANGELOG.md`, and `DATABASE.md` / `README.md` as needed
- [ ] **Final step**: remove `'pinyin'` from `ui_hidden` in `config/codes.php` to re-expose the surname-pinyin table in the codes UI
