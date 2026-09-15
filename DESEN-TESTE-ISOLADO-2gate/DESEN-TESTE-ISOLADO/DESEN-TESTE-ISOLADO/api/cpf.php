<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Escolha da função
|--------------------------------------------------------------------------
|
| /api/cpf.php?cpf=...
| /api/cpf.php?action=consultar&cpf=...
|     -> antigo consultar-cpf.php
|
| /api/cpf.php?action=getcpf&cpf=...
|     -> antigo getCpf.php
|
*/

$action = strtolower(trim($_GET['action'] ?? 'consultar'));

if ($action === 'getcpf') {
    executarGetCpf();
} else {
    executarConsultarCpf();
}


/*
|--------------------------------------------------------------------------
| ANTIGO consultar-cpf.php
|--------------------------------------------------------------------------
*/

function executarConsultarCpf()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'ok' => false,
            'error' => 'method_not_allowed'
        ]);
        exit;
    }

    $cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');

    if (strlen($cpf) !== 11) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'cpf_invalido'
        ]);
        exit;
    }

    /*
     * Ideal: mover este token posteriormente para
     * uma variável de ambiente da Vercel.
     */
    $token = 'd9ad2b68-3f28-44f8-9962-c1c476ff44e0';

    $url = 'https://api.amnesiatecnologia.lat/?token='
        . urlencode($token)
        . '&cpf='
        . urlencode($cpf);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ],
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($errno || $raw === false) {
        http_response_code(502);

        echo json_encode([
            'ok' => false,
            'error' => 'api_unreachable'
        ]);

        exit;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(502);

        echo json_encode([
            'ok' => false,
            'error' => 'api_invalid_json',
            'http' => $http
        ]);

        exit;
    }

    if (isset($data['DADOS']) && is_array($data['DADOS'])) {
        $dados = $data['DADOS'];
    } elseif (isset($data['dados']) && is_array($data['dados'])) {
        $dados = $data['dados'];
    } else {
        $dados = $data;
    }

    if (!is_array($dados)) {
        http_response_code(404);

        echo json_encode([
            'ok' => false,
            'error' => 'cpf_nao_encontrado'
        ]);

        exit;
    }

    $nome = cpfPick($dados, ['nome', 'NOME', 'name', 'Nome']);

    $cpfResp = preg_replace(
        '/\D/',
        '',
        (string) cpfPick(
            $dados,
            ['cpf', 'CPF', 'documento', 'DOCUMENTO']
        )
    );

    if ($cpfResp === '') {
        $cpfResp = $cpf;
    }

    $nomeMae = cpfPick(
        $dados,
        ['nome_mae', 'NOME_MAE', 'mae', 'nomeMae', 'MAE']
    );

    $dataNasc = cpfPick(
        $dados,
        [
            'data_nascimento',
            'DATA_NASCIMENTO',
            'nasc',
            'nascimento',
            'NASC',
            'dt_nascimento'
        ]
    );

    $sexo = cpfPick(
        $dados,
        ['sexo', 'SEXO', 'sex', 'gender']
    );

    if ($nome === '') {
        http_response_code(404);

        echo json_encode([
            'ok' => false,
            'error' => 'cpf_nao_encontrado',
            'raw_keys' => array_keys($dados)
        ]);

        exit;
    }

    echo json_encode([
        'ok' => true,
        'cpf' => $cpfResp,
        'nome' => $nome,
        'nome_mae' => $nomeMae,
        'data_nascimento' => $dataNasc,
        'sexo' => $sexo,

        'DADOS' => [
            'cpf' => $cpfResp,
            'nome' => $nome,
            'nome_mae' => $nomeMae,
            'data_nascimento' => $dataNasc,
            'sexo' => $sexo,
        ],

    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| ANTIGO getCpf.php
|--------------------------------------------------------------------------
*/

function executarGetCpf()
{
    if (
        $_SERVER['REQUEST_METHOD'] !== 'GET'
        && $_SERVER['REQUEST_METHOD'] !== 'POST'
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Método não permitido'
        ]);

        exit;
    }

    $cpf = preg_replace(
        '/\D/',
        '',
        $_GET['cpf'] ?? $_POST['cpf'] ?? ''
    );

    if ($cpf === '' || strlen($cpf) !== 11) {
        echo json_encode([
            'success' => false,
            'message' => 'CPF inválido. Deve conter 11 dígitos.'
        ]);

        exit;
    }

    if (!function_exists('curl_init')) {
        echo json_encode([
            'success' => false,
            'message' => 'Extensão cURL não disponível no servidor'
        ]);

        exit;
    }

    $networkFail = 0;
    $attempts = 1;

    [$body, $httpCode, $curlErr] = cpfFetch(
        'https://base2.sistemafullativo.online:80/api/cad?CPF='
        . urlencode($cpf)
    );

    if ($curlErr !== '') {
        $networkFail++;
    } else {

        $j = json_decode((string) $body, true);

        if (
            $httpCode === 200
            && is_array($j)
            && !empty($j['nome'])
        ) {

            $nasc = trim(
                (string) ($j['dataNascimento'] ?? '')
            );

            echo json_encode([
                'success' => true,

                'nome' => trim(
                    (string) $j['nome']
                ),

                'cpf' => preg_replace(
                    '/\D/',
                    '',
                    (string) ($j['cpf'] ?? $cpf)
                ) ?: $cpf,

                'nascimento' => $nasc,

                'mae' => trim(
                    (string) ($j['nomeMae'] ?? '')
                ) ?: null,

                'sexo' => cpfNormalizaSexo(
                    trim((string) ($j['sexo'] ?? ''))
                ) ?: null,

                'data' => $j,
                '_provider' => 'base2',

            ], JSON_UNESCAPED_UNICODE);

            exit;
        }
    }

    $message = $networkFail === $attempts

        ? 'Serviço de consulta temporariamente indisponível. Tente novamente em instantes.'

        : 'CPF não encontrado nas bases consultadas.';

    echo json_encode([
        'success' => false,
        'message' => $message,

    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| FUNÇÕES AUXILIARES
|--------------------------------------------------------------------------
*/

function cpfPick($src, $keys)
{
    foreach ($keys as $k) {

        if (
            isset($src[$k])
            && $src[$k] !== ''
            && $src[$k] !== null
        ) {
            return $src[$k];
        }
    }

    return '';
}


function cpfNormalizaSexo($raw)
{
    if ($raw === '') {
        return '';
    }

    if (
        preg_match(
            '/(masculino|feminino)/i',
            $raw,
            $m
        )
    ) {
        return ucfirst(strtolower($m[1]));
    }

    $s = strtoupper($raw);

    if (in_array(
        $s,
        ['M', 'MASCULINO', 'MALE'],
        true
    )) {
        return 'Masculino';
    }

    if (in_array(
        $s,
        ['F', 'FEMININO', 'FEMALE'],
        true
    )) {
        return 'Feminino';
    }

    return $raw;
}


function cpfFetch($url, $extraHeaders = [])
{
    $headers = array_merge(
        [
            'Accept: application/json',

            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/121.0.0.0 Safari/537.36',
        ],

        $extraHeaders
    );

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $body = curl_exec($ch);

    $code = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $err = curl_errno($ch)
        ? curl_error($ch)
        : '';

    curl_close($ch);

    return [$body, $code, $err];
}
