<?php
/**
 * Tarefas diárias do SGI (opcional — sem cron, correm automaticamente uma vez por dia após um acesso).
 * cPanel → Tarefas Cron:  php /home/CONTA/public_html/sgi/cron.php   (1×/dia)
 * Por HTTP (se não houver cron com PHP CLI): cron.php?chave=<chave mostrada em Estado do sistema>
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
if (!sgi_is_installed()) {
    exit("SGI não instalado.\n");
}
if (PHP_SAPI !== 'cli') {
    $key = hash_hmac('sha256', 'cron', (string)sgi_config()['app_key']);
    if (!hash_equals($key, (string)($_GET['chave'] ?? ''))) {
        http_response_code(403);
        exit('Acesso negado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
if ((new Migrator(sgi_db()))->pending()) {
    exit("Atualização pendente: conclua-a em /atualizar/ antes de correr as tarefas.\n");
}
$res = Cron::run(PHP_SAPI === 'cli' ? 'cron' : 'http');
echo json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
