# Busca de Imóveis — Imobiliária Camargo

Ferramenta interna de busca no acervo, com dados vindos do CRM Robust.

## Como atualizar o servidor

1. Acesse `atualizar.php?ver=1` — mostra o que mudaria, sem instalar
2. Se estiver certo, `atualizar.php?instalar=1`
3. Se algo quebrar, `atualizar.php?voltar=1` restaura a versão anterior

O atualizador **nunca** toca em `credenciais.php` nem na pasta de dados.

## Instalação nova

1. Copie `credenciais.php.exemplo` para `credenciais.php` e preencha
2. Gere o hash da senha em `gerar-hash.php` (apague o arquivo depois)
3. Acesse a ferramenta e clique em **Sincronizar com o CRM**
4. Envie a planilha de imóveis e a de pontos de referência

## Estrutura

| Arquivo | Papel |
|---|---|
| `index.php` | Verifica o login e entrega `lib/app.html` |
| `lib/app.html` | A aplicação (busca, cards, galeria, mapa) |
| `login.php` | Autenticação, com bloqueio por tentativas |
| `api.php` | Endpoints: dados, upload, compartilhar, sync |
| `sync.php` | Puxa o CRM e monta a base (roda pelo cron 1x/dia) |
| `ver.php` | Página pública da seleção enviada ao cliente |
| `config.php` | Caminhos e ajustes — **sem** credenciais |
| `credenciais.php` | Chaves e senha (fora do repositório) |

### Ferramentas

| Arquivo | Para quê |
|---|---|
| `geocodificar.php` | Converte endereços em coordenadas |
| `exportar-enderecos.php` | Baixa CSV dos imóveis sem coordenada |
| `importar-coordenadas.php` | Sobe coordenadas obtidas por fora |
| `testar-api.php` | Confere as credenciais do Robust |
| `gerar-hash.php` | Gera o hash de uma senha nova |

## Cron diário

```
/usr/bin/php /home/USUARIO/domains/imobcamargo.com.br/public_html/dados/sync.php
```
