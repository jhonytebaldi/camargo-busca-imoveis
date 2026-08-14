<?php
/* =====================================================================
   webhook.php — o GitHub chama este endereço a cada envio, e o site se
   atualiza na hora.

   Este é o ÚNICO arquivo da ferramenta que responde sem login: o GitHub
   não tem como fazer login. A proteção é outra — cada chamada vem
   assinada com um segredo que só o GitHub e este servidor conhecem, e
   quem não apresentar a assinatura correta é recusado antes de qualquer
   coisa acontecer.

   Configuração no GitHub:
     Settings > Webhooks > Add webhook
       Payload URL : https://dados.imobcamargo.com.br/webhook.php
       Content type: application/json
       Secret      : o mesmo valor de WEBHOOK_SEGREDO em credenciais.php
       Events      : Just the push event
   ===================================================================== */

require_once __DIR__ . '/config.php';

function recusar($codigo, $motivo) {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    log_sync("webhook recusado ($codigo): $motivo");
    echo $motivo . "\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') recusar(405, 'so aceita POST');

// Sem segredo configurado o endpoint fica desligado. Melhor não funcionar
// do que aceitar qualquer chamada de qualquer pessoa.
if (!defined('WEBHOOK_SEGREDO') || WEBHOOK_SEGREDO === '') {
    recusar(503, 'WEBHOOK_SEGREDO nao configurado em credenciais.php');
}

$corpo = file_get_contents('php://input');
if (strlen($corpo) > 5 * 1024 * 1024) recusar(413, 'corpo grande demais');

$assinatura = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$esperada   = 'sha256=' . hash_hmac('sha256', $corpo, WEBHOOK_SEGREDO);
// hash_equals compara em tempo constante: comparar com === permitiria
// descobrir o segredo aos poucos, medindo o tempo de resposta.
if ($assinatura === '' || !hash_equals($esperada, $assinatura)) {
    recusar(401, 'assinatura invalida');
}

$evento = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($evento === 'ping') {
    header('Content-Type: text/plain; charset=utf-8');
    log_sync('webhook: ping recebido, tudo certo');
    exit("pong\n");
}
if ($evento !== 'push') {
    header('Content-Type: text/plain; charset=utf-8');
    exit("evento '$evento' ignorado\n");
}

$dados = json_decode($corpo, true);
$ref = $dados['ref'] ?? '';
$ramoEsperado = 'refs/heads/' . GITHUB_BRANCH;
if ($ref !== $ramoEsperado) {
    header('Content-Type: text/plain; charset=utf-8');
    log_sync("webhook: envio para $ref ignorado (acompanhamos " . GITHUB_BRANCH . ')');
    exit("ramo ignorado\n");
}

$msg = $dados['head_commit']['message'] ?? '';
$msg = trim(explode("\n", $msg)[0]);
log_sync('webhook: envio recebido — ' . substr($msg, 0, 80));

// Responde ao GitHub ANTES de instalar. O GitHub desiste depois de ~10s e
// marca a entrega como falha; a instalação leva alguns segundos e não
// precisa segurar a resposta.
header('Content-Type: text/plain; charset=utf-8');
header('Connection: close');
$resposta = "recebido: " . substr($msg, 0, 60) . "\n";
header('Content-Length: ' . strlen($resposta));
echo $resposta;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { @ob_end_flush(); @flush(); }

ignore_user_abort(true);
@set_time_limit(300);

// Uma trava evita que dois envios seguidos instalem ao mesmo tempo e
// embaralhem os arquivos.
$trava = DATA_DIR . '/webhook.lock';
$fp = @fopen($trava, 'c');
if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
    try {
        $GLOBALS['DEPLOY_INTERNO'] = true;
        $GLOBALS['DEPLOY_ARGS'] = '--instalar --silencioso';
        ob_start();
        include __DIR__ . '/atualizar.php';
        $saida = trim(ob_get_clean());
        if ($saida !== '') {
            $linhas = array_slice(array_filter(explode("\n", $saida)), -6);
            log_sync('webhook instalou: ' . implode(' | ', array_map('trim', $linhas)));
        } else {
            log_sync('webhook: nada mudou');
        }
    } catch (Throwable $e) {
        log_sync('webhook FALHOU: ' . $e->getMessage());
    }
    flock($fp, LOCK_UN);
    fclose($fp);
} else {
    log_sync('webhook: outra instalacao em andamento, ignorado');
}
