# Code-Table Audited Mutation API Construction Plan

> Status: Draft plan (for discussion)
> Branch: `feature/pinyin-v-to-umlaut-migration`
> Related plan: [Pinyin v → ü Normalization Plan](./PINYIN_V_TO_UMLAUT_MIGRATION.en.md) (this plan is a **prerequisite for its Phase B**)
> 中文版本: [CODE_TABLE_MUTATION_API_PLAN.md](./CODE_TABLE_MUTATION_API_PLAN.md)

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
- **Scope** (aligned with pinyin migration Phase B): `ADDR_CODES`, `OFFICE_CODES`, `DYNASTIES`, `NIAN_HAO` (already done, serves as the template), `CHORONYM_CODES`, `ETHNICITY_TRIBE_CODES`, `TEXT_CODES`, `TEXT_INSTANCE_DATA`, `TEXT_BIBLCAT_CODES`, `GANZHI_CODES`, `SOCIAL_INSTITUTION_NAME_CODES`, `SOCIAL_INSTITUTION_TYPES`, `SOCIAL_INSTITUTION_ALTNAME_DATA`, `ADMIN_CAT_CODES`.
- **Non-goals**: do not change existing person sub-resource handler behavior; this plan does not require changing the `/codes` UI (adding audit there is an optional follow-up, see §5).

## 3. Reusable Existing Infrastructure

- `app/Services/AuditLogService.php`: `write()` already supports any table name.
- `OperationRepository::store()`: `personId` may be `0` (suitable for code tables).
- `app/Support/CompositePrimaryKey.php`: `SCHEMAS` needs code-table PKs registered; `validateOrFail()` and `buildStoredResourceId()` are reusable.
- `MutationHandlerRegistry` / `Api/MutationController` / `MutationReadService`: handler registration, dispatch, and resource definitions.
- **Template**: `app/Services/Mutations/NianHaoMutationHandler.php` (a working example of audited code-table writes).

## 4. Design

- **Shared base `AbstractCodeTableMutationHandler`**: consolidates the boilerplate (transaction, `audit_log`, `operations`, PK validation, field whitelist, change detection); each concrete per-table handler only implements a few methods such as `tableName()`, `resourceAliases()`, `keyColumns()`, `allowedFields()` (refactor `NianHaoMutationHandler` to be the first user of this base, removing its "passes a `person_id` that is then ignored" design smell).
- **The `person_id` contract**: `MutationController` currently requires `person_id` for every resource. For code-table resources, `person_id` should be **made optional** (or a code-table-specific resolution path provided), to avoid stuffing in a meaningless `person_id`. This change must ensure existing person sub-resource handlers are unaffected.
- **Primary keys**:
  - Register single and composite keys (e.g. `TEXT_INSTANCE_DATA` 3-key) in `CompositePrimaryKey::SCHEMAS`.
  - **No-PK special case `SOCIAL_INSTITUTION_ALTNAME_DATA`**: define a synthetic-identifier strategy (a uniquely-identifying column combination) or exclude it from the API and handle it manually.
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
7. Handle the no-PK special case (`SOCIAL_INSTITUTION_ALTNAME_DATA`).
8. Tests: per-table `update` + `audit_log` assertions (old/new, operation_id), composite-PK resolution, authorization (active / non-crowdsourcing), `direct`/`proposal` modes, SQLite/MariaDB compatibility.

## 6. Risks and Cautions

- **`person_id` contract change**: regression-test that existing person sub-resource handlers are unaffected.
- **No-PK table**: `SOCIAL_INSTITUTION_ALTNAME_DATA` cannot go through per-row auditing; needs a special case.
- **Composite-key resource_id consistency**: align the encoding with the existing `CodesController` / `OperationsController`.
- **UI/API gap**: the `/codes` UI still writes only `operations`, not `audit_log`; to make the UI consistent with the API, the `CodesController` direct-write path could later add `AuditLogService::write()` (listed as an optional follow-up in this plan).
- **Database portability**: respect `is_mysql()` / `is_sqlite()`.

## 7. Relationship to the Pinyin Migration Plan

- This plan is a **prerequisite for Phase B (the other, non-person-name pinyin fields)** of the [pinyin migration plan](./PINYIN_V_TO_UMLAUT_MIGRATION.en.md).
- Once this API is built, the Phase B code-table pinyin corrections can be done just like Phase A person names: via an **external script + the audited mutation API**.
- Phase A (person names) does **not** depend on this plan — person names go through the existing `basicinformation` / `altnames` mutation handlers.

## 8. To-do Ledger

- [ ] Register code-table PKs in `CompositePrimaryKey::SCHEMAS`
- [ ] Add code-table resource definitions in `MutationReadService` (`person_id_column: null`)
- [ ] Create the `AbstractCodeTableMutationHandler` shared base
- [ ] Per-code-table concrete handlers (starting with `update`); refactor `NianHaoMutationHandler` onto the new base
- [ ] Register handlers in `MutationHandlerRegistry`
- [ ] `MutationController`: make `person_id` optional for code resources (and regress existing handlers)
- [ ] No-PK table `SOCIAL_INSTITUTION_ALTNAME_DATA` special-case handling
- [ ] Tests (update / audit / composite PK / authorization / modes / compatibility)
- [ ] (Optional follow-up) add `AuditLogService::write()` to the `CodesController` direct-write path, making UI and API auditing consistent
- [ ] Doc sync: `AGENTS.md` module entry, and `CHANGELOG.md` as needed
