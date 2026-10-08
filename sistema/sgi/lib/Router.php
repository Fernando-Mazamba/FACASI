<?php
declare(strict_types=1);

class Router
{
    /** @var array<int,array{0:string,1:string,2:callable,3:array<string,mixed>}> */
    private $routes = [];

    /** @param array<string,mixed> $opts  public=>bool (sem autenticação), csrf=>bool, maintenance=>bool (disponível com atualização pendente) */
    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $re = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $re, $handler, $opts];
    }

    /** @return array{0:callable,1:array<string,string>,2:array<string,mixed>}|null */
    public function match(string $method, string $path): ?array
    {
        $allowed = false;
        foreach ($this->routes as [$m, $re, $h, $o]) {
            if (preg_match($re, $path, $mm)) {
                $allowed = true;
                if ($m === $method) {
                    $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                    return [$h, $params, $o];
                }
            }
        }
        if ($allowed) {
            throw new HttpError(405, 'Método não permitido.', 'metodo');
        }
        return null;
    }
}

/** Corpo JSON do pedido (ou campos de formulário). @return array<string,mixed> */
function body(): array
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ct, 'application/json') !== false) {
        $raw = (string)file_get_contents('php://input');
        $b = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($b)) {
            throw new HttpError(400, 'JSON inválido.', 'json_invalido');
        }
    } else {
        $b = $_POST;
    }
    return $b;
}

function qint(string $k, int $def = 0): int
{
    return isset($_GET[$k]) && is_numeric($_GET[$k]) ? (int)$_GET[$k] : $def;
}

function qstr(string $k, string $def = ''): string
{
    return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $def;
}

/** @param array<string,mixed> $b */
function req_str(array $b, string $k, string $label, int $max = 255): string
{
    $v = trim((string)($b[$k] ?? ''));
    if ($v === '') {
        throw new HttpError(422, "O campo «{$label}» é obrigatório.", 'campo_obrigatorio', ['campo' => $k]);
    }
    return mb_substr($v, 0, $max);
}

/** @param array<string,mixed> $b */
function opt_str(array $b, string $k, int $max = 255): ?string
{
    if (!isset($b[$k])) {
        return null;
    }
    $v = trim((string)$b[$k]);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/** @param array<string,mixed> $b */
function opt_date(array $b, string $k): ?string
{
    $v = trim((string)($b[$k] ?? ''));
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        throw new HttpError(422, "Data inválida em «{$k}» (use AAAA-MM-DD).", 'data_invalida', ['campo' => $k]);
    }
    return $v;
}

/** @param array<int,string> $allowed */
function opt_enum(array $b, string $k, array $allowed, ?string $def = null): ?string
{
    if (!isset($b[$k]) || $b[$k] === '') {
        return $def;
    }
    $v = (string)$b[$k];
    if (!in_array($v, $allowed, true)) {
        throw new HttpError(422, "Valor inválido em «{$k}».", 'valor_invalido', ['campo' => $k]);
    }
    return $v;
}
