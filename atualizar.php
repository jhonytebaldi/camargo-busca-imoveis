<?php
/* =====================================================================
   atualizar.php — baixa a versão mais recente do GitHub e instala.

   O que ele NUNCA toca:
     - credenciais.php  (suas chaves)
     - a pasta de dados (imóveis, referências, coordenadas, seleções)
   Antes de instalar, guarda uma cópia da versão atual, para dar para
   voltar atrás se algo sair errado.

   Acesse: atualizar.php
     ?ver=1        mostra o que mudaria, sem instalar nada
     ?instalar=1   instala
     ?voltar=1     restaura a cópia anterior
   ===================================================================== */

require_once __DIR__ . '/config.php';

// Pelo navegador exige login. Pelo cron (linha de comando) nao ha sessao,
// e o acesso ao servidor ja e a credencial.
$viaCli = php_sapi_name() === 'cli';
// O webhook.php inclui este arquivo depois de conferir a assinatura do
// GitHub. Nesse caso nao ha sessao — a autorizacao ja foi feita la.
$interno = !empty($GLOBALS['DEPLOY_INTERNO']);
if (!$viaCli && !$interno) {
    session_start();
    if (empty($_SESSION['autenticado'])) { header('Location: login.php'); exit; }
    header('Content-Type: text/plain; charset=utf-8');
    while (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);
}

// Arquivos que a atualização jamais substitui ou remove.
define('PRESERVAR', ['credenciais.php', 'dados', 'geocode.json']);

$BACKUP = dirname(DATA_DIR) . '/backup-ferramenta';

function baixarRepo() {
    if (GITHUB_REPO === '') throw new Exception(
        "GITHUB_REPO nao esta definido em credenciais.php.\n"
        . "Preencha com algo como: jhonytebaldi/camargo-busca-imoveis");

    // Escolhe o formato pelo que o PHP consegue abrir. Em hospedagem
    // compartilhada o exec() costuma estar desligado, então não dá para
    // depender do comando "tar".
    $formato = metodoExtracao();
    $ext = $formato === 'zip' ? 'zip' : 'tar.gz';
    $url = 'https://codeload.github.com/' . GITHUB_REPO . '/' . ($formato === 'zip' ? 'zip' : 'tar.gz')
         . '/refs/heads/' . GITHUB_BRANCH;
    $destino = sys_get_temp_dir() . '/ferramenta-' . bin2hex(random_bytes(4)) . '.' . $ext;

    $cab = ['Accept: application/vnd.github+json', 'User-Agent: atualizador-camargo'];
    if (GITHUB_TOKEN !== '') $cab[] = 'Authorization: Bearer ' . GITHUB_TOKEN;

    $fp = fopen($destino, 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $cab,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code !== 200) {
        @unlink($destino);
        if ($code === 404) throw new Exception(
            "Repositorio nao encontrado (404).\n"
            . "Confira GITHUB_REPO e GITHUB_BRANCH. Se for privado, o token e obrigatorio.");
        if ($code === 401 || $code === 403) throw new Exception(
            "Acesso negado ($code). O token esta errado, venceu, ou nao tem\n"
            . "permissao de leitura neste repositorio.");
        throw new Exception("Falha ao baixar (HTTP $code) $err");
    }
    if (filesize($destino) < 1000) { @unlink($destino); throw new Exception('Arquivo baixado veio vazio.'); }
    return $destino;
}

/** Qual método de extração este servidor suporta. */
function metodoExtracao() {
    if (class_exists('ZipArchive')) return 'zip';
    if (class_exists('PharData'))   return 'phar';
    // exec() pode existir como função mas estar na lista de desabilitadas.
    $desabilitadas = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (function_exists('exec') && !in_array('exec', $desabilitadas, true)) return 'exec';
    return 'nenhum';
}

/** Extrai o pacote e devolve a pasta com os arquivos. */
function extrair($arquivo) {
    $metodo = metodoExtracao();
    if ($metodo === 'nenhum') throw new Exception(
        "Este servidor nao tem como descompactar o pacote.\n"
        . "Faltam ZipArchive, PharData e exec(). Fale com o suporte da hospedagem\n"
        . "ou volte a atualizar enviando os arquivos pelo gerenciador.");

    $dir = sys_get_temp_dir() . '/ferr-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    if ($metodo === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($arquivo) !== true) throw new Exception('Nao consegui abrir o pacote (zip).');
        $zip->extractTo($dir);
        $zip->close();
    } elseif ($metodo === 'phar') {
        try {
            $p = new PharData($arquivo);
            $p->decompress();                                  // .tar.gz -> .tar
            $semGz = preg_replace('/\.gz$/', '', $arquivo);
            $t = new PharData($semGz);
            $t->extractTo($dir, null, true);
            @unlink($semGz);
        } catch (Exception $e) {
            throw new Exception('Falha ao descompactar: ' . $e->getMessage());
        }
    } else {
        $saida = []; $status = 1;
        exec('tar -xzf ' . escapeshellarg($arquivo) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $saida, $status);
        if ($status !== 0) throw new Exception('Nao consegui descompactar: ' . implode("\n", $saida));
    }

    // O GitHub embrulha tudo numa pasta "repo-ramo".
    $itens = array_values(array_diff(scandir($dir), ['.', '..']));
    $raiz = (count($itens) === 1 && is_dir("$dir/{$itens[0]}")) ? "$dir/{$itens[0]}" : $dir;

    // Confere que saiu alguma coisa: sem isto, um erro silencioso de
    // extracao viraria "0 arquivos no pacote" sem explicacao nenhuma.
    $conta = count(array_diff(scandir($raiz), ['.', '..']));
    if ($conta === 0) throw new Exception(
        "O pacote foi baixado mas veio vazio depois de descompactar.\n"
        . "Metodo usado: $metodo. Avise que isso aconteceu.");

    return $raiz;
}

function arquivosDe($base, $prefixo = '') {
    $out = [];
    foreach (array_diff(scandir($base), ['.', '..']) as $item) {
        $rel = $prefixo === '' ? $item : "$prefixo/$item";
        if (in_array($item, PRESERVAR, true)) continue;
        if (in_array($item, ['.git', '.github', '.gitignore', 'README.md'], true)) continue;
        if (is_dir("$base/$item")) $out = array_merge($out, arquivosDe("$base/$item", $rel));
        else $out[$rel] = filesize("$base/$item");
    }
    return $out;
}

function rmrf($p) {
    if (!is_dir($p)) { @unlink($p); return; }
    foreach (array_diff(scandir($p), ['.', '..']) as $i) rmrf("$p/$i");
    @rmdir($p);
}

if ($viaCli || $interno) {
    $args = $interno ? ($GLOBALS['DEPLOY_ARGS'] ?? '--instalar --silencioso')
                     : implode(' ', array_slice($argv, 1));
    $acao = strpos($args, '--instalar') !== false ? 'instalar'
          : (strpos($args, '--voltar') !== false ? 'voltar' : 'ver');
    $silencioso = strpos($args, '--silencioso') !== false;
} else {
    $acao = !empty($_GET['instalar']) ? 'instalar' : (!empty($_GET['voltar']) ? 'voltar' : 'ver');
    $silencioso = false;
}

// No modo silencioso (cron) so falamos quando algo muda ou da errado, para
// nao encher a caixa de e-mail com "nada a fazer" todo dia.
if ($silencioso) ob_start();

try {

if ($acao === 'voltar') {
    if (!is_dir($BACKUP)) exit("Nao ha copia anterior para restaurar.\n");
    $n = 0;
    foreach (arquivosDe($BACKUP) as $rel => $tam) {
        $destino = __DIR__ . '/' . $rel;
        @mkdir(dirname($destino), 0755, true);
        if (copy("$BACKUP/$rel", $destino)) $n++;
    }
    log_sync("versao anterior restaurada: $n arquivos");
    exit("Restaurados $n arquivos da copia anterior.\n");
}

echo "Baixando de " . (GITHUB_REPO ?: '(nao configurado)') . " ramo " . GITHUB_BRANCH . "...\n";
$tgz = baixarRepo();
echo 'Baixado: ' . round(filesize($tgz) / 1024) . ' KB (metodo: ' . metodoExtracao() . ")\n";
$novo = extrair($tgz);
$arquivos = arquivosDe($novo);
echo 'Arquivos no pacote: ' . count($arquivos) . "\n\n";

$novos = []; $mudados = []; $iguais = 0;
foreach ($arquivos as $rel => $tam) {
    $atual = __DIR__ . '/' . $rel;
    if (!file_exists($atual)) $novos[] = $rel;
    elseif (md5_file($atual) !== md5_file("$novo/$rel")) $mudados[] = $rel;
    else $iguais++;
}

echo "--- O QUE MUDA ---\n";
foreach ($novos as $f)   echo "  NOVO       $f\n";
foreach ($mudados as $f) echo "  ATUALIZA   $f\n";
echo "  (sem alteracao: $iguais)\n";
echo "\nPreservados sempre: " . implode(', ', PRESERVAR) . "\n";

if ($acao === 'ver') {
    rmrf($novo); @unlink($tgz);
    echo "\nNada foi instalado. Para instalar: atualizar.php?instalar=1\n";
    exit;
}

if (!$novos && !$mudados) {
    rmrf($novo); @unlink($tgz);
    if ($silencioso) { ob_end_clean(); if ($interno) return; exit(0); }
    echo "\nJa esta na versao mais recente.\n";
    if ($interno) return;
    exit;
}

echo "\nGuardando copia da versao atual...\n";
rmrf($BACKUP);
mkdir($BACKUP, 0750, true);
$nb = 0;
foreach ($mudados as $rel) {
    $destino = "$BACKUP/$rel";
    @mkdir(dirname($destino), 0755, true);
    if (copy(__DIR__ . '/' . $rel, $destino)) $nb++;
}
echo "  $nb arquivos guardados\n";

echo "\nInstalando...\n";
$ok = 0; $erros = [];
foreach (array_merge($novos, $mudados) as $rel) {
    $destino = __DIR__ . '/' . $rel;
    @mkdir(dirname($destino), 0755, true);
    if (copy("$novo/$rel", $destino)) { $ok++; echo "  ok  $rel\n"; }
    else $erros[] = $rel;
}
rmrf($novo); @unlink($tgz);

log_sync("atualizacao instalada: $ok arquivos" . ($erros ? ', ' . count($erros) . ' falharam' : ''));
echo "\n----------------------------------------\n";
echo "Instalados: $ok\n";
if ($erros) {
    echo "FALHARAM: " . implode(', ', $erros) . "\n";
    echo "Verifique as permissoes da pasta.\n";
}
echo "\nSe algo quebrar, volte com: atualizar.php?voltar=1\n";
if ($silencioso) { $saida = ob_get_clean(); echo $saida; }

} catch (Exception $e) {
    if (!empty($silencioso)) { $saida = ob_get_clean(); echo $saida; }
    if (!$viaCli) http_response_code(500);
    log_sync('atualizacao falhou: ' . $e->getMessage());
    echo "\nERRO: " . $e->getMessage() . "\n";
    if ($interno) return;
    if ($viaCli) exit(1);
}
