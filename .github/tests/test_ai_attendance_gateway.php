<?php
// Integracao do gateway verdadeiro, usando o banco e servidor HTTP locais do CI.
require_once __DIR__ . '/../../site/var/www/html/api/db.php';
$pdo = get_db_pdo();
$base = rtrim(getenv('CONTROL_PLANE_BASE_URL') ?: 'http://127.0.0.1:8099/api/v1', '/');
$key = 'ci_ai_' . bin2hex(random_bytes(16));
$readonly = 'ci_ai_read_' . bin2hex(random_bytes(16));
$hashes = [hash('sha256',$key), hash('sha256',$readonly)];
function ai_gateway_request(string $base, string $method, string $token, array $body, int $expected): void {
    $ch = curl_init($base.'/ia.php');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],
        CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_TIMEOUT=>10]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode((string)$raw,true);
    if ($status !== $expected || !is_array($data) || ($data['status']??'')!=='erro') {
        throw new RuntimeException('Gateway IA: esperado '.$expected.', recebido '.$status.': '.$raw);
    }
}
try {
    foreach ([[$key,['jarvis.command']],[$readonly,['status.read']]] as [$token,$scopes]) {
        $pdo->prepare("INSERT INTO api_client_tokens(nome,token_hash,scopes,ativo) VALUES('CI AI gateway',:h,:s,1)")
            ->execute([':h'=>hash('sha256',$token),':s'=>json_encode($scopes)]);
    }
    ai_gateway_request($base,'POST','',['mensagem'=>'teste'],401);
    ai_gateway_request($base,'POST',$readonly,['mensagem'=>'teste'],403);
    ai_gateway_request($base,'GET',$key,[],405);
    ai_gateway_request($base,'POST',$key,['mensagem'=>[]],400);
    ai_gateway_request($base,'POST',$key,['mensagem'=>str_repeat('a',4001)],413);
    echo "PASS: gateway IA real, autenticacao, escopo, metodo e entrada antes da inferencia\n";
} finally {
    foreach ($hashes as $hash) $pdo->prepare('DELETE FROM api_client_tokens WHERE token_hash=:h')->execute([':h'=>$hash]);
}
