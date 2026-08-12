<?php
/* =====================================================================
   index.php — verifica o login e entrega a aplicação.

   IMPORTANTE: o conteúdo fica em lib/app.html e é enviado com readfile().
   Ele NÃO pode ficar dentro deste arquivo: a biblioteca de planilhas
   contem trechos de abertura XML e, em servidores com short_open_tag ligado, o
   PHP tentaria interpretá-los como código, gerando erro 500.
   readfile() apenas despeja os bytes, sem passar pelo interpretador.
   ===================================================================== */

require_once __DIR__ . '/config.php';
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: login.php'); exit; }

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$app = __DIR__ . '/lib/app.html';
if (!is_readable($app)) {
    http_response_code(500);
    echo 'Arquivo lib/app.html nao encontrado. Reenvie o pacote completo.';
    exit;
}
readfile($app);
