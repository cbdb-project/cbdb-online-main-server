# Audit Log Proposal

## Goal
Introduce a true row-level audit log for core `/basicinformation` data changes. This is distinct from the existing `operations` table (which tracks application operations) and from `ai_fill_logs` (which tracks AI-assisted form flows).

Scope: only `/basicinformation` and its 12 subpages, plus the main `BIOG_MAIN` table.

## Target Tables
The initial audit scope covers these business tables:

- `BIOG_MAIN`
- `ALTNAME_DATA`
- `BIOG_ADDR_DATA`
- `TEXT_DATA`
- `BIOG_TEXT_DATA`
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

## Proposed Schema (Compatible with MySQL/MariaDB and SQLite)

Notes:
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

Example:
- `row_pk = {"c_personid":123, "c_sequence":1}`
- `row_pk_text = "c_personid=123&c_sequence=1"`

## Write Location (Implementation Guideline)
Audit logs should be written at the Repository/Service layer within the same database transaction as the data change. This avoids divergence between row data and audit log entries.

## Non-Goals (for this initial proposal)
- No JSON indexes or additional search columns (to avoid premature redundancy).
- No history backfill.
- No UI for audit log browsing.

## Future Optimizations (Optional)
If history lookup becomes too slow or volume grows:
- Add indexes on `table_name`, `row_pk_text`, `occurred_at`.
- Introduce filtering by `actor_type`/`actor_id`.
- Consider partitioning in MariaDB for very large volumes.
