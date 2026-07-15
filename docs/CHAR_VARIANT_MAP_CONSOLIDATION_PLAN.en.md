# Variant Character Map Work Plan: New `char_variant_map` Table

> 中文版：[CHAR_VARIANT_MAP_CONSOLIDATION_PLAN.md](./CHAR_VARIANT_MAP_CONSOLIDATION_PLAN.md)

> **Status: Planning**. This document currently covers only the new table's schema design, migration design, and the migration of the existing 7 rows of data. The "Call-site integration direction" section describes the **future** direction for rewiring `AdminBatchLoadBookTitlesController::TITLE_VARIANT_MAP` and the BIOG_MAIN/ALTNAME_DATA person-name write paths onto this table; that has not been implemented yet and will be a separate follow-up task.

## Background and Goals

The project currently has two completely independent, non-interoperating "variant character mapping" mechanisms, totaling 10 entries:

1. **`VariantCharNormalizer::$fallbackMap`** (7 entries, `app/Services/VariantCharNormalizer.php:31-39`) — a temporary normalization applied before pinyin generation. It does **not modify** the original data (book titles, person names, etc. remain unchanged); it only affects the pinyin lookup result. Call sites: `BiogMainRepository::auto_pinyin()`, `ApiController::buildPinyinWord()`, `AdminBatchLoadBookTitlesController::buildPinyin()`/`collectUnpinyinableHan()`.
2. **`AdminBatchLoadBookTitlesController::TITLE_VARIANT_MAP`** (3 entries, `app/Http/Controllers/AdminBatchLoadBookTitlesController.php:26-30`) — used during batch book-title import to **directly rewrite the stored book title itself** (`TEXT_CODES.c_title_chn`), applied once in `parseEntries()` (same file, lines 390-398) via `standardizeTitleVariants()`.

`VariantCharNormalizer::ensureLoaded()` (same file, lines 65-74) has a leftover `$variantMap` field (intended to hold a DB-loaded version) that was never wired up to any table — the method just sets `$loaded = true` and returns, a half-built hook that was "prepared but never connected."

**Scope of this plan**: `char_variant_map` handles **only data replacement** — it expands the current `TITLE_VARIANT_MAP`, which only serves the book-title import entry point, into a general variant-character replacement lookup usable by every write path in the project (not limited to person names or book titles). The pinyin-normalization need served by `VariantCharNormalizer::$fallbackMap` is not handled by this table at all; instead, it's addressed by adding pinyin entries directly to the existing `pinyin` table — once a character already has the correct reading in the `pinyin` table, the `VariantCharNormalizer::normalize()` indirection layer becomes unnecessary. The `pinyin` table has already been supplemented with readings for these 7 `$fallbackMap` characters (see `/app/codes/pinyin`); this is `pinyin` table data-maintenance work, a related but independent task, and out of scope for this document. Follow-up cleanup of the `VariantCharNormalizer` class itself is covered below under "Out of scope."

**Relationship to `CBDB__TRAD_SIMP_MAP` (traditional/simplified conversion)**: `CBDB__TRAD_SIMP_MAP` exists for **search/retrieval matching** — it lets the same person name be indexed under both its traditional and simplified forms for name search. That is a fundamentally different layer from this table's **data replacement** function; the two are not being merged (see "Out of scope" below).

**The key change this time**: the new table needs to let every write path in the project query a single shared variant-character replacement lookup. Whether a given mapping can be applied is judged along two axes:
- **Lenient mode** (book titles, offices, institutions, and other general contexts): as long as a variant character has a row in this table, it can be replaced — this table does not carry entries for characters that are "never replaceable anywhere"; those simply don't get a row (their pinyin needs, if any, are handled in the `pinyin` table instead).
- **Strict mode** (currently defined as the BIOG_MAIN [person's primary name] and ALTNAME_DATA [person's alternate names] write paths): on top of lenient mode, a subset of rows may need to be **additionally excluded**.

Strict mode's replaceable set is a subset of lenient mode's. There is exactly one yes/no question to answer: "should this mapping also be excluded from strict mode (person names)?" — which maps to a single boolean column, `c_strict_excluded`. See "Field design" below.

## New Table Design

### Table name: `char_variant_map`

Following the Laravel-style all-lowercase `snake_case` naming used by recent new tables (`pinyin`, `audit_log`, `operations`, `nl_query_logs`), and not using the `CBDB__` prefix — per the existing definition in `.claude/skills/database-schema.md`, the `CBDB__` prefix denotes "an internal helper table that is not part of the original CBDB schema, project-authored," and by convention such tables are read-only under `/codes` (e.g. `CBDB__TRAD_SIMP_MAP`). This table is not a read-only reference table — it's meant to be open for admins to create/edit through the existing Codes CRUD (mirroring the current `pinyin` table's setup), so it follows the same naming and field style as `pinyin`.

The term "variant" still accurately describes every entry in this table — e.g. the "峯→峰" same-character-different-form relationship is, at its core, a "variant character," and this is consistent with the existing `VariantCharNormalizer` class name already in the codebase — a natural extension of existing terminology.

### Field design

Following CBDB convention: data columns always get a `c_` prefix; `id` is a purely technical auto-increment primary key with no prefix.

| Column | Type | Constraint | Description |
|---|---|---|---|
| `id` | `bigIncrements` | PK | Purely technical auto-increment primary key |
| `c_variant_char` | `varchar(10)` | `NOT NULL`, unique | The original character (variant form), e.g. "峯" |
| `c_reference_char` | `varchar(10)` | `NOT NULL` | The reference character (normalization target), e.g. "峰". **The naming deliberately avoids "standard character"/"correct character"**: not every pair here is an "error → correct" relationship. For example, "峯→峰" — 峯 is itself a legitimate person-name character; it is not "wrong" or "variant" in the pejorative sense. This table simply designates "峰" as the target character used when replacement is applied. So the semantics of this table are "which character to normalize to when replacing," not "which character is correct" |
| `c_strict_excluded` | `tinyInteger` | `NOT NULL DEFAULT 1` | **Whether this mapping is excluded from strict mode** (BIOG_MAIN person's primary name, ALTNAME_DATA person's alternate names). **1** (default) = replaceable only in lenient mode (book titles, offices, institutions, etc.); excluded from strict mode. **0** = replaceable in both lenient and strict mode — setting this requires **explicit verification** that forcibly rewriting this character within a person's name is appropriate. When a new mapping is added, in most cases the motivation is to unify spelling in general contexts like book titles, which does not necessarily mean it's also appropriate to apply to person names — defaulting to 1 makes "this can even replace person names" something that requires human confirmation before opting in. **The column name is deliberately not hard-coded to "person_name"**: today "strict mode" only covers person-name-related data, but other contexts may need to be folded into the same exclusion rule in the future; when that happens, the same query condition can simply be added to the corresponding write path, with no need to change the column name or schema |
| `c_notes` | `varchar(255)` | `NULLABLE` | Notes, e.g. the reason for exclusion |
| `created_at` / `updated_at` | `timestamps` | — | Laravel's default timestamps (following the convention of recent new tables like `CBDB__NAME_FTS` and `audit_log`, rather than the `c_created_by`/`c_created_date` pair used only by original CBDB tables) |

**Index**: unique key on `c_variant_char` (`char_variant_map_c_variant_char_unique`) — each variant character maps to exactly one reference character, and it lets lookups (finding the reference character from the original character) use the unique key directly as a primary-key-style lookup, with no additional index needed.

**Why not use `CBDB__TRAD_SIMP_MAP`'s `VARBINARY(4)` design**: that design exists to work around a known MySQL 8.0 bug involving indexing of non-BMP utf8mb4 characters (see the comment at `2025_11_13_000000_create_internal_name_search_tables.php:35-37`). Strictly speaking, that bug concerns indexing of 4-byte (non-BMP) utf8mb4 characters and isn't necessarily limited to `PRIMARY KEY` — if `c_variant_char` ever needs to hold a rare non-BMP variant character, the unique key could theoretically be affected too. However, this project's production environment is **MariaDB 10.3** (see `AGENTS.md`), not MySQL 8.0, so the premise of that bug doesn't apply here, and the `pinyin` table has already run stably for a long time with `varchar(10)` + a unique key on the same MariaDB 10.3 setup. So the `VARBINARY` design is not adopted.

**Why `c_variant_char`/`c_reference_char` use `varchar(10)` rather than a tighter length**: the actual data is always a single CJK character (`varchar(n)`'s `n` in MySQL/MariaDB is a character count, not a byte count; each character is at most 4 bytes under utf8mb4). `varchar(10)` simply follows the existing convention of the `pinyin` table's `c_chn varchar(10)` and leaves a margin of safety (e.g. in case a future entry needs to hold a variation selector or other combining character) — the data itself never actually needs 10 characters, and the storage cost is negligible either way.

### Why `c_strict_excluded` is a single global column, not a per-table exception list

The strict-mode exclusion semantics of `c_strict_excluded` are applied via a **single global column**, rather than a many-to-many exception table like `char_variant_map_exceptions` (`variant_map_id + table_name`). The reason: the scenario currently requiring exclusion is exactly "person-name-related data" as one group (BIOG_MAIN + ALTNAME_DATA share the same exclusion semantics and are naturally treated together), so building a general many-to-many exception table for what is currently a single exception group would be over-engineering. **Known limitation**: if in the future a scenario arises where "BIOG_MAIN should exclude a mapping but ALTNAME_DATA shouldn't" — further splitting the person-name-related group — or where "one non-person-name table wants to exclude a mapping but another non-person-name table doesn't," the current field design cannot express that, and would need to be re-evaluated for a per-table exception table at that point. This plan does not preemptively design for that hypothetical scenario.

## Migration Design

A new migration (class-based, following the recent convention, filename `2026_07_15_000000_create_char_variant_map_table.php`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            column_comment($table->string('c_variant_char', 10), 'Original character (variant form)');
            column_comment($table->string('c_reference_char', 10), 'Reference character (target for replacement normalization)');
            column_comment($table->tinyInteger('c_strict_excluded')->default(1), 'Whether excluded from strict mode (BIOG_MAIN/ALTNAME_DATA person names); 1=lenient mode only, 0=both modes');
            column_comment($table->string('c_notes', 255)->nullable(), 'Notes, e.g. exclusion reason');
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        // Seed data (7 rows); see "Existing data migration" below for full rationale.
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
    }

    public function down(): void {
        Schema::dropIfExists('char_variant_map');
    }
};
```

**MySQL/SQLite compatibility**: uses only the Laravel Schema Builder throughout (`string`/`tinyInteger`/`unique`/`timestamps`), with no database-specific syntax such as `ENGINE`. Column comments are added via the project's existing `column_comment()` helper in `database/migrations/helpers.php`, rather than calling `->comment()` directly — `column_comment()` internally applies `->comment()` only when `is_mysql()`, silently skipping it on SQLite, so it can be called safely on both without any extra branching. This matches the migration convention used by the two recent new tables `audit_log` and `person_change_index`, giving the schema readable field documentation on the MySQL side.

**`down()` has no safety gate**: this is a brand-new table (unlike `pinyin`, which was a restructuring of an existing table where preserving live manual corrections on rollback mattered), and the seed data is only 7 rows — not a bulk import. If an admin adds/edits more rows via the Codes UI after deployment, `migrate:rollback` will delete that manually-entered data along with the table — this differs from the situation with the `pinyin` table's data migration, which deliberately added a content-fingerprint gate (`pinyin` bulk-inserted 6,910 dictionary entries into an existing table and needed to guard against accidental deletion; here we're creating a brand-new table, and dropping the whole table on rollback is an expected, acceptable behavior). If protection is deemed necessary in the future, that can be evaluated separately; it is not required for this change.

## Existing Data Migration (7 seed rows)

Of the original 7 entries in `VariantCharNormalizer::$fallbackMap`, only "愼→慎" and "槀→稿" have an actual data-replacement need and are included in this table. The other 5 (菴, 攷, 嶽, 註, 于) only need pinyin readings added to the `pinyin` table and don't need to go into `char_variant_map`. Two additional entries, "淸→清" and "厰→廠", are newly added — brand-new entries that don't correspond to any existing mechanism.

| c_variant_char | c_reference_char | c_strict_excluded | c_notes |
|---|---|---|---|
| 愼 | 慎 | 0 | Formerly in VariantCharNormalizer::$fallbackMap; 愼/慎 has no ambiguity risk, safe to replace anywhere, including person names |
| 槀 | 稿 | 0 | Formerly in VariantCharNormalizer::$fallbackMap; 槀/稿 has no ambiguity risk, safe to replace anywhere, including person names |
| 峯 | 峰 | 1 | Formerly in TITLE_VARIANT_MAP; replacement is fine in contexts like book titles, but must be excluded from BIOG_MAIN (person's primary name) and ALTNAME_DATA (person's alternate names) — 峯 is a legitimate person-name character and should not be forcibly rewritten |
| 靑 | 青 | 0 | Formerly in TITLE_VARIANT_MAP; 靑/青 has no ambiguity risk, safe to replace anywhere, including person names |
| 頴 | 穎 | 0 | Formerly in TITLE_VARIANT_MAP; 頴/穎 has no ambiguity risk, safe to replace anywhere, including person names |
| 淸 | 清 | 0 | New; 淸/清 has no ambiguity risk, safe to replace anywhere, including person names |
| 厰 | 廠 | 0 | New; 厰/廠 has no ambiguity risk, safe to replace anywhere, including person names |

Seed data is written directly inside the migration's `up()` (`DB::table('char_variant_map')->insert([...])`), rather than building a separate permanent data file the way the `pinyin` table consolidation did — 7 rows is small enough to reproduce reliably by hardcoding it in the migration, and admins can add to it later via the Codes UI without depending on this seed data staying up to date.

## Call-site Integration Direction (not yet implemented; direction for future work)

The following describes the **future** direction for rewiring `TITLE_VARIANT_MAP` and the BIOG_MAIN/ALTNAME_DATA write paths onto this table. This round only completes the table design and migration; no code changes are made in this round:

1. **`AdminBatchLoadBookTitlesController::TITLE_VARIANT_MAP`/`standardizeTitleVariants()`** (same file, lines 26-30, 512-514): change to query the entire `char_variant_map` table (currently all 7 rows: 愼, 槀, 峯, 靑, 頴, 淸, 厰) and apply `strtr()` replacement. The book-title entry point is "lenient mode" — `c_strict_excluded`'s value doesn't matter here; as long as a row is in the table, it applies. All 7 (including 峯→峰) apply.

2. **BIOG_MAIN person-name write path**: after actually surveying the code, the create and update paths are asymmetric and need to be handled separately during implementation:
   - **Update/proposal path**: `BiogMainMutationHandler::prepareProposalPayload()` (`app/Services/Mutations/BiogMainMutationHandler.php:196-200`) and `BiogMainRepository::updateById()` (`app/Repositories/BiogMainRepository.php:246`) each independently combine `c_surname_chn`+`c_mingzi_chn` into `c_name_chn` — a clear hook point, with the query condition `c_strict_excluded = 0` (strict mode; currently corresponds to 6 rows: 愼, 槀, 靑, 頴, 淸, 厰; 峯 is excluded because `c_strict_excluded=1`).
   - **Create path**: `BiogMainCreateHandler.php` itself has no name-composition logic; it only lists `c_name_chn`/`c_surname_chn`/`c_mingzi_chn` in its field allowlist and delegates to `store(Request $request)` in `app/Repositories/BiogMainRepository.php:353-365`. `store()` starts with `$data = $request->all()`, then runs `timestamp()`, `auto_pinyin($data)`, `BracketNormalizer::normalizeBiogMain()`, `PinyinUmlaut::normalizeFields()` (lines 355-361) in sequence before `BiogMain::create($data)` — **none of these existing steps re-derive `c_name_chn`**. In other words, when a new person is created, `c_name_chn` is the raw string sent by the frontend throughout, with no logic that recomposes it from `c_surname_chn`+`c_mingzi_chn`. This means the create path **has no existing "data replacement" hook to modify** (unlike the update path, which already has name-composition logic where a condition can simply be added in place) — applying variant-character replacement here requires **adding** a new piece of normalization code inside `store()`. This is a larger scope of work than the update path, and it's entirely new behavior (the current create path does no variant-character replacement on person names at all), so future task planning needs to estimate create and update separately and should not assume they're symmetric.

3. **ALTNAME_DATA (person's alternate names) write path**: unlike BIOG_MAIN, `AltnameCreateHandler`/`AltnameMutationHandler` each already have clear preprocessing hook points (`AltnameCreateHandler::preprocessCreateData()` lines 61-71, `AltnameMutationHandler::preprocessUpdateData()` around lines 61-66), which already apply `BracketNormalizer`/`PinyinUmlaut::normalizeFields` normalization to fields like `c_alt_name_pinyin` — a more ready-made hook point than the BIOG_MAIN create path. The query condition is likewise `c_strict_excluded = 0` (strict mode), applied to the `c_alt_name_chn` field (`c_alt_name_chn` is part of `ALTNAME_DATA`'s composite primary key: `c_personid + c_alt_name_chn + c_alt_name_type_code`; rewriting this field constitutes a "composite primary key value change" rather than a plain field update, and implementation needs to stay consistent with existing rename/primary-key-change handling — e.g. the primary-key-change handling logic in `AltnameMutationHandler` — rather than being simplified into a plain field overwrite).

4. Add `app/Services/CharVariantMapService` (working name; naming can be finalized during implementation) as a unified query interface.

5. **Audited create/update channel**: the data in this table is itself "input" (new/corrected mapping entries may be needed fairly often), and changes should leave an audit trail, following the code-table write conventions already established by [CODE_TABLE_MUTATION_API_PLAN.md](./CODE_TABLE_MUTATION_API_PLAN.md), rather than relying solely on Codes CRUD or direct DB manipulation:
   - **`config/codes.php` registration** (see above, manual UI edits): `CodesController`'s `store`/`update`/`destroy` direct-write paths already have `AuditLogService::write()` added (§D-2 already completed; see `AuditLogService` constructor-injected into `app/Http/Controllers/CodesController.php`), so once registered in `config/codes.php`, create/edit/delete via the `/app/codes/char_variant_map` UI is automatically written to `audit_log` with no additional development needed.
   - **Token-authenticated machine writes via `/api/v2/*`** (for external scripts / future batch maintenance of variant-character mappings): following the recently-added `TEXT_CODES` example, add a `char_variant_map` definition to the `tables` array in `config/code_table_writes.php` (`key_column` recommended as `id`, `allowed_fields` as `c_variant_char`, `c_reference_char`, `c_strict_excluded`, `c_notes`), and create/delete become available through the existing `CodeTableCreateHandler`/`CodeTableDeleteHandler` (`app/Services/Mutations/CodeTableCreateHandler.php`, `CodeTableDeleteHandler.php`), fully going through `operations` + `audit_log` and rollback-capable, with no need to write a separate handler subclass. If a token-authenticated update is also needed (e.g. batch-correcting `c_reference_char`), add a corresponding definition to the `tables` array in `config/code_table_mutations.php`, handled by the existing config-driven update handler (`ConfigCodeTableMutationHandler`; see `docs/CODE_TABLE_MUTATION_API_PLAN.md`), which also goes through `audit_log`.
   - This step doesn't affect the data-replacement query logic described in points 1-4 above — it's purely the governance side of "managing this table's own data," and can be scheduled separately from the integration work in points 1-4.

During implementation, branch tests for `c_strict_excluded` need to be added (point 4's `CharVariantMapService`, or the WHERE conditions used by each consumer querying `char_variant_map` directly): for lenient mode, verify that any row present in the table is found regardless of `c_strict_excluded`'s value; for strict mode, additionally verify that only `c_strict_excluded=0` rows are found and `c_strict_excluded=1` rows are excluded.

`config/codes.php` registration and the audited create/update channel are covered in point 5 above.

## Out of Scope for This Round

- **`CBDB__TRAD_SIMP_MAP` (traditional/simplified conversion) mechanism is untouched**. `CBDB__TRAD_SIMP_MAP`'s function is search/retrieval matching (letting the same person name be indexed under both its traditional and simplified forms for name search), a fundamentally different layer from this table's data replacement function; there is no merging question here, and it's out of scope for this plan.
- **`VariantCharNormalizer::$fallbackMap`'s pinyin normalization need**: addressed instead by adding pinyin data directly to the `pinyin` table for the corresponding variant characters — `pinyin` table data-maintenance work, unrelated to this table's schema design, and out of scope for this document.
- **Follow-up cleanup of the `VariantCharNormalizer` class itself**: the `pinyin` table has already been supplemented with readings for all 7 of `$fallbackMap`'s original characters (菴, 攷, 嶽, 愼, 註, 于, 槀) (see `/app/codes/pinyin`), so `VariantCharNormalizer::normalize()`'s indirection layer has already lost its reason to exist — every character can now be looked up directly in the `pinyin` table for its correct reading, with no need for character substitution before the lookup. `app/Services/VariantCharNormalizer.php` can be deleted entirely (`$fallbackMap`, `normalize()`, `ensureLoaded()`, `reset()`, `getMappingCount()`), and calls to `normalize()` can be removed from its call sites (`BiogMainRepository::auto_pinyin()`, `ApiController::buildPinyinWord()`, `AdminBatchLoadBookTitlesController::buildPinyin()`/`collectUnpinyinableHan()`). This cleanup's prerequisite has already been satisfied and can be executed as an independent follow-up task apart from this table's schema implementation; it is out of scope for this document.
- **Code changes to the `TITLE_VARIANT_MAP` / BIOG_MAIN / ALTNAME_DATA write paths**: the "Call-site integration direction" section in this document only records direction; the actual code changes, test additions, and `config/codes.php` registration are all left to a follow-up task, each going through its own round of review (see "Implementation steps" below).

## Risks and Open Items

- **`c_strict_excluded` is a global column, not a per-table exception list**: see "Why `c_strict_excluded` is a single global column" above. The current consumers are BIOG_MAIN and ALTNAME_DATA (person-name-related data treated as one group); if a future conflicting exclusion need arises between these two, or with another table, the design will need to be revisited.
- **`down()` has no safety gate**: see the "Migration design" section above — dropping the whole table on rollback is acceptable behavior for this table (brand-new, 7 seed rows), unlike the situation during the `pinyin` table consolidation.
- **BIOG_MAIN's create and update paths must be implemented separately and should not be assumed symmetric**: see point 2 of "Call-site integration direction" above — the update/proposal path already has name-composition logic where a condition can simply be added in place, but the create path (`BiogMainRepository::store()`) has no corresponding name-composition/replacement logic to modify at all and requires new code. If only the update path is changed and the create path is missed, newly-created and updated persons will end up with inconsistent normalization behavior.

## Implementation Steps (each step must pass the review process before proceeding to the next)

> Review process: after each step is completed, first dispatch a group of review agents that read the code and the changes to check it, until there are no serious issues; then use `codex exec --dangerously-bypass-approvals-and-sandbox` (PowerShell + `Write-Output "..." |` piping the prompt + proxy environment variables) to do a second round of checking, until there are no serious issues, before moving to the next step.

### Step 0: This work plan document itself

First pass the review process (review agent + codex) to confirm the plan itself has no missing field design and that the seed data mapping is fully consistent with the existing mechanisms, before proceeding to the next step.

### Step 1: Migration — create the `char_variant_map` table + seed data

Implement the migration per "Migration design" and "Existing data migration" above, and run `php artisan migrate` to verify both up/down execute correctly (including in the SQLite test environment). This step only adds the table and does not change any existing call sites (`TITLE_VARIANT_MAP` continues to use its built-in array for now; it overlaps with the new table's data but does not conflict).

### Step 2 (follow-up task, out of scope for this round): wire up call sites

Following "Call-site integration direction" above, rewire `AdminBatchLoadBookTitlesController` and the BIOG_MAIN/ALTNAME_DATA write paths one by one, and add tests and `config/codes.php` registration. This step involves an existing-behavior change (in particular, adding data replacement to BIOG_MAIN/ALTNAME_DATA is entirely new behavior — the current implementation does no variant-character replacement on person names at all), and needs to be planned as a separate task, going through the full review process.
