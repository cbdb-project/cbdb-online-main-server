# BiogMainRepository Refactor Plan

## Goal
Reduce complexity in `BiogMainRepository` without breaking existing transactional behavior or repository-level data flow. This plan focuses on reorganizing responsibilities around business workflows (not one table = one repository).

## Non-Goals
- No behavior changes in this phase.
- No schema changes.
- No API contract changes.
- No UI changes.

## Why Not One Table = One Repository
The current codebase uses **transactional business flows** that span multiple tables (e.g. office/posting + address + posting meta). Splitting by table risks:
- breaking transaction boundaries,
- scattering cross-table invariants,
- making audit/operation logging inconsistent.

Therefore, the recommended split is **workflow/domain-based**, not table-based.

## Target Split (Workflow-Based)
These are the recommended modules and their ownership boundaries:

1. **Basic Info**
   - `BIOG_MAIN`, `ALTNAME_DATA`, `BIOG_TEXT_DATA`, `BIOG_SOURCE_DATA`
   - CRUD for main biographical data and sub-entities

2. **Office / Posting**
   - `POSTED_TO_OFFICE_DATA`, `POSTED_TO_ADDR_DATA`, `POSTING_DATA`
   - Store/update/delete office posting and its address collection
   - Maintains shared resource_id behavior for `POSTED_TO_ADDR_DATA`

3. **Relationships**
   - `ASSOC_DATA`, `KIN_DATA`
   - Association and kinship flows

4. **Events / Status**
   - `EVENTS_DATA`, `STATUS_DATA`
   - Event and status flows

5. **Other Modules**
   - `ENTRY_DATA`, `POSSESSION_DATA`, `BIOG_INST_DATA`

Each module should expose **workflow-level methods**, not low-level table CRUD.

## Transaction Boundaries (Must Preserve)
- Office posting flows must remain in a **single transaction** across `POSTING_DATA`, `POSTED_TO_OFFICE_DATA`, `POSTED_TO_ADDR_DATA`.
- Audit and operations records must be written in the **same transaction** as data changes.

## Phase Plan

### Phase 0 — Preparation (No Behavior Change)
- Create new repository/service classes for each workflow module.
- Add thin delegators in `BiogMainRepository` to forward calls.
- Keep method signatures stable.

### Phase 1 — Office/Posting Extraction
- Move `officeStoreById`, `officeUpdateById`, `officeDeleteById` into a dedicated module.
- Ensure transaction boundary stays in the new module.
- Keep `BiogMainRepository` methods as wrappers (deprecated comments).

### Phase 2 — Other Modules Extraction
- Move each workflow in small batches (one module at a time).
- Update all call sites to use new module classes.

### Phase 3 — Cleanup
- Remove deprecated methods in `BiogMainRepository`.
- Update documentation and architecture notes.

## Risk & Mitigation
- **Risk**: accidental transaction split
  - **Mitigation**: enforce transactional entry points in new module classes
- **Risk**: inconsistent operation/audit logging
  - **Mitigation**: logging remains within workflow modules
- **Risk**: unknown call sites
  - **Mitigation**: search for method usage and update one module at a time

## Suggested Class Names
- `BiogBasicInfoRepository`
- `PostingRepository`
- `RelationshipRepository`
- `EventStatusRepository`
- `EntryRepository`
- `PossessionRepository`
- `InstitutionRepository`

## Tracking
This plan is informational only. No code changes are made on this branch.

## Version
- Version: 0.1
- Date: 2026-02-10
