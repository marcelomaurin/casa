<?php
// CASA/JARVIS - Device Registry: fonte unica de identidade, estado, capabilities e roteamento.

function registry_json($value, array $fallback=[]): array {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value)==='') return $fallback;
    $d=json_decode($value,true);
    return is_array($d)?$d:$fallback;
}

function registry_normalize_capability_name(string $name): string {
    return substr(trim($name),0,120);
}

function registry_sync_capabilities(PDO $pdo, string $deviceId, $declared): void {
    $caps=registry_json($declared, is_array($declared)?$declared:[]);
    foreach($caps as $k=>$v){
        $name=is_int($k)?registry_normalize_capability_name((string)$v):registry_normalize_capability_name((string)$k);
        if($name==='')continue;
        $enabled=is_int($k)?1:((bool)$v?1:0);
        $st=$pdo->prepare("INSERT INTO device_capabilities(device_id,capability,enabled,risk_level)
            VALUES(:d,:c,:e,1)
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),atualizado_em=NOW()");
        $st->execute([':d'=>$deviceId,':c'=>$name,':e'=>$enabled]);
    }
}

function registry_capabilities(PDO $pdo, string $deviceId, $legacyFallback=[]): array {
    $st=$pdo->prepare("SELECT capability,enabled,risk_level,config FROM device_capabilities WHERE device_id=:d ORDER BY capability");
    $st->execute([':d'=>$deviceId]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows && $legacyFallback){
        registry_sync_capabilities($pdo,$deviceId,$legacyFallback);
        $st->execute([':d'=>$deviceId]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    }
    return array_map(function($r){
        return [
            'name'=>(string)$r['capability'],
            'enabled'=>(bool)$r['enabled'],
            'risk_level'=>(int)$r['risk_level'],
            'config'=>registry_json($r['config']??null,[])
        ];
    },$rows);
}

function registry_get(PDO $pdo, string $deviceId, bool $allowRevoked=false): ?array {
    $deviceId=trim($deviceId);
    if($deviceId==='')return null;
    $sql="SELECT id,device_id,nome,tipo,status,health,transport,local_ip,observed_ip,gateway_device_id,
        sinal_rssi,battery_pct,capabilities,metadata,localizacao,manufacturer,model,firmware_version,
        protocol_version,config_version,ultimo_heartbeat,credential_revoked_at,
        CASE WHEN credential_revoked_at IS NULL AND ultimo_heartbeat IS NOT NULL
          AND ultimo_heartbeat>=DATE_SUB(NOW(),INTERVAL 120 SECOND) THEN 1 ELSE 0 END AS online
        FROM dispositivos_cluster WHERE device_id=:d";
    if(!$allowRevoked)$sql.=" AND credential_revoked_at IS NULL";
    $sql.=" LIMIT 1";
    $st=$pdo->prepare($sql);$st->execute([':d'=>$deviceId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $row['capabilities']=registry_capabilities($pdo,$deviceId,$row['capabilities']??[]);
    $row['metadata']=registry_json($row['metadata']??null,[]);
    $row['online']=(bool)$row['online'];
    $row['battery_pct']=$row['battery_pct']===null?null:(int)$row['battery_pct'];
    $row['sinal_rssi']=$row['sinal_rssi']===null?null:(int)$row['sinal_rssi'];
    $row['routing']=[
        'transport'=>$row['transport']??null,
        'gateway_device_id'=>$row['gateway_device_id']??null,
        'local_ip'=>$row['local_ip']??null,
        'observed_ip'=>$row['observed_ip']??null
    ];
    return $row;
}

function registry_list(PDO $pdo, bool $includeRevoked=false): array {
    $sql="SELECT device_id FROM dispositivos_cluster WHERE device_id IS NOT NULL AND device_id<>''";
    if(!$includeRevoked)$sql.=" AND credential_revoked_at IS NULL";
    $sql.=" ORDER BY nome,device_id";
    $ids=$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $out=[];
    foreach($ids as $id){$d=registry_get($pdo,(string)$id,$includeRevoked);if($d)$out[]=$d;}
    return $out;
}

function registry_require(PDO $pdo, string $deviceId): array {
    $d=registry_get($pdo,$deviceId,false);
    if(!$d)throw new RuntimeException('device_not_found');
    return $d;
}

function registry_capability(PDO $pdo, string $deviceId, string $name): ?array {
    $name=registry_normalize_capability_name($name);
    if($name==='')return null;
    $st=$pdo->prepare("SELECT capability,enabled,risk_level,config FROM device_capabilities WHERE device_id=:d AND capability=:c LIMIT 1");
    $st->execute([':d'=>$deviceId,':c'=>$name]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r)return null;
    return ['name'=>$r['capability'],'enabled'=>(bool)$r['enabled'],'risk_level'=>(int)$r['risk_level'],'config'=>registry_json($r['config']??null,[])];
}

function registry_require_capability(PDO $pdo, string $deviceId, string $name): array {
    $dev=registry_require($pdo,$deviceId);
    if(trim($name)==='')return ['device'=>$dev,'capability'=>null,'risk_level'=>1];
    $cap=registry_capability($pdo,$deviceId,$name);
    if(!$cap)throw new RuntimeException('capability_not_found');
    if(!$cap['enabled'])throw new RuntimeException('capability_disabled');
    return ['device'=>$dev,'capability'=>$cap,'risk_level'=>(int)$cap['risk_level']];
}
