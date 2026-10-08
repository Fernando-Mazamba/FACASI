<?php
declare(strict_types=1);

/**
 * Migrações versionadas: migrations/<versão>/<NNN_nome>.sql|.php
 * Cada ficheiro é aplicado uma única vez e fica registado em schema_migrations.
 */
class Migrator
{
    /** @var Db */
    private $db;
    /** @var string */
    private $dir;

    public function __construct(Db $db, ?string $dir = null)
    {
        $this->db = $db;
        $this->dir = $dir ?? SGI_ROOT . '/migrations';
    }

    public function ensureTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(20) NOT NULL,
            file VARCHAR(190) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mig (version, file)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return array<int,array{version:string,file:string,path:string}> */
    public function all(?string $upTo = null): array
    {
        $out = [];
        $versions = array_filter((array)scandir($this->dir), function ($d) {
            return $d !== '.' && $d !== '..' && is_dir($this->dir . '/' . $d) && preg_match('/^\d+\.\d+\.\d+$/', (string)$d);
        });
        usort($versions, 'version_compare');
        foreach ($versions as $v) {
            if ($upTo !== null && version_compare($v, $upTo, '>')) {
                continue;
            }
            $files = array_filter((array)scandir($this->dir . '/' . $v), function ($f) {
                return (bool)preg_match('/^\d+_[A-Za-z0-9_]+\.(sql|php)$/', (string)$f);
            });
            sort($files);
            foreach ($files as $f) {
                $out[] = ['version' => $v, 'file' => $f, 'path' => $this->dir . '/' . $v . '/' . $f];
            }
        }
        return $out;
    }

    /** @return array<int,array{version:string,file:string,path:string}> */
    public function pending(?string $upTo = null): array
    {
        $this->ensureTable();
        $done = [];
        foreach ($this->db->all('SELECT version, file FROM schema_migrations') as $r) {
            $done[$r['version'] . '/' . $r['file']] = true;
        }
        return array_values(array_filter($this->all($upTo), function ($m) use ($done) {
            return !isset($done[$m['version'] . '/' . $m['file']]);
        }));
    }

    public function schemaVersion(): string
    {
        if (!$this->db->tableExists('schema_migrations')) {
            return '0.0.0';
        }
        $vs = array_column($this->db->all('SELECT DISTINCT version FROM schema_migrations'), 'version');
        usort($vs, 'version_compare');
        return $vs ? (string)end($vs) : '0.0.0';
    }

    /** @return array<int,string> lista de migrações aplicadas */
    public function migrate(?string $upTo = null): array
    {
        $applied = [];
        foreach ($this->pending($upTo) as $m) {
            if (substr($m['file'], -4) === '.sql') {
                $this->db->runSqlFile($m['path']);
            } else {
                $fn = require $m['path'];
                $fn($this->db);
            }
            $this->db->insert('schema_migrations', [
                'version' => $m['version'],
                'file' => $m['file'],
                'checksum' => hash_file('sha256', $m['path']),
            ]);
            $applied[] = $m['version'] . '/' . $m['file'];
        }
        return $applied;
    }
}
