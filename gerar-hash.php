<?php
/* Gera o hash da senha.
   Usa formulário (POST) de propósito: senhas com # ou & quebram quando
   vão pela URL — o navegador corta tudo depois do #.
   APAGUE ESTE ARQUIVO depois de usar. */

$hash = '';
$senha = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = $_POST['s'] ?? '';
    if ($senha !== '') $hash = password_hash($senha, PASSWORD_BCRYPT);
}
?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Gerar hash da senha</title>
<style>
body{font-family:system-ui,sans-serif;max-width:640px;margin:50px auto;padding:0 20px;color:#12211c;line-height:1.6}
h1{font-size:22px}
input[type=text]{width:100%;padding:12px;font-size:16px;border:2px solid #d8d3c4;border-radius:3px;font-family:monospace}
button{margin-top:12px;padding:12px 24px;background:#2f5d4f;color:#fff;border:none;border-radius:3px;font-size:15px;font-weight:600;cursor:pointer}
.out{margin-top:24px;padding:16px;background:#f2efe6;border-left:4px solid #2f5d4f;border-radius:3px}
code.blk{display:block;word-break:break-all;font-size:14px;background:#fff;padding:12px;border:1px solid #d8d3c4;margin-top:8px;user-select:all}
.aviso{margin-top:28px;padding:13px;background:rgba(180,81,47,.09);border-left:4px solid #b4512f;font-size:14px;border-radius:3px}
.conf{font-size:13px;color:#6b7770;margin-top:10px}
</style></head><body>
<h1>Gerar hash da senha</h1>
<p>Digite a senha exatamente como ela será usada. Pode conter #, &amp; ou espaços sem problema.</p>
<form method="post">
  <input type="text" name="s" value="<?= htmlspecialchars($senha) ?>" placeholder="Digite a senha aqui" required autofocus>
  <button type="submit">Gerar hash</button>
</form>
<?php if ($hash): ?>
<div class="out">
  <b>Cole isto na linha SENHA_HASH do config.php:</b>
  <code class="blk"><?= htmlspecialchars($hash) ?></code>
  <div class="conf">
    Senha lida: <b><?= htmlspecialchars($senha) ?></b> (<?= strlen($senha) ?> caracteres)<br>
    Confira se está idêntica à que você quer usar — inclusive maiúsculas e símbolos.<br>
    Verificação automática: <b><?= password_verify($senha, $hash) ? 'hash confere' : 'ERRO' ?></b>
  </div>
</div>
<?php endif; ?>
<div class="aviso"><b>Apague este arquivo</b> depois de colar o hash no config.php.</div>
</body></html>
