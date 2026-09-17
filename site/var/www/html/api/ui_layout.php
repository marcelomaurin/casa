<?php
// CASA/JARVIS - contrato seguro de UI adaptativa para uso por IA e clientes.
// A IA escolhe layouts/componentes permitidos; HTML/CSS arbitrario nao e aceito.
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/seguranca.php');
verify_api_auth();

$layouts = ['auto','focus','menu-grid','dashboard','telemetry','alert','form','chat'];
$components = ['menu','card','metric','status','action','field','message','gauge'];

function ui_arr($v) { return is_array($v) ? array_values($v) : []; }
function ui_text($v, $max=500) {
    $s = trim((string)$v);
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max, 'UTF-8');
    return substr($s, 0, $max);
}
function ui_count_metrics($items) {
    $n = 0;
    foreach ($items as $it) {
        if (is_array($it) && (array_key_exists('metric',$it) || array_key_exists('value',$it))) $n++;
    }
    return $n;
}
function ui_choose_layout($spec, $allowed) {
    $requested = ui_text($spec['layout'] ?? 'auto', 30);
    if ($requested !== 'auto' && in_array($requested, $allowed, true)) return $requested;

    $kind = strtolower(ui_text($spec['kind'] ?? '', 40));
    $urgency = strtolower(ui_text($spec['urgency'] ?? 'normal', 20));
    $items = ui_arr($spec['items'] ?? []);
    $sections = ui_arr($spec['sections'] ?? []);
    $fields = ui_arr($spec['fields'] ?? []);
    $messages = ui_arr($spec['messages'] ?? []);

    if (in_array($urgency, ['critical','high'], true) || $kind === 'alert') return 'alert';
    if ($kind === 'chat' || count($messages) > 0) return 'chat';
    if ($kind === 'form' || count($fields) > 0) return 'form';
    if ($kind === 'telemetry' || ui_count_metrics($items) >= 6) return 'telemetry';
    if ($kind === 'menu' || count($sections) >= 2 || count($items) >= 12) return 'menu-grid';
    if (count($items) >= 4) return 'dashboard';
    return 'focus';
}
function ui_limit_assoc_list($list, $max) {
    $out = [];
    foreach (array_slice(ui_arr($list), 0, $max) as $v) {
        if (is_array($v)) $out[] = $v;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status'=>'ok',
        'name'=>'CASA Adaptive LCARS UI Contract',
        'version'=>'1.0',
        'layouts'=>$layouts,
        'components'=>$components,
        'rules'=>[
            'alert'=>'urgency high/critical ou kind=alert',
            'chat'=>'messages presentes ou kind=chat',
            'form'=>'fields presentes ou kind=form',
            'telemetry'=>'6+ metricas/valores ou kind=telemetry',
            'menu-grid'=>'2+ secoes, 12+ itens ou kind=menu',
            'dashboard'=>'4 a 11 itens',
            'focus'=>'1 a 3 itens / detalhe de um item'
        ],
        'security'=>[
            'raw_html'=>false,
            'raw_css'=>false,
            'javascript'=>false,
            'allowed_layouts_only'=>true
        ],
        'example'=>[
            'layout'=>'auto',
            'kind'=>'menu',
            'group'=>'SEGURANCA',
            'title'=>'SEGURANCA',
            'subtitle'=>'Controle e monitoramento residencial',
            'groups'=>[
                ['label'=>'← GRUPOS','action'=>'back','back'=>true],
                ['label'=>'CAMERAS'],['label'=>'ALARMES'],['label'=>'PORTAS']
            ],
            'sections'=>[
                ['title'=>'PERIMETRO','items'=>[['id'=>'portao','label'=>'Portao principal'],['id'=>'garagem','label'=>'Garagem']]],
                ['title'=>'MONITORAMENTO','items'=>[['id'=>'cameras','label'=>'Cameras'],['id'=>'sensores','label'=>'Sensores']]]
            ]
        ]
    ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'erro','mensagem'=>'Metodo nao permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$spec = json_decode(file_get_contents('php://input'), true);
if (!is_array($spec)) {
    http_response_code(400);
    echo json_encode(['status'=>'erro','mensagem'=>'JSON invalido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$layout = ui_choose_layout($spec, $layouts);
$columns = max(1, min(4, (int)($spec['columns'] ?? 4)));

$normalized = [
    'layout'=>$layout,
    'kind'=>ui_text($spec['kind'] ?? '', 40),
    'urgency'=>ui_text($spec['urgency'] ?? 'normal', 20),
    'group'=>ui_text($spec['group'] ?? 'GERAL', 80),
    'title'=>ui_text($spec['title'] ?? 'CASA / JARVIS', 120),
    'subtitle'=>ui_text($spec['subtitle'] ?? '', 300),
    'columns'=>$columns,
    'breadcrumb'=>array_slice(array_map(fn($v)=>ui_text($v,60), ui_arr($spec['breadcrumb'] ?? [])),0,6),
    'groups'=>ui_limit_assoc_list($spec['groups'] ?? [], 9),
    'sections'=>ui_limit_assoc_list($spec['sections'] ?? [], 12),
    'items'=>ui_limit_assoc_list($spec['items'] ?? [], 48),
    'actions'=>ui_limit_assoc_list($spec['actions'] ?? [], 8),
    'fields'=>ui_limit_assoc_list($spec['fields'] ?? [], 24),
    'messages'=>ui_limit_assoc_list($spec['messages'] ?? [], 50),
    'status'=>ui_limit_assoc_list($spec['status'] ?? [], 8),
    'footer'=>is_array($spec['footer'] ?? null) ? $spec['footer'] : []
];

echo json_encode([
    'status'=>'ok',
    'layout'=>$layout,
    'spec'=>$normalized
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
