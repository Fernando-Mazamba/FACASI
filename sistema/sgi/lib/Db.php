<?php
declare(strict_types=1);

/** Pequeno invólucro sobre PDO (MySQL/MariaDB). Todas as consultas usam parâmetros preparados. */
class Db
{
    /** @var PDO */
    public $pdo;
    /** @var string */
    public $name;

    public function __construct(string $host, int $port, string $name, string $user, string $pass)
    {
        $this->name = $name;
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec("SET time_zone = '+01:00'");
    }

    /** @param array<int|string,mixed> $p */
    public function q(string $sql, array $p = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st;
    }

    /** @param array<int|string,mixed> $p @return array<string,mixed>|null */
    public function one(string $sql, array $p = []): ?array
    {
        $r = $this->q($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    /** @param array<int|string,mixed> $p @return array<int,array<string,mixed>> */
    public function all(string $sql, array $p = []): array
    {
        return $this->q($sql, $p)->fetchAll();
    }

    /** @param array<int|string,mixed> $p @return mixed */
    public function val(string $sql, array $p = [])
    {
        $v = $this->q($sql, $p)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $row */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->q($sql, array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $row @param array<int|string,mixed> $wp */
    public function update(string $table, array $row, string $where, array $wp = []): int
    {
        $set = [];
        $p = [];
        foreach ($row as $k => $v) {
            $set[] = "`$k` = ?";
            $p[] = $v;
        }
        $st = $this->q('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where, array_merge($p, array_values($wp)));
        return $st->rowCount();
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /** @template T @param callable():T $fn @return T */
    public function tx(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function tableExists(string $t): bool
    {
        return (bool)$this->val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    }

    public function columnExists(string $t, string $c): bool
    {
        return (bool)$this->val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
    }

    public function indexExists(string $t, string $i): bool
    {
        return (bool)$this->val('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$t, $i]);
    }

    /** Executa um ficheiro SQL com várias instruções (separadas por ';' no fim da linha). */
    public function runSqlFile(string $file): void
    {
        $sql = (string)file_get_contents($file);
        foreach (self::splitSql($sql) as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    /** @return array<int,string> */
    public static function splitSql(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inStr = false;
        $q = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if (!$inStr && $ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($inStr) {
                $buf .= $ch;
                if ($ch === '\\') {
                    $buf .= $sql[++$i] ?? '';
                    continue;
                }
                if ($ch === $q) {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inStr = true;
                $q = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
