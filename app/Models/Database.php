<?php
declare(strict_types=1);
namespace Sipe\Models;
use PDO;
use RuntimeException;
final class Database
{
    private static ?PDO $connection = null;
    public static function get(): PDO {
        if (!self::$connection) {
            $c = \config();
            $pdo = new PDO($c['dsn'], $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            if ((stripos($version, 'MariaDB') !== false && version_compare($version, '10.4', '<')) || (stripos($version, 'MariaDB') === false && version_compare($version, '8.0.16', '<'))) { throw new RuntimeException('Se requiere MySQL 8.0.16+ o MariaDB 10.4+.'); }
            $pdo->exec("SET time_zone = '-05:00'");
            self::$connection = $pdo;
        }
        return self::$connection;
    }
    public static function all(string $sql, array $params = []): array { $s=self::get()->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    public static function one(string $sql, array $params = []): ?array { return self::all($sql,$params)[0] ?? null; }
    public static function run(string $sql, array $params = []): int { $s=self::get()->prepare($sql); $s->execute($params); return $s->rowCount(); }
    public static function transaction(callable $work): mixed {
        $pdo=self::get(); $pdo->beginTransaction();
        try { $result=$work(); $pdo->commit(); return $result; }
        catch (\Throwable $e) { if($pdo->inTransaction()){$pdo->rollBack();} throw $e; }
    }
}
