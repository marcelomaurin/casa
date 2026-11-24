<?php
// Contrato de conversa autenticada para a rota v1/comando (inclui chamadas SIP).
// O estado pertence a cada cliente/chamada; nao existe historico global de ramais.
function api_v1_command_payload(array $input, string $command, string $mode, string $correlationId): array {
    $payload = [
        'comando' => $command,
        'ia_mode' => $mode,
        'origem' => ($input['origem'] ?? '') === 'SIP' ? 'SIP' : 'API_V1',
        'correlation_id' => $correlationId,
    ];
    $history = $input['historico_mensagens'] ?? [];
    if (is_array($history)) {
        $messages = [];
        $bytes = 0;
        foreach (array_reverse(array_slice($history, -24)) as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? '', ['user', 'assistant'], true)
                || !is_string($message['content'] ?? null)) continue;
            $content = trim($message['content']);
            if ($content === '' || strlen($content) > 16000) continue;
            if ($bytes + strlen($content) > 32000) break;
            $bytes += strlen($content);
            $messages[] = ['role' => $message['role'], 'content' => $content];
        }
        $payload['historico_mensagens'] = array_reverse($messages);
    }
    $session = $input['session_id'] ?? '';
    if (is_string($session) && preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $session)) {
        $payload['session_id'] = $session;
    }
    return $payload;
}

function api_v1_command_response($raw, int $http): array {
    if (!is_string($raw) || $http < 200 || $http >= 300) {
        throw new RuntimeException('Falha no nucleo cognitivo do JARVIS.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || in_array($data['status'] ?? '', ['erro', 'error'], true)
        || ($data['status_jarvis'] ?? '') === 'erro' || ($data['ok'] ?? true) === false
        || !is_string($data['resposta'] ?? null) || trim($data['resposta']) === '') {
        throw new RuntimeException('JARVIS nao retornou uma resposta textual valida.');
    }
    return $data;
}
