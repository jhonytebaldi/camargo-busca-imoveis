<?php
/* =====================================================================
   testar-api.php — confere as credenciais do Robust no config.php.

   Nunca imprime a chave inteira: mostra só o tamanho e as pontas, que é
   o suficiente para achar os erros comuns (espaço sobrando, caractere
   faltando, aspas dentro do valor).

   Acesse: testar-api.php   |   Apague depois de usar.
   ===================================================================== */

require_once __DIR__ . '/config.php';
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Faça login primeiro.'); }
header('Content-Type: text/plain; charset=utf-8');

$nick = ROBUST_NICKNAME;
$key  = ROBUST_API_KEY;

echo "=== O QUE ESTÁ NO config.php ===\n\n";

echo "ROBUST_NICKNAME\n";
echo '  valor: "' . $nick . "\"\n";
echo '  tamanho: ' . strlen($nick) . "\n";
if ($nick !== trim($nick))      echo "  *** TEM ESPAÇO OU QUEBRA DE LINHA SOBRANDO ***\n";
if ($nick === 'COLE_O_NICKNAME_AQUI') echo "  *** AINDA É O TEXTO DE EXEMPLO ***\n";

echo "\nROBUST_API_KEY\n";
echo '  tamanho: ' . strlen($key) . " caracteres\n";
echo '  começa com: ' . substr($key, 0, 6) . "...\n";
echo '  termina com: ...' . substr($key, -6) . "\n";
if ($key !== trim($key))        echo "  *** TEM ESPAÇO OU QUEBRA DE LINHA SOBRANDO ***\n";
if ($key === 'COLE_A_CHAVE_AQUI') echo "  *** AINDA É O TEXTO DE EXEMPLO ***\n";
if (strpos($key, '"') !== false || strpos($key, "'") !== false)
                                 echo "  *** TEM ASPAS DENTRO DO VALOR ***\n";
if (strlen($key) !== 64)        echo "  Atenção: a chave usada antes tinha 64 caracteres.\n";

echo "\n=== TESTE NA API ===\n\n";
$url = 'https://api.robustcrm.io/v1/imoveis?status=1&per_page=1';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['X-Nickname: ' . $nick, 'X-API-Key: ' . $key, 'Accept: application/json'],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    echo "Falha de rede: $err\n";
} else {
    echo "HTTP $code\n";
    if ($code === 200) {
        $j = json_decode($resp, true);
        echo "FUNCIONANDO. Imóveis ativos: " . ($j['meta']['total'] ?? '?') . "\n";
        echo "\nSe o Sincronizar ainda falhar, o problema é outro — me avise.\n";
    } elseif ($code === 401 || $code === 403) {
        echo "RECUSADO: a chave ou o nickname estão errados.\n\n";
        echo "O que costuma ser:\n";
        echo "  1. A chave foi trocada no Robust e o config.php ficou com a antiga\n";
        echo "  2. Faltou um caractere ao copiar (confira o tamanho acima)\n";
        echo "  3. Sobrou espaço antes ou depois, dentro das aspas\n\n";
        echo "Pegue a chave atual em: Painel de Controle > Configurações >\n";
        echo "Dados Administrativos (precisa ser SuperAdmin).\n";
    } else {
        echo substr($resp, 0, 300) . "\n";
    }
}

echo "\n--- APAGUE ESTE ARQUIVO DEPOIS DE USAR ---\n";
