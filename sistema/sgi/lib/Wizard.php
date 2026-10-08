<?php
declare(strict_types=1);

/** Utilitários partilhados pelos assistentes de instalação e de atualização (páginas sem JavaScript). */
class Wizard
{
    public static function head(string $title): void
    {
        sgi_security_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
        $base = sgi_base_path();
        echo '<!DOCTYPE html><html lang="pt-AO"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . h($title) . '</title><link rel="stylesheet" href="' . h($base) . '/app/css/assistente.css"></head><body><main class="wz">'
            . '<header><img src="' . h($base) . '/app/img/icone-sgi.svg" alt="" width="40" height="40"><div><h1>' . h($title) . '</h1><p>SGI · versão ' . h(SGI_VERSION) . '</p></div></header>';
    }

    public static function foot(): void
    {
        echo '</main></body></html>';
    }

    /** @return array<int,array{0:string,1:bool,2:string}> [descrição, ok, detalhe] */
    public static function requirements(): array
    {
        $req = [];
        $req[] = ['PHP 7.4 ou superior (recomendado 8.2/8.3)', PHP_VERSION_ID >= 70400, PHP_VERSION];
        foreach (['pdo_mysql' => 'Ligação MySQL (pdo_mysql)', 'openssl' => 'Criptografia (openssl)', 'mbstring' => 'Texto UTF-8 (mbstring)', 'json' => 'JSON', 'fileinfo' => 'Validação de ficheiros (fileinfo)', 'dom' => 'Processamento HTML (dom)', 'zip' => 'Cópias de segurança e .xlsx (zip)'] as $ext => $label) {
            $req[] = [$label, extension_loaded($ext), extension_loaded($ext) ? 'ativa' : 'EM FALTA — ative em cPanel → Selecionar versão do PHP → Extensões'];
        }
        foreach (['gd' => 'Recompressão de fotografias (gd) — opcional', 'curl' => 'Notificações push (curl) — opcional'] as $ext => $label) {
            $req[] = [$label, true, extension_loaded($ext) ? 'ativa' : 'não ativa (funcionalidade reduzida)'];
        }
        $req[] = ['Pasta do SGI com permissão de escrita (para config.php)', is_writable(SGI_ROOT), SGI_ROOT];
        $pk = self::packageCheck();
        if ($pk !== null) {
            $req[] = ['Pacote completo (todas as partes ZIP extraídas, ficheiros íntegros)', $pk['ok'], $pk['mensagem']];
        }
        return $req;
    }

    /**
     * Verifica o manifesto PACOTE.json gerado no empacotamento: todos os ficheiros presentes e com o SHA-256 esperado.
     * @return array{ok:bool,mensagem:string,em_falta:array<int,string>}|null  null numa cópia de desenvolvimento (sem manifesto)
     */
    public static function packageCheck(): ?array
    {
        $f = SGI_ROOT . '/PACOTE.json';
        if (!is_file($f)) {
            return null;
        }
        $m = json_decode((string)file_get_contents($f), true);
        if (!is_array($m) || empty($m['ficheiros'])) {
            return ['ok' => false, 'mensagem' => 'PACOTE.json inválido.', 'em_falta' => []];
        }
        $falta = [];
        $alterados = [];
        $partesEmFalta = [];
        foreach ($m['ficheiros'] as $rel => $i) {
            $abs = SGI_ROOT . '/' . $rel;
            if (!is_file($abs)) {
                $falta[] = $rel;
                $partesEmFalta[(int)$i['parte']] = true;
            } elseif (hash_file('sha256', $abs) !== $i['sha256']) {
                $alterados[] = $rel;
            }
        }
        if (!$falta && !$alterados) {
            return ['ok' => true, 'mensagem' => 'versão ' . $m['versao'] . ' · ' . count($m['ficheiros']) . ' ficheiros verificados (' . (int)$m['total_partes'] . ' partes)', 'em_falta' => []];
        }
        $msg = [];
        if ($partesEmFalta) {
            ksort($partesEmFalta);
            $msg[] = 'Falta extrair a(s) parte(s) ' . implode(', ', array_keys($partesEmFalta)) . ' de ' . (int)$m['total_partes'] . ' (' . count($falta) . ' ficheiros em falta).';
        }
        if ($alterados) {
            $msg[] = count($alterados) . ' ficheiro(s) diferente(s) do pacote (extração incompleta ou ficheiro alterado): ' . implode(', ', array_slice($alterados, 0, 5));
        }
        return ['ok' => false, 'mensagem' => implode(' ', $msg), 'em_falta' => $falta];
    }

    public static function requirementsOk(): bool
    {
        foreach (self::requirements() as $r) {
            if (!$r[1]) {
                return false;
            }
        }
        return true;
    }

    public static function renderRequirements(): void
    {
        echo '<table class="req"><tbody>';
        foreach (self::requirements() as [$l, $ok, $d]) {
            echo '<tr><td>' . ($ok ? '✅' : '❌') . '</td><td>' . h($l) . '</td><td>' . h($d) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Limite de tentativas FALHADAS por IP, guardado em ficheiro (antes de existir BD). */
    private static function throttleFile(string $name): string
    {
        return sys_get_temp_dir() . '/sgi-' . $name . '-' . md5(SGI_ROOT . client_ip());
    }

    /** @return array<int,int> */
    private static function throttleList(string $name, int $window): array
    {
        $f = self::throttleFile($name);
        return is_file($f) ? array_values(array_filter(array_map('intval', explode(',', (string)file_get_contents($f))), function ($t) use ($window) {
            return $t > time() - $window;
        })) : [];
    }

    public static function throttleCheck(string $name, int $max = 5, int $window = 900): void
    {
        if (count(self::throttleList($name, $window)) >= $max) {
            throw new RuntimeException('Demasiadas tentativas falhadas. Aguarde 15 minutos.');
        }
    }

    public static function throttleFail(string $name, int $window = 900): void
    {
        $list = self::throttleList($name, $window);
        $list[] = time();
        @file_put_contents(self::throttleFile($name), implode(',', $list));
    }

    public static function csrf(): string
    {
        sgi_start_session();
        if (empty($_SESSION['wz_csrf'])) {
            $_SESSION['wz_csrf'] = random_token(16);
        }
        return (string)$_SESSION['wz_csrf'];
    }

    public static function checkCsrf(): void
    {
        sgi_start_session();
        if (!hash_equals((string)($_SESSION['wz_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('O formulário expirou. Recarregue a página e tente de novo.');
        }
    }
}
