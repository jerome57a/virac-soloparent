<?php
/**
 * Sample (demonstration) records: every account, application and staff account added by database/sample_data.php is listed in
 * sample_records, so they can be removed together later (database/sample_data.php remove), and a sample account can be marked
 * "never text": SMSService skips any text to its mobile number (reported as not sent, like switched-off SMS, never as a failure).
 */
final class SampleRecords
{
    public const CREATE_SQL = "CREATE TABLE IF NOT EXISTS sample_records (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  kind       ENUM('user','application','staff') NOT NULL,
  ref_id     INT NOT NULL,
  sample_key VARCHAR(20) DEFAULT NULL,          -- the row of database/sample_data/solo-parents.csv (A1, B4...)
  mobile     VARCHAR(20) DEFAULT NULL,          -- the last 10 digits (9XXXXXXXXX), so 09... and +639... both match
  no_text    TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = SMSService never texts this mobile number
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sample (kind, ref_id),
  KEY idx_sample_mobile (mobile)
) ENGINE=InnoDB";

    /** @var array<string, bool> per-request cache of the never-text check */
    private static array $cache = [];

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(self::CREATE_SQL);
    }

    public static function register(PDO $pdo, string $kind, int $refId, ?string $key = null, ?string $mobile = null, bool $noText = false): void
    {
        $pdo->prepare('INSERT INTO sample_records (kind, ref_id, sample_key, mobile, no_text) VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE sample_key = VALUES(sample_key), mobile = VALUES(mobile), no_text = VALUES(no_text)')
            ->execute([$kind, $refId, $key, $mobile !== null ? self::digits($mobile) : null, (int) $noText]);
        self::$cache = [];
    }

    /** True when $mobile belongs to a sample account marked never text. False when the table does not exist (no sample data). */
    public static function neverText(PDO $pdo, string $mobile): bool
    {
        $number = self::digits($mobile);
        if (strlen($number) !== 10) {
            return false;
        }
        if (!array_key_exists($number, self::$cache)) {
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM sample_records WHERE kind = 'user' AND no_text = 1 AND mobile = ? LIMIT 1");
                $stmt->execute([$number]);
                self::$cache[$number] = (bool) $stmt->fetchColumn();
            } catch (PDOException $e) {
                self::$cache[$number] = false; // no sample_records table: there is no sample data
            }
        }
        return self::$cache[$number];
    }

    /** The last 10 digits of a Philippine mobile number (09171234567 and +639171234567 both give 9171234567). */
    public static function digits(string $mobile): string
    {
        return substr(preg_replace('/\D/', '', $mobile), -10);
    }

    public static function forgetCache(): void
    {
        self::$cache = [];
    }
}
