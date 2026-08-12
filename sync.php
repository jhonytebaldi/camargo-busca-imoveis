<?php
/* =====================================================================
   sync.php — roda pelo cron 1x por dia (ou manualmente).
   1. Puxa os imóveis ativos da API do Robust
   2. Puxa as fotos de cada imóvel
   3. Junta com os dados do XLS (descrição, área, entrega...)
   4. Grava imoveis.json, que é o arquivo que a ferramenta lê

   REGRA DO MERGE:
     - A API manda em: preço, quartos, banheiros, status, fotos,
       proprietário, captador, endereço  (ela sempre tem o dado mais fresco)
     - O XLS manda em: descrição, área, entrega, amenidades, parcelamento,
       suítes, vagas  (a API não devolve esses campos)
     - A API NUNCA apaga o que ela não sabe.
   ===================================================================== */

require_once __DIR__ . '/config.php';

@set_time_limit(600);
$inicio = microtime(true);

/** Chamada autenticada à API do Robust. */
function api_get(string $caminho) {
    $url = 'https://api.robustcrm.io/v1' . $caminho;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'X-Nickname: ' . ROBUST_NICKNAME,
            'X-API-Key: '  . ROBUST_API_KEY,
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new Exception("Falha de rede em $caminho: $err");
    if ($code !== 200)   throw new Exception("HTTP $code em $caminho");
    $j = json_decode($resp, true);
    if ($j === null) throw new Exception("JSON inválido em $caminho");
    return $j;
}


/* ---------------------------------------------------------------------
   Normalização de bairro e mapa de regiões de Joinville.
   Precisa existir aqui (e não só no navegador) porque os imóveis que vêm
   apenas da API nunca passam pelo pipeline do XLS: sem isto, "ITINGA",
   "Itinga" e "itinga" viram três bairros diferentes e os imóveis novos
   ficam sem região.
   --------------------------------------------------------------------- */

function txt_norm($s) {
    // Sem mbstring de propósito: nem toda hospedagem tem a extensão.
    $s = trim((string)$s);
    $de = ['Á','À','Ã','Â','Ä','É','Ê','Ë','Í','Ï','Ó','Ô','Õ','Ö','Ú','Ü','Ç',
           'á','à','ã','â','ä','é','ê','ë','í','ï','ó','ô','õ','ö','ú','ü','ç'];
    $para = ['a','a','a','a','a','e','e','e','i','i','o','o','o','o','u','u','c',
             'a','a','a','a','a','e','e','e','i','i','o','o','o','o','u','u','c'];
    return strtolower(str_replace($de, $para, $s));
}

// Grafia oficial de cada bairro (chave = versão normalizada).
$GLOBALS['BAIRRO_NOME'] = [
    // Norte
    'america'=>'América','atiradores'=>'Atiradores','gloria'=>'Glória',
    'costa e silva'=>'Costa e Silva','santo antonio'=>'Santo Antônio',
    'bom retiro'=>'Bom Retiro','saguacu'=>'Saguaçu','dona francisca'=>'Dona Francisca',
    'dona francisca (pirabeiraba)'=>'Dona Francisca','dona francisca (piraberaba)'=>'Dona Francisca',
    'jardim sofia'=>'Jardim Sofia',
    // Sul
    'anita garibaldi'=>'Anita Garibaldi','floresta'=>'Floresta','guanabara'=>'Guanabara',
    'itaum'=>'Itaum','bucarein'=>'Bucarein','fatima'=>'Fátima',
    'adhemar garcia'=>'Adhemar Garcia','jarivatuba'=>'Jarivatuba',
    'parque guarani'=>'Parque Guarani','petropolis'=>'Petrópolis',
    'boehmerwald'=>'Boehmerwald','itinga'=>'Itinga','profipo'=>'Profipo',
    'santa catarina'=>'Santa Catarina','ulysses guimaraes'=>'Ulysses Guimarães',
    'joao costa'=>'João Costa','paranaguamirim'=>'Paranaguamirim',
    'volta redonda'=>'Volta Redonda',
    // Leste
    'aventureiro'=>'Aventureiro','iririu'=>'Iririú','boa vista'=>'Boa Vista',
    'comasa'=>'Comasa','espinheiros'=>'Espinheiros','jardim iririu'=>'Jardim Iririú',
    'vila cubatao'=>'Vila Cubatão','zona industrial tupy'=>'Zona Industrial Tupy',
    'zona industrial norte'=>'Zona Industrial Norte',
    // Oeste
    'vila nova'=>'Vila Nova','morro do meio'=>'Morro do Meio',
    'nova brasilia'=>'Nova Brasília','sao marcos'=>'São Marcos',
    // Distrito
    'pirabeiraba'=>'Pirabeiraba','centro (pirabeiraba)'=>'Centro (Pirabeiraba)',
];

$GLOBALS['BAIRRO_REGIAO'] = [
    'america'=>'Norte','atiradores'=>'Norte','gloria'=>'Norte','costa e silva'=>'Norte',
    'santo antonio'=>'Norte','bom retiro'=>'Norte','saguacu'=>'Norte',
    'dona francisca'=>'Norte','jardim sofia'=>'Norte',

    'anita garibaldi'=>'Sul','floresta'=>'Sul','guanabara'=>'Sul','itaum'=>'Sul',
    'bucarein'=>'Sul','fatima'=>'Sul','adhemar garcia'=>'Sul','jarivatuba'=>'Sul',
    'parque guarani'=>'Sul','petropolis'=>'Sul','boehmerwald'=>'Sul','itinga'=>'Sul',
    'profipo'=>'Sul','santa catarina'=>'Sul','ulysses guimaraes'=>'Sul',
    'joao costa'=>'Sul','paranaguamirim'=>'Sul','volta redonda'=>'Sul',

    'aventureiro'=>'Leste','iririu'=>'Leste','boa vista'=>'Leste','comasa'=>'Leste',
    'espinheiros'=>'Leste','jardim iririu'=>'Leste','vila cubatao'=>'Leste',
    'zona industrial tupy'=>'Leste','zona industrial norte'=>'Leste',

    'vila nova'=>'Oeste','morro do meio'=>'Oeste','nova brasilia'=>'Oeste',
    'sao marcos'=>'Oeste',

    'pirabeiraba'=>'Pirabeiraba (distrito)','centro (pirabeiraba)'=>'Pirabeiraba (distrito)',
];

/** Devolve [bairro com grafia oficial, região]. */
function bairro_regiao($bruto) {
    $n = txt_norm($bruto);
    $nome = $GLOBALS['BAIRRO_NOME'][$n] ?? null;
    if ($nome === null) {
        // Bairro fora do mapa: preserva o texto e só arruma a caixa das
        // palavras, deixando conectivos em minúsculo.
        $palavras = preg_split('/\s+/', strtolower(trim((string)$bruto)));
        $conect = ['e','de','do','da','dos','das'];
        foreach ($palavras as $i => $p) {
            if ($p === '') continue;
            $palavras[$i] = ($i > 0 && in_array($p, $conect, true)) ? $p : ucfirst($p);
        }
        $nome = implode(' ', $palavras);
    }
    $reg = $GLOBALS['BAIRRO_REGIAO'][$n] ?? null;
    return [$nome, $reg];
}

try {
    log_sync('--- início do sync ---');

    // ---------- 1. Lista de imóveis ativos ----------
    // Modo "só remontar": usado quando o usuário acabou de enviar uma planilha.
    // Reaproveita o que já foi baixado da API (api.json) em vez de buscar tudo
    // de novo. Sem isso a requisição levava 35-60s e o servidor devolvia 504
    // antes de o navegador receber resposta.
    $somenteMerge = !empty($GLOBALS['SYNC_SOMENTE_MERGE']) && file_exists(ARQ_API);

    if ($somenteMerge) {
        $imoveis = json_decode(@file_get_contents(ARQ_API), true) ?: [];
        log_sync('remontando a partir do cache: ' . count($imoveis) . ' imóveis');
    } else {
        $imoveis = [];
        $pagina  = 1;
        do {
            $r = api_get("/imoveis?status=1&per_page=500&page=$pagina");
            foreach (($r['data'] ?? []) as $item) $imoveis[] = $item;
            $totalPaginas = $r['meta']['pages'] ?? 1;
            $pagina++;
        } while ($pagina <= $totalPaginas);
        log_sync('imóveis ativos na API: ' . count($imoveis));
    }

    if (count($imoveis) === 0) {
        // Proteção: nunca substitui uma base boa por uma vazia.
        throw new Exception('API retornou zero imóveis — mantendo a base anterior.');
    }

    // ---------- 2. Fotos e anexos ----------
    // Só busca fotos de quem mudou, ou de quem ainda não tem foto salva.
    $fotosAnteriores = [];
    if (file_exists(ARQ_MERGE)) {
        foreach ((json_decode(@file_get_contents(ARQ_MERGE), true)['imoveis'] ?? []) as $ant) {
            if (!empty($ant['fotos']) || !empty($ant['atu'])) {
                $fotosAnteriores[$ant['c']] = [
                    'fotos' => $ant['fotos'] ?? [],
                    'upd'   => $ant['upd'] ?? null,
                    'atu'   => $ant['atu'] ?? null,
                    'lat'   => $ant['lat'] ?? null,
                    'lng'   => $ant['lng'] ?? null,
                ];
            }
        }
    }

    $comFoto = 0;
    foreach ($imoveis as &$im) {
        if ($somenteMerge) {
            // O cache de api.json já guarda _fotos, _atu, _lat e _lng.
            if (!empty($im['_fotos'])) $comFoto++;
            continue;
        }
        $id  = (int)$im['id'];
        $upd = $im['updated_at'] ?? null;
        $cache = $fotosAnteriores[$id] ?? null;

        // 1) DETALHE — sempre. É a única fonte de 'atualizado_em' (data da
        //    conferência do anúncio). Não dá para usar cache aqui: a conferência
        //    muda essa data SEM alterar updated_at, que é justamente o caso
        //    que queremos capturar.
        try {
            $d = api_get("/imoveis/$id");
            $d = $d['data'] ?? $d;
            $im['_atu'] = $d['atualizado_em'] ?? null;
            // Coordenadas: só ~25% dos imóveis têm, mas bastam algumas por
            // bairro para calcular o centro dele e medir distâncias reais.
            $la = $d['endereco_latitude']  ?? null;
            $lo = $d['endereco_longitude'] ?? null;
            $im['_lat'] = is_numeric($la) ? (float)$la : null;
            $im['_lng'] = is_numeric($lo) ? (float)$lo : null;
        } catch (Exception $e) {
            $im['_atu'] = $cache['atu'] ?? null;
            $im['_lat'] = $cache['lat'] ?? null;
            $im['_lng'] = $cache['lng'] ?? null;
            log_sync("aviso: detalhe do imóvel $id falhou (" . $e->getMessage() . ')');
        }

        // 2) FOTOS — só quando o cadastro mudou ou ainda não temos nenhuma.
        //    Usamos /files e não o 'arquivos' do detalhe: aquele vem de um
        //    cache do CRM que às vezes lista menos fotos do que existem.
        if ($cache && ($cache['upd'] ?? null) === $upd && !empty($cache['fotos'])) {
            $im['_fotos'] = $cache['fotos'];
            $comFoto++;
            continue;
        }
        try {
            $f = api_get("/imoveis/$id/files");
            $fotos = [];
            foreach (($f['data'] ?? []) as $arq) {
                if (($arq['filetype'] ?? '') !== 'media') continue;
                if (($arq['visible'] ?? true) !== true && ($arq['visible'] ?? 1) != 1) continue;
                $u = $arq['urls'] ?? [];
                if (empty($u['full']) && empty($u['small'])) continue;
                $fotos[] = [
                    'p'   => $u['small'] ?? $u['full'],
                    'g'   => $u['full']  ?? $u['small'],
                    'leg' => $arq['legend'] ?? '',
                ];
            }
            $im['_fotos'] = $fotos;
            if ($fotos) $comFoto++;
        } catch (Exception $e) {
            $im['_fotos'] = $cache['fotos'] ?? [];
            if (!empty($im['_fotos'])) $comFoto++;
            log_sync("aviso: fotos do imóvel $id falharam (" . $e->getMessage() . ')');
        }
    }
    unset($im);
    log_sync("imóveis com foto: $comFoto");

    file_put_contents(ARQ_API, json_encode($imoveis, JSON_UNESCAPED_UNICODE));

    // Coordenadas obtidas por geocodificação do endereço (geocodificar.php),
    // usadas só onde o CRM não tem a posição preenchida.
    $geo = [];
    if (file_exists(DATA_DIR . '/geocode.json')) {
        $geo = json_decode(@file_get_contents(DATA_DIR . '/geocode.json'), true) ?: [];
    }

    // ---------- 3. Merge com o XLS ----------
    $xls = [];
    $xlsData = null;
    if (file_exists(ARQ_XLS)) {
        $j = json_decode(@file_get_contents(ARQ_XLS), true);
        $xlsData = $j['gerado_em'] ?? null;
        foreach (($j['imoveis'] ?? []) as $x) $xls[(int)$x['c']] = $x;
    }

    $saida = [];
    $semDescricao = 0;
    foreach ($imoveis as $a) {
        $id = (int)$a['id'];
        $x  = $xls[$id] ?? null;

        // Base: tudo que veio do XLS (descrição, área, entrega, amenidades...)
        $reg = $x ?: [];

        // A API sobrescreve APENAS o que ela realmente sabe.
        $reg['c']  = $id;
        $reg['t']  = $a['tipo']             ?? ($reg['t']  ?? '');
        list($_b, $_r) = bairro_regiao($a['endereco_bairro'] ?? ($reg['b'] ?? ''));
        $reg['b'] = $_b;
        // A região sempre vem do mapa de bairros — inclusive para os imóveis
        // que só existem na API e nunca passaram por um XLS.
        if ($_r !== null) $reg['r'] = $_r;
        elseif (!isset($reg['r'])) $reg['r'] = null;
        $reg['ci'] = $a['endereco_cidade']  ?? ($reg['ci'] ?? '');
        $reg['p']  = isset($a['valor_venda']) ? (float)$a['valor_venda'] : ($reg['p'] ?? null);
        if (isset($a['quartos']) && $a['quartos'] !== null && $a['quartos'] !== '') {
            $reg['q'] = (int)$a['quartos'];
        }
        // Os campos proprietario_1/captador_1 trazem só o ID; os nomes estão
        // em *_detalhes. Pegamos APENAS o nome — telefone e e-mail ficam de fora.
        $nomesProp = [];
        foreach (($a['proprietarios_detalhes'] ?? []) as $pd) {
            if (!empty($pd['nome'])) $nomesProp[] = trim($pd['nome']);
        }
        if ($nomesProp) $reg['prop'] = implode(', ', $nomesProp);
        elseif (empty($reg['prop']) || is_numeric($reg['prop'])) $reg['prop'] = '';

        $nomesCap = [];
        foreach (($a['captadores_detalhes'] ?? []) as $cd) {
            if (!empty($cd['nome'])) $nomesCap[] = trim($cd['nome']);
        }
        if ($nomesCap) $reg['cap'] = $nomesCap;
        elseif (empty($reg['cap']) || !is_array($reg['cap'])) $reg['cap'] = [];
        // 'alt' = última ALTERAÇÃO de campo | 'atu' = última ATUALIZAÇÃO (conferência)
        $reg['alt']  = $a['updated_at'] ?? ($reg['alt'] ?? null);
        $reg['upd']  = $a['updated_at'] ?? null;
        $reg['atu']  = $a['_atu'] ?? null;
        $reg['lat']  = $a['_lat'] ?? null;
        $reg['lng']  = $a['_lng'] ?? null;
        $reg['geo']  = null;   // origem da coordenada
        if ($reg['lat'] !== null) {
            $reg['geo'] = 'crm';
        } elseif (!empty($geo[(string)$id]['la'])) {
            $g = $geo[(string)$id];
            $reg['lat'] = $g['la'];
            $reg['lng'] = $g['lo'];
            // 'numero' = acertou o número da casa; 'rua' = caiu no meio da rua.
            $reg['geo'] = $g['q'] === 'numero' ? 'endereco' : 'rua';
        }
        $reg['fotos'] = $a['_fotos'] ?? [];

        // Endereço legível
        $end = trim(($a['endereco_logradouro'] ?? '') . ' ' . ($a['endereco_numero'] ?? ''));
        if ($end !== '') $reg['e'] = $end;

        // Telefone do proprietário: removido a pedido (não vai para a web).
        unset($reg['tel']);

        // Sinaliza quem ainda não tem descrição carregada por XLS.
        $reg['semDesc'] = $x ? 0 : 1;
        if (!$x) {
            $semDescricao++;
            if (empty($reg['ti'])) {
                $partes = [$reg['t'] ?: 'Imóvel'];
                if (!empty($reg['q'])) $partes[] = $reg['q'] . ' quarto' . ($reg['q'] > 1 ? 's' : '');
                $reg['ti'] = implode(', ', $partes) . ($reg['b'] ? ' no ' . $reg['b'] : '');
                $reg['tiGerado'] = 1;
            }
            // Campos que dependem da descrição ficam nulos, nunca inventados.
            foreach (['d','a','af','ea','em','pt','pa','de','am','su','v','ba'] as $k) {
                if (!array_key_exists($k, $reg)) $reg[$k] = ($k === 'am') ? [] : null;
            }
            $reg['f'] = ['descrição não carregada'];
            $reg['g'] = 3;
            $reg['s'] = strtolower(($reg['ti'] ?? '') . ' ' . $reg['b'] . ' ' . $reg['t']);
        }
        $saida[] = $reg;
    }

    // ---------- centros de bairro (para a busca por proximidade) ----------
    // O centro sai da média das coordenadas dos imóveis do próprio bairro.
    // Só vale com coordenada plausível para a região de Joinville — assim um
    // endereço geocodificado errado não desloca o bairro inteiro.
    $acc = [];
    foreach ($saida as $r) {
        $la = $r['lat'] ?? null; $lo = $r['lng'] ?? null; $b = $r['b'] ?? '';
        if ($b === '' || $la === null || $lo === null) continue;
        // O centro do bairro só usa posição vinda do CRM ou do número exato:
        // alimentar com aproximação de rua degradaria o cálculo com o tempo.
        if (!in_array($r['geo'] ?? '', ['crm', 'endereco'], true)) continue;
        if ($la < -26.9 || $la > -25.9 || $lo < -49.4 || $lo > -48.4) continue;
        if (!isset($acc[$b])) $acc[$b] = ['la' => 0, 'lo' => 0, 'n' => 0];
        $acc[$b]['la'] += $la; $acc[$b]['lo'] += $lo; $acc[$b]['n']++;
    }
    $centros = [];
    foreach ($acc as $b => $v) {
        $centros[$b] = ['la' => round($v['la'] / $v['n'], 6),
                        'lo' => round($v['lo'] / $v['n'], 6),
                        'n'  => $v['n']];
    }
    $porOrigem = ['crm' => 0, 'endereco' => 0, 'rua' => 0];
    foreach ($saida as $r) if (!empty($r['geo'])) $porOrigem[$r['geo']] = ($porOrigem[$r['geo']] ?? 0) + 1;
    log_sync('centros de bairro: ' . count($centros)
        . ' | coordenadas: CRM ' . $porOrigem['crm']
        . ', endereço ' . $porOrigem['endereco'] . ', rua ' . $porOrigem['rua']);

    // Pontos de referência (shoppings, faculdades, terminais...) enviados
    // por planilha. Vão junto na base para a busca e os cards usarem.
    $refs = [];
    $refsMeta = null;
    if (file_exists(DATA_DIR . '/referencias.json')) {
        $rj = json_decode(@file_get_contents(DATA_DIR . '/referencias.json'), true);
        $refs = $rj['pontos'] ?? [];
        $refsMeta = $rj['gerado_em'] ?? null;
    }

    $meta = [
        'gerado_em'      => date('c'),
        'centros'        => $centros,
        'referencias'    => $refs,
        'coordenadas'    => $porOrigem,
        'refs_gerado_em' => $refsMeta,
        'total'          => count($saida),
        'com_foto'       => $comFoto,
        'sem_descricao'  => $semDescricao,
        'xls_gerado_em'  => $xlsData,
        'fonte'          => 'API Robust + XLS',
    ];

    $tmp = ARQ_MERGE . '.tmp';
    file_put_contents($tmp, json_encode(['meta' => $meta, 'imoveis' => $saida], JSON_UNESCAPED_UNICODE));
    rename($tmp, ARQ_MERGE);   // troca atômica: a ferramenta nunca lê arquivo pela metade

    $seg = round(microtime(true) - $inicio, 1);
    log_sync("OK: {$meta['total']} imóveis, $comFoto com foto, $semDescricao sem descrição, {$seg}s");

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'meta' => $meta], JSON_UNESCAPED_UNICODE);
    } else {
        echo "Sync OK: {$meta['total']} imóveis ({$seg}s)\n";
    }

} catch (Exception $e) {
    log_sync('ERRO: ' . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'ERRO: ' . $e->getMessage() . "\n";
    }
}
