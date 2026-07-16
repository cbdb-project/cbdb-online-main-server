# "Physical Delete × ON DELETE CASCADE" Is the One Unacceptable Combination

> 中文版（權威版本）: [ON_DELETE_CASCADE_RISK.md](./ON_DELETE_CASCADE_RISK.md) — this is a translation; in case of discrepancy the Chinese version prevails.
>
> Written: 2026-07-07 ｜ Based on: `database/migrations/2025_01_01_000000_import_cbdb_schema.php` (the source from which the production MariaDB schema was imported)
>
> **Purpose of this document**: to show that this project's schema currently sits in the combination most dangerous to a data asset — "physical delete × cascading delete" — and to explain the principled risk (which should be common knowledge in database practice); to present the escape matrix — **what de-cascading (RESTRICT) and soft deletion (deprecate) each solve, and why the two are complementary rather than either/or** — and to lay out an executable migration roadmap.
>
> Every number in this document can be re-verified with the commands in Appendix A.

---

## TL;DR

1. This project's schema contains **186 `ON DELETE CASCADE` foreign keys** (plus 187 `ON UPDATE CASCADE`), spread across all core tables. The vast majority sit on **reference relationships** from "biographical data → code tables"; meanwhile the application layer performs **physical deletes** on code tables (and most paths do no reference checking).
2. The consequence of this combination: **deleting one code-table record (a reign period, a dynasty, a book title) silently cascades away all biographical data that references it** — including the persons themselves in `BIOG_MAIN`, and then everything belonging to those persons. The application layer's auditing (audit_log), operation records (operations), proposal review, and restore mechanisms are **all completely blind** to it.
3. The escape matrix (the core argument of this document):

   | | `ON DELETE CASCADE` | `RESTRICT` |
   |---|---|---|
   | **Physical delete** | **Status quo: disaster** (one DELETE silently guts the database) | Acceptable (with reference checks and migration tooling) |
   | **Soft delete / deprecate** | A live trap (protected only by convention; one violation is a disaster) | **Target state** |

   At least one factor must be broken; breaking both is the complete solution. **De-cascading is the fuse (it caps the consequences when something goes wrong); soft deletion is the product semantics (deletion no longer happens on the normal path) — they operate at different layers and complement rather than replace each other.**
4. Migration path: flip the constraints first (one migration, the cheapest bleeding-stop), then introduce the code-table lifecycle (deprecate as the primary path, merge for duplicates, physical delete only at zero references). The two steps reinforce each other: once soft deletion is universal there is no legitimate hard-delete flow left, so flipping to RESTRICT meets zero resistance; once RESTRICT is in place, violations of the soft-delete convention hit a backstop. See §5.

---

## 1. Current State (Evidence)

Foreign-key behavior statistics in `import_cbdb_schema.php`:

| ON DELETE behavior | Count |
|---|---|
| `CASCADE` | **186** |
| `SET NULL` | 1 (`fk_merged_person_source`, a newer table — done correctly) |
| `RESTRICT` / `NO ACTION` | 0 |

Most-referenced target tables (incoming edges):

| Referenced table | Incoming CASCADE FKs | Nature |
|---|---|---|
| `BIOG_MAIN` | 25 | Person master table |
| `NIAN_HAO` | 24 | Code table (reign periods) |
| `YEAR_RANGE_CODES` | 23 | Code table |
| `TEXT_CODES` | 22 | Code table (bibliography) |
| `ADDR_CODES` | 11 | Code table (places) |
| `GANZHI_CODES` / `DYNASTIES` | 9 each | Code tables |

Note in particular: **`BIOG_MAIN` itself has 13 CASCADE foreign keys pointing at code tables** (`BIOG_MAIN_ibfk_1`–`13`, pointing at `NIAN_HAO`×4, `YEAR_RANGE_CODES`×3, `GANZHI_CODES`×2, `DYNASTIES`, `CHORONYM_CODES`, `ETHNICITY_TRIBE_CODES`, `HOUSEHOLD_STATUS_CODES`).

At the same time, the application layer's "delete" on code tables is currently a **physical DELETE**: the `/codes` editor and `AdminBatchLoadBookTitlesController::deleteBatch` hard-delete `TEXT_CODES` with no reference check beforehand — both conditions of the matrix's top-left cell hold simultaneously.

### 1.1 Historical Context: a Deliberate Trade-off, Not an Oversight

"Physical delete × CASCADE" was a conscious time-saving choice in the original design: CBDB's code tables were not built entry by entry with dictionary-grade verification, but accumulated **in bulk, with an accepted error rate** — so "physical delete × CASCADE" became the low-cost way to clean up codes together with the data referencing them. This document does not dispute that the trade-off was reasonable at the time; it argues that the cost structure the trade-off rested on has changed:

- With agent assistance, the development cost of moving the cleanup logic carried by the foreign-key mechanism into application code (reference checks, merge-and-redirect, explicit cascades) is now manageable;
- The project's traceability goal is shifting from **discrete traceability** (publishing discrete versions, with the differences between versions unrecoverable) to **linear traceability** (operations / audit_log capture the complete logs between every pair of versions; Phase B has already added audit_log coverage to the `/codes` direct-write path). DB-level cascades are a structural blind spot on that line (§3.3) — without removing them, linear traceability is mechanically impossible;
- The online system and the offline release (Michael's Access database) are structurally separated, with the export function guaranteeing that released versions stay consistent with the Access schema (§5.3) — so the evolution of the online schema (e.g. adding a `c_deprecated` column) is no longer constrained by the release format.

## 2. A Concrete Disaster Scenario

Take `NIAN_HAO` (the reign-period code table) and run one apparently harmless statement:

```sql
DELETE FROM NIAN_HAO WHERE c_nianhao_id = 630;   -- delete one "duplicate" reign period
```

What InnoDB actually executes:

1. Delete the reign period;
2. **Cascade-delete every `BIOG_MAIN` row whose `c_by_nh_code` / `c_dy_nh_code` / `c_fl_ey_nh_code` / `c_fl_ly_nh_code` references it** — i.e. every **person** whose birth/death or floruit years were recorded with that reign period;
3. For each deleted person, cascade onward and delete all of their records across 25 tables: alternate names, kinship, social associations, office postings (including posting addresses), writings, entry into office, events, property…;
4. The mirror rows of kinship/association relations touch **other persons'** data pages;
5. Throughout: the application layer sees affected rows = `1` from step 1 only; **not a single extra row** appears in `operations` or `audit_log`; the proposal-review flow is never triggered; there is no restorable snapshot of any kind.

In other words: **one DELETE can silently erase the complete biographies of hundreds of people, with no way to know afterwards that it ever happened**.

> This sounds unbelievable, so a minimal reproduction was run on a real MariaDB 10.11 with constraints taken verbatim from this schema (Appendix C):
> deleting 1 reign period, `ROW_COUNT()` reports **1**, while 8 rows actually vanish (2 whole persons plus their alt-name/kinship records), the cascade propagates two levels deep,
> and the second-level deletions correspond to no DELETE statement whatsoever that the application layer could intercept.

This is not purely theoretical. The complete accident chain already exists in current code:

- The `/codes` editor and `AdminBatchLoadBookTitlesController::deleteBatch` hard-delete `TEXT_CODES` **with no reference check of any kind before deleting**;
- `TEXT_CODES` has 22 incoming CASCADE edges (`ALTNAME_DATA.c_source`, `BIOG_SOURCE_DATA.c_textid`, `TEXT_DATA`, `KIN_DATA.c_source`… even a `TEXT_CODES` self-reference); deleting one book title silently deletes the alt-name, source, and writing records that reference it;
- `deleteBatch` additionally deletes the corresponding `operations` records — erasing the only remaining trace.

Moreover, **tests are completely unable to catch this class of problem**: the local and CI test environment is SQLite, whose table definitions contain none of these foreign keys (`PRAGMA foreign_keys` is 0 as well); the cascade behavior exists only on production MariaDB. A green test suite proves nothing.

## 3. Why "Physical Delete × CASCADE" Is Dangerous — Principles

### 3.1 It Mistakes "Reference" for "Ownership"

Cascading deletion has exactly one legitimate semantics: **composition** — where a child record is meaningless apart from its parent, as order lines are to an order. Deleting the order deletes the lines; perfectly proper.

But CBDB's foreign keys are almost entirely **references**: an alt-name record "cites" a book as its source; that does not make the alt-name "belong to" the book. Fixing a mistyped book-title entry is code-table maintenance; destroying hundreds of biographical facts along the way is a data disaster. Code tables are **vocabulary**; biographical data is the **asset**. Editing vocabulary should never carry the power to destroy assets.

A useful test: ask "if the referenced entry is deleted, does the historical fact carried by the referencing record still hold?" — the fact "Zhang San's alternate name appears in such-and-such book" does not cease to exist because the book's code entry was deleted. If the fact still stands, the record should not be deleted. Of CBDB's 186 CASCADEs, those that pass the composition test can be counted on one hand (e.g. `POSTED_TO_ADDR_DATA` relative to `POSTED_TO_OFFICE_DATA`).

### 3.2 It Is an Unbounded Amplifier

CASCADE is a **transitive closure**: code table → `BIOG_MAIN` → 25 child tables → mirror rows. The actual blast radius of one DELETE depends on the whole foreign-key graph and the data distribution at that moment; the person (or code) issuing the DELETE **cannot tell from the statement itself** how much will be deleted. The amount of destruction is completely decoupled from the intent of the operation — violating the most basic predictability principle of data manipulation.

### 3.3 It Is Entirely Invisible to the Application Layer

This point is fatal for this project. We have invested heavily in data-protection machinery — `operations` records, `audit_log` before/after images, proposal review (propose/approve), restore — all of which operates at the **application layer**. DB cascades happen at the **storage-engine layer**:

- Rows deleted by cascade get no image in `audit_log` and no record in `operations`;
- Proposal review becomes theater: a single-row edit must pass review, while one code-table DELETE bypasses everything;
- The goal "every operation is restorable" becomes mathematically unattainable — you cannot replay deletions you never saw.

**As long as CASCADE exists, every application-level audit and undo mechanism has a structural skylight.**

### 3.4 The Failure Modes Are Asymmetric

When choosing a mechanism, engineering compares not "which is more convenient when things go right" but "what does it cost when things go wrong":

| | Failure scenario | Consequence | Visibility | Recoverability |
|---|---|---|---|---|
| `RESTRICT` | App layer forgets to handle references before deleting | **Error; operation refused** | Immediate, explicit | Total (nothing happened) |
| `CASCADE` | App layer issues one wrong DELETE | **Data silently destroyed** | Possibly months later | Permanently lost once backups rotate |

RESTRICT's failure mode is "annoying but harmless" (fail-closed); CASCADE's failure mode is "quiet but devastating." CASCADE is safe only under the premise that "the application layer will never issue a wrong DELETE" — a premise that holds in no real system, and this project's `deleteBatch` is a ready-made counterexample. **Note that this argument does not depend on how the application layer intends to delete: whether it uses physical deletion or a soft-delete convention, as long as CASCADE remains, the price of a violation or a bug is a gutted database. This is why the matrix's bottom-left cell in §4 remains unacceptable.**

### 3.5 Why This Counts as Industry Consensus

- Nearly every domain that treats data as an asset (finance, healthcare, archives) has database standards that prohibit or strictly limit cascading deletes, precisely for the reasons in 3.3/3.4: audit integrity requires every change to pass through the application layer.
- Mainstream ORMs (Laravel/Eloquent, Rails, Django, Hibernate) all offer **application-level cascading** as the recommended path, because only application-level cascades trigger hooks, observers, auditing, and business validation; Eloquent model events do not fire at all under DB cascades — which is exactly the mechanism behind this project's audit blindness.
- The accepted use case for `ON DELETE CASCADE` is generally confined to: pure compositional ownership + no application-level audit requirements + a predictable deletion scope. Of CBDB's 186, almost none satisfies all three.

Incidentally, the schema's lone exception, `fk_merged_person_source` (`ON DELETE SET NULL`), comes from recent work — showing that someone inside the project has already made the right call on a new table; what is needed now is to extend that judgment back over the legacy stock.

## 4. The Escape Matrix: What De-cascading and Soft Deletion Each Solve

### 4.1 The Three Conflict Resolutions Under Physical Deletion

The invariant guaranteed by foreign-key constraints is **referential integrity**: no dangling references. When you **physically delete** a target that is still referenced, that invariant is threatened, and mathematically there are only three resolutions:

1. **Refuse** (RESTRICT): the delete is not allowed; go deal with the references first;
2. **Nullify** (SET NULL): set the referencing column to NULL (only for columns where the reference is optional);
3. **Propagate** (CASCADE): delete the referencing rows too.

**All three preserve exactly the same invariant.** Cascade provides no additional consistency whatsoever — it merely picks the option that "automatically destroys the most data," without asking anyone. So "how do we keep consistency without cascade?" is a pseudo-question: after switching to RESTRICT, the database still **enforces** the absence of dangling references; the only change is that the conflict is thrown to the application layer and to humans to decide.

### 4.2 The Fourth Option: Don't Physically Delete (Soft Delete / Deprecate)

The three-way choice above presupposes that "deletion must happen." The standard practice of modern data-asset systems dissolves that premise: **code-table entries are never physically deleted; they are retired (deprecated)**. This is exactly the universal practice of authority-file systems (library authority files, SNOMED, MeSH) — entries never disappear; they are merely flagged "no new references allowed." CBDB's code tables are, in essence, authority files.

After adding a status column to the code tables (semantically `c_deprecated` is recommended over `deleted_at` — the entry has not been deleted, only retired):

- Selectors/search endpoints filter out retired entries, achieving the business purpose of "deletion";
- Foreign keys from existing biographical data remain valid — **no dangling references are created**;
- The historical fact "this person's alternate name appears in this book" is preserved.

Two necessary details:

- **Visibility is contextual, not a global switch**: hide in selectors, but when biographical pages JOIN the code table for display labels, it **must display as usual** (otherwise the reign periods/book titles on old records all go blank). In implementation, only the "pick" endpoints change; the work is bounded.
- **Deprecate solves "retirement"; it does not solve "errors and duplicates"**: in practice the most frequent trigger for "delete this entry" is a typo entry or a duplicate entry (like the duplicated "Zhiyuan 至元"). There the old references point at a **wrong record** — keeping it preserves not history but error: references end up split between the correct and the wrong entry, and retrieval by the correct entry misses that batch forever. The right answer there is **merge + redirect** (re-point the references in bulk to the correct entry; retire the wrong entry and leave a redirect). So a "reference migration tool" is not complexity imposed by the de-cascading plan — it is a requirement of data-quality governance itself, which a soft-delete plan needs just the same.

### 4.3 Why Soft Deletion Cannot Replace De-cascading — the Matrix

Crossing the two dimensions yields this document's core conclusion:

| | `ON DELETE CASCADE` | `RESTRICT` |
|---|---|---|
| **Physical delete** | **① Status quo: disaster.** One DELETE silently guts the database; audits are blind; unrecoverable | **② Acceptable.** Needs reference checks and migration tooling; forgetting them yields an error, not data loss |
| **Soft delete / deprecate** | **③ A live trap.** The normal path is safe, but the protection rests only on "convention"; any violating DELETE (script, migration, bug) triggers the same devastation as ① | **④ Target state.** No deletion on the normal path; fail-closed on violations |

The key is ③: **soft deletion is an application-layer convention; the constraint is a database-layer fuse — different layers**. Convention governs "what to do normally"; the fuse governs "what happens when something goes wrong." Choosing soft deletion while keeping CASCADE is like removing the smoke detector and promising "I won't start a fire" — `deleteBatch` has already demonstrated that conventions get violated. The failure-mode argument of §3.4 applies verbatim to a soft-delete plan.

Conversely, the two reinforce each other:

- **Once soft deletion is universal, flipping to RESTRICT costs zero** — no legitimate hard-delete flow remains, the constraint blocks no normal feature, and it becomes pure mistake-proofing;
- **Once RESTRICT is flipped, the soft-delete convention has a backstop** — a newcomer who doesn't know the convention, or an old script that wasn't updated, gets an error, not a silent disaster.

So this document's claim is not "de-cascade vs. soft delete, pick one," but: **break at least one factor of the matrix (flipping the constraints is the bleeding-stop achievable with a single migration), with ④ as the target state.**

### 4.4 The Full Strategy per Relationship Type

| Relationship type | Example | DB constraint | Application-layer responsibility |
|---|---|---|---|
| **Code-table reference** (vast majority) | `ALTNAME_DATA.c_source → TEXT_CODES`, `BIOG_MAIN.c_dy → DYNASTIES` | `RESTRICT` | Entry lifecycle: **deprecate as the primary path** (retired; hidden in selectors, displayed as usual); **merge + redirect** for errors/duplicates; **true deletion only for zero-reference** entries |
| **Compositional ownership** (few) | `POSTED_TO_ADDR_DATA → POSTED_TO_OFFICE_DATA` | `RESTRICT` (backstop) | **Explicit cascade** at the application layer: within one transaction, expand the child-record set, snapshot each row into audit_log (same operation_id), then delete; UI announces "will also delete N rows" beforehand |
| **Peer mirrors** | `KIN_DATA` / `ASSOC_DATA` reverse rows | `RESTRICT` | Application-layer paired handling + the existing mirror-conflict detection |
| **Optional reference** | `fk_merged_person_source` | `SET NULL` acceptable | Already the correct current template |

"Application-level explicit cascade" is not a new invention — `OfficePostingRepository` already explicitly deletes `POSTED_TO_ADDR_DATA` within the transaction when removing a posting; that is the ready-made correct pattern. The last line of defense is a **periodic orphan scan** (defense in depth, not the primary mechanism).

### 4.5 "But 'Deleting' Becomes a Hassle" — Yes; That Is the Point

After the migration, "deleting" a reign period that is still referenced is no longer one casual click: retire it, or migrate the references away first. **Destroying (or hiding) a code entry still referenced by hundreds of biographical records should never have been an operation you can complete casually.** The hassle does not disappear; it merely moves from "investigating afterwards where the data went" to "two extra clicks beforehand" — the trade-off every data-asset system makes.

## 5. Migration Roadmap

### 5.0 Target State (Matrix ④)

- **A code-table entry lifecycle replaces "deletion"**: normal retirement goes through **deprecate** (hidden in selectors, displayed as usual, existing references untouched); errors/duplicates go through **merge + redirect** (audited bulk re-pointing; the wrong entry retired); **physical deletion only for entries with zero references**, confirmed by a reference check.
- **Deleting owned records** (posting → posting address and other true compositions): a unified deletion service — within one transaction, expand child records, snapshot each row into `audit_log` (same operation_id), delete, restorable as a group.
- **DB constraints uniformly `RESTRICT`**: when any layer above is bypassed, the result is an error rather than data loss. Every write has operations/audit records and is replayable.

### 5.1 Migration Steps

> The order is deliberately "application layer first, DB constraints last": if the constraints were flipped to RESTRICT first, the deletion paths that currently rely implicitly on cascades would immediately start returning 500s. Fill in the application layer first, then flip the constraints — no behavioral cliff anywhere along the way.

**Step 0: Verify the production database (half a day)**
Run the Appendix B query on production MariaDB and export the list of constraints actually in effect. This document is based on the migration file; **production is authoritative**.
Deliverable: `cascade_inventory.csv` (constraint name, table, column, target table, DELETE_RULE, UPDATE_RULE).

**Step 1: Classification and impact inventory (1–2 days)**
Label every CASCADE foreign key with its relationship type (the four classes of §4.4); simultaneously grep all deletion code paths (controllers/repositories/commands) and flag those that **implicitly rely on DB cascades** to clean up references (known: `/codes` deletion, `AdminBatchLoadBookTitlesController::deleteBatch`; a full sweep is needed).
Deliverable: a decision table (one row per constraint: current → target rule → code paths depending on it → application-layer changes required).

**Step 2: Application-layer catch-up (one independent PR each)**
1. **Reference-check service**: input (code table, key value); output per-referencing-table counts and samples — the data source is exactly Step 0's foreign-key list, drivable directly from `information_schema`, no hand-maintained mapping needed.
2. **Code-table lifecycle**: add a `c_deprecated` status column to the code tables (code tables only, not data tables; **adding a column requires prior discussion by the executive committee; the alignment rules for the offline release and the export update are in §5.3, and the export update ships in the same milestone as this column**); selector/search endpoints filter retired entries while display JOINs do not; `/codes` "delete" becomes "retire," with physical deletion available only at zero references; abolish `deleteBatch`'s deletion of `operations` records.
3. **Merge + redirect tool**: re-point all references from entry A to entry B in bulk — audited, restorable, A retired with a redirect left behind. This is also the isomorphic foundation for future "person merge."
4. **Explicit cascade-deletion service**: covering the few relationships Step 1 labels "composition" (following the current `OfficePostingRepository` pattern, adding snapshots and same-operation_id grouping).

**Step 3: Flip the constraints (bleeding-stop migration, run in a maintenance window)**
Execute in batches per Step 1's decision table:

```sql
ALTER TABLE ALTNAME_DATA
  DROP FOREIGN KEY ALTNAME_DATA_ibfk_3,
  ADD CONSTRAINT ALTNAME_DATA_ibfk_3 FOREIGN KEY (c_source)
      REFERENCES TEXT_CODES (c_textid)
      ON DELETE RESTRICT ON UPDATE CASCADE;   -- leave the UPDATE behavior alone at this stage
```

- Only the constraint behavior changes; table structures and data are untouched. Rebuilding an FK requires a validation scan — measure execution time on large tables against a staging replica first, and decide whether to skip validation with `SET foreign_key_checks=0` (existing data consistency is already guaranteed by the original constraint).
- Execute in batches (one group of tables per batch), revertible between batches: the rollback is simply flipping that batch's constraints back to CASCADE — one ALTER, no data risk.
- Prioritize by incoming-edge count: `NIAN_HAO` (24), `YEAR_RANGE_CODES` (23), `TEXT_CODES` (22), `ADDR_CODES` (11), `GANZHI_CODES`/`DYNASTIES` (9), then the rest.
- Update `import_cbdb_schema.php` (or add a migration) in the same change, keeping fresh installs consistent.

**Step 4: Verification and regression (same window as Step 3)**
- Live test on staging: delete a referenced reign period → expect the application layer to block it (or the DB to raise error 1451), with not one row of biographical data lost; retire a reign period → it disappears from selectors while existing records display as usual;
- Check `information_schema`: `DELETE_RULE='CASCADE'` drops to zero within the target batch;
- After the production run, perform the same check plus spot tests.

**Step 5: Wrap-up and long-term items**
- **Orphan-scan schedule**: periodic referential-integrity checks as defense in depth (covering constraint gaps and historical leftovers).
- **`ON UPDATE CASCADE` (187 of them) as a separate project**: key-change propagation does not destroy data and is one grade less dangerous, but it bypasses auditing all the same. Long term, "code-table key changes" should be absorbed into an audited application-layer operation and then flipped to RESTRICT; until then, document and surface the current behavior in docs and UI.
- **The test-environment gap as a separate project**: SQLite having no foreign keys means CI can never test any behavior in this document; MariaDB constraint-related changes need dedicated integration verification (e.g. CI spinning up a MariaDB container to run constraint tests).

### 5.2 Effort and Dependency Overview

| Step | Effort | Depends on | Risk |
|---|---|---|---|
| 0 Verify | Half a day | Read-only access to production | None |
| 1 Classify | 1–2 days | Step 0 | None (pure analysis) |
| 2 App-layer catch-up | Medium (4 small PRs) | Step 1 | Low; each item independently revertible |
| 3 Flip constraints | Low (migration) + maintenance window | Step 2 complete | Medium; staging measurement + batching + single-ALTER rollback |
| 4 Verify | Half a day | Step 3 | None |
| 5 Long-term items | Separate projects each | — | — |

No step on this route is a "big bang": the application-layer PRs are independent, and the constraint flips are batched and revertible batch by batch. Once complete, the system lands in matrix cell ④ (soft delete × RESTRICT): "deletion" no longer happens on the normal path, and on abnormal paths the cost is capped at an error — only then does "every operation is restorable" become mechanically possible for the first time. Until then, the skylight of DB cascades voids the guarantees of any audit/undo engineering — which is why this document's conclusion is: **breaking the "physical delete × CASCADE" combination must come before all other data-safety engineering.**

### 5.3 Offline Releases (SQLite / Access) and Governance Alignment

The online system and the offline releases are structurally separated, with the export function guaranteeing that released versions stay consistent with the Access schema. When `c_deprecated` lands, two things about the offline release must be handled in lockstep:

**(1) Export exclusion rules — deprecated codes are structurally different from the person soft delete, and cannot be excluded wholesale the same way.**

The existing precedent is the export exclusion of soft-deleted BIOG_MAIN persons (commit 8930d73, see `docs/SQLITE_DATA_RELEASE.md`): a deleted person is excluded **as a whole group** — the person row, all of their data rows, and the relationship rows in other people's records that mention them (via FK columns dynamically detected through `information_schema`) all disappear together, leaving no dangling references in the exported file; if the `information_schema` query fails, the entire export aborts fail-closed, with dedicated regression tests. That **pattern** (filtering centralized at query construction, fail-closed, regression tests) is worth reusing.

But the **semantics** of deprecation are the opposite of the person soft delete: existing references are preserved, and only new references are forbidden. The rows referencing a deprecated code all belong to normal persons' normal data and must not be excluded by association; if deprecated codes were excluded wholesale from the export, those data rows would carry dangling foreign-key values (pointing to codes absent from the Access/SQLite file), and JOINs on the Access side would silently drop data. The export rule therefore splits by reference count:

| References to the deprecated code | Export behavior | Notes |
|---|---|---|
| **Zero** | Excluded from the export | Equivalent to "deleted" from Access's perspective, consistent with current expectations |
| **Still referenced** | Exported as an ordinary code row (**without the `c_deprecated` column**) | Fully transparent to Access; no dangling references; on the governance side, merge + redirect (Step 2-3) gradually migrates the references away, and once the count reaches zero the code naturally disappears from the next release |

The export update ships **in the same milestone** as the `c_deprecated` column itself; no window is allowed where the column exists but the export hasn't caught up.

**(2) Governance prerequisite — adding a column requires executive committee discussion.**

Any new column in the online system must first be discussed by the executive committee. Since `c_deprecated` exists only in the online system and — per the table above — is **not exported to Access**, for the committee this is not a change to the shared schema but a **change to the code-table lifecycle policy** ("delete" becomes "deprecate / merge," plus the inclusion rules for released versions). For the discussion, prepare a one-page brief: the semantics of `c_deprecated`, the export rules above, and confirmation that released Access versions keep an unchanged schema. This discussion is a prerequisite for Step 2 milestone 2.

---

## Appendix A: Re-runnable Verification Commands

```bash
# ON DELETE behavior statistics (against the migration)
grep -o "ON DELETE [A-Z ]*" database/migrations/2025_01_01_000000_import_cbdb_schema.php | sort | uniq -c

# Ranking of incoming CASCADE edges per table
grep -o "CONSTRAINT \`[A-Za-z_0-9]*\` FOREIGN KEY (\`[a-z_]*\`) REFERENCES \`[A-Z_]*\`" \
  database/migrations/2025_01_01_000000_import_cbdb_schema.php \
  | sed 's/.*REFERENCES `\([A-Z_]*\)`/\1/' | sort | uniq -c | sort -rn | head -20

# BIOG_MAIN's own foreign keys (13, all CASCADE)
grep -o "CONSTRAINT \`BIOG_MAIN_ibfk_[0-9]*\`[^,]*" database/migrations/2025_01_01_000000_import_cbdb_schema.php

# All foreign keys referencing TEXT_CODES (22 CASCADE + 1 SET NULL)
grep -o "CONSTRAINT \`[A-Za-z_0-9]*\` FOREIGN KEY (\`[a-z_]*\`) REFERENCES \`TEXT_CODES\`[^,]*" \
  database/migrations/2025_01_01_000000_import_cbdb_schema.php
```

## Appendix B: Production Verification Query (MariaDB)

```sql
SELECT rc.CONSTRAINT_NAME, rc.TABLE_NAME, kcu.COLUMN_NAME,
       rc.REFERENCED_TABLE_NAME, rc.DELETE_RULE, rc.UPDATE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
JOIN information_schema.KEY_COLUMN_USAGE kcu
  ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
ORDER BY rc.DELETE_RULE, rc.REFERENCED_TABLE_NAME, rc.TABLE_NAME;

-- CASCADE only
-- ... AND rc.DELETE_RULE = 'CASCADE'
```

## Appendix C: Minimal Reproduction of the Disaster Scenario (Verified on MariaDB 10.11)

Constraint clauses taken verbatim from `import_cbdb_schema.php` (`BIOG_MAIN_ibfk_2`, `ALTNAME_DATA_ibfk_2`, `KIN_DATA_ibfk_1`):

```bash
docker run -d --name cascade-test -e MARIADB_ROOT_PASSWORD=test \
  -e MARIADB_DATABASE=cbdbtest mariadb:10.11
```

```sql
CREATE TABLE NIAN_HAO (c_nianhao_id INT PRIMARY KEY, c_nianhao_chn VARCHAR(50)) ENGINE=InnoDB;
CREATE TABLE BIOG_MAIN (
  c_personid INT PRIMARY KEY, c_name_chn VARCHAR(255), c_by_nh_code INT DEFAULT NULL,
  CONSTRAINT BIOG_MAIN_ibfk_2 FOREIGN KEY (c_by_nh_code)
    REFERENCES NIAN_HAO (c_nianhao_id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB;
CREATE TABLE ALTNAME_DATA (
  c_personid INT NOT NULL, c_alt_name_chn VARCHAR(255),
  CONSTRAINT ALTNAME_DATA_ibfk_2 FOREIGN KEY (c_personid)
    REFERENCES BIOG_MAIN (c_personid) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB;
CREATE TABLE KIN_DATA (
  c_personid INT NOT NULL, c_kin_id INT NOT NULL,
  CONSTRAINT KIN_DATA_ibfk_1 FOREIGN KEY (c_personid)
    REFERENCES BIOG_MAIN (c_personid) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB;

INSERT INTO NIAN_HAO VALUES (630, '至元');
INSERT INTO BIOG_MAIN VALUES (1,'張三',630),(2,'李四',630),(3,'王五',NULL);
INSERT INTO ALTNAME_DATA VALUES (1,'張三別名A'),(1,'張三別名B'),(2,'李四別名'),(3,'王五別名');
INSERT INTO KIN_DATA VALUES (1,3),(2,3),(3,1);

DELETE FROM NIAN_HAO WHERE c_nianhao_id = 630;
SELECT ROW_COUNT();          -- → 1 (the only number the application layer sees)
SELECT COUNT(*) FROM BIOG_MAIN;    -- 3 → 1 (Zhang San and Li Si vanish whole)
SELECT COUNT(*) FROM ALTNAME_DATA; -- 4 → 1
SELECT COUNT(*) FROM KIN_DATA;     -- 3 → 1
```

Measured result: one DELETE, reporting 1 row, actually deleting 8, with the cascade propagating two levels (reign period → persons → the persons' alt-names/kinship records).
The second-level deletions correspond to **no DELETE statement at all**; the application layer (audit, operations, Eloquent observers) has nothing to hook.
Note: in the real schema, `KIN_DATA.c_kin_id` also has a CASCADE foreign key to `BIOG_MAIN`, so rows in **other persons'** records where "the kin is the deleted person" vanish as well (this experiment built only the one-sided foreign key, which is why Wang Wu's kinship row pointing at Zhang San survived) — the real spread is wider than the experiment shows.
