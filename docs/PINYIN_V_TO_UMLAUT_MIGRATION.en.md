# Database-wide Pinyin `v` → `ü` Normalization Plan

> Status: **Finalized (ready to execute; §D "Locked Decisions" is authoritative — where it conflicts with older prose below, §D governs)**
> Branch: `feature/pinyin-v-to-umlaut-migration` (this branch = the "plan PR" #1087; **each implementation milestone gets its own descriptively-named branch and its own PR**)
> Related PR: [#1086](https://github.com/cbdb-project/cbdb-online-main-server/pull/1086) (generation-dictionary fix, folded into #1087)
> 中文版本: [PINYIN_V_TO_UMLAUT_MIGRATION.md](./PINYIN_V_TO_UMLAUT_MIGRATION.md)

## §D. Locked Decisions (execute as-is; do NOT re-confirm or re-litigate)

> The following were **decided** after the team's email discussion and are ready to execute; no further human confirmation is needed. **Secrets such as tokens are NOT written into this document.**

### D-0 Scope & workflow
- **Split into two independent goals**: **Phase A first** = stop-the-bleed + read-only scan + query expansion + person-name audited batch correction; **Phase B (other code-table pinyin) is a later, separate goal**, gated on first building the [Code-Table Audited Mutation API](./CODE_TABLE_MUTATION_API_PLAN.en.md).
- **Workflow (every milestone)**: first dispatch a "read the code + read the diff" review agent, iterate until no serious issues; then run **codex (a terminal command, NOT an agent)** until no serious issues; only then advance. **Do not merge without explicit human instruction.**
- **Each milestone = its own descriptively-named branch + its own PR** (e.g. `feature/pinyin-stop-the-bleed`, `feature/pinyin-scan-command`, `feature/pinyin-query-expansion`, `feature/pinyin-names-migration`).

### D-1 Do NOT do the code-22 alias
- **Do not create the `ALTNAME_DATA` code-22 "alternative romanization" alias** (team confirmed: query expansion covers search, so code 22 is redundant). Treat every "code 22 / alias" passage below as **NOT to be executed**.

### D-2 Source of truth = the public Google Sheet (per-record list); the scan command only cross-checks
- Two tabs (public, directly CSV-exportable):
  - **ALTNAME_DATA**: `gid=1425535916`, CSV: `https://docs.google.com/spreadsheets/d/19SOyBtA8cKE9aq_hIkxRiT-e2i6f5bFDIY_TcNAn57I/export?format=csv&gid=1425535916`; 957 data rows; columns `table,field,id,wrong_pinyin,correct_pinyin,note_en,note_zh`.
  - **BIOG_MAIN**: `gid=248977087`, CSV: same URL with `gid=248977087`; 11,407 data rows (`c_name` 5,783 / `c_surname` 3,509 / `c_mingzi` 2,115); columns `table,field,id,wrong_pinyin,correct_pinyin`.
- `id` = `c_personid`. **Western names are already human-excluded.** **The Sheet is authoritative — apply it row by row**; the scan command `cbdb:scan-pinyin-v` only cross-checks and reports diffs, and **must not override the Sheet**.

### D-3 BIOG_MAIN application
- **Send only `c_surname` and/or `c_mingzi`** via `/api/v2/mutate`; `c_name` is recomputed by the system. **Ignore the Sheet's `c_name` rows** (the API blocks direct `c_name` writes).

### D-4 The 204 "orphan `c_name`" BIOG_MAIN rows — use "decompose into components" (approach A)
- Definition: in the BIOG_MAIN tab, a `c_personid` that has a `c_name` change but **no** corresponding `c_surname`/`c_mingzi` row — **204** in total (detection: ids present in the `c_name` set but absent from the `c_surname ∪ c_mingzi` set).
- Handling: for each, **read that person's actual `c_surname`/`c_mingzi`/`c_name`**, use the deterministic syllable rule (`lv/lve/nv/nve`) to decide which component holds the `v`, and **update only that component**.
- **Verification gate (mandatory)**: after applying, the recomputed `c_name` MUST equal the Sheet's `correct_pinyin`. **Anything that does not reconcile is NOT written** — collect it into a small exception list for a human (Hongsu) to decide.

### D-5 ALTNAME application (composite-PK resolution)
- The Sheet's `id` = `c_personid`, but `ALTNAME_DATA` has a **3-column composite PK**. Locate the row by **`c_personid = id` AND `c_alt_name = wrong_pinyin`**, resolve the full PK (per `CompositePrimaryKey::SCHEMAS` / a read), then `/api/v2/mutate` to set `c_alt_name` = `correct_pinyin`.
- If a `(c_personid, c_alt_name)` pair matches **>1 row** (ambiguous) → **skip and add to the exception list**.

### D-6 Execution method & cadence
- Use the operator's Sanctum **Bearer token** (an active, non-crowdsourcing user with `canWriteDirectly()`; **the token is NOT written into any file / commit / PR / log**). Use `/api/v2/*`, `mode:"direct"`, audit automatic.
- Flow: **(1) dry-run first**, producing the full planned change set as an artifact and self-asserting "nothing outside the Sheet, no `[OTHER-v]` slips in"; **(2) run all batches through** (BIOG_MAIN one surname per batch, ALTNAME in chunks); **(3) after completion, output a sample** for human spot-check.
- Target = **production directly** (no staging). **Rollback**: every change is an audited mutation, so any erroneous record can be reversed via the same audited API / operations restore.

### D-7 Stop-the-bleed status
- The generation dictionary `app/Models/Pinyin.php` (29 entries) is **done** (commit d4ad265).
- **To build**: a shared `v→ü` helper wired into the generation entry points (`BiogMainRepository::auto_pinyin()`, the three batch `buildPinyin()`, `ApiController::buildPinyinWord()`/`searchPinyin()`).
- The DB `pinyin` table's 4 surnames were **already corrected on production by Frank** → **read-only verify, do not rewrite** (if needed, cross-check via `https://input.cbdb.fas.harvard.edu/app/basicinformation`).

### D-8 Search
- `u` already folds to `ü` via collation (no change needed). **Query expansion** (when a user types `lv/lve/nv/nve`, OR-search both the `v` and `ü` forms) is **in Phase A**. SQLite tests do not fold — account for it.

### D-9 Phase B (forward — executed in the later Phase B goal)
- **Empirical conclusion: code tables are essentially pure pinyin; no Phase-A-style human Sheet is needed.** Evidence: of 498 `ETHNICITY_TRIBE_CODES.c_name` rows, 11 contain `v` — **all genuine pinyin, 0 Western**; of 173 `CHORONYM_CODES.c_choronym_desc` rows, 1 is `Vietnam` — which **has no `lv/nv` syllable cluster, so the rule leaves it untouched**.
- Approach: **apply the deterministic syllable rule directly to both dedicated pinyin columns and romanized-name columns**; the scan command additionally emits a small `[OTHER-v]` list (v that is NOT in an `lv/lve/nv/nve` syllable, e.g. `Vietnam`) for a 30-second human eyeball (a safety net, not a per-row review). `ADDR_CODES` (the largest, not publicly full-scannable) is confirmed by the read-only scan command at Phase B start before any write.
- **D-9a No-PK table `SOCIAL_INSTITUTION_ALTNAME_DATA`: SKIP** (no edit entry point; excluded from the API and the migration).
- **D-9b `/codes` UI audit gap: fix it** (add `AuditLogService::write()` to the `CodesController` direct-write paths, consistent with the new API) — see the code-table API plan.
- **D-9c `ADDRESSES` derived table:** after correcting `ADDR_CODES`, **rebuild** via `cbdb:regenerate-addresses-table` (production, MySQL-only).

### D-10 Final step
- Removing `'pinyin'` from `config/codes.php`'s `ui_hidden` (to re-expose the surname-pinyin table) is **part of the agreed plan** (done after the data is clean), **not a separate human decision**.

## 0. Background and Agreed Decisions

The CBDB pinyin convention has long used `v` in place of `ü` (e.g. `呂 = Lv`, `閭丘 = Lvqiu`, `耶律 = Yelv`). Following the email discussion among Frank Lin, Song Chen, Hongsu, Michael Fuller, and Peter Bol, the following decisions were reached:

1. **`ü` is the single canonical form**; pinyin fields across the database should no longer use `v` in its place (per the *Scheme for the Chinese Phonetic Alphabet / Hanyu Pinyin*).
2. **`v` is accepted on input**: on manual entry / pinyin generation, `v` is normalized to `ü` (canonical storage); on search, a user-typed `v` form matches both `v` and `ü` (query expansion, see §3) for keyboard convenience.
3. ~~The old `v` forms may optionally be kept as an "alternative romanization" alias (`ALTNAME_DATA` code 22)~~ **(DROPPED: not doing it — see §D-1; query expansion covers search)**.
4. **All data changes must be audited**; **centralized SQL that bypasses the audit log must not be used**. Performing the corrections via the audited mutation API (an external script) is the safest approach.

## 1. Planning Principles (adjusted per follow-up discussion)

Per Frank's follow-up suggestions, this plan adopts the following principles to avoid coupling a straightforward data-quality correction with the larger compatibility effort:

- **Decouple data correction from search compatibility**: treat `v → ü` as a **data-quality correction** — equivalent to an editor fixing records one by one in the UI, only more systematic and less error-prone, with full audit traceability. Search compatibility is **separate and deferrable** work and should not block the data correction.
- **Phased, person-names first**: a full-database migration does not need to be done in one go. Start with the **high-visibility, low-risk person-name pinyin**, then handle other pinyin fields (place names, office titles, reign periods, etc.) in later stages after scanning and review.
- **Do not block on downstream systems, nor on the Access edition**: the canonical correction in the online academic database need not wait for downstream systems to implement the same change, nor for the Access edition (the standalone CBDB distribution — a parallel edition rather than a downstream system). We will communicate the change and recommend compatible behavior to both downstream systems and the Access edition, but none of these should block fixing data-quality issues.
- **Execute via the mutation API**: use the existing, tested audited mutation API (an external script) rather than building a new or bypassing write path.

## 2. Pinyin Detection Rule (Core)

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

## 3. Current Search Behavior (determines the scope of compatibility work)

> This section is the basis for "why search compatibility can be deferred".

- **Collation fact**: on production MariaDB, both `utf8mb4_general_ci` and `utf8mb4_unicode_ci` are **accent-insensitive and fold `ü` to `u`**. The affected columns `c_surname`, `c_mingzi`, `pinyin.lastname_pinyin`, and `c_alt_name` are general_ci; `c_name` is unicode_ci — both fold.
- **Conclusion**: users **searching with plain `u` already match `ü` data** with no code change (Frank's live example: searching `yelu` matches `Yelü`, as expected). So changing the data to `ü` does **not** break existing users who search with `u`.
- **The only compatibility gap: users accustomed to typing `v`** (e.g. `Lv`, `Yelv`) — `v` is not folded to `ü` by collation. **The recommended approach is query expansion: when a user types a `v`-containing syllable form (`lv`/`lve`/`nv`/`nve`), the system searches for both the `v` form and the corresponding `ü` form (OR), rather than replacing `v` with `ü` in the query.**
  - Rationale: in a user query it is **not possible to reliably distinguish** whether `lv` is a stand-in for `lü` or part of a Western name (e.g. `Calvin`); replacing it would commit to one interpretation and could make a `v`-containing Western-name query miss. Searching both is the most robust — it matches normalized `ü` data, residual `v` forms, and Western names alike.
  - This is deferrable and non-blocking; and it **makes the code-22 alias unnecessary for search** (if the alias is still done, it is for purposes other than search — see §5.3).
- **SQLite exception**: the test environment (SQLite) uses binary / `NOCASE` comparison and does **not** fold `ü`/`u`. Keep this in mind when writing regression tests (normalize on the test side or use a custom collation if needed); do not infer production behavior from SQLite.

## 4. Scope Inventory (phased)

> Pinyin fields are not limited to name-related columns; place names, office titles, dynasties, reign periods, ethnicities, and other code tables also contain `v`. But per the "person-names first, others in batches" principle, the table below marks the phase. Column names have been verified one by one against `docs/DATABASE_SCHEMA.md`.

### Phase A — person-name pinyin (priority)
| Table | Column | Notes |
|-------|--------|-------|
| `BIOG_MAIN` | `c_surname`, `c_mingzi` | Hanyu Pinyin; the mutation API **can update these directly**, and the update path does not re-run `auto_pinyin` (it will not regenerate-from-Chinese and overwrite your supplied value). |
| `BIOG_MAIN` | `c_name` | Full name (`c_surname + ' ' + c_mingzi`); the mutation API blocks it as a direct input, but the update path **automatically recomputes `c_name` from the merged `c_surname`+`c_mingzi`** (the handler first merges the changes onto the full original record). **So after correcting `c_surname`/`c_mingzi`, `c_name` follows automatically — no separate handling needed.** |
| `ALTNAME_DATA` | `c_alt_name` | Person alt-name romanization (`c_alt_name_chn` is the Chinese; 3-column composite PK). |

### Phase B — other pinyin fields (later batches, after scan + review)
| Table | Column | PK note |
|-------|--------|---------|
| `ADDR_CODES` | `c_name` | Single PK `c_addr_id`; the derived table `ADDRESSES` (`c_name`, `belongs1_Name`…`belongs5_Name`) is rebuilt after changing the source |
| `OFFICE_CODES` | `c_office_pinyin`, `c_office_pinyin_alt` | Single PK `c_office_id` |
| `ETHNICITY_TRIBE_CODES` | `c_name`, `c_romanized`, `c_surname` | Single PK `c_ethnicity_code` |
| `DYNASTIES` | `c_dynasty` | Single PK `c_dy` (in practice almost no `lv/nv`) |
| `NIAN_HAO` | `c_nianhao_pin` | Single PK `c_nianhao_id` |
| `CHORONYM_CODES` | `c_choronym_desc` | Single PK `c_choronym_code` |
| `TEXT_CODES` | `c_title` | Single PK `c_textid` (`c_title_trans` is a translation, not converted) |
| `TEXT_INSTANCE_DATA` | `c_instance_title` | **Composite PK, 3 keys** `c_textid`, `c_text_edition_id`, `c_text_instance_id` |
| `TEXT_BIBLCAT_CODES` | `c_text_cat_pinyin` | Single PK `c_text_cat_code` |
| `GANZHI_CODES` | `c_ganzhi_py` | Single PK; the 60 ganzhi contain no `lü/nü`, expected 0 rows |
| `SOCIAL_INSTITUTION_NAME_CODES` | `c_inst_name_py` | Single PK `c_inst_name_code` |
| `SOCIAL_INSTITUTION_TYPES` | `c_inst_type_py` | Single PK `c_inst_type_code` |
| `SOCIAL_INSTITUTION_ALTNAME_DATA` | `c_inst_altname_py` | **No primary key** (special case, see below) |
| `ADMIN_CAT_CODES` | `c_admin_cat_py` | Single PK `c_admin_cat_code` |

> During the scan, enumerate columns automatically by the rule "any column name ending in `_py` / `_pinyin`, or flagged as romanized in the schema", so future columns are not missed.
> **No-PK special case**: `SOCIAL_INSTITUTION_ALTNAME_DATA` has no PRIMARY KEY. **Per §D-9a it is simply SKIPPED — not handled** (no edit entry point; excluded from the API and the migration).
> **Phase B audit path (assessed — an API must be built first)**: the mutation API (`/api/v2/*`) is currently oriented around persons and their sub-resources; among code tables **only `NIAN_HAO` has a handler**. The `/codes` UI is generic but writes only `operations`, not `audit_log`, and is CSRF web routes unsuitable for an external script. So before starting Phase B, an audited write API for the code tables must be built — see the [Code-Table Audited Mutation API Construction Plan](./CODE_TABLE_MUTATION_API_PLAN.en.md). Do not use audit-bypassing SQL.

### Columns NOT converted
- **Non-Hanyu-Pinyin romanizations**: `BIOG_MAIN.c_name_rm`, `c_surname_rm`, `c_mingzi_rm` (Wade-Giles / McCune-Reischauer, user-editable, different `ü` usage).
- **Native names**: `BIOG_MAIN.c_name_proper`, `c_surname_proper`, `c_mingzi_proper` (Latin script, may contain a genuine `v`).
- **Translation fields**: `OFFICE_CODES.c_office_trans` / `c_office_trans_alt`, `TEXT_CODES.c_title_trans`, `ADMIN_CAT_CODES.c_admin_cat_trans`, etc.
- `v` confirmed to belong to a Western / foreign name (see the exclusion rule in §2).

## 5. Correction Method: External Script via the Existing Audited Mutation API

> Principle: **reuse the tested audited mutation API; no centralized SQL that bypasses the audit.**

### 5.1 Mutation API (verified)
- Endpoints: `POST /api/v2/mutate` (update), `POST /api/v2/create` (create), `POST /api/v2/delete` (delete).
- Auth: Sanctum **Bearer token** (to an active, non-crowdsourcing user); `/api/v2/*` is **CSRF-exempt** → suitable for an external batch script. `mode: "direct"` requires `canWriteDirectly()` (non-crowdsourcing).
- **Audit is automatic**: the handler calls `AuditLogService::write()` and populates `operations` automatically — no extra code.

### 5.2 Person-name pinyin correction
- `BIOG_MAIN.c_surname` / `c_mingzi`: `/api/v2/mutate` **allows direct updates**, and the update path does **not** re-run `auto_pinyin` (it will not regenerate-from-Chinese and overwrite your supplied value). The script can supply the corrected pinyin directly.
- **`c_name` is recomputed automatically (no open item)**: the handler's `buildMergedPayload()` first merges `changes` onto the full original record, then `updateById()` recomputes `c_name` from the merged `c_surname`+`c_mingzi` (along with `c_name_chn`/`c_name_proper`/`c_name_rm`). So the script **only needs to send the corrected `c_surname`/`c_mingzi`** and `c_name` follows correctly — no data-loss risk, no separate handling.
- Suggested batch cadence: a few hundred records at a time, or **one surname per batch**; dry-run / sample-review before submitting for real.

### 5.3 Alias (**DROPPED — not executed; see §D-1**; kept below for record only)
- Search compatibility is achieved via query expansion (§3), so the **code-22 alias is not needed for search**. The following applies only if the old `v` forms are kept as aliases for reasons other than search.
- How: create alias rows via `POST /api/v2/create`, `resource: "altnames"`, `c_alt_name_type_code: 22`, `c_alt_name: <v form>`, plus `c_alt_name_chn` (the PK requires the Chinese name).
- **Prerequisite**: `ALTNAME_CODES` currently has no seed; code 22 "alternative romanization" (with Chinese/English descriptions) must be confirmed/created first, or the FK will reject the unknown code.
- If done, it is best added **after the data cleanup is complete**; whether it is mandatory is undecided.

### 5.4 Scan tool
- A read-only artisan command `php artisan cbdb:scan-pinyin-v` is still recommended: scan the §4 candidate columns, classify by syllable rule (likely pinyin / likely Western name), and output a CSV for human review and alignment with Frank's Google Sheet. This command is **read-only** and safe to run against production.

## 6. Phased Execution Plan (adopting Frank's suggestion)

1. **Stop the bleed**: make `ü` the canonical form for new data input; make the necessary frontend/generation changes to prevent storing new `v`.
   - Merge PR #1086 (`Pinyin::$dic`: `lv/lve/nv → lü/lüe/nü`) + update surnames in the DB `pinyin` table.
   - Add `v → ü` normalization at the "Chinese → pinyin" generation entry points (verified): `BiogMainRepository::auto_pinyin()`, `ApiController::buildPinyinWord()` / `searchPinyin()`, and the three batch importers' own `buildPinyin()` (`AdminBatchLoadOfficesController`, `AdminBatchLoadBookTitlesController`, `AdminBatchLoadSocialInstitutesController`); these paths already call `VariantCharNormalizer::normalize()` before generation, a natural hook point. A shared `v→ü` helper must be created (the repo currently has none).
   - Note: `nve` is not present in the generation dictionary; `nve → nüe` only matters for input normalization.
2. **Correct high-visibility person-name pinyin**: batch-correct via the audited mutation API (§5.2), in batches (a few hundred / one surname at a time).
3. **Search compatibility (executed in Phase A, see §D-8)**: via query expansion, so a user-typed `v` form matches both `v` and `ü` (§3). (The code-22 alias is not done, see §D-1.)
4. **Recommend that downstream systems and the Access edition** add query compatibility — matching both `v` and `ü` forms at query time — when practical (communicate, non-blocking).
5. **Continue scanning and correcting other non-name pinyin fields** (Phase B, §4).

## 7. Risks and Cautions

- **Western-name damage** (Silva/Calvin…): guarded by the syllable rule + manual review; `c_*_proper`, `c_*_rm`, and translation fields are untouched.
- **Current search behavior**: `u` already matches `ü` (collation folding); only `v` needs compatibility, via query expansion (a typed `v` searches both `v` and `ü`), and it can be deferred (§3). SQLite (tests) does not fold — keep this in mind when writing tests.
- ~~Code-22 FK prerequisite~~ **(N/A: code 22 is not done — see §D-1)**.
- **Phase B audit path**: among code tables only `NIAN_HAO` currently has a mutation handler; an audited write API must be built first (see the [Code-Table Audited Mutation API Construction Plan](./CODE_TABLE_MUTATION_API_PLAN.en.md)) before starting (§4).
- **No-PK table**: `SOCIAL_INSTITUTION_ALTNAME_DATA` is **SKIPPED — not handled** (see §D-9a).
- **Derived table consistency**: `ADDRESSES` is rebuilt with `cbdb:regenerate-addresses-table` only after changing the source `ADDR_CODES` (that command is MySQL-only and cannot run on SQLite).
- **No audit-bypassing centralized SQL**: all data changes go through the mutation API or an audited flow.

## 8. Execution Order and To-do Ledger

- [ ] Phase 1 (stop the bleed): merge PR #1086 + update surnames in the DB `pinyin` table to `ü`
- [ ] Phase 1 (stop the bleed): build a shared `v→ü` helper and hook it into the generation entry points (`auto_pinyin` + the three batch `buildPinyin` + `ApiController`) to prevent new `v`
- [ ] Inventory: `cbdb:scan-pinyin-v` read-only scan + report; align with Frank's Google Sheet and the Western-name exclusion list
- [ ] Phase 2 (person names): external script via `/api/v2/mutate` to batch-correct `c_surname`/`c_mingzi` (`c_name` is recomputed automatically by the system), in batches, dry-run reviewed first
- [ ] **(Phase A, §D-8)** Search compatibility: implement query expansion on the pinyin LIKE query side (a typed `v` form searches both `v` and `ü`) (§3)
- [ ] ~~confirm/create `ALTNAME_CODES` code 22, then add aliases~~ **(not doing it — see §D-1)**
- [ ] Communicate with downstream systems and the Access edition, recommending they match both `v` and `ü` forms at query time
- [ ] Phase B prerequisite: build the code-table audited write API per the [Code-Table Audited Mutation API Construction Plan](./CODE_TABLE_MUTATION_API_PLAN.en.md)
- [ ] Phase B: once the API is ready, scan and correct other pinyin fields in batches (including the `TEXT_INSTANCE_DATA` composite PK and the `ADDRESSES` rebuild; `SOCIAL_INSTITUTION_ALTNAME_DATA` is **SKIPPED, see §D-9a**)
- [ ] Regression tests (generation / normalization / person-name correction / audit / Western-name exclusion; mind the SQLite collation difference)
- [ ] Doc sync: `CHANGELOG.md`, and `DATABASE.md` / `README.md` as needed
- [ ] **Final step**: remove `'pinyin'` from `ui_hidden` in `config/codes.php` to re-expose the surname-pinyin table in the codes UI
