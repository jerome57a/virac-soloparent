# Database tooling safety

## Test configuration

Database-backed test scripts fail closed unless the process explicitly sets all
of these variables:

```powershell
$env:APP_ENV = 'testing'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3307'
$env:DB_NAME = 'virac_solo_parent_test'
$env:DB_USER = 'soloparent_test'      # or soloparent_tmpdb; see "Two-account design"
$env:DB_PASS = '<test-only password>'
$env:SMS_PROVIDER = 'test'
```

Use `DB_HOST=127.0.0.1`: the isolated MariaDB instance runs with `skip-name-resolve`, so an
account defined on `localhost` does not match a TCP connection.

Use the two dedicated database users described under "Two-account design and grant SQL"
(`soloparent_test` for the persistent test database, `soloparent_tmpdb` for tools that
create disposable databases). Neither is `root`, and neither holds a global privilege.
Keep credentials in the process environment or a test runner secret store. Do not
put them in `config/local.php`, source control, command output, or logs. Test
scripts check these settings before loading the application database connector.
They reject missing settings, a non-testing environment, any database name other
than `virac_solo_parent_test`, and a non-test SMS provider. Transaction rollback
and temporary tables are additional isolation techniques, never the safety gate.

Run the no-connection guard checks with:

```powershell
php database/tool_safety_guard_checks.php
```

This script uses in-memory configuration arrays and performs no SQL.

## Independent Save Verification test

`database/test_save_verification.php` does not use `virac_solo_parent` or
`virac_solo_parent_test`. It never loads `config/database.php`, selects no
existing database, and copies no tables or rows from one. It requires these
process environment variables (not `config/local.php`):

```powershell
$env:APP_ENV = 'testing'
$env:SMS_PROVIDER = 'test'
$env:DB_HOST = '127.0.0.1'        # only localhost or 127.0.0.1; use 127.0.0.1 (skip-name-resolve)
$env:DB_PORT = '3307'
$env:DB_USER = 'soloparent_tmpdb' # dedicated account; root is rejected
$env:DB_PASS = '<test-only password>'
```

`DB_NAME` is ignored. The test creates `solo_review_test_` + 12 hex characters,
builds the schema from `database/schema.sql` (database-level `DROP DATABASE`,
`CREATE DATABASE` and `USE` are skipped; only table and trigger DDL runs; any
other statement stops setup), inserts synthetic roles, one category and three
required document requirements, and drops only that generated database. The
name is validated before creation and again before the drop.

Privileges for this tool are the `solo\_review\_test\_%` line of the final grant SQL under
"Two-account design and grant SQL" below (`CREATE, DROP, ALTER, INDEX, TRIGGER, SELECT,
INSERT, UPDATE, DELETE`). `REFERENCES` is not granted: MariaDB lists it as unused.

For `test_save_verification.php` only, the account needs no privilege on
`virac_solo_parent` or `virac_solo_parent_test`. This does **not** apply to
`test_staff_review_workflow.php`, `test_simplified_renewal.php` or
`test_new_application_uploads.php`, which read table definitions from
`virac_solo_parent_test` (see "Privileges for the staff workflow and simplified renewal
tests" below). `TRIGGER` is needed here for two reasons: the tool creates triggers, and a
trigger runs under its definer, which is this account, so the definer must hold `TRIGGER`
on the table.

## Temporary database prefixes and grant patterns

Four tools create and drop their own disposable databases for the acceptance tests, and a
fifth, `sync_schema.php`, does so only in its optional check mode. Each generates a
name from a fixed prefix plus a lowercase hexadecimal suffix and validates it
(`validate_temporary_database_target()` in `database/tool_safety.php`) before any
SQL runs. The validator rejects `virac_solo_parent` and `virac_solo_parent_test`.
(The validator itself accepts a 12- or 16-character hexadecimal suffix; the four test
scripts generate 12, `sync_schema.php` generates 16.)

Re-validation before the drop differs by tool:

- `test_save_verification.php`: as described above, the name is validated before
  creation and again before the drop.
- `test_staff_review_workflow.php`, `test_simplified_renewal.php` and
  `test_new_application_uploads.php`: the name is validated once, before any SQL. These
  scripts run `DROP DATABASE` directly on the same variable and do **not** re-validate
  immediately before the drop. The variable is not reassigned between validation and
  drop in the inspected code, but no re-validation step exists.
  (`drop_temporary_database()` in `database/tool_safety.php` does re-validate; these
  scripts do not use it.)

| Tool | Prefix | Grant pattern (database level) | In first acceptance run |
|---|---|---|---|
| `database/test_save_verification.php` | `solo_review_test_` | ``solo\_review\_test\_%`` | yes |
| `database/test_staff_review_workflow.php` | `solo_review_test_` | ``solo\_review\_test\_%`` | yes |
| `database/test_simplified_renewal.php` | `renewal_test_` | ``renewal\_test\_%`` | yes |
| `database/test_new_application_uploads.php` | `newapp_upload_test_` | ``newapp\_upload\_test\_%`` | yes |
| `database/sync_schema.php` (**optional**) | `sp_schema_check_` (16 hex) | ``sp\_schema\_check\_%`` | **no, excluded** |

The `solo_review_test_` grant covers only that prefix. **It does not cover
`test_simplified_renewal.php` or `test_new_application_uploads.php`**, which each need
their own separate grant pattern (``renewal\_test\_%`` and ``newapp\_upload\_test\_%``).
Without it, the test fails at `CREATE DATABASE` with an access error. Grants for the
patterns are independent; do not widen one pattern to cover another (for example, do not
use `%` or `%\_test\_%`). If several patterns match one database name, MariaDB applies
only the most specific one; the patterns above are disjoint and none matches
`virac_solo_parent_test`.

**Optional `sync_schema.php`.** `php database/sync_schema.php --check-with-temp-db
--allow-temporary-database` builds a `sp_schema_check_` + 16-hex reference database from
`schema.sql`, compares it with `DB_NAME`, prints the differences and applies nothing. It
is not an application test and is **excluded from the first acceptance run**; no account
holds the `sp\_schema\_check\_%` grant until it is separately approved. If it is enabled
later it additionally needs `CREATE, DROP, ALTER, INDEX, TRIGGER, SELECT` on
``sp\_schema\_check\_%`` and `SELECT, TRIGGER` on `virac_solo_parent_test` (`SHOW TRIGGERS`
requires `TRIGGER`). `GET_LOCK()` needs no privilege.

**Wildcard create/drop is unverified.** MariaDB documents `_` and `%` as wildcards in
database-level grants and the `\_` escape, but the documentation does not state that a
`CREATE` or `DROP` grant on a pattern authorizes `CREATE DATABASE` / `DROP DATABASE` only
for matching names. This has **not** been tested on this server. It requires a
separately approved empirical test after provisioning (create and drop one matching
disposable database as the account, and confirm that a non-matching name is refused).
The pattern also matches any name with the prefix, not only the 12-hex names the scripts
generate; the hex check is enforced by the scripts, not by MariaDB.

In MariaDB database-level grant patterns, `_` is a single-character wildcard and
`%` matches any string. Each literal underscore in a pattern must therefore be
escaped as `\_`; an unescaped pattern such as `solo_review_test_%` would also
match unrelated database names. The escaped patterns are shown in the table.

### Privileges for the staff workflow and simplified renewal tests

Applies **only** to `test_staff_review_workflow.php`, `test_simplified_renewal.php` and
`test_new_application_uploads.php`. It is based on a read-only source inspection (no
SQL, tests or scripts were run and no server was contacted) and must not be
generalized to other database tools. The privileges the inspected code paths need are
recorded here; the GRANT statements are in "Two-account design and grant SQL" below.

**Source test database `virac_solo_parent_test`** (the guard requires
`DB_NAME=virac_solo_parent_test`):

- Required: `SELECT` only.
- Used for `SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'` and
  `SHOW CREATE TABLE` (table definitions only; no rows are copied). The source
  connection runs no `INSERT`, `UPDATE` or `DELETE` in these tests.
- `SELECT` is sufficient for both statements. **Verified** against the MariaDB
  documentation: `SHOW CREATE TABLE` requires `SELECT` on the table, and `SHOW TABLES`
  lists only tables on which the account holds some privilege (a database-level `SELECT`
  covers every table). Not yet observed on this server.

**Temporary databases** (each pattern is granted independently):

| Test | Prefix | Database-level pattern |
|---|---|---|
| `test_staff_review_workflow.php` | `solo_review_test_` | ``solo\_review\_test\_%`` |
| `test_simplified_renewal.php` | `renewal_test_` | ``renewal\_test\_%`` |
| `test_new_application_uploads.php` | `newapp_upload_test_` | ``newapp\_upload\_test\_%`` |

- Minimum privileges on the matching pattern for each test:
  `CREATE, DROP, ALTER, SELECT, INSERT, UPDATE, DELETE`; `test_new_application_uploads.php`
  additionally needs `TRIGGER`.
- `CREATE` and `DROP` cover `CREATE DATABASE` / `DROP DATABASE` and the cloned
  `CREATE TABLE` statements. **`ALTER` is required** (correcting an earlier version of this
  document): `ALTER TABLE ... AUTO_INCREMENT = ...` is issued by
  `upload_fixture_isolate_ids()` / `upload_fixture_prepare_application_id()` in
  `database/upload_fixtures.php` (reached by the staff workflow through
  `checklist_flow_checks.php` and `automatic_interview_checks.php`, and by
  `test_new_application_uploads.php`), and directly by `test_simplified_renewal.php`
  (`ALTER TABLE applications AUTO_INCREMENT`). `DELETE` is exercised by the draft cleanup
  that runs when an application or renewal is submitted. `UPDATE` is used by status,
  checklist, appointment and settings changes (including
  `INSERT ... ON DUPLICATE KEY UPDATE`) and by `SELECT ... FOR UPDATE`, which needs
  `SELECT` plus `UPDATE`.
- `TRIGGER` for `test_new_application_uploads.php`: it creates and drops a
  `fixture_fail_history` trigger in its disposable database.
- Do not widen the patterns to cover each other (for example `%` or `%\_test\_%`).
- No global privilege was found to be required. `SUPER` is not needed (`log_bin` is off;
  `schema.sql` has no `DEFINER` clauses). `FILE` and `GRANT OPTION` are not used.

**Not required by `test_staff_review_workflow.php` and `test_simplified_renewal.php`:**
`INDEX`, `REFERENCES` and `TRIGGER`. In the inspected code these two tests issue no
`CREATE INDEX` or `CREATE TRIGGER`. Foreign keys and indexes arrive inside the
`CREATE TABLE` text returned by `SHOW CREATE TABLE`. `REFERENCES` is listed as "unused"
in the MariaDB 10.4 GRANT documentation and is not granted anywhere in this plan. Application
code reached by the tests (services, models, controllers, includes) contains no DDL.
`test_save_verification.php` runs `ALTER TABLE` and `CREATE TRIGGER` from `schema.sql`;
`INDEX` is kept on the `solo_review_test_` pattern only as a precaution, because it was not
confirmed whether adding an index or constraint through `ALTER TABLE` (`schema.sql`
line 492) needs `INDEX` in addition to `ALTER`.

**Second connection.** `test_staff_review_workflow.php` opens an additional
connection to the same temporary database with the same account
(`database/appointment_management_checks.php`) to verify slot capacity against a
booking committed by another connection. It needs no privilege beyond the pattern
grant above, but the account must be allowed more than one session.

**Cloned databases omit triggers.** The tests copy only base-table definitions
with `SHOW CREATE TABLE`, which does not include triggers. The temporary databases
therefore do not contain the `audit_logs_no_update` and `audit_logs_no_delete`
triggers defined in `schema.sql`. This is why `TRIGGER` is not needed, but it also
means these tests do not exercise the audit-log immutability triggers. Views are
not cloned either (the schema defines none).

### What the earlier inspection reported, and what remains unverified

Reported by an earlier source inspection (`test_staff_review_workflow.php`,
`test_simplified_renewal.php`, `tool_safety.php`, and the code the tests call). **Not
independently re-verified** when this document was last corrected; the sources were not
re-opened:

- The prefixes and name format above, and that each script contains exactly one
  `CREATE DATABASE` and one `DROP DATABASE`, both using only the generated name.
- `CREATE DATABASE`, `DROP DATABASE`, `SHOW FULL TABLES` and `SHOW CREATE TABLE`
  are the only statements run on the source connection.
- All fixture and application statements run on the temporary-database
  connection(s) and are `SELECT`, `INSERT`, `UPDATE` or `DELETE`, plus
  `CREATE TABLE` during cloning.

Resolved by the MariaDB documentation (not yet observed on this server): `SELECT` is
sufficient for `SHOW CREATE TABLE` and `SHOW FULL TABLES`; `SHOW TRIGGERS` requires
`TRIGGER`; `DROP TEMPORARY TABLE` needs no `DROP` privilege ("for temporary tables, no
privilege is required"); `REFERENCES` is unused in MariaDB, so foreign keys need no
`REFERENCES` privilege; a trigger runs under its definer, which needs `TRIGGER` on the table.

Unverified (inferred or not observed; an administrator must verify before the
tests are run, and none of this has been tested against a server):

- **Wildcard `CREATE DATABASE` / `DROP DATABASE`:** that a `CREATE` / `DROP` grant on a
  database-name pattern authorizes creating and dropping only matching databases. Requires
  a separately approved empirical test (see "Temporary database prefixes and grant
  patterns").
- Whether `INDEX` is needed in addition to `ALTER` for adding an index or constraint with
  `ALTER TABLE` (kept on the `solo_review_test_` pattern as a precaution).
- That `ALTER TABLE ... AUTO_INCREMENT` needs only `ALTER`.
- That session settings such as `FOREIGN_KEY_CHECKS` and `sql_mode` need no
  privilege.
- That the three disposable-database scripts run no application query against
  `virac_solo_parent_test` before switching to the disposable database, so that
  `SELECT` is enough for `soloparent_tmpdb` there (only `SHOW` statements, `CREATE DATABASE`
  and `DROP DATABASE` were seen on the source connection).
- Dynamic or runtime-built SQL: the inspection was static, so SQL assembled at
  runtime could not be exhaustively ruled out.
- The privileges the MariaDB accounts actually hold.

### Check the real grants first

This document does not grant anything, and the patterns above do not prove the
account has them. Before running either test, a database administrator must
independently check the grants that actually exist for the dedicated test
account (for example with `SHOW GRANTS` for that account) and confirm that:

- the account has privileges on the correct pattern for the test being run;
- it has no privilege on `virac_solo_parent`, and no broader pattern;
- it is not `root`;
- for the staff workflow, simplified renewal and new-application upload tests, the
  account used (`soloparent_tmpdb`) has only `SELECT` on `virac_solo_parent_test`, and
  holds no `sp\_schema\_check\_%` grant until `sync_schema.php` is separately approved.

**Limits of the guard.** `database/tool_safety.php` rejects `DB_USER=root`
(case-insensitive, trimmed), requires `DB_NAME=virac_solo_parent_test`, and
refuses the production name. It does **not** inspect grants, so it cannot detect a
non-root account that has excessive privileges. Production safety therefore
depends on the account's actual grants, which only the administrator's check above
can establish.

## Two-account design and grant SQL

**As built (confirmed 2026-11-16).** Both accounts exist on the isolated instance
(`127.0.0.1:3307`, MariaDB 10.4.32): `soloparent_test@127.0.0.1` and
`soloparent_tmpdb@127.0.0.1`. Their `SHOW GRANTS` match the SQL below, with no global
privilege other than the implicit `USAGE`, and both can authenticate. No third (import)
account exists; schema and fixtures are loaded as `root@127.0.0.1` (see "Import procedure").
The SQL below was originally written as a proposal ("Proposed for review; not executed")
and is kept as the reference definition. This section replaces the earlier single-account
guidance. Both accounts use host
`127.0.0.1` (the instance binds to `127.0.0.1` and runs with `skip-name-resolve`), each gets
its own generated password supplied through a protected local file or the process
environment (never a command line, project file or Git), and neither holds a global
privilege, `SUPER`, `FILE` or `GRANT OPTION`.

- **`soloparent_test`**: tests that run against the persistent `virac_solo_parent_test`
  database (eligibility history, public tracking, the Node fixtures and live pages, and the
  four temporary-table tests below).
- **`soloparent_tmpdb`**: tests that create disposable databases (save verification, staff
  workflow, simplified renewal, new-application uploads). It holds only `SELECT` on the
  persistent database, which satisfies the "only `SELECT` on `virac_solo_parent_test`" check.

```sql
-- Account A: persistent-database tests
CREATE USER 'soloparent_test'@'127.0.0.1' IDENTIFIED BY '<generated, never displayed>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES
  ON `virac_solo_parent_test`.* TO 'soloparent_test'@'127.0.0.1';

-- Account B: disposable-database tests
CREATE USER 'soloparent_tmpdb'@'127.0.0.1' IDENTIFIED BY '<generated, never displayed>';
GRANT SELECT ON `virac_solo_parent_test`.* TO 'soloparent_tmpdb'@'127.0.0.1';
GRANT CREATE, DROP, ALTER, INDEX, TRIGGER, SELECT, INSERT, UPDATE, DELETE
  ON `solo\_review\_test\_%`.* TO 'soloparent_tmpdb'@'127.0.0.1';
GRANT CREATE, DROP, ALTER, SELECT, INSERT, UPDATE, DELETE
  ON `renewal\_test\_%`.* TO 'soloparent_tmpdb'@'127.0.0.1';
GRANT CREATE, DROP, ALTER, TRIGGER, SELECT, INSERT, UPDATE, DELETE
  ON `newapp\_upload\_test\_%`.* TO 'soloparent_tmpdb'@'127.0.0.1';
```

Not included: `REFERENCES` (unused in MariaDB), any `sp\_schema\_check\_%` grant
(`sync_schema.php` is optional and excluded from the first acceptance run), and any
privilege on `virac_solo_parent`.

**Provisioning order (each step needs its own approval).**

1. Create `virac_solo_parent_test` (`utf8mb4` / `utf8mb4_unicode_ci`) as an administrator.
2. Load the schema and synthetic fixtures as `root@127.0.0.1`. The audit-log triggers take
   their definer from the importing account, so do not import as `root@angii` (that account
   is inert while `skip-name-resolve` is on); a trigger whose definer does not exist fails
   when it fires.
3. Create the two accounts and issue the grants above.
4. Run `SHOW GRANTS` for both accounts and compare with this section.
5. Run the separately approved wildcard create/drop test (see "Temporary database prefixes
   and grant patterns").

The `audit_logs` triggers refuse `UPDATE` and `DELETE` in the persistent database by design;
the disposable databases are built from `SHOW CREATE TABLE` and do not have them.

**Known deviation (resolved 2026-11-16): default `test` rows in `mysql.db`.** On the isolated
instance (`127.0.0.1:3307`, MariaDB 10.4.32) orphaned `mysql.db` rows with `User=''`, `Host='%'`
for the databases `test` and ``test\_%`` gave **both** accounts above broad privileges on those
names, in addition to their audited grants; `SHOW GRANTS` did not show it. No anonymous account
existed, and `REVOKE` on the rows failed with error 1141 (no matching in-memory grant).
Resolution: exactly two rows were deleted from `mysql.db` with the narrow predicate
`User = '' AND Host = '%' AND HEX(Db) IN ('74657374','746573745C5F25')`; exactly 2 rows were
affected and `FLUSH PRIVILEGES` completed. Postflight confirmed no remaining `User=''`,
`Host='%'` rows and no active privileges for `''@'%'`, and that both accounts above kept
their original grants. The empty `test` database itself still exists; it was not dropped or
modified. Details: `docs/mariadb-test-instance-runbook.md`, section 7.

## Additional privilege: `CREATE TEMPORARY TABLES`

Applies **only** to these four tests, which are separate from the disposable-database
tests above:

- `database/test_admin_management_layout.php`
- `database/test_role_account_creation.php`
- `database/test_user_account_actions.php` (**deferred**)
- `database/test_optional_applicant_gmail.php` (**deferred**)

> **Deferred tests.** `test_user_account_actions.php` and `test_optional_applicant_gmail.php`
> belong to the optional applicant Gmail and account-management work, which is not part of
> the pruning checkpoint and is **not yet committed**. They are analysed here because they
> will use the same isolation guard once they are added. Wherever this document names them,
> read "deferred, not yet committed"; until then only the other two tests exist in the
> repository.

Based on read-only source inspection; nothing was run and no server was contacted.

What they do: connect with the guarded test settings (`DB_NAME=virac_solo_parent_test`),
read table definitions with `SHOW CREATE TABLE` from that database, rewrite
`CREATE TABLE` to `CREATE TEMPORARY TABLE`, strip the `CONSTRAINT` (foreign key) lines
because temporary tables cannot have them, and run all fixture writes against those
connection-local temporary tables. They create no database and drop none.

| Requirement | Detail |
|---|---|
| Database | `virac_solo_parent_test` only (database-level, not global) |
| Privilege this section adds | `CREATE TEMPORARY TABLES` (needed to create the connection-local copies) |
| Source-table privilege (separate) | `SELECT` on each real source table whose definition is cloned with `SHOW CREATE TABLE`, plus `SELECT` on the one real table the admin layout test reads (listed in the trace below). Expected to be sufficient for `SHOW CREATE TABLE`; inferred from MariaDB behavior, not tested |
| Not needed by these four | `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, `TRIGGER`, any global privilege, and any pattern grant such as `solo\_review\_test\_%` or `renewal\_test\_%`. `DROP` on the real tables is not needed. All four tests issue `DROP TEMPORARY TABLE`; per the MariaDB documentation that needs no `DROP` privilege (see below) |

`SELECT` on the real source tables and `CREATE TEMPORARY TABLES` are two different
privileges that serve two different purposes: `SELECT` lets the test read a table
definition (and, for `applications` in the admin layout test only, read rows), while
`CREATE TEMPORARY TABLES` lets it create the connection-local tables, including the
hand-written stand-ins that need no source `SELECT`. Neither one substitutes for the
other.

### Table trace

Based on a read-only trace of the four scripts and the application code they reach
(nothing was run). **Not independently verified:** the trace below was taken from the
pre-import review and applied as a documentation correction; the PHP sources were not
re-opened or re-traced while making this correction.

Two kinds of temporary table appear:

- **Cloned:** a connection-local copy created from `SHOW CREATE TABLE` of the real table
  (foreign-key lines removed). Each cloned table needs `SELECT` on its real source table.
- **Hand-written stand-in:** a temporary table whose `CREATE TEMPORARY TABLE` text is
  written inside the test script. It is **not** a clone of the source definition, issues no
  `SHOW CREATE TABLE`, and needs no source-table privilege.

All fixture reads and writes for a temporary table go to the temporary table.

| Test | Cloned temporary tables (source `SELECT` needed) | Hand-written stand-ins (no source privilege) | Real table read directly |
|---|---|---|---|
| `test_role_account_creation.php` | `users`, `applicants`, `audit_logs` | none | none |
| `test_optional_applicant_gmail.php` | `users`, `applicants`, `audit_logs` | none | none |
| `test_user_account_actions.php` | `users`, `applicants`, `audit_logs` | `applications`, `appointment_slots`, `appointments` | none |
| `test_admin_management_layout.php` | `sms_logs`, `public_announcements` | none | `applications`, read-only (`SELECT`) |

Source-table `SELECT` in `virac_solo_parent_test` is therefore needed on: `users`,
`applicants`, `audit_logs`, `sms_logs`, `public_announcements`, and `applications`. Only
`test_admin_management_layout.php` needs source-table `SELECT` on `applications` (it reads
the real table). **No source-table `SELECT` is required on `appointment_slots` or
`appointments` for these four tests**; in `test_user_account_actions.php` they exist only
as hand-written temporary stand-ins. Real-table access that is not read-only was not found
in this trace (not independently verified).

All four tests issue `DROP TEMPORARY TABLE`. The MariaDB documentation states that for
temporary tables no privilege is required for `DROP`; this is documented behavior, not yet
observed on this server.

Keep the two requirement sets separate. The `CREATE, DROP, ...` pattern grants
documented above are for tools that create disposable databases; they are not needed
by these four tests and do not substitute for `CREATE TEMPORARY TABLES` on
`virac_solo_parent_test`. Granting the disposable-database privileges to the account to
satisfy these four tests would be unnecessary widening. The account's real grants must
still be checked by an administrator (see "Check the real grants first"); this document
grants nothing.

Notes and unverified points:

- A temporary table shadows a real table of the same name for that connection only and
  disappears when the connection closes. The trace above lists the tables the review
  found shadowed; that it is complete for the four scripts (no other real table touched,
  and the only real-table read being `applications` in
  `test_admin_management_layout.php`) is **not independently verified**.
- **Unverified assumptions (not tested on this server):** (1) `INSERT`, `UPDATE`,
  `DELETE` and `SELECT` on a temporary table that the same connection created need no
  privilege beyond `CREATE TEMPORARY TABLES`; (2) the tables vanish when the connection
  closes. These match documented MariaDB/MySQL behavior but must be confirmed by an
  administrator before the tests are relied on. (`DROP TEMPORARY TABLE`, which all four tests
  issue, needs no `DROP` privilege according to the MariaDB documentation.)
- Foreign keys are removed and `SHOW CREATE TABLE` omits triggers, so these tests do not
  exercise cascade rules or the `audit_logs` immutability triggers.
- If the account lacks the privilege, `CREATE TEMPORARY TABLE` fails and the test stops
  before any fixture write.

## Synthetic test data (proposal, not applied)

Proposed files, **not imported and not executed**:

| File | Purpose |
|---|---|
| `database/testing/schema_test.sql` | Copy of the table, index, foreign-key and trigger definitions of `schema.sql`. That the body is line-for-line identical to `schema.sql` is **not independently verified** (`schema.sql` was not compared in this correction). Needs a database created with `utf8mb4` / `utf8mb4_unicode_ci` and the command-line client (`DELIMITER`). Contains no `DROP DATABASE`, no `CREATE DATABASE`, no production name and no rows. Its only database selection is `USE virac_solo_parent_test;`. |
| `database/testing/seed_test.sql` | Fictional rows for a clean test database: 3 roles, 1 administrator, 1 staff, 2 applicants, 3 `TEST` categories, required and optional document requirements, 2 renewal requirements, 2 applications with children, history and checklists, settings and 3 SMS templates. `sms_enabled` is `0`. **Requires MariaDB 10.2.7 or later** (the `JSON` column type of `schema_test.sql` is the highest requirement; see "Minimum MariaDB version"); MySQL compatibility is not claimed. |

Rules for the seed:

- `schema.sql` and `seed.sql` are unchanged. The proposed seed never creates the
  production administrator and contains no known default password.
- **No password or hash is stored in the file.** The seed reads two bcrypt hashes from
  session variables, `@test_admin_password_hash` and `@test_user_password_hash`. A guard
  block runs before the first `INSERT` and raises an error (`SIGNAL`) if either variable
  is `NULL` or does not match the bcrypt-shaped pattern (`$2a$`, `$2b$` or `$2y$`, a
  two-digit cost, then 53 characters of any kind), or if the session `sql_mode` contains
  neither `STRICT_ALL_TABLES` nor `STRICT_TRANS_TABLES`. The seed does not change
  `sql_mode`; the administrator establishes strict mode for the import session only (see
  "Server version and session sql_mode") and the guard stops the run if it is not strict.
- **The procedure requires MariaDB 10.2.7 or later.** The seed uses an anonymous
  `BEGIN NOT ATOMIC` block, which is MariaDB syntax, and `schema_test.sql` (imported first)
  uses `JSON` columns. See "Minimum MariaDB version" in the Import procedure for how the
  number was established and what is still unconfirmed. Do not read this document as
  implying the seed works on MySQL; MySQL compatibility is not claimed and was not checked.
- **Hash-format validation does not prove a hash corresponds to a known password, and does
  not validate exact length.** The guard checks the shape only. The pattern's end anchor
  (`$`) can also match just before a single trailing newline, so a value of 53 characters
  plus one trailing newline may pass (reasoned from regular-expression behavior, not
  tested on this server), and the 53 characters are not restricted to the bcrypt
  alphabet. A well-formed hash of an unknown or discarded secret passes, and so would a
  well-formed string that was never produced by bcrypt. Whether anyone can sign in with
  the seeded accounts depends on how the administrator made the hashes, not on this
  check. Each hash must be a single line with no trailing whitespace; the guard is not a
  substitute for that.
- The administrator creates each hash from a random secret that is not printed, logged
  or committed, for example by having PHP call `password_hash()` on `random_bytes()`
  and print only the resulting hash, then writing the three statements of the one-time
  file (strict-mode setting and the two hash `SET` statements, see "Temporary files")
  outside the repository, to be deleted afterwards (deletion is not secure erasure;
  see "Import procedure"). If browser tests must sign in, the secret must instead come
  from the test runner's secret store, never from the repository. The claim that no tool
  in this repository generates, prints or requests these values is **not independently
  verified** in this correction.
- The import must stop on the first error. `--force` (`-f`) is prohibited both as a
  command-line option and through any client option file or `my.ini` (a `force` setting
  there is equivalent). The import must never be pointed at `virac_solo_parent`; both files
  `USE` the test database explicitly. See "Import procedure" below.
- The seed adds no `application_documents` rows: their `stored_path` would reference
  files that must not exist in shared storage. Tests that need uploads create them in
  the isolated storage described below.
- Most existing tests create their own rows; this seed is the minimum for the schema
  based tests and manual or browser checks. It was not matched against every test.

### Import procedure (proposal, not applied)

Performed by a database administrator only, after approval. Nothing here has been run: **no schema or fixture import has been performed** on the test instance, and each of the two needs its own explicit approval.

**Target (fixed).** Every import connection goes to the isolated test instance only: host `127.0.0.1`, port **`3307`**, database `virac_solo_parent_test`. **Port `3306` and the XAMPP MariaDB service must never be used**, not even to "check" or to read from; stop if a client reaches either. Only the XAMPP client program `mysql.exe` is borrowed (read-only); the XAMPP server is never contacted. Each command below therefore carries `--protocol=TCP --host=127.0.0.1 --port=3307 --database=virac_solo_parent_test` explicitly, in addition to the option file. See the runbook, `docs/mariadb-test-instance-runbook.md`, sections 1 and 3.
**This document does not state that the import is safe or ready to execute.** Every
command below is a proposal that the administrator must review, adapt and take
responsibility for. Windows commands are for `cmd.exe`, not PowerShell. Words in capital
letters (`ADMIN_NAME`, `IMPORT_ACCOUNT_PASSWORD`, and so on) are placeholders the
administrator replaces with real values before use; no angle brackets are used because
`<` and `>` are redirection operators in `cmd.exe`.

**Prerequisites**

- The test database `virac_solo_parent_test` exists, is empty, and was created with
  character set `utf8mb4` and collation `utf8mb4_unicode_ci` (the schema file does not
  create it and most tables inherit the database default). "Initial emptiness and object
  check" must pass before Step 1. See "Before any drop and recreate" if it has to be
  created or recreated; nothing in this document drops anything.
- **The server must be MariaDB 10.2.7 or later.** `schema_test.sql` uses `JSON` columns and
  the seed's anonymous `BEGIN NOT ATOMIC` guard block is MariaDB syntax; MySQL is not
  supported and its compatibility was not checked. Verify the installed version first
  (see "Minimum MariaDB version" and "Server version and session `sql_mode`").
- The MariaDB command-line client is used (`mysql.exe` or `mariadb.exe`).
  `schema_test.sql` needs it for `DELIMITER`, and `seed_test.sql` needs it for `DELIMITER`
  and the guard block. See "Locating the XAMPP client".
- **Import account: `root@127.0.0.1` on the test instance (decision below).** Connect with
  `--host=127.0.0.1` so the session is `root@127.0.0.1` (not `root@angii`, which is inert
  under `skip-name-resolve`). Neither `soloparent_test` nor `soloparent_tmpdb` may be used: the
  first lacks `CREATE`, `ALTER`, `INDEX` and `TRIGGER`, the second holds only `SELECT` on this
  database. Credentials are kept in a client option file (below) rather than on a command
  line. This procedure contains no `DROP` statement; dropping the database remains a
  separate administrator action.
- **Why not a dedicated import account (decision, 2026-11-16).** An earlier version of this
  procedure proposed a third account with `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, `TRIGGER`,
  `INSERT`, `UPDATE`, `SELECT` on `virac_solo_parent_test`. It is **not adopted and not
  created**, because the documents do not establish a safe design for it:
  1. The privilege list is not derived from the contents of `schema_test.sql` and
     `seed_test.sql` anywhere in the documents (for example whether any view, routine or
     event needs further privileges). `REFERENCES` is documented here as unused.
  2. The audit-log triggers take their definer from the importing account, and a trigger
     whose definer does not exist fails when it fires. A throwaway import account that is
     dropped afterwards would break the triggers, while keeping it makes it a permanent
     third account that must keep `TRIGGER` and `INSERT` on the audit table. Which of these
     is acceptable is not documented.
  3. No account name, host or credential handling is defined for it.
  `root@127.0.0.1` already exists, is the documented importer in "Two-account design and
  grant SQL" (provisioning step 2) and is the definer the triggers were designed around.
  Using it trades least privilege for a defined, existing trigger definer; the risk is
  limited by the isolated loopback-only instance (`127.0.0.1:3307`), by this account being
  used for import and administration only, never by a test script, and by the fixed target above. A least-privilege import
  account stays **unresolved** and would need: (a) a privilege list derived from the two
  SQL files, (b) a decision on the trigger-definer lifecycle, and (c) an approved name and
  credential handling. Do not create one on the strength of this document.
- A working folder for all temporary files, **outside the repository and outside the web
  root**: for example `C:\Users\ADMIN_NAME\soloparent-import-temp`. It must not be under
  `C:\xampp\htdocs` (served by Apache) or `C:\xampp\htdocs\virac-soloparent`. Every
  temporary file in this procedure goes there and is referred to by a full, quoted,
  absolute path.

**Temporary files: access, encoding and contents**

Three files are created in the working folder:

| File | Contents |
|---|---|
| `import-client.cnf` | Client option file with only `[client]`, `host=127.0.0.1`, `port=3307`, `user=root`, `password=IMPORT_ACCOUNT_PASSWORD` (the test instance's own `root` password, never the XAMPP one). Nothing else: no `force`, no `init-command`, no `!include`, no other option. |
| `session_and_hashes.sql` | Exactly three statements, each on its own line, ending with a newline: `SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES');`, then `SET @test_admin_password_hash = 'PASTE_ADMIN_HASH_HERE';` and `SET @test_user_password_hash = 'PASTE_USER_HASH_HERE';`. |
| `combined_seed.sql` | Created by the `copy /b` command in Step 2; the previous file followed by the repository's `seed_test.sql`. |

- **`port` and server identity.** The port is always `3307` on host `127.0.0.1`, written in
  the file and repeated on every command line. **Never use `3306` or the XAMPP service port.**
  MariaDB's client documentation says the port is otherwise taken from the option files, then
  the `MYSQL_TCP_PORT` environment variable, then `/etc/services`, then the built-in default
  3306, so a file with no `port` line, or an inherited environment variable, could reach the
  XAMPP server. That is why the port is stated twice and why "Server version and session
  `sql_mode`" includes a server-identity check that must pass before anything else.
- **Password characters.** MariaDB's option-file documentation says double quotes may
  quote a value and that `\n`, `\r`, `\t`, `\b`, `\s`, `\"`, `\'` and `\\` are escape
  sequences, but it does not say how `#`, quotes or backslashes inside a password are
  read. That is **unverified**, so the password written to this file should consist only of
  letters and digits (no `#`, quotes, backslashes, spaces or `%`) and be written without
  quotes. The import account is the existing `root`, so this constrains its existing
  password; if it contains other characters, the administrator must test the quoting on a
  throwaway account first (not by changing grants of the two test accounts).
- **Create the folder, then restrict it, before any file is created.** In `cmd.exe`, run
  `mkdir "C:\Users\ADMIN_NAME\soloparent-import-temp"`. If it reports that the folder
  already exists, stop and inspect it; do not reuse a folder from an earlier run.
  `ADMIN_NAME` must be the exact Windows account name of the person running the import (the
  value of `%USERNAME%`; a domain or machine-qualified form such as `MACHINE\ADMIN_NAME`
  is acceptable where the account is not local). If the account named in the grant is not
  the account that runs the command, removing inherited access can lock the operator out.
  Then restrict it:
  `icacls "C:\Users\ADMIN_NAME\soloparent-import-temp" /inheritance:r /grant:r "ADMIN_NAME:(OI)(CI)F"`.
  The Microsoft `icacls` page documents `/grant:r`, the `(OI)(CI)F` rights and the use of
  friendly account names, but lists the inheritance switch as `/inheritancelevel:r`
  (documented there as "Disables inheritance and removes only inherited ACEs"). The
  spelling `/inheritance:r` above is widely used but is **not confirmed by that page**, so
  the administrator must read the local `icacls /?` help and use the spelling this Windows
  version accepts. The page does not document `icacls` exit codes, so read its printed
  result, then run `icacls "C:\Users\ADMIN_NAME\soloparent-import-temp"` and confirm that
  no other user or group has access. Do not create any file until that listing is correct.
- **Saved without a BOM.** Save each file as plain UTF-8 **without** a byte-order mark
  (in Notepad, "UTF-8", not "UTF-8 with BOM" and not "Unicode"; in other editors, the
  equivalent). A BOM at the start of `session_and_hashes.sql` is read as part of the first
  statement, and a UTF-16 file is not valid input. Do not create these files by redirecting
  PowerShell output (`>`), whose default encoding differs by version. Confirm the encoding
  from the editor's status bar; do not display the file contents to check.
- **No shared copies.** Do not paste the hashes into chat, tickets, shell history, source
  control or a synced folder (for example OneDrive), and do not leave the files open in an
  editor that keeps backup or recovery copies.

**Locating the XAMPP client**

XAMPP installs MariaDB under its `mysql` folder, so the client is normally at
`C:\xampp\mysql\bin\mysql.exe`. The XAMPP install folder can differ: open the XAMPP
Control Panel and use "Explorer" or "Shell" to see it, then look in `mysql\bin` under it.
Confirm the file with:

```
"C:\xampp\mysql\bin\mysql.exe" --version
```

The output must say `MariaDB`. Always call the client by its quoted full path. Do not rely
on a bare `mysql` from `PATH`; `where mysql` lists other clients that might be picked up
instead, including a MySQL client. (Typical XAMPP layout; not verified on this machine.)

**Server version and session `sql_mode`**

Before importing, verify the server and the session mode with read-only queries:

```
"C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --protocol=TCP --host=127.0.0.1 --port=3307 --database=virac_solo_parent_test --default-character-set=utf8mb4 --execute="SELECT VERSION(), @@port, @@datadir, @@GLOBAL.sql_mode, @@SESSION.sql_mode;"
```

- **Server identity.** `@@port` must be exactly `3307`, `@@datadir` exactly
  `C:\dev\mariadb-test-3307\data\` (batch output may print the backslashes doubled) and
  `VERSION()` MariaDB 10.4.32. If `@@port` is `3306`, if `@@datadir` is anywhere under
  `C:\xampp`, or if anything else differs, stop immediately: the client reached the XAMPP
  or another server. Run no further command and import nothing. (That `@@port` and
  `@@datadir` are available this way is standard MariaDB behavior that this document did not
  confirm against the documentation.)
- `VERSION()` must report MariaDB 10.2.7 or later (see "Minimum MariaDB version" below). If
  it reports MySQL, or an earlier MariaDB, stop; the procedure is not supported there.
- `@@SESSION.sql_mode` is the effective mode of that session (it can differ from
  `@@GLOBAL.sql_mode` if an option file or client sets an initial command). The seed
  needs `STRICT_ALL_TABLES` or `STRICT_TRANS_TABLES` in it.
- To make the seed session strict **without changing any global or server setting**, the
  first statement of `session_and_hashes.sql` sets it for that session only:
  `SET SESSION sql_mode = ...` as shown above, which appends `STRICT_ALL_TABLES` to the
  mode the session already has. Do not run `SET GLOBAL sql_mode`, and do not edit
  `my.ini` or any server configuration. The seed's own guard re-checks the mode and stops
  the run if it is still not strict. (The `CONCAT_WS`/`NULLIF` form and whether a
  duplicated `STRICT_ALL_TABLES` entry is accepted were **not confirmed against MariaDB
  documentation** and are not tested on this server.)

**Minimum MariaDB version**

The document uses the highest requirement found, **10.2.7**:

- `JSON` columns in `schema_test.sql` (`application_drafts`, `eligibility_assessments` and
  `chat_messages` each have one). The MariaDB 10.2.7 release notes
  (https://mariadb.com/kb/en/mariadb-1027-release-notes/) state "The JSON data type (an
  alias for LONGTEXT) was introduced." That is the highest requirement, so Step 1 needs
  10.2.7 or later.
- The seed's anonymous `BEGIN NOT ATOMIC` block. MariaDB's documentation says "`NOT ATOMIC`
  is required when used outside of a stored procedure" and its 10.1 change list says
  "Compound statements can be used outside of stored programs". **The patch number 10.1.1
  that earlier versions of this document and `seed_test.sql` gave was not confirmed by any
  page consulted**; it is lower than 10.2.7 and therefore does not change the requirement.
- `schema_test.sql` header line 27 says "the seed requires MariaDB 10.1.1 or later". That is
  a **minimum-version requirement**, not the version of the test server (which is 10.4.32),
  and it is **stale**: it is lower than the 10.2.7 that the `JSON` columns need. The test
  server satisfies both. The SQL file is not edited here; this document owns the
  requirement, and **10.2.7 governs**. (`seed_test.sql`'s own header was not re-inspected
  in this correction.)
- Other pages consulted: https://mariadb.com/kb/en/begin-end/ and
  https://mariadb.com/kb/en/changes-improvements-in-mariadb-101/. The administrator should
  confirm against the release notes for the server actually installed. Nothing here was
  verified at runtime.

**Initial emptiness and object check (before Step 1)**

Run these before the first schema import, and again after any drop and recreate. They are
read-only. They run as the import account from `import-client.cnf`, so they see only what
that account's privileges let it see: object lists from `information_schema` may be
**incomplete** for an account that lacks privileges on routines or events, so a clean result
here is necessary, not sufficient. (That `information_schema` hides objects the account has
no privilege on is expected MariaDB behavior that was not confirmed against the
documentation.)

Query 1, the database and its defaults:

```
"C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --protocol=TCP --host=127.0.0.1 --port=3307 --database=virac_solo_parent_test --default-character-set=utf8mb4 --execute="SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'virac_solo_parent_test';"
```

- **Pass:** exactly one row, `SCHEMA_NAME` equal to `virac_solo_parent_test`,
  `utf8mb4` and `utf8mb4_unicode_ci`.
- **Fail:** zero rows (the database does not exist, or the account cannot see it), more than
  one row, any other name, or any other character set or collation. Stop. Do not start
  Step 1. A database that fails here is state G in "Step D" below (including the case where
  Query 2 shows zero objects but the character set or collation is wrong): it goes to the
  administrator-review path there, not to a drop.

Query 2, objects in the database:

```
"C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --protocol=TCP --host=127.0.0.1 --port=3307 --database=virac_solo_parent_test --default-character-set=utf8mb4 --execute="SELECT 'TABLES_AND_VIEWS' AS kind, COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'virac_solo_parent_test' UNION ALL SELECT 'ROUTINES', COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'virac_solo_parent_test' UNION ALL SELECT 'TRIGGERS', COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'virac_solo_parent_test' UNION ALL SELECT 'EVENTS', COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = 'virac_solo_parent_test';"
```

- **Pass (the database is empty of visible objects):** all four counts are `0`.
- **Fail:** any count above `0`. Stop. Do not start Step 1, and go to "Before any drop and
  recreate". A non-zero count is not an invitation to drop; it is the start of the
  assessment there.

Both commands contain only single-quoted SQL strings inside the double-quoted `--execute`
value, with no `%`, `^`, `&`, `|`, `<` or `>`, so `cmd.exe` passes them through unchanged
(this document has not run them). MariaDB's client documentation says `--execute`
"Disables `--force`", which is a useful property here but does not replace the prohibition
on `--force`.

**Step 0: clear inherited state (before any check or import)**

A variable inherited from the user or system environment, or left over from an earlier run
in the same window, must not be able to authorise a later command. Open a **new**
`cmd.exe` window for this procedure, then type these four lines separately (not in a
parenthesised block):

```
set COMBINED_VERIFIED=
set SCHEMA_RESULT=NOT_RUN
set SEED_RESULT=NOT_RUN
set COMBINED_VERIFIED
```

The last line lists the variable; the expected output is that it is not defined (typical
`cmd.exe` behavior; **not confirmed against Microsoft's documentation here and not tested**).
If it is defined, stop and find out where it comes from. These settings affect only that
window: a `COMBINED_VERIFIED` stored in the user or system environment would reappear in
every new window, so it must not be stored there. Never set it permanently.

**Step 1: schema** (its own session; no variables needed)

```
"C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --protocol=TCP --host=127.0.0.1 --port=3307 --database=virac_solo_parent_test --default-character-set=utf8mb4 < "C:\xampp\htdocs\virac-soloparent\database\testing\schema_test.sql" && set SCHEMA_RESULT=CLIENT_RETURNED_0 || set SCHEMA_RESULT=CLIENT_RETURNED_NONZERO
echo Schema result marker: %SCHEMA_RESULT%
```

Type these as separate lines at the `cmd.exe` prompt, not inside a parenthesised block.
`SCHEMA_RESULT` was set to `NOT_RUN` in Step 0, so it cannot carry a stale value; this
procedure deliberately does not read `%ERRORLEVEL%`, which keeps its previous value when a
command is skipped. The `&&` / `||` behavior is standard `cmd.exe` behavior that was **not
tested** here. **The client's exit status after an SQL error is not established** by the
MariaDB client documentation consulted, so the marker is advisory only. The operator must
read the actual client output: do not start Step 2 unless `NOT_RUN` is gone, the marker is
`CLIENT_RETURNED_0` **and** no error was printed. Any other marker, or any printed error,
means stop.

**Step 2: seed** (one session containing the strict-mode setting, the hash variables and
the seed)

The variables exist only for the session that set them, so the `SET` statements and the
seed must reach the client as one input stream. The steps below create that file and only
let the client read it after it has been checked. They are written so that a skipped or
failed step leaves the client **not run** rather than running something unintended.

2a. **The combined file must not already exist.** Type:

```
if exist "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql" echo STOP: combined_seed.sql already exists. Do not continue.
```

If it prints `STOP`, do not continue: an old combined file means an earlier run did not
clean up. Treat it as sensitive, find out why it is there, and remove it deliberately
(Step 3) before starting again. (`if exist` is documented on Microsoft's `if` page. The
batch-style `else` clause must be on the same line as the `if`, so no `else` is used here.)

2b. **Create the combined file.** Only if 2a printed nothing:

```
copy /b /-y "C:\Users\ADMIN_NAME\soloparent-import-temp\session_and_hashes.sql" + "C:\xampp\htdocs\virac-soloparent\database\testing\seed_test.sql" "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql"
```

- Microsoft's `copy` page documents `/b` (binary, so a Ctrl+Z is not treated as end of file),
  the `file1 + file2` form for appending, and `/-y` (prompts before overwriting). Because 2a
  proved the destination absent, no overwrite prompt should appear; if one does, answer
  `N` and stop.
- That page does **not** document the exit status of `copy`, so this procedure does not
  depend on it.
- **Evidence for byte-for-byte joining.** That `copy /b a + b c` appends the bytes of `b`
  to `a` unchanged is the expected result from the `/b` option as documented, but this
  document did not find an explicit statement that no bytes are added or altered, and it was
  not tested. It is therefore an **unverified expectation**, not a fact. The BOM and
  trailing-newline requirements on the inputs are precautions based on that expectation.

2c. **Verify the combined file, without printing its contents.** The file contains hashes,
so never `type` it. The checks below are **limited sanity checks, not proof** that
`combined_seed.sql` is exactly `session_and_hashes.sql` followed by `seed_test.sql`. Matching
sizes and a single `COMMIT;` match cannot show that the bytes are the intended bytes or in the
intended order: a different file of the same size, corrupted content or swapped content would
pass them. The stronger independent checks (2c-ii) are also required before the import.

2c-i. **Sanity checks.** Run:

```
dir "C:\Users\ADMIN_NAME\soloparent-import-temp\session_and_hashes.sql" "C:\xampp\htdocs\virac-soloparent\database\testing\seed_test.sql" "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql"
findstr /n /c:"COMMIT;" "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql"
```

- **Pass:** `combined_seed.sql` exists, its size in bytes is exactly the size of
  `session_and_hashes.sql` plus the size of `seed_test.sql`, and `findstr` prints exactly one
  line, and that line is only `COMMIT;` (with its line number). `findstr /c:` matches the
  text anywhere in a line, so read the line rather than counting matches.
- **Fail:** the combined file is missing, any size differs, or `findstr` prints nothing,
  more than one line, or a line that contains more than `COMMIT;`. Stop, go to Step 3 and
  start again.
- Microsoft's `findstr` page documents `/n` (line numbers), `/c:` (literal string) and that
  options precede the search string; it does **not** document `findstr` exit codes, so rely
  on the printed output. This `findstr` pattern prints only the matching line, which is not
  a hash. Whether `findstr` copes with every file encoding is not documented there; the files
  must be plain UTF-8 or ASCII without a BOM in any case.

2c-ii. **Independent verification of the inputs and their order (required).** Run these;
none of the patterns matches a hash line, so no hash is printed:

```
certutil -hashfile "C:\xampp\htdocs\virac-soloparent\database\testing\seed_test.sql" SHA256
findstr /n /c:"USE virac_solo_parent_test;" "C:\xampp\htdocs\virac-soloparent\database\testing\seed_test.sql" "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql"
findstr /n /c:"COMMIT;" "C:\xampp\htdocs\virac-soloparent\database\testing\seed_test.sql"
findstr /n /b /c:"SET SESSION sql_mode" "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql"
```

- **Reviewed file.** The `certutil` SHA-256 of the repository's `seed_test.sql` must equal the
  hash recorded in the approval record (see Step E) for the reviewed version, taken from
  that record and not from the file just computed. A mismatch means the file is not the
  reviewed one: stop. `certutil -hashfile` is a standard Windows tool that this document
  did not look up or test; if it is unavailable or prints something unexpected, use another
  administrator-approved byte-comparison method, or do not proceed. No reviewed hash is
  recorded by this document.
- **Order.** In the combined file, the `SET SESSION sql_mode` line must be line 1, and the
  `USE virac_solo_parent_test;` and `COMMIT;` line numbers must each be exactly the number
  in `seed_test.sql` plus 3 (the three lines of `session_and_hashes.sql`; compare the two
  listings the commands print). Because `copy /b` is an unverified expectation (above), a
  second administrator should repeat these checks.
- **What this still does not prove.** Together with the size check this strongly suggests,
  but does not mathematically prove, that the file is the two inputs concatenated: the
  middle of `seed_test.sql` is not compared byte by byte, and the hash lines are not
  displayed or validated here (the seed's own guard checks their format only). If a
  byte-for-byte comparison of the seed portion is required, it needs a method this
  document has not established.

2d. **Release the gate, then run.** The gate is two conditions on one line, and both start
unset (Step 0): `SCHEMA_RESULT` must be exactly `CLIENT_RETURNED_0`, and `COMBINED_VERIFIED`
must be exactly `SEED_CHECKS_PASSED_2C`. Type the `set` line **only after** 2c-i and 2c-ii
both passed and you read the Step 1 output with no error:

```
set COMBINED_VERIFIED=SEED_CHECKS_PASSED_2C
if "%SCHEMA_RESULT%"=="CLIENT_RETURNED_0" if "%COMBINED_VERIFIED%"=="SEED_CHECKS_PASSED_2C" "C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --protocol=TCP --host=127.0.0.1 --port=3307 --database=virac_solo_parent_test --default-character-set=utf8mb4 < "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql" && set SEED_RESULT=CLIENT_RETURNED_0 || set SEED_RESULT=CLIENT_RETURNED_NONZERO
echo Seed result marker: %SEED_RESULT%
set COMBINED_VERIFIED=
```

- **What the gate is.** Typing the value is a deliberate **manual acknowledgement** by the
  operator. It does **not** prove that the checks passed or that the files are correct; no
  variable can. What the one-line condition does add is that an inherited or stale value
  (cleared in Step 0, compared exactly rather than only tested for being defined) and an
  unrun or failed Step 1 cannot release the client by themselves.
- **No stale values.** `SEED_RESULT` is `NOT_RUN` until the client line runs, and this
  procedure reads no `%ERRORLEVEL%`. If a condition is false the client should not run and
  the marker should stay `NOT_RUN` (expected `cmd.exe` behavior for `if ... if ... command
  && ... || ...` on one line; **not tested**). If the marker is `CLIENT_RETURNED_NONZERO`
  but the client printed nothing, the line did not behave as expected; stop and do not
  assume the client did or did not run.
- **The marker is advisory.** The client's exit status after an SQL error is not
  established (see "Error handling and limits"), so the printed client output decides.
  Proceed only if the marker is `CLIENT_RETURNED_0` **and** nothing was printed that looks
  like an error. Do not delete any file until the output and marker have been recorded
  (without hashes); on any doubt treat the import as failed and go to Step A of "Before any
  drop and recreate".
- `if` and its string comparison are documented on Microsoft's `if` page; this document has
  not run these lines. Type the lines separately at the prompt, as for Step 1, never inside
  a parenthesised block.

**Step 3: delete the temporary files (only after the result markers and printed output were recorded and read)**

```
del /f /q "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql" "C:\Users\ADMIN_NAME\soloparent-import-temp\session_and_hashes.sql" "C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf"
dir "C:\Users\ADMIN_NAME\soloparent-import-temp"
```

- Microsoft's `del` page documents `/f` (deletes read-only files), `/q` (quiet; no
  confirmation) and a list of names. It does **not** document `del`'s exit status, and `/q`
  suppresses prompts, so the `dir` listing is the check: the folder must show none of the
  three files. If any remains, delete it by name and list again.
- Delete `import-client.cnf` here unless local policy deliberately keeps it in the
  restricted folder; if it is kept, it is still a credential file and the folder's
  restrictions must stay in place. Remove the empty folder with
  `rmdir "C:\Users\ADMIN_NAME\soloparent-import-temp"` once the listing is empty (`rmdir` is
  standard `cmd.exe`; not consulted against Microsoft's documentation here).
- Do this on success and on failure.

**Deletion is not secure erasure.** `del` only removes directory entries; the data can
remain on disk, and `del` bypasses the Recycle Bin but not other copies. The hashes (and
the option file's password) may persist in: editor backup, swap or recovery files; the
clipboard or a clipboard history; shell or terminal history and scrollback; Windows
shadow copies, file-history or cloud-sync copies and backups; any option file;
logs; and, on the server, general, binary, slow-query or audit logs if those are enabled.
The hashes are, of course, also stored in the `users` table of the test database by
design. Treat every hash as a credential equivalent: it protects only a disposable test
database and must not be reused anywhere else, and the secrets behind it must never be
recorded.

**Prohibited for this procedure**

- `--force` (`-f`) **on the command line**: it makes the client continue after an error,
  so a later statement could run after the guard or an `INSERT` failed.
- `force` **in any client option file or `my.ini`** that the client reads: a `force`
  setting in `[client]` or `[mysql]` has the same effect without appearing on the command
  line. The commands above use `--defaults-file`. See "What `--defaults-file` does and does
  not isolate" below. The administrator must still confirm that the named file contains
  none of the setting. If `--defaults-extra-file` or no option flag is used instead, every
  default option file the client reads must also be checked, for example with
  `findstr /i /n "force" "C:\xampp\mysql\bin\my.ini"`. The standard locations are not
  exhaustively listed here, and `findstr` prints matching lines only (its exit codes are not
  documented on Microsoft's page, so read the output, not the code).
- **What `--defaults-file` does and does not isolate.** MariaDB's client documentation says
  `--defaults-file` is "Only read default options from the given file. Must be given as the
  first option." The commands above put it first. It is meant to stop the standard option
  files from being read. It does **not** isolate:
  - other options on the same command line (for example `--force` typed by mistake;
    `--execute` is documented to disable `--force`, but the import commands use input
    redirection, not `--execute`);
  - environment variables: the documentation consulted states that `MYSQL_TCP_PORT` is
    consulted for the port, after option files; it did not cover `MYSQL_PWD` or
    `MYSQL_HOST`, so whether they are consulted is **unverified**. Do not set them for this
    session;
  - anything in the named file itself, including a `port` line that points at the wrong
    server, or an `!include` line that pulls in another file (the file must contain none);
  - the server's own settings (global `sql_mode`, `init_connect`, logging) and which server
    is reached; that is what the server-identity check in "Server version and session
    `sql_mode`" is for.
  The option-file documentation consulted does not describe byte-order-mark handling,
  which is why the option file must also be saved without a BOM.
- Interactive `source` (or `\.`) inside a `mysql` prompt: a failure does not end the
  session, so a half-run seed and an open transaction stay in place, and variables
  could be set by hand and left in shell history.

**Error handling and limits**

- Without `--force` (on the command line or in an option file), the client is expected to
  read the input non-interactively and stop at the first error. **That the client stops,
  and what exit status it returns after an SQL error, is not established** by the MariaDB
  client documentation consulted and was not tested; this document does not rely on the
  exit status. The operator must inspect the actual client output and the result markers
  before proceeding, and record them (without hashes) before deleting any temporary file.
  Warnings do not stop the client.
- **Unverified seed-guard behavior.** That the `[$]` bracket expression in the guard's
  `REGEXP` matches a literal `$` on this server, and that `SIGNAL` is accepted inside an
  anonymous `BEGIN NOT ATOMIC` block outside a stored program, are **not established** by
  documentation this review consulted and were not tested. Likewise the claim that a guard
  failure inserts nothing depends on these and on the client stopping. If the guard does not
  behave as described, treat the seed as not guarded.
- **Schema:** every DDL statement can cause an implicit commit, including `CREATE TABLE`,
  `CREATE TRIGGER` **and `ALTER TABLE`** (the schema ends its table list with an
  `ALTER TABLE appointments` that adds an index and a foreign key). MariaDB's page on
  statements that cause an implicit commit
  (https://mariadb.com/kb/en/sql-statements-that-cause-an-implicit-commit/) lists
  `ALTER TABLE`, `CREATE TABLE` and `CREATE TRIGGER`. There is no
  transaction around the schema, so a failure part-way leaves the objects created so far.
  Nothing rolls them back; recovery means the drop and recreate described below, and
  only after the checks there. The schema is not re-runnable.
- **Seed:** the guard runs before any `INSERT` and before the transaction starts, so a
  guard failure inserts nothing. After the guard, all inserts are in one transaction
  that `COMMIT` ends on the last line. If a statement fails, the client is expected to
  exit, the connection to close and the server to roll back the uncommitted work. This
  relies on the client actually stopping (not established, see above); if the connection
  stays open, issue `ROLLBACK`. Once
  `COMMIT` has run, the seed cannot be undone by this procedure, and the seed is not
  idempotent: re-running it fails on duplicate keys.
- The transaction covers InnoDB tables only; the schema uses InnoDB throughout. This
  behavior was reasoned from the files and MariaDB documentation and has not been
  tested on this server.

**Before any drop and recreate**

Dropping a database is irreversible. **This document contains no `DROP` statement and does
not script one.** It describes the assessment and approval that must be complete before an
administrator decides, in a separate action, to drop and recreate the test database (for
example after a failed schema import). The order is fixed: **inspect first, ask for
confirmation second.** Nothing in this section is a reason to drop; every path below can
end in "stop and do not drop".

**Accounts and their limits**

| Purpose | Account | Limitation |
|---|---|---|
| Inspection queries below | An **administrator account** chosen by the administrator, with read access to `information_schema` and `SELECT` on `virac_solo_parent_test`. Its credentials are supplied through its own restricted option file or an interactive prompt, never on a command line, and are not covered by `import-client.cnf`. | The import account (`import-client.cnf`) can run the object-count queries, but it sees only what its privileges allow and has no privilege to inspect other databases, so it can under-report; it is **not** the account for the disposability decision. |
| The drop and recreate itself | An administrator account, in a separate action | The import account is `root@127.0.0.1`, which does hold `DROP`; this procedure contains no `DROP` statement, and a drop needs its own explicit approval after Steps A to E. |

**Step A: stop and record the failure.** Keep the exit code and the exact error text from the
failed step. Do not delete the temporary files yet (see Step 3).

**Step B: verify the database name and server.** List candidates with a read-only query such
as `SHOW DATABASES LIKE 'virac%';` and read the exact name reported by the server. It must be
character-for-character `virac_solo_parent_test`: not `virac_solo_parent`, not a name with a
prefix, suffix or trailing space, and not whatever the current connection or an environment
variable happens to select. Type the name by hand rather than relying on a stored command or
a pasted variable. How the server folds database-name case on Windows is **not confirmed
against MariaDB documentation**, so do not rely on a case difference to tell two names
apart; if two names differ only in case, stop. Re-run the server-identity check (`@@port`,
`@@datadir`) so the inspection and the drop target the same server.

**Step C: inspect the database (read-only).** Run Query 1 and Query 2 from "Initial emptiness
and object check" with the administrator account, then:

```
SELECT TABLE_NAME, ENGINE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'virac_solo_parent_test' ORDER BY TABLE_NAME;
```

`TABLE_ROWS` is only an estimate for InnoDB tables and **must not be used as proof of
anything**. For each table that is present, the administrator runs
`SELECT COUNT(*) FROM virac_solo_parent_test.TABLE_NAME;` to get an exact count (replace
`TABLE_NAME` by hand with each name listed). Also run:

```
SELECT COUNT(*) FROM virac_solo_parent_test.users WHERE email NOT LIKE '%@example.test' AND email <> '';
```

(only if `users` exists). Any result other than `0` means a user that is not fixture data.
A result of `0` is not proof of the opposite.

**A table count of zero is not proof that the database is disposable.** (State A needs no
drop at all; it is simply ready.) It says only that no
tables are visible to the inspecting account. The database may still hold views, routines,
events or triggers the account could not see, may be the wrong database, or may be one whose
owner has not agreed it can be discarded. The decision rests on Step D and Step E, not on
the counts.

**Step D: classify the state of the database.** Compare the inspection (Query 1, Query 2,
the table list and the exact row counts) with the files and the recorded result markers and
printed output of the failed or finished step. Pick **one** state. If more than one fits, or
none fits, the state is **F (ambiguous)**.

| State | What was observed | Meaning, and what it permits |
|---|---|---|
| **A. Untouched empty database** | Query 1 passes (one row, `virac_solo_parent_test`, `utf8mb4`, `utf8mb4_unicode_ci`); Query 2 shows `0` for tables/views, routines, triggers and events; nothing has been run against it | Ready for Step 1. No drop or recreate is involved. (Counts are what the import account can see; see the caveat in "Initial emptiness and object check".) |
| **B. Partial schema import** | A subset of the tables in `schema_test.sql`, in the order of its `CREATE TABLE` statements with no gap before the first missing table (that order is **not verified** by this document); `COUNT(*)` exactly `0` in every table present; no views, routines or events; triggers only `audit_logs_no_update` and `audit_logs_no_delete`, and only if `audit_logs` exists; **and** the recorded Step 1 error names a statement that matches the first missing object | Consistent with a failed schema import. Necessary but not sufficient: goes to Steps E and F as a **recommendation**, not a decision. |
| **C. Partial seed import (schema complete, no seed rows)** | All tables, the two triggers, `COUNT(*)` exactly `0` in every table, and a recorded Step 2 failure or guard error (or Step 2 was never run) | Schema exists and the seed was not committed (the seed commits only on its last line; the expected rollback is untested). Recovery may not need a drop at all (the schema is not re-runnable, but the seed may be retried only after the administrator confirms zero rows). Any decision goes to Steps E and F. **Any rows present after a recorded seed failure contradict the expected rollback and make the state F, not C.** |
| **D. Completed import, or previously seeded** | All tables present and row counts equal to the fixtures described in `seed_test.sql` (and `users` has only `@example.test` addresses, Step C query); with or without a recorded Step 2 result of success | The database holds data. It could be this run's completed import or an **earlier** import or earlier tests, and nothing in the database can tell them apart. It is **not** a failed import and is **not automatically disposable**. It needs a separate administrator assessment of why it exists and who owns it, then Step E, before anything is decided. Do not re-run the seed (it fails on duplicate keys). |
| **E. Pre-existing or unknown data** | Any of: a table not in `schema_test.sql`; tables missing from the middle of the sequence; row counts that do not match the fixtures; `users` rows with other addresses; any view, routine or event; any other trigger; a recorded error that is absent or unrelated | Fail: stop and do not drop. Treat as someone's data until the owner says otherwise. |
| **F. Ambiguous** | Matches more than one state, matches none, the inspecting account may be missing objects, or the record of what was run is missing | Fail: stop and do not drop. An administrator investigates with an account that can see everything. |
| **G. Wrong character set/collation, or not visible** | Query 1 returns zero rows, more than one row, another name, or any other character set or collation, whatever Query 2 shows | Stop. This includes a database with **zero objects** but the wrong character set or collation. An administrator (not the import account) confirms with their own account which database exists, its defaults and its contents; then, through Step E approval, decides whether to correct it as a separate action (recreating it per Step F with the required character set and collation is the only correction this document describes; no alteration is scripted). Wrong defaults are a configuration error, not an import failure. |

States E, F and G, and any state not clearly A, B or C, are a **fail**: stop and do not drop.
State D is never treated as disposable by default. States B and C are **necessary but not
sufficient**: they support a recommendation, not a decision. Anything ambiguous is a fail.
Nothing in this table scripts or authorises a destructive action.

**Step E: confirmation that the database is disposable.** After Steps B to D and not before:

- **Who.** The confirmer must be a **different person** from the operator who ran the import
  and the inspection, and must be an administrator who is responsible for this system's
  data (the data owner for `virac_solo_parent_test`). If no second administrator exists,
  the operator must not self-approve: the decision waits for one, or is escalated to the
  system's responsible office. A self-approval exception is not provided by this document.
- **What they confirm.** In their own words, that the exact name from Step B refers to a
  database that is a throwaway test database, that it holds no data anyone needs (including
  anything copied into it from any other source, and including `virac_solo_parent` data),
  and that they have read the Step C and Step D results.
- **Where it is recorded.** In an approval record kept **outside the repository and outside
  the database being dropped**, in the office's change or approval log (a ticket or signed
  form). It must contain: date and time; the exact database name; the server identity
  (`VERSION()`, `@@port`, `@@datadir`); the Step C counts and the Step D state (A to G) with its evidence; the SHA-256 of the
  reviewed `seed_test.sql` (no hashes of passwords); the
  operator's name; the approver's name; and the decision. It must contain no hashes,
  passwords or option-file contents.
- Without a completed record of this kind, do not drop.

**Step F: the drop and recreate (not scripted here).** An administrator account performs it
as a separate, deliberate action, only after Step E is complete, retyping the exact name
checked in Step B. The recreated database must use the required character set and
collation:
`CREATE DATABASE virac_solo_parent_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
Afterwards re-run "Server version and session `sql_mode`" and "Initial emptiness and object
check" and require them to pass before Step 1.

Never perform a drop or recreate against `virac_solo_parent`, or against any database that
fails Step B.

## Storage isolation proposal

### Test-artifact isolation (implemented, Phase 2)

> **Runtime tests remain BLOCKED.** Nothing in this section authorises running any test, browser
> workflow or application start-up. Database isolation (a dedicated test server/account and
> `virac_solo_parent_test` independently verified as not production) must be confirmed by the
> operator first. The code below only removes the shared-storage hazard; it does not prove the
> database is safe.

`config/storage.php` (loaded by `config/app.php`) resolves every runtime artifact folder.
Production (`APP_ENV` anything but `testing`) keeps the original paths and ignores
`TEST_STORAGE_ROOT`. With `APP_ENV=testing`, `TEST_STORAGE_ROOT` (process environment only, never
`config/local.php`) is mandatory and everything lives inside it; there is no fallback.

| Artifact | Production | Testing | Defined in |
|---|---|---|---|
| Uploaded documents, drafts, `tmp/` | `storage/uploads` | `<root>/uploads` | `STORAGE_PATH` |
| Avatars | `storage/avatars` | `<root>/avatars` | `AVATAR_STORAGE_PATH` |
| PHP sessions | `storage/sessions` | `<root>/sessions` | `SESSION_PATH`, used by `config/config.php` |
| PHP error log (incl. `save_verification_test.log`) | `storage/logs/php-error.log` | `<root>/logs/...` | `LOG_PATH`, used by `config/errors.php` |
| Test temp files (`tempnam`) | not used | `<root>/tmp` | `TEST_TMP_PATH` |
| Browser-test screenshots | not written | `<root>/screenshots` | `scripts/lib/test-artifacts.cjs` |

Requirements enforced (PHP: `validate_test_storage_root()`, `storage_prepare_test_dir()`;
Node: `scripts/lib/test-artifacts.cjs`). Any failure throws; the process stops:

1. `TEST_STORAGE_ROOT` is set, absolute, local (no UNC / `\?\`), has no `.`/`..` segments, is not a
   drive or filesystem root, and exists.
2. No component of the path is a link, and every component equals its own `realpath()` (this is
   what catches NTFS junctions, see limits below).
3. It lies entirely outside the application folder, and does not equal, contain or sit inside the
   *real* location of `storage`, `storage/uploads`, `avatars`, `sessions` or `logs`, even if
   `storage` is itself a junction/symlink.
4. It holds a hand-created regular file `.virac-test-storage`.
5. Each subfolder is created if missing (modes 0750/0700), is not a link, and resolves to itself.
6. `config/config.php` refuses to start a session, and `config/errors.php` refuses to run, if the
   root no longer passes `storage_root_still_safe()` (marker, subfolders, no links). Browser
   tests require `APP_ENV=testing` and `TEST_STORAGE_ROOT` and never write to `storage/`.
7. Login/logging paths are covered by the configuration, not by tests avoiding them: even a test
   that logs in writes its session, log and uploads only under the test root.
8. The test root must also lie outside the **Apache document root** (not inside it, not equal to
   it, not a parent of it). The document root comes from `$_SERVER['DOCUMENT_ROOT']` (web
   requests) and/or the process environment variable `TEST_DOCUMENT_ROOT` (the operator's
   statement of the Apache `DocumentRoot`; **required for command-line PHP tests**, where PHP has
   no document root). Each source that is present must resolve (`realpath()`) to an existing
   directory, and if none is available validation fails. Being outside the project is not enough:
   `C:\xampp\htdocs` is a parent of both the project and a root like
   `C:\xampp\htdocs\virac-test-storage`. `storage_document_roots()` in `config/storage.php`.
   Production paths and behaviour are unchanged (the check runs only under `APP_ENV=testing`).
   PHP cannot see Apache `Alias`/`Directory`/virtual-host mappings that expose other folders, so
   the operator must confirm the test root is not reachable that way.
9. **Browser-test target.** Browser scripts that drive a server take `VIRAC_TEST_URL`; there is no
   default. `scripts/lib/test-origin.cjs` accepts only the exact shape
   `http://localhost:PORT/`, `http://127.0.0.1:PORT/` or `http://[::1]:PORT/` plus an optional plain
   path ending in `/`, with an **explicit port** from 1024-65535 (no leading zero; 8080 and 3306 are
   refused). Rejected: the ordinary `http://localhost/virac-soloparent/` and any default-port URL
   (including an explicit `:80`), `https`/other protocols, other hosts (external names, numeric
   aliases such as `2130706433`), credentials, query, fragment, whitespace, backslashes,
   percent-encoding, `//`, `.` / `..` segments, a missing trailing `/`, and anything on which the
   strict pattern and the WHATWG URL parser disagree. **A passing URL is not proof of isolation:**
   localhost proves nothing about which database, storage or sessions the server uses. The server
   behind it must be started separately, per process, with `APP_ENV=testing`, the isolated test
   database and `TEST_STORAGE_ROOT` (plus `TEST_DOCUMENT_ROOT` if it runs from the command line).
10. **Browser request isolation.** `staff-workspace`, `admin-workspace` and `role-account-creation`
    scripts now create their context through `newGuardedContext()` (service workers blocked) which
    routes **every** request, including sub-resources and redirects. Cross-origin requests are
    aborted and recorded; non-GET requests are aborted and recorded; same-origin requests are
    answered from the PHP fixture HTML (whose hard-coded `http://localhost/virac-soloparent` is
    rewritten to the test origin), a placeholder page, or passed through **only** as a static file
    (`css js map png jpg jpeg gif svg webp ico woff woff2 ttf otf`), fetched with redirects
    disabled (any 3xx is aborted and recorded). Anything else is aborted. Each script asserts the
    guard recorded nothing before it passes. Display-preferences additionally lists the three
    live public pages it needs (`portal.php`, `eligibility.php`, `login.php`) as GET-only pass-through.
    These scripts have not been run; the Playwright behaviour described is from its documentation
    (version pinned in `scripts/package.json`: 1.49.1).
11. **Screenshots are never overwritten.** `screenshotPath(name)` keeps the strict name check
    (`[A-Za-z0-9][A-Za-z0-9._-]{0,100}.png`, no `..`), re-validates the root, marker and
    `<root>/screenshots` (no links) on every call, requires the target to sit directly in that
    folder, and reserves the name by creating an empty file with the exclusive `wx` flag. That
    fails on any existing entry, including a file, folder or (dangling) symlink/junction, and never
    follows a link. A name can be reserved once: a repeat call or a leftover from an earlier run is
    refused, so **clear `<root>/screenshots` by hand between runs** (also after an aborted run,
    which leaves empty reserved files). `screenshotDir()` validates without reserving.
    Residual limit: Playwright writes the image later by path, so a link swapped in between the
    reservation and that write is not detected; keep the test root private to the test user.
12. **Subfolder link protection (testing only).** `storage_dir_confined()` in `config/storage.php`
    returns true at once outside `APP_ENV=testing`, so production behaviour and paths are unchanged.
    Under testing a directory must be the upload or avatar folder or lie below one, contain no `.`/`..`
    segment, and every **existing** component below that folder must equal its own `realpath()` and not
    be a link; components that do not exist yet are allowed. Components above the validated folders are
    not examined. It guards `DocumentValidationService::ensureDir()` (before and after `mkdir`),
    `deleteDir()`, `deleteDraftFile()`, and, in `ApplicationService::storeDraftDocuments()`, the draft
    folder creation (via `ensureDir()`) and the source folder of each staged file before it is renamed.
    The folder-creation and delete paths used by renewals go through the same methods. Not changed:
    avatar deletion (a basename inside the already validated avatar folder; unlinking a file link
    removes only the link), and the rollback renames and `rmdir` calls that reuse paths already
    validated in the same request.
    Renewal drafts: `RenewalService::getDraft()` now keeps a staged document only if its stored name
    matches `/\A[a-f0-9]{32}\.(?:jpe?g|png|pdf)\z/` (the same rule as
    `ApplicationService::rebuiltStagedFiles()`) and rebuilds its path inside the applicant's draft
    folder; any other entry is dropped and the document is uploaded again.

**Cleanup routines.** `AuthService::purgeAbandonedDrafts()` stands down unless
`storage_root_still_safe()` passes, works only under `realpath(STORAGE_PATH)`, and considers only
direct, non-link children of `<uploads>/tmp` and `<uploads>/drafts` whose names match strict
patterns and whose real path is exactly `<base>/<name>`. `DocumentValidationService::deleteDir()`
deletes only regular files directly inside such a folder (a link is unlinked, never followed) and
then `rmdir` (fails if not empty; no recursion). It will delete *any* file inside a qualifying
stale folder, tracked or not; that is the original design and is bounded to the test uploads root
in testing. Test fixture cleanup (`upload_fixtures.php`) removes only files it tracked itself and
`rmdir`s folders it created. `ApplicantProfileService` unlinks `AVATAR_STORAGE_PATH/basename(...)`.

**Production cleanup compatibility (static review; nothing was run).** The new candidate rules
apply in production too, because `purgeCandidates()` is not limited to testing. Compared with the
original `glob(STORAGE_PATH/tmp/*)` and `glob(STORAGE_PATH/drafts/*)`:
- `drafts/`: current code creates only `drafts/<user_id>` (`ApplicationService`) and
  `drafts/renewal_<user_id>` (`RenewalService`), and `ApplicationDraft::folderNames()` produces the
  same two shapes. The pattern `(renewal_)?[0-9]{1,12}` covers both, so no legitimate draft folder
  is skipped.
- `tmp/`: **no current code creates `tmp/` folders**; only the purge reads them. They are leftovers
  of "old session-based" drafts, probably named after the PHP session ID, but that cannot be
  confirmed statically (the creating code is gone, and no `session.sid_length` or
  `session.sid_bits_per_character` is set in the repository). The pattern `[A-Za-z0-9_-]{1,80}`
  covers the default 32-character IDs, but would **skip** a legacy folder whose name has more than
  80 characters or contains a comma (`sid_bits_per_character=6`), or any other character outside
  the pattern. Skipped folders are simply never deleted by the purge (the safe direction).
- Also skipped now, where the original would have deleted them: a `tmp` or `drafts` folder that is
  itself a symlink/junction or resolves elsewhere, and any child that is a link or resolves to a
  different name.
- Deletion was **not broadened**, and the purge was not modified in this batch. Compatibility with
  unusual legacy `tmp/` names is not established, so it is documented here instead of changing
  cleanup speculatively. If the operator finds legacy folders that are being skipped, list their
  names (not contents) and decide separately whether to widen the pattern or remove them by hand.

**Windows / XAMPP limits (honest list).**
- PHP's `is_link()` is not relied on for junctions; the `realpath()` comparison is the real
  defence. It also fails closed on mapped network drives, `SUBST` drives, 8.3 short names and
  trailing dots/spaces (the path will not equal its realpath, so it is refused).
- Comparison is case-insensitive on Windows via ASCII `strtolower` only; a path differing in
  non-ASCII case is refused rather than accepted. Separators and trailing slashes are normalised.
- Hard links to files and race conditions (a junction swapped in between check and use) cannot be
  excluded by PHP or Node; the root must be a folder only the test operator can write to.
- The marker proves intent, not content: a marker copied into a real folder defeats it.
- **Junction probe, verified 2026-11-16 (PHP 8.2.12, `C:\xampp\php\php.exe`).** In a scratch folder
  outside the project and the test storage, an NTFS junction was created with `mklink /J` to an empty
  target. `is_link()` returned **false** for the junction; `realpath()` resolved it to its **target**;
  the normalized `realpath()` therefore differed from the junction path, so the equality test in
  `storage_assert_no_link()` refuses it. The junction was removed with non-recursive `rmdir`, the target
  was confirmed still present, then the empty target and scratch folder were removed; no storage was
  touched. This verifies junction handling on the tested PHP build only. **Not tested:** symbolic
  links, hard links, mapped or SUBST drives, 8.3 aliases and any other redirection type.

**Operator verification still required (not provable from code).**
- The folder chosen for `TEST_STORAGE_ROOT` is empty, outside the project, not a junction, with
  the marker created by hand.
- `TEST_STORAGE_ROOT`, `APP_ENV`, DB and SMS variables are set per process, not in Apache or
  `config/local.php`, and any browser-test target is a separate, test-configured instance.
- The test database is a distinct, non-production database with a limited account.
- `TEST_DOCUMENT_ROOT` (command-line PHP) names the real Apache `DocumentRoot`; and no Apache
  `Alias`, virtual host or `Directory` mapping exposes `TEST_STORAGE_ROOT`.
- The server behind `VIRAC_TEST_URL` runs on a port that is not the ordinary site's, started with
  `APP_ENV=testing`, the test database, the test SMS provider and the same `TEST_STORAGE_ROOT`;
  check this from its own configuration, not from the URL.
- `<TEST_STORAGE_ROOT>/screenshots` is emptied before each browser run.
- **Every** browser script that touches a server (`test-display-preferences`, `test-staff-workspace`,
  `test-admin-workspace`, `test-role-account-creation`, `test-offline-layout`) now requires
  `VIRAC_TEST_URL` and routes all traffic through `scripts/lib/test-origin.cjs`; none falls back to
  `http://localhost/virac-soloparent/`. `test-offline-layout.cjs` serves its fixtures from PHP
  previews (rewritten to the test origin), static assets, and passes only `portal.php`,
  `eligibility.php`, `login.php` and `mswd-login.php` through to the test server as GET without
  redirects; any other request, any non-GET, any redirect and any cross-origin request is aborted and
  fails the run. Those four pages are rendered by the server behind `VIRAC_TEST_URL`, so that server
  must be the separately configured test instance. A new browser script must use the same guard
  before it is run. None of these scripts has been executed; only their syntax was checked.
- ~~That a deliberately created junction inside a scratch folder is refused~~ Done for junctions on
  PHP 8.2.12 on 2026-11-16 (see "Windows / XAMPP limits"). Still open for other redirection types.

Everything below is the original proposal and source reading, kept for reference. Parts of it
(sessions, logs, screenshots) are now implemented as described above; no storage file was
opened, listed, moved or deleted while preparing either.

### Current layout (from source)

| Kind | Location | Defined in |
|---|---|---|
| Final documents | `storage/uploads/<application_id>/` | `config/constants.php` (`STORAGE_PATH`), `ApplicationService`, `DocumentController` |
| Application drafts | `storage/uploads/drafts/<user_id>/` | `ApplicationService` |
| Renewal drafts | `storage/uploads/drafts/renewal_<user_id>/` | `RenewalService` |
| Temporary uploads | `storage/uploads/tmp/<dir>/` | `AuthService::purgeAbandonedDrafts()` |
| Avatars | `storage/avatars/` | `AVATAR_STORAGE_PATH` |
| Sessions | `storage/sessions/` | `config/config.php` |
| Logs | `storage/logs/php-error.log` | `config/app.php` (`LOG_PATH`), `config/errors.php` |
| Browser-test screenshots | `storage/*.png` | `scripts/test-*.cjs` |

All paths derive from `APP_ROOT`. None depends on `APP_ENV`, the database name or an
environment variable, so a test run and a real run share the same folders.

**The four temporary-table CLI tests** (`test_admin_management_layout.php`,
`test_role_account_creation.php`, `test_user_account_actions.php`,
`test_optional_applicant_gmail.php`) create and use the shared `storage/sessions` and
`storage/logs` folders (before the Phase 2 change; now the test root). They do not log in through `AuthService::startSession()`, so
they do not reach the login cleanup routine described below.

### The `AuthService` cleanup hazard

`AuthService::startSession()` calls `purgeAbandonedDrafts()` on every successful login
(applicant or staff). It deletes, with no check of which environment or database owns
the files:

1. Every directory under `storage/uploads/tmp/` whose modification time is older than
   `SESSION_ABSOLUTE_HOURS`.
2. Every directory under `storage/uploads/drafts/` that is older than one hour and whose
   name (`<user_id>` or `renewal_<user_id>`) is **not** in `application_drafts` of the
   database the process is connected to.

Consequences when test and real storage are shared:

- **Test database against real storage:** the test database holds few or no draft rows,
  so a single test login deletes every real draft folder older than an hour. Because the
  decision uses only the connected database, nothing warns that the folders belong to
  another environment.
- **Draft-folder collision, risk 1 (tests delete real drafts):** draft folder names are
  plain user ids (`<user_id>`, `renewal_<user_id>`). A test draft cleanup, or a test
  submission that clears its draft, acts on `storage/uploads/drafts/<user_id>/`, which is
  the same folder as the real user with that id. It can delete real draft files whose id
  matches a test user id (for example test user `3`).
- **Draft-folder collision, risk 2 (test rows protect real drafts):** the purge keeps a
  draft folder if its name appears in `application_drafts` of the connected database. A
  draft row in the test database can therefore protect a real folder with the same name
  from cleanup, so stale real drafts stay on disk.
- **Final document folders:** the purge routine does not touch
  `storage/uploads/<application_id>/`; it works only on `tmp/` and `drafts/`. However, a
  submission moves draft files into the application-id folder, so a test submission for
  application id `1` writes into the same folder as the real application `1` and can
  overwrite or mix with its files.
- **Avatars:** files in `storage/avatars/` get a random suffix in their names, so a
  direct filename collision between a test upload and a real avatar is low risk. Test
  avatars still accumulate in the shared folder.
- **The reverse:** a real login while an unrelated database is selected (a
  misconfiguration) has the same deletion effect.
- `deleteDir()` removes only the top-level files of a folder and then calls `rmdir`; it
  does not recurse, so a folder that contains subfolders is not fully removed.

### Proposed separation

1. **One storage root per environment.** Introduce a single configured root (for
   example `STORAGE_ROOT`) from which every kind below is derived. In production it
   defaults to today's `storage/`. When `APP_ENV=testing` it is required, must be
   outside the project tree and outside the production root, and the application refuses
   to start otherwise (same fail-closed style as `database/tool_safety.php`).
2. **One subfolder per kind** under that root: `uploads/`, `drafts/`, `avatars/`,
   `sessions/`, `logs/`, `screenshots/`. Move drafts and temporary uploads out of
   `uploads/` so cleanup can never reach final documents.
3. **Per-run isolation for tests.** Each test run creates `<TEST_STORAGE_BASE>/<run-id>/`
   with a marker file naming the environment and database. Teardown deletes only a
   directory that has the marker and sits under the test base.
4. **Ownership marker for cleanup.** `purgeAbandonedDrafts()` should run only when the
   storage root carries a marker matching the current environment and database name; it
   should skip, and log, on a mismatch or a missing marker. Add a dry-run mode that lists
   candidates before any deletion is enabled, and consider moving the routine out of the
   login path into the scheduled job.
5. **Screenshots** go to a `SCREENSHOT_DIR` supplied by the runner (default under the
   test storage root), not `storage/`. Scripts must not write PNGs into the production
   storage folder.
6. **Sessions and logs** per environment, so test cookies, CSRF state and error output
   never mix with real ones. Logs must not record fixture credentials.
7. **Apache and config:** the test virtual host (not part of this task) would set
   `APP_ENV`, the test database variables and `STORAGE_ROOT` together; none of them
   should live in `config/local.php`.

### Suggested order (each step needs separate approval)

1. Add the storage-root setting and the testing-mode guard, with production defaults
   unchanged and a no-connection guard check.
2. Switch `AuthService`, `ApplicationService`, `RenewalService`, `DocumentController`,
   avatar handling, session and log paths to the root.
3. Add the ownership marker and dry-run to the purge routine.
4. Move browser-test screenshots to `SCREENSHOT_DIR`.
5. Only then run any test that logs in or submits an application.

Until step 3 is in place, **do not log in or submit applications through any
environment that shares `storage/` with real data**, regardless of which database is
selected.

## Why temporary databases need privileges, and why a second name is not isolation

Tools such as `test_simplified_renewal.php`, `test_new_application_uploads.php` and
`test_save_verification.php` run `CREATE DATABASE` for a random name, build the schema in it
(`CREATE TABLE`/`TRIGGER`/`ALTER`/`INSERT`) and finish with `DROP DATABASE`. Some also read
`SHOW CREATE TABLE`/`SELECT` on the source database to copy definitions. MySQL/MariaDB grants
are per server account, so the account must hold `CREATE`, `DROP`, `ALTER`, `INSERT`,
`TRIGGER`, `REFERENCES` and so on over the generated name pattern. A privilege pattern such
as ``solo_review_test_%`` limits this to matching names, but it cannot be written to exclude
a particular name: the account either has the privilege on a name or it does not.

A separate database *name* on the same `localhost` server therefore does **not** prove
isolation:

- Both databases live in one server instance with one account list. Any account that can
  create a database can usually also be granted, or already hold, rights over others; and
  `root` (or any account with global `DROP`) can drop `virac_solo_parent` just as easily as a
  test database. The name check in `tool_safety.php` is a client-side string comparison; the
  server never sees it.
- `DB_HOST=localhost` only shows where the client connects, not which server it is. A
  production instance and a "test" instance on the same machine/port are the same server.
- Wildcard grants are matched by the server, and `_`/`%` in a pattern are wildcards: an
  unescaped ``virac_solo_parent_test`` grant also matches `virac-solo-parent-test` style names,
  and a pattern broader than intended silently reaches production.
- Server-wide resources (CPU, disk, the binary log, `max_connections`, global `SET`/`FLUSH`,
  `LOAD_FILE`/`SELECT ... INTO OUTFILE` with `FILE`) are shared; a runaway test affects
  production.
- The CREATE/DROP is by design destructive. The only real safeguard is that the account
  holding it cannot see anything valuable.

**Recommended configuration (nothing here has been applied):**

1. A dedicated test server instance (separate `mysqld`, data directory, port or socket, and
   preferably a separate machine or container) that never holds production data. Tests
   connect to it by explicit host and port; production credentials are never present there.
2. On that server a restricted account, for example `solo_test_runner`, granted only on
   ``solo\_review\_test\_%``, ``renewal\_test\_%``, ``newapp\_upload\_test\_%`` and the one
   fixed `virac_solo_parent_test` database (underscores escaped). No `*.*` grants, no `FILE`,
   `SUPER`, `PROCESS`, `GRANT OPTION`, and not `root`.
3. Source-table definitions come from `virac_solo_parent_test` (or `schema.sql`), never from
   the production database; the test account has no grant on `virac_solo_parent`.
4. The account is created from the server console by the owner, with its password supplied
   as a process-level environment variable (`DB_PASS`) for the run only.
5. `TEST_STORAGE_ROOT` points at a dedicated test folder on the same dedicated host, so
   database and file isolation are verified together.
6. Verify independently before any run (the owner, outside this repository):
   `SHOW GRANTS FOR` the test account on the test server, and confirm that production
   credentials fail to authenticate against it.

The production configuration (`config/app.php`, `config/local.php`) is not changed by any of
this.

## Tool behavior

| Tool | Behavior | Safety notes |
|---|---|---|
| `database/test_admin_management_layout.php`, `test_role_account_creation.php`, `test_user_account_actions.php`, `test_optional_applicant_gmail.php` | Create connection-local temporary tables in `virac_solo_parent_test` and write fixtures only there. Most are clones of source definitions; `test_user_account_actions.php` also uses three hand-written stand-ins, and `test_admin_management_layout.php` also reads real `applications`. | Two separate privileges, neither substituting for the other: source-table `SELECT` (cloned definitions via `SHOW CREATE TABLE`, plus real `applications` for the admin layout test only) and `CREATE TEMPORARY TABLES` (the connection-local tables). Whether `DROP TEMPORARY TABLE` (issued by all four) needs a separate privilege is unverified. See "Additional privilege: `CREATE TEMPORARY TABLES`". Not covered by the disposable-database grants. |
| `database/test_save_verification.php` | Creates and drops a random disposable database built from `schema.sql` with synthetic fixtures. | Selects no existing database; see "Independent Save Verification test" above. |
| `database/test_*.php` | May read schema/data and/or write fixtures; some use temporary tables or transactions. | All entry-point database tests require the isolated test environment above. |
| `database/test_staff_review_workflow.php` | Creates and drops a random disposable database; copies table definitions (schema only) from the configured source and inserts synthetic fixtures. No source rows are copied. | Requires the source to be exactly `virac_solo_parent_test`; its target name is validated before connection/SQL (not re-validated immediately before `DROP DATABASE`). Uses a second connection to the temporary database; temporary copy omits `audit_logs` triggers. See "Privileges for the staff workflow and simplified renewal tests". |
| `database/test_simplified_renewal.php` | Creates and drops a random disposable database; copies table definitions (schema only) from the configured source and inserts synthetic fixtures. No source rows are copied. | Same source check as above. Target prefix is `renewal_test_`, not `solo_review_test_`; it needs its own grant pattern (see "Temporary database prefixes and grant patterns"). Not re-validated immediately before `DROP DATABASE`; temporary copy omits `audit_logs` triggers. |
| `database/sync_schema.php` | Comparison only. Creates and drops a temporary reference database, compares it with the selected database, and prints differences. Never alters the selected database; no apply mode exists. | Requires an isolated test environment and `--allow-temporary-database`. Never use it on production. |
| `database/schema.sql` | Drops and recreates `virac_solo_parent`, then creates schema. | Fresh-install-only. Never run against an existing database. |
| `database/seed.sql` | Inserts initial roles, reference data, settings, and initial administrator. | Fresh-install-only seed; do not re-run against an existing installation. |
| `database/migrate_*.sql` | Version-specific schema and/or data changes. | Review the exact migration and target database before a separate approved deployment. Do not batch-run historical migrations. |
| `database/seed_admin.php` | Creates an initial administrator against its configured database and supports web/CLI invocation. | Treat as a privileged write utility; restrict access and verify the target explicitly before use. |

Schema comparison must be invoked explicitly:

```powershell
php database/sync_schema.php --check-with-temp-db --allow-temporary-database
```

The tool creates and drops a temporary reference database but never changes the
selected database. Schema changes must go through reviewed migrations. The
command-line flag is not a replacement for the environment checks.

## Retention

Application deletion can cascade into application documents, decisions, status
history, appointments, assessments, SMS logs, and other records. Account
management blocks deletion for accounts with applications and provides
deactivation; deactivation is the preferred operational action. Historical QR
and chat rows are retained, but their foreign keys can still cascade if their
owning application or user is deleted. Do not change retention or deletion
behavior without a reviewed archival policy and separate approval.
