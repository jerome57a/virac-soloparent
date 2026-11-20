<?php
/**
 * Interview date booking: the rollout switch and the schema-readiness guard.
 *
 * The feature is effectively ON only when BOTH hold:
 *   1. system_settings.interview_date_booking_enabled is exactly '1' (default OFF; deliberately not in
 *      SETTINGS_REGISTRY, so it never appears on the admin Settings page), and
 *   2. every database object created by database/migrate_interview_date_booking.sql is present and
 *      defined as that migration defines it (see inspect()).
 *
 * Anything that reads or writes interview_dates, interview_invitations, appointment_events or the
 * appointments columns interview_date / date_only / booking_source / confirmation_sms_log_id must run
 * only behind enabled() (feature code) or schemaReady() (extra clauses in legacy queries). Until the
 * migration is applied, none of that code runs and the application behaves exactly as before.
 *
 * The readiness probe reads information_schema ONLY. It never selects from a new table or a new
 * column, so it cannot itself fail on an unmigrated database. Every object name is bound as a
 * parameter. The answer is memoized per PDO connection for the rest of the request. It fails
 * closed: any missing object, any definition that differs, missing privileges, or any database
 * error means "not ready". Nothing here ever enables the feature or writes to the database.
 *
 * Assumptions to confirm on a disposable database before the flag is ever turned on (each can
 * only make the guard answer "not ready", never "ready"):
 *   - the connecting account can see the triggers (information_schema.TRIGGERS lists a table's
 *     triggers only to an account holding the TRIGGER privilege);
 *   - MySQL 8.0.16 or later lists CHECK constraints in information_schema.TABLE_CONSTRAINTS (the
 *     migration's own re-run test relies on the same; an earlier MySQL ignores CHECK clauses and
 *     would not list them, so the guard answers "not ready") and reports the generated column
 *     through GENERATION_EXPRESSION / EXTRA.
 */
final class InterviewDateFeature
{
    public const FLAG_KEY = 'interview_date_booking_enabled';

    // ---- Expected structure: copied from database/migrate_interview_date_booking.sql (the source of truth). ----

    /** New tables => column => information_schema DATA_TYPE. Extra columns added later are allowed. */
    private const NEW_TABLES = [
        'interview_dates' => [
            'interview_date' => 'date', 'status' => 'enum', 'capacity_override' => 'int', 'created_by' => 'int',
            'closed_by' => 'int', 'closed_at' => 'datetime', 'closed_reason' => 'varchar',
            'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        ],
        'interview_invitations' => [
            'id' => 'int', 'application_id' => 'int', 'status' => 'enum', 'reschedules_allowed' => 'tinyint',
            'reschedules_used' => 'tinyint', 'opened_by' => 'int', 'opened_at' => 'datetime', 'closed_by' => 'int',
            'closed_at' => 'datetime', 'close_reason' => 'varchar', 'sms_log_id' => 'int',
            'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        ],
        'appointment_events' => [
            'id' => 'int', 'application_id' => 'int', 'appointment_id' => 'int', 'event_type' => 'varchar',
            'interview_date' => 'date', 'previous_interview_date' => 'date', 'source' => 'varchar',
            'actor_user_id' => 'int', 'actor_name' => 'varchar', 'actor_role' => 'varchar',
            'reason' => 'varchar', 'details' => 'varchar', 'created_at' => 'timestamp',
        ],
    ];

    /** "table.column" => exact (lower-case) COLUMN_TYPE of the enum columns. */
    private const ENUM_TYPES = [
        'interview_dates.status' => "enum('open','closed')",
        'interview_invitations.status' => "enum('open','booked','closed')",
        'appointments.booking_source' => "enum('legacy','staff','applicant')",
    ];

    /** The four columns added to appointments. */
    private const APPOINTMENT_COLUMNS = ['interview_date', 'date_only', 'booking_source', 'confirmation_sms_log_id'];

    /** table => index name => [ordered columns, NON_UNIQUE]. */
    private const INDEXES = [
        'appointments' => [
            'idx_appt_interview_date' => [['interview_date', 'status'], 1],
            'idx_appt_confirm_sms' => [['confirmation_sms_log_id'], 1],
        ],
        'interview_dates' => [
            'PRIMARY' => [['interview_date'], 0],
            'idx_idate_status' => [['status', 'interview_date'], 1],
        ],
        'interview_invitations' => [
            'PRIMARY' => [['id'], 0],
            'uq_invite_app' => [['application_id'], 0],
            'idx_invite_status' => [['status'], 1],
        ],
        'appointment_events' => [
            'PRIMARY' => [['id'], 0],
            'idx_aevent_app' => [['application_id', 'id'], 1],
            'idx_aevent_appt' => [['appointment_id'], 1],
            'idx_aevent_date' => [['interview_date', 'event_type'], 1],
            'idx_aevent_type' => [['event_type', 'created_at'], 1],
        ],
    ];

    /** table => CHECK constraint name. */
    private const CHECKS = [
        'appointments' => 'chk_appt_date_only',
        'interview_dates' => 'chk_idate_capacity',
    ];

    /** Foreign key name => [table, referenced table, DELETE_RULE]. */
    private const FOREIGN_KEYS = [
        'fk_appt_confirm_sms' => ['appointments', 'sms_logs', 'SET NULL'],
        'fk_idate_creator' => ['interview_dates', 'users', 'SET NULL'],
        'fk_idate_closer' => ['interview_dates', 'users', 'SET NULL'],
        'fk_invite_app' => ['interview_invitations', 'applications', 'CASCADE'],
        'fk_invite_opener' => ['interview_invitations', 'users', 'SET NULL'],
        'fk_invite_closer' => ['interview_invitations', 'users', 'SET NULL'],
        'fk_invite_sms' => ['interview_invitations', 'sms_logs', 'SET NULL'],
        'fk_aevent_app' => ['appointment_events', 'applications', 'CASCADE'],
        'fk_aevent_appt' => ['appointment_events', 'appointments', 'SET NULL'],
        'fk_aevent_actor' => ['appointment_events', 'users', 'SET NULL'],
    ];

    /** Trigger name => [event, timing]; both are on appointment_events. */
    private const TRIGGERS = [
        'appointment_events_no_update' => ['UPDATE', 'BEFORE'],
        'appointment_events_no_delete' => ['DELETE', 'BEFORE'],
    ];

    /** @var ?WeakMap<PDO, array> per-connection answers for the current request */
    private static ?WeakMap $memo = null;

    // ---- Public API ----

    /** The effective feature state: the flag is exactly '1' AND the whole schema is ready. The schema is not probed while the flag is off. */
    public static function enabled(PDO $pdo): bool
    {
        return self::flagOn($pdo) && self::schemaReady($pdo);
    }

    /** True only when system_settings.interview_date_booking_enabled is exactly '1'. Any error or other value is false. */
    public static function flagOn(PDO $pdo): bool
    {
        $cached = self::recall($pdo, 'flag');
        if (is_bool($cached)) {
            return $cached;
        }
        try {
            $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $stmt->execute([self::FLAG_KEY]);
            $on = $stmt->fetchColumn() === '1';
        } catch (Throwable $e) {
            error_log('Interview date flag could not be read: ' . $e->getMessage());
            $on = false;
        }
        self::remember($pdo, 'flag', $on);
        return $on;
    }

    /** True only when every object the migration creates is present and defined as the migration defines it. */
    public static function schemaReady(PDO $pdo): bool
    {
        return self::schemaProblems($pdo) === [];
    }

    /**
     * What is missing or different, one short sentence each; empty when the schema is complete. For
     * diagnostics and verification: the application gates on schemaReady(), never on this text.
     * A failed check returns a single generic entry (the detail goes to the error log).
     *
     * @return string[]
     */
    public static function schemaProblems(PDO $pdo): array
    {
        $cached = self::recall($pdo, 'problems');
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $problems = self::inspect($pdo);
        } catch (Throwable $e) {
            error_log('Interview date schema check failed: ' . $e->getMessage());
            $problems = ['The schema check could not be completed.'];
        }
        self::remember($pdo, 'problems', $problems);
        return $problems;
    }

    /**
     * Three-state, flag-independent answer to "does appointments.date_only exist?", for read paths where
     * guessing wrong is harmful (the timed reminder query): 'present', 'absent' or 'unknown'.
     *   1. information_schema.COLUMNS has a row            => present.
     *   2. otherwise (no row, or the query failed) the table itself is probed with
     *      SELECT date_only FROM appointments WHERE 1 = 0 (the same privilege the real queries need):
     *        succeeds                                   => present;
     *        MySQL error 1054 / SQLSTATE 42S22          => absent (the only way to answer absent);
     *        any other failure                          => unknown.
     * A privilege-hidden metadata row therefore can never read as absent. Remembered per connection under
     * its own key; an 'unknown' result is logged once per remembered result, with the
     * error numbers only. Does not use the flag.
     */
    public static function dateOnlyColumnPresence(PDO $pdo): string
    {
        $cached = self::recall($pdo, 'date_only_presence');
        if (is_string($cached)) {
            return $cached;
        }
        $presence = 'unknown';
        try {
            $found = self::rows($pdo,
                'SELECT COLUMN_NAME AS col FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['appointments', 'date_only']);
            if ($found !== []) {
                $presence = 'present';
            }
        } catch (Throwable $e) {
            // fall through to the table probe
        }
        if ($presence !== 'present') {
            try {
                $probe = $pdo->prepare('SELECT date_only FROM appointments WHERE 1 = 0');
                $probe->execute();
                $presence = 'present';
            } catch (Throwable $e) {
                $info = $e instanceof PDOException && is_array($e->errorInfo) ? $e->errorInfo : [];
                $state = (string) ($info[0] ?? ($e instanceof PDOException ? $e->getCode() : ''));
                $errno = (int) ($info[1] ?? 0);
                if ($errno === 1054 || $state === '42S22') {
                    $presence = 'absent';
                } else {
                    error_log('Interview date_only column presence is unknown (SQLSTATE ' . ($state !== '' ? $state : '?') . ', error ' . $errno . ').');
                }
            }
        }
        self::remember($pdo, 'date_only_presence', $presence);
        return $presence;
    }

    // ---- Inspection ----

    /** @return string[] */
    private static function inspect(PDO $pdo): array
    {
        $problems = [];
        $newTables = array_keys(self::NEW_TABLES);

        // 1. Tables. An unmigrated database stops here after a single cheap query.
        $found = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME AS tbl, TABLE_TYPE AS table_type, ENGINE AS engine FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . self::marks(count($newTables)) . ')', $newTables) as $row) {
            $found[strtolower((string) $row['tbl'])] = $row;
        }
        foreach ($newTables as $table) {
            if (!isset($found[$table])) {
                $problems[] = "Table $table is missing.";
            } elseif (strcasecmp((string) $found[$table]['table_type'], 'BASE TABLE') !== 0 || strcasecmp((string) $found[$table]['engine'], 'InnoDB') !== 0) {
                $problems[] = "Table $table is not an InnoDB base table.";
            }
        }
        if ($problems) {
            return $problems;
        }

        // 2. Columns: every column of the new tables (by name and type), and the four appointments columns in full.
        $columns = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable,
                    COLUMN_DEFAULT AS col_default, EXTRA AS extra, GENERATION_EXPRESSION AS generation
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND (TABLE_NAME IN (' . self::marks(count($newTables)) . ') OR (TABLE_NAME = ? AND COLUMN_NAME IN (' . self::marks(count(self::APPOINTMENT_COLUMNS)) . ')))',
            array_merge($newTables, ['appointments'], self::APPOINTMENT_COLUMNS)) as $row) {
            $columns[strtolower((string) $row['tbl'])][strtolower((string) $row['col'])] = $row;
        }
        foreach (self::NEW_TABLES as $table => $expected) {
            foreach ($expected as $column => $type) {
                $row = $columns[$table][$column] ?? null;
                if ($row === null) {
                    $problems[] = "Column $table.$column is missing.";
                } elseif (strtolower((string) $row['data_type']) !== $type) {
                    $problems[] = "Column $table.$column has the wrong type.";
                }
            }
        }
        foreach (self::ENUM_TYPES as $name => $type) {
            [$table, $column] = explode('.', $name);
            if ($table === 'appointments') {
                continue; // checked in full by inspectAppointmentColumns()
            }
            $row = $columns[$table][$column] ?? null;
            if ($row !== null && strtolower((string) $row['column_type']) !== $type) {
                $problems[] = "Column $name does not have the expected values.";
            }
        }
        $problems = array_merge($problems, self::inspectAppointmentColumns($columns['appointments'] ?? []));

        // 3. Indexes (ordered columns and uniqueness).
        $indexes = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME AS tbl, INDEX_NAME AS idx, SEQ_IN_INDEX AS seq, COLUMN_NAME AS col, NON_UNIQUE AS non_unique
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND (TABLE_NAME IN (' . self::marks(count($newTables)) . ') OR (TABLE_NAME = ? AND INDEX_NAME IN (' . self::marks(count(self::INDEXES['appointments'])) . ')))
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            array_merge($newTables, ['appointments'], array_keys(self::INDEXES['appointments']))) as $row) {
            $key = strtolower((string) $row['tbl']) . '.' . strtolower((string) $row['idx']);
            $indexes[$key]['cols'][(int) $row['seq']] = strtolower((string) $row['col']);
            $indexes[$key]['non_unique'] = (int) $row['non_unique'];
        }
        foreach (self::INDEXES as $table => $expectedIndexes) {
            foreach ($expectedIndexes as $name => [$expectedColumns, $nonUnique]) {
                $actual = $indexes[$table . '.' . strtolower($name)] ?? null;
                if ($actual === null) {
                    $problems[] = "Index $table.$name is missing.";
                    continue;
                }
                ksort($actual['cols']);
                if (array_values($actual['cols']) !== $expectedColumns || $actual['non_unique'] !== $nonUnique) {
                    $problems[] = "Index $table.$name is defined differently.";
                }
            }
        }

        // 4. CHECK constraints.
        $checkTables = array_keys(self::CHECKS);
        $checks = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME AS tbl, CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = ? AND TABLE_NAME IN (' . self::marks(count($checkTables)) . ')',
            array_merge(['CHECK'], $checkTables)) as $row) {
            $checks[strtolower((string) $row['tbl']) . '.' . strtolower((string) $row['name'])] = true;
        }
        foreach (self::CHECKS as $table => $name) {
            if (!isset($checks[$table . '.' . strtolower($name)])) {
                $problems[] = "Check constraint $table.$name is missing.";
            }
        }

        // 5. Foreign keys, with their referenced table and delete rule.
        $foreignTables = array_unique(array_column(self::FOREIGN_KEYS, 0));
        $keys = [];
        foreach (self::rows($pdo,
            'SELECT TABLE_NAME AS tbl, CONSTRAINT_NAME AS name, REFERENCED_TABLE_NAME AS ref, DELETE_RULE AS delete_rule
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN (' . self::marks(count($foreignTables)) . ')',
            array_values($foreignTables)) as $row) {
            $keys[strtolower((string) $row['tbl']) . '.' . strtolower((string) $row['name'])] = $row;
        }
        foreach (self::FOREIGN_KEYS as $name => [$table, $referenced, $rule]) {
            $row = $keys[$table . '.' . strtolower($name)] ?? null;
            if ($row === null) {
                $problems[] = "Foreign key $table.$name is missing.";
            } elseif (strcasecmp((string) $row['ref'], $referenced) !== 0 || strcasecmp((string) $row['delete_rule'], $rule) !== 0) {
                $problems[] = "Foreign key $table.$name is defined differently.";
            }
        }

        // 6. Triggers (the append-only rule on appointment_events).
        $triggers = [];
        foreach (self::rows($pdo,
            'SELECT TRIGGER_NAME AS name, EVENT_MANIPULATION AS event, ACTION_TIMING AS timing FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['appointment_events']) as $row) {
            $triggers[strtolower((string) $row['name'])] = $row;
        }
        foreach (self::TRIGGERS as $name => [$event, $timing]) {
            $row = $triggers[strtolower($name)] ?? null;
            if ($row === null) {
                $problems[] = "Trigger $name is missing (or not visible to this account).";
            } elseif (strcasecmp((string) $row['event'], $event) !== 0 || strcasecmp((string) $row['timing'], $timing) !== 0) {
                $problems[] = "Trigger $name is defined differently.";
            }
        }

        return $problems;
    }

    /**
     * The four appointments columns as the migration defines them.
     *
     * @param array<string, array> $columns found appointments columns, keyed by lower-case name
     * @return string[]
     */
    private static function inspectAppointmentColumns(array $columns): array
    {
        $problems = [];
        $default = function (?array $row): ?string {
            $value = $row['col_default'] ?? null;
            // Strip optional surrounding quotes, so a quoted ('LEGACY') and an unquoted (LEGACY) default compare the same.
            return $value === null ? null : trim((string) $value, "'\"");
        };
        foreach (self::APPOINTMENT_COLUMNS as $column) {
            if (!isset($columns[$column])) {
                $problems[] = "Column appointments.$column is missing.";
            }
        }

        if (isset($columns['interview_date'])) {
            $row = $columns['interview_date'];
            $expression = strtolower((string) ($row['generation'] ?? ''));
            $extra = strtolower((string) ($row['extra'] ?? ''));
            if (strtolower((string) $row['data_type']) !== 'date'
                || $expression === '' || !str_contains($expression, 'scheduled_at')
                || (!str_contains($extra, 'stored') && !str_contains($extra, 'persistent'))) {
                $problems[] = 'Column appointments.interview_date is not a stored generated date derived from scheduled_at.';
            }
        }
        if (isset($columns['date_only'])) {
            $row = $columns['date_only'];
            if (strtolower((string) $row['column_type']) !== 'tinyint(1)' || strtoupper((string) $row['nullable']) !== 'NO' || $default($row) !== '0') {
                $problems[] = 'Column appointments.date_only is not tinyint(1) NOT NULL DEFAULT 0.';
            }
        }
        if (isset($columns['booking_source'])) {
            $row = $columns['booking_source'];
            if (strtolower((string) $row['column_type']) !== self::ENUM_TYPES['appointments.booking_source']
                || strtoupper((string) $row['nullable']) !== 'NO' || strcasecmp((string) $default($row), 'LEGACY') !== 0) {
                $problems[] = "Column appointments.booking_source is not enum('LEGACY','STAFF','APPLICANT') NOT NULL DEFAULT 'LEGACY'.";
            }
        }
        if (isset($columns['confirmation_sms_log_id'])) {
            $row = $columns['confirmation_sms_log_id'];
            if (strtolower((string) $row['data_type']) !== 'int' || strtoupper((string) $row['nullable']) !== 'YES') {
                $problems[] = 'Column appointments.confirmation_sms_log_id is not a nullable INT.';
            }
        }
        return $problems;
    }

    // ---- Small helpers ----

    /** @return array<int, array<string, mixed>> */
    private static function rows(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function marks(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }

    private static function recall(PDO $pdo, string $key): mixed
    {
        return self::$memo !== null && isset(self::$memo[$pdo]) ? (self::$memo[$pdo][$key] ?? null) : null;
    }

    private static function remember(PDO $pdo, string $key, mixed $value): void
    {
        self::$memo ??= new WeakMap();
        $entry = isset(self::$memo[$pdo]) ? self::$memo[$pdo] : [];
        $entry[$key] = $value;
        self::$memo[$pdo] = $entry;
    }
}
