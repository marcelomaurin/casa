<?php
/**
 * Presenca real dos dispositivos no CASA.
 * `status=online` e apenas o ultimo estado persistido e nunca comprova conectividade.
 * O calculo usa TIMESTAMPDIFF no MySQL para evitar erros de fuso horario do PHP.
 */
function pd_limite_segundos(array $row): int {
    $tipo=strtolower((string)($row['tipo']??''));
    $id=strtolower((string)($row['device_id']??''));
    $caps=$row['capabilities']??[];
    if (is_string($caps)) $caps=json_decode($caps,true);
    if (!is_array($caps)) $caps=[];
    $caps=array_map('strtolower',array_filter($caps,'is_string'));
    if (in_array($tipo,['linux-arm','cluster','server','arm-agent'],true) ||
        preg_match('/^(raspberry|cubie)/',$id) ||
        in_array('arm-agent',$caps,true) || in_array('linux-arm',$caps,true))
        return 45; // Node Agent: heartbeat a cada 20 segundos.
    // Firmwares IoT: heartbeats usuais de 15 a 30s. Margem para falha de rede.
    return 120;
}

function pd_avaliar(array $row): array {
    $age=$row['heartbeat_age_seconds']??null;
    $age=is_numeric($age)?(int)$age:null;
    $limit=pd_limite_segundos($row);
    $stored=strtolower(trim((string)($row['status']??'')));
    $revoked=!empty($row['credential_revoked_at']) || $stored==='revoked';
    $disabled=array_key_exists('ativo',$row) && $row['ativo']!==null && (int)$row['ativo']===0;
    $online=!$revoked && !$disabled && !in_array($stored,['offline','error','erro','revoked'],true)
        && $age!==null && $age>=0 && $age<=$limit;
    $reason=$revoked?'credencial_revogada':($disabled?'cadastro_inativo':
        ($age===null?'sem_heartbeat':($age<0?'horario_invalido':
        ($age>$limit?'heartbeat_expirado':(
            $online?'heartbeat_recente':'status_explicitamente_offline')))));
    return [
        'online'=>$online, 'status'=>$revoked?'revoked':($online?'online':'offline'),
        'reason'=>$reason, 'age_seconds'=>$age, 'max_age_seconds'=>$limit,
    ];
}

/** Útil para API, contexto e testes: lista somente conexões confirmadas recentemente. */
function pd_apenas_online(array $rows): array {
    return array_values(array_filter($rows,function($row){
        return is_array($row) && pd_avaliar($row)['online'];
    }));
}
