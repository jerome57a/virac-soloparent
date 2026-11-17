# MariaDB 10.4.32 test-instance runbook

Status: the isolated test instance (`127.0.0.1:3307`) has been set up: it is running, the two dedicated accounts exist, `virac_solo_parent_test` holds the imported schema, and the orphaned `test` privilege rows were removed on 2026-11-16 (section 7). **Not yet performed:** database-connected application tests, fixture imports, migrations, and storage verification (section 4). Phase 2 is not complete. Commands below are for the operator to run by hand, after the checkpoints pass; steps already carried out are recorded for reference.
Companion to [database-tooling-safety.md](database-tooling-safety.md) and [runtime-test-plan.md](runtime-test-plan.md).

## 0. Prohibitions

Never, at any step:

- touch the production database files, `C:\xampp\mysql\data`, or the XAMPP MariaDB instance on port 3306;
- touch `APP_ROOT/storage` or any production upload, avatar, session or log folder;
- read, copy, print or commit credentials, `config/local.php`, backups or applicant records;
- use `root`, `virac_solo_parent`, or any non-loopback host for tests (the test instance's own `root` is used only for administration in 3.3-3.5, never by a test script);
- run `database/schema.sql` unmodified against the test instance (see 3.5);
- use `--force` on any `mysql` client command.

## 1. Target layout

| Item | Value |
|---|---|
| Binaries | `C:\xampp\mysql\bin` (`mysqld.exe`, `mysql.exe`, `mysql_install_db.exe`), used read-only |
| `basedir` | `C:\xampp\mysql`, read-only: nothing is written there |
| Instance folder | `C:\dev\mariadb-test-3307`, outside `C:\xampp` and the Apache document root. Holds `data` (datadir), `tmp` (tmpdir), `logs` (error log), `my.ini` and the pid file. Nothing is written outside it |
| Port / bind | 3307, `bind-address=127.0.0.1` |
| Service | none (started by hand in a console) |
| Binary logging | off (no `log_bin`; otherwise CREATE TRIGGER may demand SUPER) |
| Persistent database | `virac_solo_parent_test`, schema imported from a filtered copy of `schema.sql` (3.5) |
| Test accounts | `soloparent_test` and `soloparent_tmpdb`, each at `127.0.0.1` only, each with its own non-empty password (see 2) |
| Test storage root | e.g. `C:\dev\virac-test-storage` (see section 4) |

## 2. Required grants (exact)

Two accounts, as defined in [database-tooling-safety.md](database-tooling-safety.md), "Two-account design and grant SQL" (authoritative; this section only repeats it): `'soloparent_test'@'127.0.0.1'` and `'soloparent_tmpdb'@'127.0.0.1'`. Each has its own password. Tests connect with `DB_HOST=127.0.0.1` over TCP, so no `localhost` account is needed or created. `skip-name-resolve` (3.2) makes the match IP-based. Do not share an account between the two categories and do not combine their privileges.

**`soloparent_test`** (tests against the persistent `virac_solo_parent_test` database, including the temporary-table tests):

| Grant | Scope |
|---|---|
| `SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES` | `virac_solo_parent_test`.* |

**`soloparent_tmpdb`** (tests that create and drop disposable databases):

| Grant | Scope |
|---|---|
| `SELECT` | `virac_solo_parent_test`.* |
| `CREATE, DROP, ALTER, INDEX, TRIGGER, SELECT, INSERT, UPDATE, DELETE` | `solo\_review\_test\_%`.* |
| `CREATE, DROP, ALTER, SELECT, INSERT, UPDATE, DELETE` | `renewal\_test\_%`.* |
| `CREATE, DROP, ALTER, TRIGGER, SELECT, INSERT, UPDATE, DELETE` | `newapp\_upload\_test\_%`.* |

Neither account has global privileges (only the implicit `USAGE`), `GRANT OPTION`, `FILE` or `SUPER`, any grant on `virac_solo_parent`, or any `sp\_schema\_check\_%` grant. Underscores must be escaped; an unescaped `_` matches any character.

Temporary database names are script-generated: prefix plus 12 lowercase hex (`sp_schema_check_` uses 16). The scripts create and drop them.

### 2.1 sp_schema_check is optional

`database/sync_schema.php --check-with-temp-db --allow-temporary-database` is a comparison-only tool, **not part of normal acceptance testing**. No account holds a `sp\_schema\_check\_%` grant. If it is separately approved later, the reference lists `CREATE, DROP, ALTER, INDEX, TRIGGER, SELECT` on `sp\_schema\_check\_%` and `SELECT, TRIGGER` on `virac_solo_parent_test`; which account would receive them is not established there.

### 2.2 Which script needs what

| Script | Temp DB prefix | Reads `virac_solo_parent_test` (needs `DB_NAME`) | `DB_USER` |
|---|---|---|---|
| `test_save_verification.php` | `solo_review_test_` | no | `soloparent_tmpdb` |
| `test_staff_review_workflow.php` (includes `appointment_management_checks.php`) | `solo_review_test_` | yes | `soloparent_tmpdb` |
| `test_simplified_renewal.php` | `renewal_test_` | yes | `soloparent_tmpdb` |
| `test_new_application_uploads.php` | `newapp_upload_test_` | yes | `soloparent_tmpdb` |
| `sync_schema.php` (optional) | `sp_schema_check_` | yes | none: no account holds its grant (2.1) |
| Persistent-database tests (eligibility history, public tracking, Node fixtures and live pages, and the temporary-table tests such as `test_admin_management_layout.php` and `test_role_account_creation.php`) | none | yes | `soloparent_test` |

## 3. Operator actions

Each step may start only after the matching checkpoint in section 5 is satisfied. All `mysql.exe` commands below use `--no-defaults` (so XAMPP's `my.ini` is never read), `--protocol=TCP`, `--host=127.0.0.1`, `--port=3307`, and prompt for the password (`--password`, never on the command line).

1. **Initialize** the data directory in `C:\dev\mariadb-test-3307\data` with `C:\xampp\mysql\bin\mysql_install_db.exe --datadir=C:\dev\mariadb-test-3307\data --port=3307`, and nowhere else. The port is supplied explicitly. Do not pass `--service` (no service is to be named or installed), `--socket` (named pipes are not needed: this instance is TCP-only), `--password`, `--default-user`, `--allow-remote-root-access` or `--skip-networking`. `--verbose-bootstrap` may be added; it only prints more output. First run the datadir guard (also before step 3); STOP if it throws:
   ```powershell
   $d = [IO.Path]::GetFullPath('C:\dev\mariadb-test-3307\data').TrimEnd('\')
   $x = [IO.Path]::GetFullPath('C:\xampp\mysql\data').TrimEnd('\')
   if ($d -ieq $x -or $d.StartsWith('C:\xampp\', [StringComparison]::OrdinalIgnoreCase)) { throw 'STOP: datadir is inside XAMPP' }
   ```
   Neither the instance folder nor any existing parent from `C:\dev` down may be a junction/symlink (the `ReparsePoint` loop in section 5, "Before initialization", must pass).

   **Two different `my.ini` files.** The Windows `mysql_install_db.exe` creates its own `my.ini` inside the data directory (`C:\dev\mariadb-test-3307\data\my.ini`). That generated file is not the file in step 2. For normal server startup, `--defaults-file=C:\dev\mariadb-test-3307\my.ini` (step 3) explicitly selects the configuration, so the generated `data\my.ini` is not used then. That statement covers server startup only: which option files the initializer's bootstrap `mysqld` reads remains unverified. After initialization, inspect it read-only and record its contents (do not edit it, and do not rely on it); `C:\dev\mariadb-test-3307\my.ini` must be unchanged (compare its SHA-256 before and after).

   **Service state.** Check Windows services before and after initialization and compare the lists (for example `Get-Service | Sort-Object Name | Select-Object Name, Status, StartType`, or `sc query state= all`). Any new or changed service: STOP. `--service` is omitted deliberately.

   **What has and has not been established.** The utility's `--help` (Ver 1.00, Monty Program Ab) lists `--datadir`, `--service`, `--password`, `--port`, `--socket`, `--default-user`, `--allow-remote-root-access`, `--skip-networking`, `--innodb-page-size`, `--silent` and `--verbose-bootstrap`; it has no `--version` and no `--basedir` option. That the generated `data\my.ini` exists is a stated expectation to be confirmed after the run. Not established from the help or the documentation available: how the utility treats an existing or non-empty datadir (so the datadir must be absent before the run, and an existing one is a STOP); which option files, if any, the bootstrap `mysqld` reads (it may read XAMPP's `C:\xampp\mysql\my.ini`); how it chooses its base directory; the exact accounts it creates; and whether it can install a service. Treat these as unknown, and rely on the before/after checks rather than on assumptions.
2. **Config** `C:\dev\mariadb-test-3307\my.ini`. Do not reuse `C:\xampp\mysql\bin\my.ini`. Under `[mysqld]`:
   ```ini
   port=3307
   bind-address=127.0.0.1
   skip-name-resolve
   basedir=C:/xampp/mysql
   datadir=C:/dev/mariadb-test-3307/data
   tmpdir=C:/dev/mariadb-test-3307/tmp
   pid-file=C:/dev/mariadb-test-3307/mariadb-test-3307.pid
   log-error=C:/dev/mariadb-test-3307/logs/error.log
   ```
   No `log_bin`. Create `tmp` and `logs` by hand first. **Windows IPC isolation:** the XAMPP instance may use the default named pipe (`MySQL`) and shared-memory name (`MYSQL`). Named pipe and shared memory are off by default in `mysqld` and must stay off: do **not** set `enable-named-pipe` or `shared-memory`. If either is ever enabled, give it a unique name (`socket=MARIADB_TEST_3307` is the pipe name on Windows; `shared-memory-base-name=MARIADB_TEST_3307`). Clients always use `--protocol=TCP`, so they cannot reach the XAMPP instance by pipe or shared memory.
3. **Start** `C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\dev\mariadb-test-3307\my.ini --console` from the new folder. Do not install it as a service.
4. **Secure initial accounts, then create the test accounts.** Do not assume the initial state; inspect first.
   - 4a. Connect as the administrator `root` (initially passwordless, so use `--user=root --skip-password` for this first connection only), then inspect:
     ```sql
     SELECT User, Host, (authentication_string = '' OR authentication_string IS NULL) AS empty_pw FROM mysql.user;
     SELECT Db, User, Host FROM mysql.db;
     SHOW DATABASES;
     ```
   - 4b. Set a non-empty root password (chosen by you, never stored in the repository) for every `root` row you keep (`root@127.0.0.1`, `root@localhost`), e.g. `ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '<ROOT_PASSWORD>';`. Drop any other `root` rows (e.g. `::1`, hostname). Drop every anonymous account (`User = ''`), e.g. `DROP USER ''@'localhost';`. Drop the default `test` database if present (`DROP DATABASE IF EXISTS test;`) and remove any `mysql.db` rows for `test` / `test\_%`, then `FLUSH PRIVILEGES;`. Re-run the 4a queries: every remaining row has `empty_pw = 0`, there is no `User = ''` row and no `test` database or grant. If unsure, STOP.
   - 4c. Expected `SHOW DATABASES` now: `information_schema`, `mysql`, and `performance_schema` (if listed by this build). Nothing else.
   - 4d. Create the database (as `root`). The two test accounts are created later, in 5e, following the provisioning order in the reference ("Two-account design and grant SQL": create the database, import as `root@127.0.0.1`, then create the accounts):
     ```sql
     CREATE DATABASE virac_solo_parent_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
5. **Import the schema** as the administrator `root` (connect with `--host=127.0.0.1` so the session is `root@127.0.0.1`; not a test account: `soloparent_tmpdb` is SELECT-only on that database and `soloparent_test` has no schema-changing privileges). `schema.sql` lines 30-33 are `DROP DATABASE IF EXISTS virac_solo_parent;`, a two-line `CREATE DATABASE virac_solo_parent ... ;` and `USE virac_solo_parent;`. Never run it as is, and do not edit `schema.sql`.
   - 5a. Write a filtered copy **outside the repository and outside the instance folder** (the target folder must be new/empty), removing exactly those three statements:
     ```powershell
     $src = '<APP_ROOT>\database\schema.sql'
     $dst = 'C:\dev\mariadb-test-import\schema.filtered.sql'
     $enc = New-Object System.Text.UTF8Encoding($false)
     $text = [IO.File]::ReadAllText($src, $enc)
     $rx = '(?im)^[ \t]*(DROP DATABASE IF EXISTS virac_solo_parent;|CREATE DATABASE virac_solo_parent\r?\n[^;]*;|USE virac_solo_parent;)[ \t]*\r?\n'
     $n = [regex]::Matches($text, $rx).Count
     if ($n -ne 3) { throw "STOP: expected 3 matches, got $n" }
     $out = [regex]::Replace($text, $rx, '')

     # checks (each must pass, else STOP)
     if ([regex]::Matches($out, '(?im)^\s*(DROP|CREATE)\s+(DATABASE|SCHEMA)\b|^\s*USE\s').Count -ne 0) { throw 'STOP: database-control statement left' }
     $names = { param($t) ($t -split "\r?\n" | Where-Object { $_ -match 'virac_solo_parent' }) }
     $left = @(& $names $out)
     $srcNames = @(& $names $text)
     $srcComments = @($srcNames | Where-Object { $_ -match '^\s*--' })
     # exactly two occurrences remain, both comment-only lines (line starts with --), identical to the source's comment-only lines
     if ($left.Count -ne 2) { throw "STOP: expected exactly 2 lines with the production name, got $($left.Count)" }
     if (@($left | Where-Object { $_ -notmatch '^\s*--' }).Count -ne 0) { throw 'STOP: production name in a trailing-comment or executable line' }
     if ($srcComments.Count -ne 2 -or (Compare-Object $srcComments $left)) { throw 'STOP: remaining comment lines do not match the source schema' }
     $pat = @{ T = '(?im)^\s*CREATE\s+TABLE\b'; A = '(?im)^\s*ALTER\s+TABLE\b'; G = '(?im)^\s*CREATE\s+(DEFINER\s*=\s*\S+\s+)?TRIGGER\b' }
     foreach ($k in $pat.Keys) {
       $a = [regex]::Matches($text, $pat[$k]).Count; $b = [regex]::Matches($out, $pat[$k]).Count
       "$k source=$a filtered=$b"
       if ($a -ne $b -or $a -eq 0) { throw "STOP: $k count mismatch or zero" }
     }
     $dl = '(?im)^\s*DELIMITER\b'
     $da = [regex]::Matches($text, $dl).Count; $db = [regex]::Matches($out, $dl).Count
     "DELIMITER source=$da filtered=$db"
     if ($da -ne $db) { throw 'STOP: DELIMITER directive count mismatch' }
     [IO.File]::WriteAllText($dst, $out, $enc)
     ```
     The check also prints the three counts; keep them, they are the expected object counts in section 5. The only permitted occurrences of `virac_solo_parent` in the copy are exactly two comment-only lines (each starting with `--`, the header comments of `schema.sql`) that match the source; a trailing comment (`statement; -- virac_solo_parent`) or any executable line containing the name is rejected. The `DELIMITER` directive count must be identical in source and filtered copy (it is printed; keep it). `DELIMITER` is a mysql-client command, so the import in 5c must stay the mysql client `SOURCE` method; never pipe, redirect (`<`), or send the file through PHP/another driver. If any check is uncertain or fails, STOP and write nothing.
   - 5b. Confirm the destination before importing (expect `3307`, a datadir under `C:\dev\mariadb-test-3307\data`, and `virac_solo_parent_test`; anything else, STOP):
     ```
     C:\xampp\mysql\bin\mysql.exe --no-defaults --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --database=virac_solo_parent_test --execute="SELECT @@port, @@datadir, DATABASE()"
     ```
   - 5c. Import into `virac_solo_parent_test` only, no `--force` (the client stops on the first error):
     ```
     C:\xampp\mysql\bin\mysql.exe --no-defaults --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --database=virac_solo_parent_test --execute="SOURCE C:/dev/mariadb-test-import/schema.filtered.sql"
     ```
   - 5d. Verify `SHOW DATABASES` has no `virac_solo_parent`. Then delete the filtered copy and its folder.
   - 5e. Create the two test accounts, after the import, as `root` (placeholders only; generate each password yourself, never display it, and supply it through a protected local file or the process environment, never a command line, project file or Git). SQL as in the reference, "Two-account design and grant SQL":
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
     No `sp\_schema\_check\_%` grant is issued (2.1). **Order:** the reference lists database, then import as `root@127.0.0.1`, then accounts, and this runbook follows it. The reference gives a reason only for importing as `root@127.0.0.1` (the audit-log triggers take their definer from the importing account, and `root@angii` is inert under `skip-name-resolve`). It states no technical dependency of the test accounts on the schema, so creating them earlier is not shown to be unsafe; the order is kept because the reference specifies it. (On this instance the accounts already exist; this step records the intended procedure.)
   - **Schema and fixture import: account, target and approval.** Both are performed as `root@127.0.0.1` (connect with `--host=127.0.0.1`), never as `soloparent_test` or `soloparent_tmpdb`: `soloparent_tmpdb` has only `SELECT` on `virac_solo_parent_test`, and `soloparent_test` has `INSERT` but no `CREATE`, `ALTER`, `INDEX` or `TRIGGER`. The only permitted target is host `127.0.0.1`, port `3307`, database `virac_solo_parent_test`; port 3306 and the XAMPP MariaDB must never be used. A dedicated least-privilege import account (an earlier proposal in the reference) is **not adopted and not created**: its privilege list is not derived from the SQL files, the trigger-definer lifecycle is undecided, and no name or credential handling is defined (reference, "Import procedure", prerequisites). That decision stays unresolved. The schema import of step 5 is recorded as done on this instance (section 7: 33 tables, 2 triggers, 51 foreign keys). **No fixture import (`seed_test.sql`) has been performed**, and it needs separate explicit approval before it runs, as would any further schema import or database drop.
6. **Create the storage root and marker** (section 4).
7. **Set the environment** in the shell that runs the tests (process-level only; never `config/local.php`):

| Variable | Value | Required by |
|---|---|---|
| `APP_ENV` | `testing` | all |
| `SMS_PROVIDER` | `test` | all |
| `DB_HOST` | `127.0.0.1` exactly (not `localhost`) | all |
| `DB_PORT` | `3307` | all |
| `DB_USER` | per test category (2.2): `soloparent_tmpdb` for the four disposable-database scripts, `soloparent_test` for persistent-database tests. Set it per run; never a shared or combined account | all |
| `DB_PASS` | the password of the account named in `DB_USER` (not empty) | all |
| `DB_NAME` | `virac_solo_parent_test`, exact | staff workflow, renewal, new-application uploads, sync_schema; not needed by `test_save_verification.php` |
| `TEST_STORAGE_ROOT` | the folder from section 4 | all |
| `TEST_DOCUMENT_ROOT` | must equal the actual Apache `DocumentRoot` serving the test application. If the vhost's `DocumentRoot` differs from `C:\xampp\htdocs`, use the vhost value | all (CLI) |

## 4. Test storage

Operator creates `C:\dev\virac-test-storage` (example) and, by hand, an empty regular file named `.virac-test-storage` inside it. The five subfolders `uploads`, `avatars`, `sessions`, `logs`, `tmp` are **auto-created**; do not pre-create them. (`screenshots` is used only by the Node UI tests.)

`TEST_STORAGE_ROOT` must be:

- an absolute local path (no UNC, no `.` or `..`, no leading/trailing whitespace, not a drive root);
- existing, with no component that is a symlink, junction, mapped or SUBST drive, or 8.3 alias (its realpath must equal the path given). Junction handling was verified on PHP 8.2.12 on 2026-11-16 (`is_link()` false, `realpath()` resolves to the target, so the equality check refuses it; reference, "Windows / XAMPP limits"); symlinks, hard links, mapped drives and other redirection types were **not** tested;
- outside the application folder (not equal, inside, or a parent) and outside `APP_ROOT/storage`, including its real target if that is a junction;
- outside the Apache document root (not equal, inside, or a parent). Hence not under `C:\xampp\htdocs`;
- outside the MariaDB test-instance folder `C:\dev\mariadb-test-3307` (not equal, inside, or a parent), so test uploads never mix with datadir, tmpdir or logs;
- the holder of the regular marker file `.virac-test-storage` (not a link), re-checked at runtime.

Any failure stops the test before any database work; there is no fallback to production folders.

### 4.1 PHP temporary folders for the test server (pending: not yet created or verified)

Uploaded files first land in PHP's own temporary folder, before any application code runs. XAMPP's `php.ini` sets `upload_tmp_dir=C:\xampp\tmp` (verified, read-only), so a test server must override it for its own process only; the global `php.ini` and Apache configuration are never changed.

1. **Create the folder before starting PHP.** The operator creates `<TEST_STORAGE_ROOT>\tmp` (for example `C:\dev\virac-test-storage\tmp`) by hand, after the root and marker exist. It does not exist yet, and the application never creates it in time (PHP reads the setting first). Verify it is an ordinary directory (no `ReparsePoint` attribute) with the inherited ACL of the test root.
2. **Start the test server with both settings as process-level options**, for example (not executed here):
   `C:\xampp\php\php.exe -S 127.0.0.1:<port> -t <project folder> -d "upload_tmp_dir=<TEST_STORAGE_ROOT>\tmp" -d "sys_temp_dir=<TEST_STORAGE_ROOT>\tmp"`. Both must be given and both must name exactly that folder. The port is 1024-65535 and never 3306.
3. **The PHP process must run under an account with write permission** to that folder (the account shown in its ACL). On Windows `is_writable()` reflects only the read-only attribute, so the application cannot prove the ACL; the operator does.
4. **Verify the effective settings before any upload test:** read the server process's command line (`Get-CimInstance Win32_Process`) and confirm both `-d` options; `php -d upload_tmp_dir=... -d sys_temp_dir=... -r "echo ini_get('upload_tmp_dir'), ' ', ini_get('sys_temp_dir');"` shows the flags are honoured. The application also checks this itself: with `APP_ENV=testing`, on every web request (not the command line), `upload_tmp_dir` and `sys_temp_dir` must both be set, absolute, resolve to themselves (no link, junction or alias) and equal `TEST_TMP_PATH`, and the folder must exist and be writable; otherwise the request is refused with the reason. Production is unaffected. Command-line test scripts are exempt (they receive no uploads and use `TEST_TMP_PATH` explicitly).
5. **Fallback is not assumed away.** PHP is believed to fall back to the system temporary folder if the configured folder is unusable (recalled from its source, not verified here); the checks above refuse such a start, and `sys_temp_dir` points any fallback at the same folder. A controlled check of the real behaviour needs separate approval.

Until the folder exists and the effective settings are independently verified, test-server upload testing stays pending.

## 5. Verification checkpoints (verifier, read-only)

**If any check below fails or is uncertain, STOP. Do not proceed to the next step.**

**Before initialization**
- `C:\dev\mariadb-test-3307` is a new or empty folder (`Test-Path` is `False`, or `Get-ChildItem -Force` prints nothing), outside `C:\xampp` and the document root.
- No junction/symlink on the path. Every existing directory from `C:\dev` through the instance folder has no `ReparsePoint` attribute (read-only; a directory that does not exist yet is skipped, and the loop must be re-run once the folder exists):
  ```powershell
  foreach ($p in 'C:\dev','C:\dev\mariadb-test-3307') {
    if (Test-Path -LiteralPath $p) {
      if ((Get-Item -LiteralPath $p -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "STOP: reparse point at $p" }
    }
  }
  ```
  Expected: no output. Any exception, or an attribute you cannot read: STOP.
- Port 3307 is free, checked by exact local port (listening endpoints and existing connections in every TCP state, IPv4 and IPv6):
  ```powershell
  Get-NetTCPConnection -LocalPort 3307 -ErrorAction SilentlyContinue
  netstat -ano -p TCP | Select-String -Pattern '^\s*TCP\s+\S+:3307\s'
  ```
  Expected: both print nothing. `-LocalPort` matches only the local port number, so 3306 and ports such as 33070 or 13307 never match; the `netstat` pattern anchors on the local-address column and needs a space after `:3307`. Any row (state `Listen`, `Established`, `TimeWait`, etc.) means 3307 is in use or in recent use: STOP. If the two commands disagree, STOP. Port 3306 may be listening; it belongs to XAMPP and is not touched.
- The datadir guard in 3.1 passes: the datadir is the new folder, not `C:\xampp\mysql\data`.
- `C:\dev\mariadb-test-3307\data` does not exist (`Test-Path` is `False`), and the SHA-256 of `C:\dev\mariadb-test-3307\my.ini` is recorded.
- The list of Windows services is recorded for comparison after initialization.

**After initialization**
- `C:\dev\mariadb-test-3307\data` exists. Isolation from XAMPP rests on explicit path and configuration isolation: the initializer was given only the test datadir, the datadir guard passed, and the server is started only with the test `--defaults-file`. A matching top-level listing of `C:\xampp\mysql\data` is not proof that no XAMPP file was modified, and XAMPP's data is not hashed or inspected recursively. Do not read, open or inspect anything under `C:\xampp\mysql\data`.
- `C:\dev\mariadb-test-3307\my.ini` has the same SHA-256 as before. The generated `data\my.ini` is a separate file, inspected read-only and recorded.
- The Windows service list matches the one taken before initialization. Any difference: STOP.

**Before server startup**
- **Option inventory (mandatory, read-only).** Inspect the installed binary with `C:\xampp\mysql\bin\mysqld.exe --no-defaults --help --verbose` (prints help and exits; it does not start a server). In its output, confirm that every **required option used by the configuration** exists, is spelled exactly as written in 3.2, and has an understood default: `skip-name-resolve`, `port`, `bind-address`, `basedir`, `datadir`, `tmpdir`, `pid-file`, `log-error`. If any of these is absent, spelled differently, or its behaviour or default is unclear, STOP. **Optional transport options, not used by the configuration:** `enable-named-pipe` and `socket` are present in this build's help; `shared-memory` and `shared-memory-base-name` are absent from this MariaDB 10.4.32 build's help output and variables table, so they are recorded as absent/unsupported here and their defaults are not verified. None of the four may appear in `my.ini`; their absence or presence in the help does not by itself stop the run. XAMPP's default pipe and shared-memory names (`MySQL`, `MYSQL` in 3.2) remain **unverified**. Do not substitute guessed options or edit `my.ini` from memory.
- `my.ini` has `port=3307`, `bind-address=127.0.0.1`, `skip-name-resolve`, `basedir=C:/xampp/mysql`, datadir/tmpdir/pid-file/log-error all inside the new folder, no `log_bin`, and no `enable-named-pipe` or `shared-memory`.
- The start command uses `--defaults-file` for this file, with no service installation.

**Before database creation (4d), and so before the import and account creation (5e)**
- Loopback-only listener: `Get-NetTCPConnection -LocalPort 3307 -State Listen` shows exactly one row with `LocalAddress` `127.0.0.1` (no `0.0.0.0`, no `::`), and its `OwningProcess` is the `mysqld` you started (`Get-Process -Id <pid>`), not the XAMPP one on 3306.
- `SELECT @@port, @@log_bin, @@datadir` shows 3307, 0/OFF, and a path under `C:\dev\mariadb-test-3307\data`.
- 3.4a-4c done: every remaining account has a non-empty password, no anonymous accounts (met; the orphaned `test` grant rows were removed, section 7), no `test` database (not met: the empty `test` database still exists, section 7); `SHOW DATABASES` is `information_schema`, `mysql`, and `performance_schema` if listed.
- The password placeholders have been replaced outside the repository and are not empty.

**Before schema import**
- Filtered copy produced by 3.5a with exactly 3 matches and all checks passing; the copy lives outside the repository.
- Exactly two comment-only lines containing `virac_solo_parent` remain, equal to the source's; no trailing-comment or executable-line occurrence.
- The `DELIMITER` directive count printed by 3.5a is equal for source and filtered copy, and the import uses the mysql client `SOURCE` method (3.5c).
- 3.5b output shows 3307, the new datadir, and `virac_solo_parent_test`. No `--force` in the command.

**After account creation and import / before runtime tests**
- `SHOW GRANTS FOR 'soloparent_test'@'127.0.0.1'` lists exactly: `USAGE ON *.*` (implicit, with the password hash) and `SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES` on `virac_solo_parent_test`.*. Nothing else.
- `SHOW GRANTS FOR 'soloparent_tmpdb'@'127.0.0.1'` lists exactly: `USAGE ON *.*` (implicit, with the password hash); `SELECT` on `virac_solo_parent_test`.*; and the three pattern grants in section 2 (`solo\_review\_test\_%`, `renewal\_test\_%`, `newapp\_upload\_test\_%`) with exactly the privilege lists shown there. No `sp\_schema\_check\_%` grant.
- For both accounts: no other global privilege, no `GRANT OPTION`, `FILE` or `SUPER`, and no `soloparent_test@localhost` or `soloparent_tmpdb@localhost` account exists. `SHOW GRANTS` does not show rows in `mysql.db` that apply to every user, so also check `SELECT * FROM mysql.db WHERE User = ''` returns no rows (section 7).
- Connecting as each account over `--protocol=TCP --host=127.0.0.1 --port=3307`, `SELECT CURRENT_USER()` returns `soloparent_test@127.0.0.1` and `soloparent_tmpdb@127.0.0.1` respectively.
- `virac_solo_parent_test` has the same counts as the filtered copy (the three counts printed in 3.5a), checked with:
  ```sql
  SELECT COUNT(*) FROM information_schema.TABLES   WHERE TABLE_SCHEMA='virac_solo_parent_test' AND TABLE_TYPE='BASE TABLE';
  SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='virac_solo_parent_test';
  ```
  Tables should equal the `CREATE TABLE` count (`ALTER TABLE` adds no tables), triggers the `CREATE TRIGGER` count. Any difference you cannot explain from the filtered copy: STOP.
- `SHOW DATABASES` has no `virac_solo_parent` (production name) and no `test`. Note: the dedicated accounts no longer see `test`; the empty `test` database itself still exists (section 7), so this check remains not met for `root`.
- Environment variables match section 3.7; `DB_HOST` is exactly `127.0.0.1` and `DB_PORT` exactly `3307`; `TEST_DOCUMENT_ROOT` equals the Apache `DocumentRoot` of the test application.
- Storage root satisfies section 4, with the marker present and not inside `C:\dev\mariadb-test-3307`. **Pending:** the 4.1 temporary folder exists and the test server's effective `upload_tmp_dir` and `sys_temp_dir` were verified.

**Runtime tests**
- Run the scripts in 2.2 (not `sync_schema.php`) and confirm each drops its temporary database. Afterwards `SHOW DATABASES` lists exactly `information_schema`, `mysql`, `performance_schema` (if listed) and `virac_solo_parent_test`: no production-named database, no `test` (the empty `test` database still exists as of 2026-11-16, section 7), and no leftover `solo_review_test_*`, `renewal_test_*`, `newapp_upload_test_*` or `sp_schema_check_*` databases.
- Confirm that nothing was written under `APP_ROOT/storage`.

## 6. Known documentation issue (not fixed here)

`database/tool_safety.php:104` says the test account should be "limited to solo_review_test_% databases". This is stale. Scripts also create `renewal_test_*` and `newapp_upload_test_*` (and `sp_schema_check_*` for `sync_schema.php`), and four of them read `virac_solo_parent_test`. The message needs updating in a separate change; this runbook is authoritative until then.

## 7. Deviation as built: orphaned default `test` privilege rows (resolved 2026-11-16; empty `test` database remains)

Recorded 2026-11-16 for the instance at `127.0.0.1:3307` (MariaDB 10.4.32). The orphaned privilege rows were removed the same day (see "Resolution" below). The sections that follow keep the original finding as history.

- **Finding.** `mysql.db` contains two orphaned rows with `User=''`, `Host='%'` and `Db` equal to `test` and `test\_%`. They carry broad database-level privileges (including `CREATE`, `DROP`, `ALTER`, `INDEX`, `TRIGGER`, `INSERT`, `UPDATE`, `DELETE`, `SELECT`, `CREATE TEMPORARY TABLES`, but not `GRANT OPTION`). They are installer defaults. Step 4b above expects such rows to be removed; they were not.
- **No anonymous account exists.** There is no `User=''` row in `mysql.user` or `mysql.global_priv`. The rows are orphaned grant-table entries, and an empty `User` in `mysql.db` applies to every user.
- **Both test accounts were affected**, in spite of their explicit grants being exactly as audited (`docs/database-tooling-safety.md`, "Two-account design and grant SQL"). `soloparent_test@127.0.0.1` and `soloparent_tmpdb@127.0.0.1` both list the `test` database in `SHOW DATABASES`, and the rows give them rights on `test` and on any database whose name begins `test_`. `SHOW GRANTS` for the accounts does not show this access, so the usual "check the real grants" step does not reveal it.
- **`REVOKE` does not work.** ``REVOKE ALL PRIVILEGES ON `test`.* FROM ''@'%';`` failed with `ERROR 1141 (42000): There is no such grant defined for user '' on host '%'`, because the server has no matching in-memory account or grant for these rows. The second `REVOKE` (on ``test\_%``) was not run, as agreed. The rows were unchanged afterwards. The same effect is behind the earlier `root@angii` entry (ignored under `--skip-name-resolve`, startup warning).
- **No fallback remediation was performed at that point.** The cleanup below was a separate, explicitly approved step.
- **Mitigating facts.** The server listens only on `127.0.0.1` (`bind-address`, `skip-name-resolve`); the `test` database is empty; the project's disposable database prefixes (`solo_review_test_`, `renewal_test_`, `newapp_upload_test_`, `sp_schema_check_`) and `virac_solo_parent_test` do not match ``test\_%``, so no test depends on the rows; nothing in `config/`, `database/` or `scripts/` refers to a database named `test`.
- **Resolution (2026-11-16).** Chosen option: direct grant-table edit, with no `CREATE USER`, `DROP USER` or `REVOKE`. Preflight confirmed port 3307, MariaDB 10.4.32, the expected datadir, exactly the two rows (`HEX(Db)` `74657374` and `746573745C5F25`, unchanged flags) and `mysql.db` as transactional Aria. In one client session:
  ```sql
  DELETE FROM mysql.db
  WHERE User = '' AND Host = '%'
    AND HEX(Db) IN ('74657374','746573745C5F25');
  ```
  It affected exactly 2 rows, and `FLUSH PRIVILEGES` then completed (it runs only on a count of 2).
- **Verification.** No `User=''`, `Host='%'` rows remain in `mysql.db` (5 rows left, from 7) and no privileges for `''@'%'` are active. `soloparent_test@127.0.0.1` and `soloparent_tmpdb@127.0.0.1` still authenticate, their `SHOW GRANTS` are identical to before, and neither lists `test` in `SHOW DATABASES` any more. `virac_solo_parent_test` is unchanged: 33 InnoDB tables, 2 triggers, 51 foreign keys, and zero rows in every application table. No credentials are recorded here.
- **Not touched.** Port 3306, XAMPP, application data, fixtures, migrations, project configuration and storage.
- **Remaining.** The empty `test` database still exists and was not modified or dropped; only its orphaned privilege rows are gone. The checkpoints in section 5 that require no `test` database stay **not met** until a separate decision removes it or accepts it. The privilege-related deviation is closed. Storage verification (section 4) and approval of the fixture import remain pending, and the runtime tests in section 5 have not been run, so this does not mark Phase 2 complete.
