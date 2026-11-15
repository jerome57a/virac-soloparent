# Database tooling safety

## Test configuration

Database-backed test scripts fail closed unless the process explicitly sets all
of these variables:

```powershell
$env:APP_ENV = 'testing'
$env:DB_HOST = 'localhost'
$env:DB_NAME = 'virac_solo_parent_test'
$env:DB_USER = 'soloparent_test'
$env:DB_PASS = '<test-only password>'
$env:SMS_PROVIDER = 'test'
```

Use a dedicated database user limited to the isolated test database (and, only
for tools that create disposable databases, the required create/drop privileges).
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
$env:DB_HOST = 'localhost'        # only localhost or 127.0.0.1
$env:DB_USER = 'soloparent_test'  # dedicated account; root is rejected
$env:DB_PASS = '<test-only password>'
```

`DB_NAME` is ignored. The test creates `solo_review_test_` + 12 hex characters,
builds the schema from `database/schema.sql` (database-level `DROP DATABASE`,
`CREATE DATABASE` and `USE` are skipped; only table and trigger DDL runs; any
other statement stops setup), inserts synthetic roles, one category and three
required document requirements, and drops only that generated database. The
name is validated before creation and again before the drop.

Privileges for the dedicated account, and nothing else:

```sql
GRANT CREATE, DROP, ALTER, INDEX, REFERENCES, TRIGGER, SELECT, INSERT, UPDATE, DELETE
  ON `solo\_review\_test\_%`.* TO 'soloparent_test'@'localhost';
```

For `test_save_verification.php` only, the account needs no privilege on
`virac_solo_parent` or `virac_solo_parent_test`. This does **not** apply to
`test_staff_review_workflow.php` or `test_simplified_renewal.php`, which read table
definitions from `virac_solo_parent_test` (see "Privileges for the staff workflow
and simplified renewal tests" below). The grant above is the one documented for
`test_save_verification.php`; it is not the minimum for those two tests.

## Temporary database prefixes and grant patterns

Three tools create and drop their own disposable databases. Each generates a
name from a fixed prefix plus 12 lowercase hexadecimal characters and validates it
(`validate_temporary_database_target()` in `database/tool_safety.php`) before any
SQL runs. The validator rejects `virac_solo_parent` and `virac_solo_parent_test`.
(The validator itself also accepts a 16-character hexadecimal suffix; the scripts
generate 12.)

Re-validation before the drop differs by tool:

- `test_save_verification.php`: as described above, the name is validated before
  creation and again before the drop.
- `test_staff_review_workflow.php` and `test_simplified_renewal.php`: the name is
  validated once, before any SQL. These two scripts run `DROP DATABASE` directly on
  the same variable and do **not** re-validate immediately before the drop. The
  variable is not reassigned between validation and drop in the inspected code, but
  no re-validation step exists. (`drop_temporary_database()` in
  `database/tool_safety.php` does re-validate; these two scripts do not use it.)

| Tool | Prefix | Grant pattern (database level) |
|---|---|---|
| `database/test_save_verification.php` | `solo_review_test_` | ``solo\_review\_test\_%`` |
| `database/test_staff_review_workflow.php` | `solo_review_test_` | ``solo\_review\_test\_%`` |
| `database/test_simplified_renewal.php` | `renewal_test_` | ``renewal\_test\_%`` |

The grant above covers only the `solo_review_test_` prefix. **It does not cover
`test_simplified_renewal.php`**, which needs its own separate grant pattern on
``renewal\_test\_%``. Without it, that test fails at `CREATE DATABASE` with an
access error. Grants for the two patterns are independent; do not widen one
pattern to cover both (for example, do not use `%` or `%\_test\_%`).

In MariaDB database-level grant patterns, `_` is a single-character wildcard and
`%` matches any string. Each literal underscore in a pattern must therefore be
escaped as `\_`; an unescaped pattern such as `solo_review_test_%` would also
match unrelated database names. The escaped patterns are shown in the table.

### Privileges for the staff workflow and simplified renewal tests

Applies **only** to `test_staff_review_workflow.php` and
`test_simplified_renewal.php`. It is based on a read-only source inspection (no
SQL, tests or scripts were run and no server was contacted) and must not be
generalized to other database tools. This document does not add GRANT statements;
it records which privileges the inspected code paths need.

**Source test database `virac_solo_parent_test`** (the guard requires
`DB_NAME=virac_solo_parent_test`):

- Required: `SELECT` only.
- Used for `SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'` and
  `SHOW CREATE TABLE` (table definitions only; no rows are copied). The source
  connection runs no `INSERT`, `UPDATE` or `DELETE` in these tests.
- That `SELECT` alone is sufficient for the two `SHOW` statements is inferred from
  MariaDB behavior, not tested on this server.

**Temporary databases** (each pattern is granted independently):

| Test | Prefix | Database-level pattern |
|---|---|---|
| `test_staff_review_workflow.php` | `solo_review_test_` | ``solo\_review\_test\_%`` |
| `test_simplified_renewal.php` | `renewal_test_` | ``renewal\_test\_%`` |

- Minimum privileges on the matching pattern for each test:
  `CREATE, DROP, SELECT, INSERT, UPDATE, DELETE`.
- `CREATE` and `DROP` cover `CREATE DATABASE` / `DROP DATABASE` and the cloned
  `CREATE TABLE` statements. `DELETE` is exercised by the draft cleanup that runs
  when an application or renewal is submitted. `UPDATE` is used by status,
  checklist, appointment and settings changes (including
  `INSERT ... ON DUPLICATE KEY UPDATE`).
- Do not widen either pattern to cover both (for example `%` or `%\_test\_%`).
- No global privilege was found to be required.

**Not required by these two test paths:** `ALTER`, `INDEX`, `REFERENCES` and
`TRIGGER`. In the inspected code, these tests issue no `ALTER TABLE`,
`CREATE INDEX` or `CREATE TRIGGER`. Foreign keys and indexes arrive inside the
`CREATE TABLE` text returned by `SHOW CREATE TABLE`. Application code reached by
the tests (services, models, controllers, includes) contains no DDL. This applies
to these two tests only; `test_save_verification.php` runs `ALTER TABLE` and
`CREATE TRIGGER` from `schema.sql`, and other tools are not covered by this
finding.

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

Unverified (inferred or not observed; an administrator must verify before the
tests are run, and none of this has been tested against a server):

- That `SELECT` is sufficient on the source for `SHOW FULL TABLES` and
  `SHOW CREATE TABLE`.
- Whether MariaDB requires `REFERENCES` to create foreign keys on this server
  version. It is expected not to, but this was not tested; if the clone fails on
  foreign keys, this is the first privilege to investigate.
- Whether `SELECT ... FOR UPDATE` needs `UPDATE` on this server version (moot,
  because `UPDATE` is granted).
- That session settings such as `FOREIGN_KEY_CHECKS` and `sql_mode` need no
  privilege.
- Dynamic or runtime-built SQL: the inspection was static, so SQL assembled at
  runtime could not be exhaustively ruled out.
- The privileges the MariaDB account actually holds.

### Check the real grants first

This document does not grant anything, and the patterns above do not prove the
account has them. Before running either test, a database administrator must
independently check the grants that actually exist for the dedicated test
account (for example with `SHOW GRANTS` for that account) and confirm that:

- the account has privileges on the correct pattern for the test being run;
- it has no privilege on `virac_solo_parent`, and no broader pattern;
- it is not `root`;
- for the staff workflow and simplified renewal tests, it has only `SELECT` on
  `virac_solo_parent_test`.

**Limits of the guard.** `database/tool_safety.php` rejects `DB_USER=root`
(case-insensitive, trimmed), requires `DB_NAME=virac_solo_parent_test`, and
refuses the production name. It does **not** inspect grants, so it cannot detect a
non-root account that has excessive privileges. Production safety therefore
depends on the account's actual grants, which only the administrator's check above
can establish.

## Additional privilege: `CREATE TEMPORARY TABLES`

Applies **only** to these four tests, which are separate from the disposable-database
tests above:

- `database/test_admin_management_layout.php`
- `database/test_role_account_creation.php`
- `database/test_user_account_actions.php`
- `database/test_optional_applicant_gmail.php`

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
| Not needed by these four | `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, `TRIGGER`, any global privilege, and any pattern grant such as `solo\_review\_test\_%` or `renewal\_test\_%`. `DROP` on the real tables is not needed. All four tests issue `DROP TEMPORARY TABLE`; whether that statement needs a separate privilege is **unverified** (see below) |

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

All four tests issue `DROP TEMPORARY TABLE`. Whether `DROP TEMPORARY TABLE` requires a
separate privilege is **unverified**.

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
  privilege beyond `CREATE TEMPORARY TABLES`; (2) `DROP TEMPORARY TABLE`, which all four
  tests issue, needs no separate `DROP` privilege (unverified in either direction); (3)
  the tables vanish when the connection closes. These match documented MariaDB/MySQL
  behavior but must be confirmed by an administrator before the tests are relied on.
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

Performed by a database administrator only, after approval. Nothing here has been run.
**This document does not state that the import is safe or ready to execute.** Every
command below is a proposal that the administrator must review, adapt and take
responsibility for. Windows commands are for `cmd.exe`, not PowerShell. Words in capital
letters (`ADMIN_NAME`, `IMPORT_ACCOUNT_NAME`, and so on) are placeholders the
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
- An import account with `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, `TRIGGER`, `INSERT`,
  `UPDATE`, `SELECT` on `virac_solo_parent_test` only. It has no `DROP` or global
  privilege, so dropping the database is a separate administrator action. Its credentials
  are kept in a client option file (below) rather than on a command line. Check its real
  grants first.
- A working folder for all temporary files, **outside the repository and outside the web
  root**: for example `C:\Users\ADMIN_NAME\soloparent-import-temp`. It must not be under
  `C:\xampp\htdocs` (served by Apache) or `C:\xampp\htdocs\virac-soloparent`. Every
  temporary file in this procedure goes there and is referred to by a full, quoted,
  absolute path.

**Temporary files: access, encoding and contents**

Three files are created in the working folder:

| File | Contents |
|---|---|
| `import-client.cnf` | Client option file with only `[client]`, `host=localhost`, `port=PORT_NUMBER`, `user=IMPORT_ACCOUNT_NAME`, `password=IMPORT_ACCOUNT_PASSWORD`. Nothing else: no `force`, no `init-command`, no `!include`, no other option. |
| `session_and_hashes.sql` | Exactly three statements, each on its own line, ending with a newline: `SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES');`, then `SET @test_admin_password_hash = 'PASTE_ADMIN_HASH_HERE';` and `SET @test_user_password_hash = 'PASTE_USER_HASH_HERE';`. |
| `combined_seed.sql` | Created by the `copy /b` command in Step 2; the previous file followed by the repository's `seed_test.sql`. |

- **`port` and server identity.** Set `PORT_NUMBER` to the port the XAMPP MariaDB service
  actually uses (see the XAMPP Control Panel; the default is 3306 but XAMPP installs are
  often changed). MariaDB's client documentation says the port is otherwise taken from the
  option files, then the `MYSQL_TCP_PORT` environment variable, then `/etc/services`,
  then the built-in default 3306. A file with no `port` line can therefore reach a
  different server than the one intended. "Server version and session `sql_mode`"
  includes a server-identity check.
- **Password characters.** MariaDB's option-file documentation says double quotes may
  quote a value and that `\n`, `\r`, `\t`, `\b`, `\s`, `\"`, `\'` and `\\` are escape
  sequences, but it does not say how `#`, quotes or backslashes inside a password are
  read. That is **unverified**, so give the import account a password made only of
  letters and digits (no `#`, quotes, backslashes, spaces or `%`) and write it without
  quotes. If that is not possible, the administrator must test the quoting on a
  throwaway account first.
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
"C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --default-character-set=utf8mb4 --execute="SELECT VERSION(), @@port, @@datadir, @@GLOBAL.sql_mode, @@SESSION.sql_mode;"
```

- **Server identity.** `@@port` must equal the `port` written in `import-client.cnf` and the
  port shown for MariaDB in the XAMPP Control Panel, and `@@datadir` must be the data folder
  of the intended XAMPP installation (normally under `C:\xampp\mysql\data`). If either
  differs, stop: the client reached a different server than intended. (That `@@port` and
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
- `schema_test.sql` header line 27 still says 10.1.1 (that file is not edited by this
  correction). Where the two differ, 10.2.7 governs.
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
"C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --default-character-set=utf8mb4 --execute="SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'virac_solo_parent_test';"
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
"C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --default-character-set=utf8mb4 --execute="SELECT 'TABLES_AND_VIEWS' AS kind, COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'virac_solo_parent_test' UNION ALL SELECT 'ROUTINES', COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'virac_solo_parent_test' UNION ALL SELECT 'TRIGGERS', COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'virac_solo_parent_test' UNION ALL SELECT 'EVENTS', COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = 'virac_solo_parent_test';"
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
"C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --default-character-set=utf8mb4 < "C:\xampp\htdocs\virac-soloparent\database\testing\schema_test.sql" && set SCHEMA_RESULT=CLIENT_RETURNED_0 || set SCHEMA_RESULT=CLIENT_RETURNED_NONZERO
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
if "%SCHEMA_RESULT%"=="CLIENT_RETURNED_0" if "%COMBINED_VERIFIED%"=="SEED_CHECKS_PASSED_2C" "C:\xampp\mysql\bin\mysql.exe" --defaults-file="C:\Users\ADMIN_NAME\soloparent-import-temp\import-client.cnf" --default-character-set=utf8mb4 < "C:\Users\ADMIN_NAME\soloparent-import-temp\combined_seed.sql" && set SEED_RESULT=CLIENT_RETURNED_0 || set SEED_RESULT=CLIENT_RETURNED_NONZERO
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
| The drop and recreate itself | An administrator account, in a separate action | The import account has no `DROP` privilege and must not be given one. |

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

## Storage isolation proposal (no code changed)

Nothing below is implemented. No storage file was opened, listed, moved or deleted
while preparing it; the findings come from source reading only.

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
`storage/logs` folders. They do not log in through `AuthService::startSession()`, so
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
