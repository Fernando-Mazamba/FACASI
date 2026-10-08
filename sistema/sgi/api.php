<?php
/**
 * API REST do SGI: api.php/<rota>
 * Funciona em Apache/LiteSpeed (cPanel) e no servidor embutido do PHP, sem mod_rewrite.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/Router.php';

sgi_security_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && isset($_GET['r'])) {
    $path = (string)$_GET['r'];
}
$path = '/' . trim((string)$path, '/');

try {
    if (!sgi_is_installed()) {
        throw new HttpError(503, 'O SGI ainda não está instalado. Abra o assistente de instalação.', 'nao_instalado');
    }
    $router = new Router();
    foreach (glob(__DIR__ . '/lib/api/*.php') as $f) {
        (require $f)($router);
    }
    $m = $router->match($method, $path);
    if (!$m) {
        throw new HttpError(404, 'Rota não encontrada.', 'rota');
    }
    [$handler, $params, $opts] = $m;

    $mig = new Migrator(sgi_db());
    if (empty($opts['maintenance']) && $mig->pending()) {
        throw new HttpError(503, 'O sistema está a ser atualizado. O administrador da plataforma deve concluir a atualização em /atualizar/.', 'atualizacao_pendente');
    }

    Auth::resolve();
    if (empty($opts['public']) && !Auth::user()) {
        throw new HttpError(401, 'Sessão expirada ou inexistente. Inicie sessão novamente.', 'nao_autenticado');
    }
    $mutating = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    if ($mutating && ($opts['csrf'] ?? true)) {
        Auth::checkCsrf();
    }

    // Idempotência (sincronização offline): mesma chave ⇒ mesma resposta, sem repetir a operação.
    $idemKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    $useIdem = $mutating && $idemKey !== '' && Auth::user() && sgi_db()->tableExists('idempotency_keys');
    if ($useIdem) {
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $idemKey)) {
            throw new HttpError(400, 'Idempotency-Key inválida.', 'idempotencia');
        }
        $prev = sgi_db()->one('SELECT * FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [Auth::id(), $idemKey]);
        if ($prev) {
            if ($prev['endpoint'] !== $method . ' ' . $path) {
                throw new HttpError(422, 'Idempotency-Key reutilizada noutra operação.', 'idempotencia');
            }
            header('Idempotent-Replay: true');
            http_response_code((int)$prev['status_code']);
            header('Content-Type: application/json; charset=utf-8');
            echo $prev['response'];
            exit;
        }
    }

    $result = $handler($params);
    if ($result === null) {
        exit; // o handler já enviou a resposta (ficheiro)
    }
    $status = 200;
    if (is_array($result) && isset($result['__status'])) {
        $status = (int)$result['__status'];
        unset($result['__status']);
    }
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($useIdem) {
        try {
            sgi_db()->insert('idempotency_keys', [
                'org_id' => Auth::orgId(), 'user_id' => Auth::id(), 'idem_key' => $idemKey,
                'endpoint' => $method . ' ' . $path, 'status_code' => $status, 'response' => $json,
            ]);
        } catch (PDOException $e) {
            // corrida entre dois envios simultâneos: devolve a resposta já guardada
            $prev = sgi_db()->one('SELECT * FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [Auth::id(), $idemKey]);
            if ($prev) {
                $json = $prev['response'];
                $status = (int)$prev['status_code'];
            }
        }
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $json;
    Cron::maybeRunAfterResponse();
} catch (HttpError $e) {
    json_out(['error' => $e->getMessage(), 'code' => $e->codeName] + $e->extra, $e->status);
} catch (PDOException $e) {
    error_log('[SGI] BD: ' . $e->getMessage());
    json_out(['error' => 'Erro de base de dados.' . (!empty(sgi_config()['debug']) ? ' ' . $e->getMessage() : ''), 'code' => 'erro_bd'], 500);
}
