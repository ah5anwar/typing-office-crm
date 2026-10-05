<?php
/**
 * AH5 Office - Database layer (PDO)
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

final class DB
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]);
                self::$pdo->exec("SET time_zone = '" . self::tzOffset() . "'");
            } catch (PDOException $e) {
                error_log('DB connect failed: ' . $e->getMessage());
                Response::error('Database connection failed', 500);
            }
        }
        return self::$pdo;
    }

    private static function tzOffset(): string
    {
        $tz  = new DateTimeZone(APP_TIMEZONE);
        $off = $tz->getOffset(new DateTime('now', $tz));
        $sign = $off < 0 ? '-' : '+';
        $off = abs($off);
        return sprintf('%s%02d:%02d', $sign, intdiv($off, 3600), intdiv($off % 3600, 60));
    }

    /** Run a query and return the statement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** All rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Single row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Single scalar value or null. */
    public static function value(string $sql, array $params = [])
    {
        $val = self::run($sql, $params)->fetchColumn();
        return $val === false ? null : $val;
    }

    /** INSERT helper. Returns new id. */
    /** Run a closure inside one database transaction. */
    public static function transaction(callable $work)
    {
        self::begin();
        try {
            $result = $work();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
              . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int) self::conn()->lastInsertId();
    }

    /** UPDATE helper. Returns affected rows. */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) {
            return 0;
        }
        $set = [];
        foreach (array_keys($data) as $col) {
            $set[] = '`' . $col . '` = ?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
        return self::run($sql, array_merge(array_values($data), $whereParams))->rowCount();
    }

    public static function exists(string $sql, array $params = []): bool
    {
        return (bool) self::value('SELECT EXISTS(' . $sql . ')', $params);
    }

    public static function begin(): void
    {
        if (!self::conn()->inTransaction()) {
            self::conn()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::conn()->inTransaction()) {
            self::conn()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::conn()->inTransaction()) {
            self::conn()->rollBack();
        }
    }

    /** Read a setting value from the settings table (cached per request). */
    public static function setting(string $key, ?string $default = null): ?string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach (self::all('SELECT key_name, value FROM settings') as $r) {
                $cache[$r['key_name']] = $r['value'];
            }
        }
        return array_key_exists($key, $cache) && $cache[$key] !== null && $cache[$key] !== ''
            ? $cache[$key]
            : $default;
    }
}
