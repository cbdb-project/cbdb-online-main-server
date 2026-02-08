# Audit Log Proposal

## Goal
Introduce a true row-level, fact-based audit log for core `/basicinformation` data changes. This is distinct from the existing `operations` table (which tracks application operations) and from `ai_fill_logs` (which tracks AI-assisted form flows).

The audit log guarantees row-level factual changes only.
It does NOT guarantee full historical semantic reconstruction for referenced lookup / code tables unless explicitly recorded.

Scope: only `/basicinformation` and its 12 subpages, plus the main `BIOG_MAIN` table.

## Target Tables
The initial audit scope covers these business tables:

- `BIOG_MAIN`
- `ALTNAME_DATA`
- `BIOG_ADDR_DATA`
- `BIOG_TEXT_DATA` (aka `TEXT_DATA`)
- `BIOG_SOURCE_DATA`
- `POSTED_TO_OFFICE_DATA`
- `POSTED_TO_ADDR_DATA`
- `ASSOC_DATA`
- `KIN_DATA`
- `EVENTS_DATA`
- `STATUS_DATA`
- `ENTRY_DATA`
- `POSSESSION_DATA`
- `BIOG_INST_DATA`

## Intended Scope
This proposal only targets `/basicinformation` and its 12 subpages. No other modules or tables are included in the initial phase.

## Risks and Costs
- **Write amplification**: every data change adds an extra insert to `audit_log`.
- **Performance impact**: additional writes and larger transactions can slow down high-volume operations.
- **Storage growth**: full-row JSON snapshots grow quickly, especially for frequently updated tables.
- **Query complexity**: without extra indexes, history lookups can be slower as volume grows.
- **Consistency requirement**: audit writes must be in the same transaction to avoid divergence.

## Implementation Outline
1. **Create migration** for `audit_log` using Schema Builder and `is_mysql()`/`is_sqlite()` guards.
2. **Add a small AuditLog service** responsible for:
   - building `row_pk` and `row_pk_text` using primary key schema order
   - capturing `old_data` and `new_data` as full JSON snapshots
3. **Integrate into repositories** (not controllers), starting with:
   - `BIOG_MAIN`
   - `POSTED_TO_OFFICE_DATA` / `POSTED_TO_ADDR_DATA`
   - then expand to the remaining `/basicinformation` tables
4. **Keep writes transactional**: audit entry must be written in the same DB transaction as the data change.
5. **Testing**:
   - ensure migrations run on SQLite
   - cover basic insert/update/delete flows for at least one composite key table
6. **(Optional) Capture semantic snapshots** for selected reference fields where historical human-readable meaning is required.

## Progress Tracker
- [x] Create migration for `audit_log` (`database/migrations/2026_02_08_000000_create_audit_log_table.php`)
- [x] Add AuditLog service (create + tests)
- [x] Integrate `BIOG_MAIN` repository writes
- [ ] Integrate `POSTED_TO_OFFICE_DATA` / `POSTED_TO_ADDR_DATA` writes
- [ ] Integrate remaining `/basicinformation` tables
- [ ] Ensure transactional writes for audit + data changes
- [ ] Add SQLite migration coverage in tests

## Planned Touchpoints
- `app/Services/AuditLogService.php`
- `app/Support/CompositePrimaryKey.php` (RFC 3986 encoding for `row_pk_text`)
- `app/Repositories/*` for `/basicinformation` writes
- `tests/Feature/*` or `tests/Unit/*` for audit log coverage
- `docs/AUDIT_LOG_PROPOSAL.md` progress updates

## Audit Semantics Boundary
The `audit_log` table records factual row-level changes of business tables at the time they occur (field values before/after).

If a field references external lookup or code tables (e.g. office codes, status codes, titles), the audit log records only the referenced identifier by default.

Changes to the semantic meaning of referenced data (e.g. renaming a title or office) are NOT automatically reflected in historical audit records unless one of the following strategies is explicitly applied:

1. The referenced table itself is audited, or
2. A semantic snapshot is recorded together with the row-level change.

This design choice is intentional to avoid full-database audit coupling.

## Additional Guidelines
- **Operation ID source**: do not assume `operation_id` always comes from the `operations` table.
  - If `operations` exists for the change, reuse its ID.
  - If not (scripts, migrations, fixes), generate a new `operation_id`.
  - Treat `operation_id` as a request/command-level UUID, not a foreign key.
- `operation_id` SHOULD be globally unique and sortable when possible (e.g. ULID), but no specific format is enforced at the schema level.
- **Timestamp semantics**:
  - `occurred_at` represents when the business-level data change actually happened.
  - `created_at` represents when the audit record was persisted.
  - In normal request flows, these two timestamps are expected to be identical.
  - Divergence is allowed only for controlled backfill or delayed-write scenarios.
- **Operation semantics**:
  - `operation` reflects data-layer facts only (`INSERT`, `UPDATE`, `DELETE`).
  - Application-level intent semantics (e.g. PUT vs PATCH) MUST NOT be encoded here.
- **Append-only rule**: `audit_log` is append-only. Any `UPDATE` or `DELETE` on `audit_log` is a bug and should be treated as such.

The audit log intentionally does not enforce referential integrity against business tables or the `operations` table.

## Proposed Schema (Compatible with MySQL/MariaDB and SQLite)

Notes:
- This DDL is **conceptual** and not meant to be executed directly in SQLite.
- The actual migration should use Laravel Schema Builder and `is_mysql()`/`is_sqlite()` guards per project rules.
- Avoid `ENUM` and engine-specific SQL for SQLite compatibility.
- `JSON` columns map to `TEXT` in SQLite, which is acceptable for storage but not for JSON indexing.
- No extra indexes or redundant columns are introduced at this stage to keep data minimal.

```sql
CREATE TABLE audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  occurred_at  DATETIME NOT NULL COMMENT 'When the operation actually occurred',
  created_at   DATETIME NOT NULL COMMENT 'When the audit log was written',

  table_name   VARCHAR(64) NOT NULL COMMENT 'Target business table',
  operation    VARCHAR(16) NOT NULL COMMENT 'INSERT/UPDATE/DELETE',

  actor_type   VARCHAR(32) NOT NULL COMMENT 'user/system/job/api_key',
  actor_id     VARCHAR(128) NOT NULL COMMENT 'Actor identifier in business layer',

  operation_id CHAR(26) NOT NULL COMMENT 'Unique identifier of the operation',

  row_pk       JSON NOT NULL COMMENT 'Primary key (supports composite key)',
  row_pk_text  VARCHAR(512) NOT NULL COMMENT 'Stable serialized primary key',

  old_data     JSON NULL COMMENT 'Full row before change',
  new_data     JSON NULL COMMENT 'Full row after change'
);
```

## Row Key Serialization (`row_pk_text`)
We need a stable, query-friendly representation for composite primary keys. The order must be deterministic.

Recommendation:
- Use the **primary key field order defined by the table schema**, reusing the existing composite key definitions (see `CompositePrimaryKey::getSchema()` in `app/Support/CompositePrimaryKey.php`).
- Serialize via `http_build_query()` to match existing conventions used by composite key encoding.
- This guarantees stable ordering per table and aligns with existing logic in the codebase.
- `row_pk_text` MUST be a deterministic, reversible, unambiguous string representation of the primary key, using RFC 3986 URL query string encoding rules (spaces encoded as `%20`, not `+`).

Example:
- `row_pk = {"c_personid":123, "c_sequence":1}`
- `row_pk_text = "c_personid=123&c_sequence=1"`
Escaping example:
- `row_pk = {"code":"A&B=1"}`
- `row_pk_text = "code=A%26B%3D1"`

## Write Location (Implementation Guideline)
Audit logs should be written at the Repository/Service layer within the same database transaction as the data change. This avoids divergence between row data and audit log entries.

Note (current implementation):
- `BIOG_MAIN` inserts are currently performed in `BasicInformationController` (including `saveas` and `Duplicate_Collateral_Info`) and in `Api\OperationsController@storeProcess`. Audit log writes have been added on these paths and are executed in the same transaction as the data write.

## Non-Goals (for this initial proposal)
- No JSON indexes or additional search columns (to avoid premature redundancy).
- No history backfill.
- No UI for audit log browsing.

## Future Optimizations (Optional)
If history lookup becomes too slow or volume grows:
- Add indexes on `table_name`, `row_pk_text`, `occurred_at`.
- Introduce filtering by `actor_type`/`actor_id`.
- Consider partitioning in MariaDB for very large volumes.

## Version
- Version: 0.2
- Date: 2026-02-07
