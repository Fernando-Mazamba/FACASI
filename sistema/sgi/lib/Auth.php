<?php
declare(strict_types=1);

/**
 * Autenticação e autorização.
 * - Navegador: sessão PHP + token CSRF (cabeçalho X-CSRF-Token) em pedidos que alteram dados.
 * - Aplicação: token Bearer de curta duração (ver Tokens).
 */
class Auth
{
    public const ACCOUNT_MAX_FAILS = 5;
    public const LOCK_MINUTES = 15;
    public const IP_MAX_FAILS = 50;
    public const ORG_MAX_FAILS = 30;

    /** @var array<string,mixed>|null */
    private static $user = null;
    /** @var string */
    private static $mode = 'nenhum';
    /** @var int|null */
    private static $deviceId = null;

    public const ROLES = [
        'plataforma' => 'Administrador da plataforma',
        'admin' => 'Administrador da organização',
        'direcao' => 'Direção',
        'coordenador' => 'Coordenador do SGI',
        'supervisor' => 'Supervisor de campo',
        'operario' => 'Operário',
    ];

    /** Permissões por perfil (o perfil "plataforma" não acede a dados das organizações). */
    private const PERMS = [
        'docs.view' => ['admin', 'direcao', 'coordenador', 'supervisor', 'operario'],
        'docs.manage' => ['admin', 'coordenador'],
        'records.create' => ['admin', 'direcao', 'coordenador', 'supervisor', 'operario'],
        'records.view_all' => ['admin', 'direcao', 'coordenador', 'supervisor'],
        'records.manage_all' => ['admin', 'coordenador'],
        'approvals.decide' => ['admin', 'direcao', 'coordenador', 'supervisor'],
        'catalog.view' => ['admin', 'direcao', 'coordenador', 'supervisor', 'operario'],
        'catalog.manage' => ['admin', 'coordenador'],
        'nc.view' => ['admin', 'direcao', 'coordenador', 'supervisor', 'operario'],
        'nc.manage' => ['admin', 'coordenador', 'supervisor'],
        'calendar.view' => ['admin', 'direcao', 'coordenador', 'supervisor', 'operario'],
        'calendar.manage' => ['admin', 'coordenador', 'supervisor'],
        'assets.view' => ['admin', 'direcao', 'coordenador', 'supervisor', 'operario'],
        'assets.manage' => ['admin', 'coordenador', 'supervisor'],
        'reports.view' => ['admin', 'direcao', 'coordenador'],
        'indicators.manage' => ['admin', 'coordenador'],
        'import' => ['admin', 'coordenador'],
        'users.manage' => ['admin'],
        'org.settings' => ['admin'],
        'org.export' => ['admin'],
        'platform' => ['plataforma'],
    ];

    /** Hierarquia usada para validar o perfil aprovador. */
    public const ROLE_RANK = ['operario' => 1, 'supervisor' => 2, 'coordenador' => 3, 'direcao' => 4, 'admin' => 5];

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function mode(): string
    {
        return self::$mode;
    }

    public static function deviceId(): ?int
    {
        return self::$deviceId;
    }

    public static function orgId(): ?int
    {
        return self::$user && self::$user['org_id'] !== null ? (int)self::$user['org_id'] : null;
    }

    public static function id(): ?int
    {
        return self::$user ? (int)self::$user['id'] : null;
    }

    public static function can(string $perm): bool
    {
        if (!self::$user) {
            return false;
        }
        return in_array(self::$user['role'], self::PERMS[$perm] ?? [], true);
    }

    public static function require(string $perm): void
    {
        if (!self::$user) {
            throw new HttpError(401, 'Sessão expirada ou inexistente. Inicie sessão novamente.', 'nao_autenticado');
        }
        if (!self::can($perm)) {
            throw new HttpError(403, 'Não tem permissão para esta operação.', 'sem_permissao');
        }
    }

    /** Org obrigatória (utilizador de organização, não de plataforma). */
    public static function requireOrg(): int
    {
        if (!self::$user) {
            throw new HttpError(401, 'Sessão expirada ou inexistente. Inicie sessão novamente.', 'nao_autenticado');
        }
        $o = self::orgId();
        if ($o === null) {
            throw new HttpError(403, 'A administração da plataforma não tem acesso aos documentos das organizações.', 'plataforma_sem_acesso');
        }
        return $o;
    }

    /** Resolve o utilizador do pedido (Bearer ou sessão). */
    public static function resolve(): void
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($hdr === '' && function_exists('apache_request_headers')) {
            $h = array_change_key_case((array)apache_request_headers(), CASE_LOWER);
            $hdr = (string)($h['authorization'] ?? '');
        }
        if ($hdr === '' && isset($_SERVER['HTTP_X_SGI_TOKEN'])) {
            $hdr = 'Bearer ' . $_SERVER['HTTP_X_SGI_TOKEN'];
        }
        if (stripos($hdr, 'Bearer ') === 0) {
            $res = Tokens::authenticate(trim(substr($hdr, 7)));
            if (!$res) {
                throw new HttpError(401, 'Token inválido, expirado ou revogado.', 'token_invalido');
            }
            self::$user = $res['user'];
            self::$deviceId = (int)$res['device_id'];
            self::$mode = 'token';
            return;
        }
        sgi_start_session();
        if (!empty($_SESSION['uid'])) {
            $u = self::loadActive((int)$_SESSION['uid']);
            if ($u && (int)($_SESSION['pwv'] ?? 0) === self::pwVersion($u)) {
                self::$user = $u;
                self::$mode = 'sessao';
            } else {
                $_SESSION = [];
            }
        }
    }

    /** Força um utilizador (usado por tarefas internas e testes de unidade). */
    public static function setUser(?array $u, string $mode = 'interno'): void
    {
        self::$user = $u;
        self::$mode = $mode;
    }

    public static function loadActive(int $id): ?array
    {
        $u = sgi_db()->one('SELECT u.*, o.status AS org_status, o.name AS org_name, o.slug AS org_slug
            FROM users u LEFT JOIN organizations o ON o.id = u.org_id WHERE u.id = ?', [$id]);
        if (!$u || $u['status'] !== 'ativo') {
            return null;
        }
        if ($u['org_id'] !== null && $u['org_status'] !== 'ativa') {
            return null;
        }
        return $u;
    }

    /** Muda quando a palavra-passe muda → invalida sessões antigas. */
    public static function pwVersion(array $u): int
    {
        return (int)sprintf('%u', crc32((string)$u['password_hash']));
    }

    public static function csrfToken(): string
    {
        sgi_start_session();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = random_token(32);
        }
        return (string)$_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        if (self::$mode !== 'sessao') {
            return;
        }
        $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals(self::csrfToken(), $sent)) {
            throw new HttpError(403, 'Token CSRF inválido. Recarregue a página.', 'csrf');
        }
    }

    /**
     * Verifica credenciais com bloqueio por conta, por IP e por organização.
     * @return array<string,mixed> utilizador
     */
    public static function verifyCredentials(string $email, string $password): array
    {
        $db = sgi_db();
        $email = mb_strtolower(trim($email));
        $ip = client_ip();
        $window = date('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $generic = new HttpError(401, 'Credenciais inválidas.', 'credenciais');

        $ipFails = (int)$db->val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [$ip, $window]);
        if ($ipFails >= self::IP_MAX_FAILS) {
            throw new HttpError(429, 'Demasiadas tentativas a partir deste endereço. Aguarde ' . self::LOCK_MINUTES . ' minutos.', 'bloqueio_ip');
        }
        $u = $db->one('SELECT u.*, o.status AS org_status FROM users u LEFT JOIN organizations o ON o.id = u.org_id WHERE u.email = ?', [$email]);
        $orgId = $u ? ($u['org_id'] !== null ? (int)$u['org_id'] : null) : null;

        if ($orgId !== null) {
            $orgFails = (int)$db->val('SELECT COUNT(*) FROM login_attempts WHERE org_id = ? AND success = 0 AND created_at > ?', [$orgId, $window]);
            if ($orgFails >= self::ORG_MAX_FAILS) {
                throw new HttpError(429, 'O acesso desta organização está temporariamente bloqueado por excesso de tentativas falhadas. Aguarde ' . self::LOCK_MINUTES . ' minutos.', 'bloqueio_org');
            }
        }
        if ($u && $u['locked_until'] !== null && strtotime((string)$u['locked_until']) > time()) {
            $db->insert('login_attempts', ['email' => $email, 'ip' => $ip, 'org_id' => $orgId, 'success' => 0]);
            throw new HttpError(423, 'Conta bloqueada temporariamente por tentativas falhadas. Tente mais tarde.', 'conta_bloqueada');
        }
        $ok = $u && password_verify($password, (string)$u['password_hash']);
        if (!$ok) {
            // Custo constante quando o utilizador não existe.
            if (!$u) {
                password_verify($password, '$2y$10$elUH0C6Rc6zCNY4FTMjtiu6BAv97jjQV7exsmsNyVJ4c2F9m/3ANm');
            }
            $db->insert('login_attempts', ['email' => $email, 'ip' => $ip, 'org_id' => $orgId, 'success' => 0]);
            if ($u) {
                $fails = (int)$u['failed_attempts'] + 1;
                $lock = $fails >= self::ACCOUNT_MAX_FAILS ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
                $db->update('users', ['failed_attempts' => $lock ? 0 : $fails, 'locked_until' => $lock], 'id = ?', [$u['id']]);
                if ($lock) {
                    audit($orgId, (int)$u['id'], 'conta_bloqueada', 'user', (int)$u['id']);
                }
            }
            throw $generic;
        }
        if ($u['status'] !== 'ativo') {
            throw $generic;
        }
        if ($orgId !== null && $u['org_status'] !== 'ativa') {
            throw new HttpError(403, 'A organização está suspensa. Contacte o administrador da plataforma.', 'org_suspensa');
        }
        if (password_needs_rehash((string)$u['password_hash'], PASSWORD_DEFAULT)) {
            $db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        }
        $db->update('users', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()], 'id = ?', [$u['id']]);
        $db->insert('login_attempts', ['email' => $email, 'ip' => $ip, 'org_id' => $orgId, 'success' => 1]);
        return self::loadActive((int)$u['id']) ?? $u;
    }

    public static function loginSession(array $u): void
    {
        sgi_start_session();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['pwv'] = self::pwVersion($u);
        $_SESSION['csrf'] = random_token(32);
        self::$user = $u;
        self::$mode = 'sessao';
    }

    public static function logoutSession(): void
    {
        sgi_start_session();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    public static function validatePassword(string $pw): void
    {
        if (mb_strlen($pw) < 10 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            throw new HttpError(422, 'A palavra-passe deve ter pelo menos 10 caracteres, com letras e números.', 'palavra_passe_fraca');
        }
    }

    /** Dados públicos do utilizador para o cliente. */
    public static function publicUser(array $u): array
    {
        return [
            'id' => (int)$u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'role' => $u['role'],
            'role_label' => self::ROLES[$u['role']] ?? $u['role'],
            'org_id' => $u['org_id'] !== null ? (int)$u['org_id'] : null,
            'org_name' => $u['org_name'] ?? null,
            'must_change_password' => (bool)$u['must_change_password'],
            'perms' => array_values(array_filter(array_keys(self::PERMS), function ($p) use ($u) {
                return in_array($u['role'], self::PERMS[$p], true);
            })),
        ];
    }
}
