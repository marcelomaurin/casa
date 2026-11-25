<?php
// Atendimento textual do site, compartilhado por /comando, /ia/atender e SIP.
require_once __DIR__ . '/command_conversation.php';
require_once __DIR__ . '/../fala_texto.php';

class ApiV1AttendanceException extends RuntimeException {}

function api_v1_attendance_payload(string $raw, string $route, string $correlationId): array {
    if (strlen($raw) > 65536) throw new ApiV1AttendanceException('Requisicao excede 64 KB.', 413);
    $input = json_decode($raw, true);
    if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE || substr(ltrim($raw), 0, 1) !== '{') {
        throw new ApiV1AttendanceException('Envie um objeto JSON valido.', 400);
    }
    $command = $input['comando'] ?? $input['mensagem'] ?? '';
    if (!is_string($command) || trim($command) === '') {
        throw new ApiV1AttendanceException('Informe uma mensagem ou comando em texto.', 400);
    }
    $command = trim($command);
    if (preg_match_all('/./us', $command) > 4000) {
        throw new ApiV1AttendanceException('Mensagem excede 4000 caracteres.', 413);
    }
    $mode = $input['ia_mode'] ?? 'auto';
    if (!is_string($mode) || !preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/', $mode)) {
        throw new ApiV1AttendanceException('Modo de IA invalido.', 400);
    }
    if (isset($input['session_id']) && (!is_string($input['session_id']) ||
        !preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $input['session_id']))) {
        throw new ApiV1AttendanceException('Identificador de conversa invalido.', 400);
    }
    if (isset($input['historico_mensagens']) && !is_array($input['historico_mensagens'])) {
        throw new ApiV1AttendanceException('Historico deve ser uma lista de mensagens.', 400);
    }
    $candidate = $input['correlation_id'] ?? null;
    if (is_string($candidate) && preg_match('/\A[A-Za-z0-9._:-]{8,80}\z/', $candidate)) $correlationId = $candidate;
    $payload = api_v1_command_payload($input, $command, $mode, $correlationId);
    // Sem override explicito, o JARVIS respeita o roteamento configurado no site.
    if (!array_key_exists('ia_mode', $input)) unset($payload['ia_mode']);
    $payload['session_id'] = $payload['session_id'] ?? 'ai_' . bin2hex(random_bytes(12));
    if ($route === 'sip/atender' || ($input['origem'] ?? '') === 'SIP') {
        $payload['origem'] = 'SIP';
    } elseif ($route === 'ia/atender') {
        $payload['origem'] = 'API_IA';
    } elseif (is_string($input['origem'] ?? null) &&
        preg_match('/\A[A-Za-z0-9_-]{1,48}\z/', $input['origem'])) {
        $payload['origem'] = $input['origem'];
    }
    foreach (['idioma', 'locale'] as $key) {
        if (is_string($input[$key] ?? null) && strlen($input[$key]) <= 32) $payload[$key] = $input[$key];
    }
    if (is_array($input['task_context'] ?? null)) $payload['task_context'] = $input['task_context'];
    $payload['skip_planner'] = ($input['skip_planner'] ?? false) === true;
    return $payload;
}

function api_v1_attendance_http(string $url, string $token, array $payload): array {
    $body = ''; $tooLarge = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $token],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 55,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) { $tooLarge = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($tooLarge) throw new ApiV1AttendanceException('Resposta do JARVIS excedeu o limite.', 502);
    if ($ok === false) {
        throw new ApiV1AttendanceException('Nao foi possivel obter resposta do JARVIS.', $errno === CURLE_OPERATION_TIMEDOUT ? 504 : 502);
    }
    return ['http' => $http, 'body' => $body];
}

function api_v1_attendance_execute(array $payload, string $url, string $token, ?callable $transport = null): array {
    if ($token === '') throw new ApiV1AttendanceException('Atendimento por IA ainda nao configurado.', 503);
    $transport = $transport ?? 'api_v1_attendance_http';
    $upstream = $transport($url, $token, $payload);
    $http = (int)($upstream['http'] ?? 0);
    try {
        $data = api_v1_command_response($upstream['body'] ?? false, $http);
    } catch (RuntimeException $e) {
        throw new ApiV1AttendanceException('O JARVIS nao retornou uma resposta valida.', $http === 503 || $http === 429 ? 503 : 502);
    }
    if (strtolower(trim((string)($data['provedor'] ?? ''))) === 'indisponivel') {
        throw new ApiV1AttendanceException('Nenhum modelo de IA configurado respondeu.', 503);
    }
    $text = trim($data['resposta']);
    if (strlen($text) > 64000) throw new ApiV1AttendanceException('Resposta da IA excedeu o limite.', 502);
    $speech = casa_texto_para_fala(preg_replace('/\[\[CMD:.*?\]\]/s', '', $text));
    if ($speech === '') throw new ApiV1AttendanceException('A IA nao produziu uma resposta textual utilizavel.', 502);
    $model = null;
    if (is_array($data['modelo_usado'] ?? null)) {
        $model = array_intersect_key($data['modelo_usado'], array_flip(['id', 'nome', 'modelo', 'classe_hardware', 'nivel_capacidade']));
    }
    return [
        'status' => 'sucesso',
        'session_id' => $payload['session_id'],
        'comando' => $payload['comando'],
        'resposta' => $payload['origem'] === 'SIP' ? $speech : $text,
        'resposta_texto' => $text,
        'resposta_fala' => $speech,
        'provedor_ia' => is_string($data['provedor'] ?? null) ? $data['provedor'] : 'JARVIS',
        'modelo_usado' => $model,
        'acao_executada' => $data['acao'] ?? $data['acao_executada'] ?? null,
        'audio_url' => casa_audio_no_cliente($payload['origem']) ? null : ($data['audio_url'] ?? null),
        'id_plano' => $data['id_plano'] ?? null,
        'id_tarefa_raiz' => $data['id_tarefa_raiz'] ?? null,
        'task_context' => $data['task_context'] ?? null,
        'task_status' => $data['task_status'] ?? null,
        'correlation_id' => $data['task_context']['correlation_id'] ?? $payload['correlation_id'],
        'tarefas_execucao' => $data['tarefas_execucao'] ?? [],
        'status_jarvis' => $data['status'] ?? 'sucesso',
    ];
}
