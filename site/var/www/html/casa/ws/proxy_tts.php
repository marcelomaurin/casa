<?php
header('Content-Type: application/json; charset=utf-8');
include_once('../sessao.php');
include_once('../config.php');

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'vozes') {
    $resp = @file_get_contents("{$tts_api_url}/vozes");
    if ($resp) {
        echo $resp;
    } else {
        echo json_encode(['vozes' => ['padrao']]);
    }
    exit;
}

if ($action === 'falar') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data) || empty($data['texto'])) {
        if (isset($_POST['texto'])) {
            $data = [
                'texto' => $_POST['texto'],
                'speaker' => isset($_POST['speaker']) ? $_POST['speaker'] : 'padrao',
                'reproduzir' => isset($_POST['reproduzir']) ? filter_var($_POST['reproduzir'], FILTER_VALIDATE_BOOLEAN) : true
            ];
            $input = json_encode($data);
        }
    }
    $ch = curl_init("{$tts_api_url}/falar");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $resp = curl_exec($ch);
    curl_close($ch);
    echo $resp ? $resp : json_encode(['status' => 'erro', 'detail' => 'Falha ao conectar no servico de voz']);
    exit;
}

if ($action === 'clonar') {
    if (!isset($_FILES['amostra']) || empty($_POST['nome'])) {
        echo json_encode(['status' => 'erro', 'detail' => 'Arquivo de amostra e nome da voz sao obrigatorios']);
        exit;
    }
    
    $cfile = curl_file_create($_FILES['amostra']['tmp_name'], $_FILES['amostra']['type'], $_FILES['amostra']['name']);
    $post_fields = [
        'nome' => $_POST['nome'],
        'amostra' => $cfile
    ];
    
    $ch = curl_init("{$tts_api_url}/clonar_voz");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $resp = curl_exec($ch);
    curl_close($ch);
    echo $resp ? $resp : json_encode(['status' => 'erro', 'detail' => 'Falha ao enviar para clonagem']);
    exit;
}

if ($action === 'audio') {
    $file = isset($_GET['file']) ? basename($_GET['file']) : '';
    $audio_url = "{$tts_api_url}/audio/{$file}";
    header('Content-Type: audio/wav');
    readfile($audio_url);
    exit;
}

echo json_encode(['status' => 'erro', 'mensagem' => 'Acao invalida']);
?>
