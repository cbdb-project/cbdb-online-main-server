# Code-Table Audited Mutation API Construction Plan

> Status: **Finalized (ready to execute; §D is authoritative — where it conflicts with older prose below, §D governs)**
> Branch: `feature/pinyin-v-to-umlaut-migration` (each implementation milestone gets its own branch and PR)
> Related plan: [Pinyin v → ü Normalization Plan](./PINYIN_V_TO_UMLAUT_MIGRATION.en.md) (this plan is a **prerequisite for its Phase B**)
> 中文版本: [CODE_TABLE_MUTATION_API_PLAN.md](./CODE_TABLE_MUTATION_API_PLAN.md)

## §D. Locked Decisions (execute as-is; do NOT re-confirm)

- **D-1 No-PK table `SOCIAL_INSTITUTION_ALTNAME_DATA`: SKIP.** It has no edit entry point, so it is **excluded from this API and from the migration — not handled** (do NOT build a "synthetic identifier" path). Treat every "synthetic key / special-case handling" passage about this table below as **not to be executed — simply skip it**.
- **D-2 `/codes` UI audit gap: fix it within this plan's scope.** Add `AuditLogService::write()` to the `CodesController` direct-write paths (`store`/`update`/`destroy`) so the UI is audited consistently with the new API (promoted from "optional follow-up" to **required**).
- **D-3 `person_id` contract: implementer's choice, either option, both guarded by tests and non-blocking.**
  - (a) Modify `MutationController`: make `person_id` optional for code resources (`person_id_column === null`) — **regression tests must confirm existing person sub-resources are unchanged** (still 422 on missing person_id; cross-check still fires); OR
  - (b) **zero controller change**: the external script sends `person_id: 0` for code tables (reusing `NianHaoMutationHandler`'s existing approach).
- **D-4 `ADDRESSES` derived table:** after correcting `ADDR_CODES`, **rebuild** via `cbdb:regenerate-addresses-table` (production, MySQL-only).
- **D-5 Phase B "what to change" is governed by the scan rule, no human Sheet needed:** code tables are essentially pure pinyin (verified: of 498 `ETHNICITY.c_name` rows, 11 with v are all genuine pinyin, 0 Western; of 173 `CHORONYM` rows, 1 `Vietnam` has no `lv/nv` syllable and is excluded by the rule). So **apply the deterministic `lv/lve/nv/nve` syllable rule directly to both dedicated pinyin columns and romanized-name columns**; the scan command additionally emits a small `[OTHER-v]` list for a human eyeball (safety net). `ADDR_CODES` is confirmed by the read-only scan at Phase B start before any write.
- **D-6 Save-time v→ü normalization (stop-the-bleed 2.0, mirroring Phase A §D-12): included in this plan.** Phase A already added save-path stop-the-bleed for person names (`PinyinUmlaut::normalizeFields()`, see [PINYIN_SAVE_NORMALIZE_DESIGN.md](./PINYIN_SAVE_NORMALIZE_DESIGN.md)); after the code tables are batch-cleaned, their **manual-input surface** and **external API** would likewise re-accumulate `v`, so this is added too — otherwise the batch fix gets re-polluted by later data entry.
  - **Hook points (three)**:
    1. the new **`AbstractCodeTableMutationHandler`** (covers all code-table API writes in one place — cheapest);
    2. **`CodesController`** write paths (`store`/`update` and the proposal methods; `destroy` deletes a row, so no normalization needed) — `store`/`update` **overlap with §D-2's `audit_log` hook points** (add alongside), while the proposal methods are §D-6-specific (not covered by §D-2, but they persist column values so they need normalization);
    3. **`AdminBatchLoadBookTitlesController::updatePinyin()`** (book-title inline edit, currently whitespace/case only; its batch `buildPinyin()` already uses `PinyinUmlaut` — this fixes the inline-vs-batch inconsistency).
  - **Per-table pinyin-column registry = the SAME list as §D-5**: v→ü may only touch columns that are **definitely Hanyu pinyin**, so a "table → pinyin columns" list is required to exclude English translations (e.g. `OFFICE_CODES.c_office_trans`), Chinese columns (`c_*_chn`), and semantically-uncertain alternate-romanization columns. This list **is exactly the "dedicated pinyin / romanized-name columns" list used by §D-5's batch migration** — batch fix and save-time stop-the-bleed **share one registry** to avoid drift. Generic writes (`CodesController` writing arbitrary columns of arbitrary tables) **must** consult the registry first and normalize only matched columns; never blanket-apply (else Wade-Giles / translation columns get corrupted).
  - **Tier split (verified 2026-07 against a full local CBDB copy + finalized by Hongsu)**: most code pinyin columns are pure pinyin → **Tier 1 backend silent conversion**; a few **named "mixed" columns** (pinyin interleaved with Western/English) go to **Tier 2 (altname-style dialog)** — **reusing the frontend detect+dialog mechanism already built in §D-12** (`resources/js/inertia/utils/pinyinUmlaut.ts` + dialog). The only difference: the generic `/codes` editor must consult the "(table,column) → Tier" registry below to decide whether to prompt, and the backend must **not** silently convert Tier-2 columns (respect the user's dialog choice). This registry is the concrete realization of the "shared-with-§D-5 pinyin-column list" from the previous bullet (with an added Tier column).

    | table.column | Tier | Basis |
    |---|---|---|
    | `OFFICE_CODES.c_office_pinyin`, `c_office_pinyin_alt` | Tier 1 | Hongsu: definitely pinyin (`_alt` = the alt-name's pinyin, not another romanization) |
    | `NIAN_HAO.c_nianhao_pin`, `GANZHI_CODES.c_ganzhi_py`, `TEXT_BIBLCAT_CODES.c_text_cat_pinyin`, `SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_py`, `SOCIAL_INSTITUTION_TYPES.c_inst_type_py`, `ADMIN_CAT_CODES.c_admin_cat_py` | Tier 1 | pure pinyin columns (`_py`/`_pinyin`) |
    | `ETHNICITY_TRIBE_CODES.c_name` | Tier 1 | Hongsu: is pinyin; §D-5 verified 498 rows, 11 v all genuine pinyin, 0 Western |
    | `TEXT_CODES.c_title`, `TEXT_INSTANCE_DATA.c_instance_title` | Tier 1 | romanized titles (batch loader `buildPinyin` already uses `PinyinUmlaut`); `c_instance_title` follows `c_title` (same nature; not individually scanned — spot-check at Phase B start) |
    | **`ADDR_CODES.c_name`** | **Tier 2** | mixes English place names + pinyin (verified: `Lvchuan→Lüchuan` etc. correct; `Soviet Far East`/`Vietnam` untouched by the rule); Hongsu: use altname-style prompt on manual input |
    | **`ETHNICITY_TRIBE_CODES.c_romanized`** | **Tier 2** | mixes pinyin + Western (verified: 206 rows, 3 with v: `Kitan-Yelv`/`Kitan-Shulv`→ü correct, `Bavard` skipped by the rule); Hongsu: use altname-style prompt |
    | **`ETHNICITY_TRIBE_CODES.c_surname`** | **Tier 2** | verified 52 rows, 0 v; use altname-style prompt for future input |
    | **`DYNASTIES.c_dynasty`** | **Tier 2** | contains English dynasty names (e.g. `Five Dynasties`); keep as-is, altname-style prompt for future input |
    | **`CHORONYM_CODES.c_choronym_desc`** | **Tier 2** | contains foreign text (e.g. `Vietnam`); keep as-is, altname-style prompt for future input |
    | `ADDR_CODES.c_alt_names` | **excluded** | verified 1516 non-empty, 0 with Latin letters = pure Chinese, no pinyin, never convert |

    > Batch migration: Tier 1 applies the rule directly; Tier 2 also applies the **same rule** but **only converts matched rows after a human eyeball** (Western text stays as-is), and existing hits are tiny (`ADDR.c_name` a few dozen, `c_romanized` 2 [3 contain v; `Bavard` is Western, not converted], `c_surname`/`c_dynasty`/`c_choronym_desc` all 0). So Tier 2 adds almost no batch burden — its value is mainly to **guard future manual input**.
  - **Tests**: per hook point (Tier-1 column: typing `lv` reads back as `lü`; Tier-2 column: typing `lv` triggers the dialog, convert/keep both work, "keep" is not overwritten by the backend; English-translation / Chinese columns unaffected; columns not in the registry unchanged; book-title inline consistent with batch).
- **Scope adjustments**: per D-1, remove `SOCIAL_INSTITUTION_ALTNAME_DATA` from this API's target tables; per D-2, add the `CodesController` audit-fix item; per D-6, add save-time v→ü normalization (hooked at `AbstractCodeTableMutationHandler` + `CodesController` + book-title inline, per the shared registry).

## 0. Background and Motivation

**Phase B** of the pinyin migration plan needs to make **audited batch corrections** to code/lookup tables (place names, office titles, reign periods, book titles, social institutions, administrative categories, etc.). Corrections must go through an audited path (writing `audit_log`), be callable by an external script with a token, and must not use audit-bypassing centralized SQL. After assessing the current state, this capability is **largely missing** and needs to be built.

## 1. Current-State Assessment

### 1.1 The `/codes` admin interface (`CodesController`)
- **Generic**: the direct write path (`store`/`update`/`destroy`) is driven by `config/codes.php` `tables` and can write any column of any code table; composite primary keys are handled via URL encoding (`col1_._col2_._col3`).
- **But it writes only `operations`, not `audit_log`**: all three direct-write methods call `recordOperation()` → `OperationRepository::store()` (writes the `operations` table) and **never call `AuditLogService::write()`**.
- **Not suitable for external batch use**: these are `web` routes requiring a session + CSRF token; not a token API.
- Conclusion: suitable for **manual UI edits** (recorded in `operations` for review), but does **not** satisfy the "external script + full `audit_log`" requirement.

### 1.2 The v2 mutation API (`/api/v2/*`)
- Provides a complete audit pipeline: transaction, `AuditLogService::write()` (writes `audit_log`), `OperationRepository::store()` (writes `operations`), `CompositePrimaryKey` PK validation, field whitelist, change detection, and a proposal mode.
- Handlers are registered/dispatched via `MutationHandlerRegistry`.
- **But code tables have almost no handler**: the only exception is **`NianHaoMutationHandler` (NIAN_HAO)** — it extends `AbstractMutationHandler` directly, sets `person_id` to `0`, and still writes `audit_log` + `operations`. All other code tables (`ADDR_CODES`, `OFFICE_CODES`, `DYNASTIES`, `CHORONYM_CODES`, `ETHNICITY_TRIBE_CODES`, `TEXT_CODES`, `TEXT_INSTANCE_DATA`, `TEXT_BIBLCAT_CODES`, `GANZHI_CODES`, `SOCIAL_INSTITUTION_*`, `ADMIN_CAT_CODES`, etc.) **have no handler**.

### 1.3 Conclusion
- **The audit and primary-key infrastructure is already in place** (`NianHaoMutationHandler` proves a code table can go through the mutation API with full auditing).
- **What is missing** is per-code-table handlers and their registration, plus a shared base class to minimize the extension cost.
- Size estimate: **medium** (~300–500 LOC, depending on the number of tables and whether a shared base is extracted).

## 2. Goals and Scope

- **Goal**: enable an external script to modify code-table columns (primarily pinyin columns, but designed as generic column writes) using a **Bearer token**, audited via `audit_log`, and reviewable.
- **Scope** (aligned with pinyin migration Phase B; per §D-1, `SOCIAL_INSTITUTION_ALTNAME_DATA` is **removed**): `ADDR_CODES`, `OFFICE_CODES`, `DYNASTIES`, `NIAN_HAO` (already done, serves as the template), `CHORONYM_CODES`, `ETHNICITY_TRIBE_CODES`, `TEXT_CODES`, `TEXT_INSTANCE_DATA`, `TEXT_BIBLCAT_CODES`, `GANZHI_CODES`, `SOCIAL_INSTITUTION_NAME_CODES`, `SOCIAL_INSTITUTION_TYPES`, `ADMIN_CAT_CODES`.
- **Non-goals**: do not change existing person sub-resource handler behavior. (Note: fixing `/codes` UI audit is now **required**, see §D-2 — no longer an "optional follow-up".)

## 3. Reusable Existing Infrastructure

- `app/Services/AuditLogService.php`: `write()` already supports any table name.
- `OperationRepository::store()`: `personId` may be `0` (suitable for code tables).
- `app/Support/CompositePrimaryKey.php`: `SCHEMAS` needs code-table PKs registered; `validateOrFail()` and `buildStoredResourceId()` are reusable.
- `MutationHandlerRegistry` / `Api/MutationController` / `MutationReadService`: handler registration, dispatch, and resource definitions.
- **Template**: `app/Services/Mutations/NianHaoMutationHandler.php` (a working example of audited code-table writes).

## 4. Design

- **Shared base `AbstractCodeTableMutationHandler`**: consolidates the boilerplate (transaction, `audit_log`, `operations`, PK validation, field whitelist, change detection; plus a guard — `keyColumns()` must match `CompositePrimaryKey::SCHEMAS`, else 500, preventing a partial-key multi-row UPDATE).
  - **Implementation decision: a single config-driven `ConfigCodeTableMutationHandler` + `config/code_table_mutations.php`** instead of one subclass per table — the 13 tables (NIAN_HAO + 12 new) are highly uniform (only table/PK/whitelist constants differ), so config-driving avoids handler/registry boilerplate and the same config can later host §D-6's "table → pinyin-column Tier" registry. The base keeps its abstract-method design, so a table needing custom validation can still be a subclass. `NIAN_HAO` is folded into this config (the old `NianHaoMutationHandler` subclass is deleted; its 22 API tests pass unchanged).
- **The `person_id` contract (§D-3 option b: zero controller change)**: code-table handlers set `operations.c_personid` to 0 internally and ignore the caller's value; callers still pass `person_id` per the `MutationController` contract (usually 0). **`MutationController` is not modified** — avoiding any change to the existing person sub-resources' required-field validation and cross-checks.
- **Primary keys**:
  - Register single and composite keys (e.g. `TEXT_INSTANCE_DATA` 3-key) in `CompositePrimaryKey::SCHEMAS`.
  - **No-PK table `SOCIAL_INSTITUTION_ALTNAME_DATA`: SKIP — not handled** (see §D-1; do not build a synthetic key).
- **Auth / authorization**: reuse Sanctum **Bearer token**, `active` and non-crowdsourcing (`canWriteDirectly()`); keep both `direct` and `proposal` modes.
- **Endpoints**: reuse `/api/v2/mutate`, `/api/v2/create`, `/api/v2/delete`, routing to the corresponding code-table handler by the `resource` string.
- **resource_id encoding consistency**: the composite-key `resource_id` must match the existing `CodesController` / `OperationsController` format, to avoid breaking `operations` link resolution.

## 5. Implementation Steps

1. Register each code table's PK (single and composite) in `CompositePrimaryKey::SCHEMAS`.
2. Add a resource definition for each code table in `MutationReadService` definitions (`person_id_column: null` + aliases).
3. Create the `AbstractCodeTableMutationHandler` shared base.
4. Create a concrete handler per table (`update` first; `create` / `delete` as needed), and refactor `NianHaoMutationHandler` onto the new base.
5. Register each handler in `MutationHandlerRegistry`.
6. Adjust `MutationController`: make `person_id` optional for code resources.
7. No-PK table `SOCIAL_INSTITUTION_ALTNAME_DATA`: **SKIP — not handled** (§D-1). New item instead: add `AuditLogService::write()` to the `CodesController` direct-write paths (§D-2).
8. Tests: per-table `update` + `audit_log` assertions (old/new, operation_id), composite-PK resolution, authorization (active / non-crowdsourcing), `direct`/`proposal` modes, SQLite/MariaDB compatibility.

## 6. Risks and Cautions

- **`person_id` contract change**: regression-test that existing person sub-resource handlers are unaffected.
- **No-PK table**: `SOCIAL_INSTITUTION_ALTNAME_DATA` is **skipped — not handled** (§D-1).
- **Composite-key resource_id consistency**: align the encoding with the existing `CodesController` / `OperationsController`.
- **UI/API gap**: the `/codes` UI currently writes only `operations`, not `audit_log`; **this plan requires** adding `AuditLogService::write()` to the `CodesController` direct-write paths (§D-2).
- **Database portability**: respect `is_mysql()` / `is_sqlite()`.

## 7. Relationship to the Pinyin Migration Plan

- This plan is a **prerequisite for Phase B (the other, non-person-name pinyin fields)** of the [pinyin migration plan](./PINYIN_V_TO_UMLAUT_MIGRATION.en.md).
- Once this API is built, the Phase B code-table pinyin corrections can be done just like Phase A person names: via an **external script + the audited mutation API**.
- Phase A (person names) does **not** depend on this plan — person names go through the existing `basicinformation` / `altnames` mutation handlers.
- **Both halves of stop-the-bleed align here**: Phase A's "save-time stop-the-bleed" is covered by [PINYIN_SAVE_NORMALIZE_DESIGN.md](./PINYIN_SAVE_NORMALIZE_DESIGN.md) for person-name save paths; the code-table half (§D-6) is built into this plan (new handler + `CodesController` + book-title inline), sharing §D-5's per-table pinyin-column registry with the batch fix. Together, code tables also stop producing new `v` after Phase B.

## 8. To-do Ledger

- [x] Register code-table PKs in `CompositePrimaryKey::SCHEMAS` (+ sync `OperationsController::resourceKeyColumns()`)
- [~] Add code-table resource definitions in `MutationReadService` (`person_id_column: null`) — **not needed for update** (`store()` uses only the registry); add when GET/create/delete are built
- [x] Create the `AbstractCodeTableMutationHandler` shared base (M1)
- [x] Code-table update handler — realized as config-driven `ConfigCodeTableMutationHandler` + `config/code_table_mutations.php` (13 tables = `NIAN_HAO` + 12 new); old `NianHaoMutationHandler` subclass deleted
- [x] Register handlers in `MutationHandlerRegistry` (`ConfigCodeTableMutationHandler` replaces `NianHaoMutationHandler`)
- [x] `person_id` contract: §D-3 option b (handler forces 0 internally, **`MutationController` unchanged**)
- [ ] No-PK table `SOCIAL_INSTITUTION_ALTNAME_DATA`: **SKIP — not handled** (§D-1)
- [x] Tests (`ApiV2MutateCodeTablesTest`: single/multi/triple-column & composite-3-key update, audit, person_id=0, whitelist rejection, proposal, 404, 403; `ApiV2MutateNianHaoTest` 22 unchanged)
- [ ] **(Required, §D-2)** add `AuditLogService::write()` to the `CodesController` direct-write paths, making UI and API auditing consistent
- [x] **(§D-6)** "table → pinyin-column Tier" registry — lives in `config/code_table_mutations.php` as each table's `tier1_fields`/`tier2_fields` (subset of allowed_fields; same source as §D-5)
- [~] **(§D-6)** save-time v→ü normalization at three hooks: **(1) `AbstractCodeTableMutationHandler`/`ConfigCodeTableMutationHandler` done** (Tier 1 silent, Tier 2 not converted by backend); (2) `CodesController` (`store`/`update` + proposal, overlapping §D-2) and (3) `AdminBatchLoadBookTitlesController::updatePinyin()` **in a later milestone**
- [~] **(§D-6)** Tests: **API-handler Tier-1 silent / Tier-2 not-converted / idempotence (normalize before change detection) done** (`ApiV2MutateCodeTablesTest`); `CodesController` / book-title inline / frontend Tier-2 dialog tests pending
- [ ] Doc sync: `AGENTS.md` module entry, and `CHANGELOG.md` as needed
