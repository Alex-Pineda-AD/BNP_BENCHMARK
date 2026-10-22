<?php
function conciliacionPropietario(PDO $pdo,int $usuario): bool {
    $q=$pdo->prepare('SELECT 1 FROM conciliacion_control c JOIN usuarios u ON u.id=c.propietario_id WHERE c.id=1 AND u.id=? AND u.estado=1 AND u.id_rol=5'); $q->execute([$usuario]); return (bool)$q->fetchColumn();
}
function conciliacionPeriodo(PDO $pdo): ?array { return $pdo->query("SELECT *, (mes+INTERVAL '1 month')::date fin_exclusivo FROM conciliacion_periodos WHERE estado='ABIERTO'")->fetch()?:null; }
function conciliacionEnPeriodo(?array $periodo,array $estado): bool {
    return $periodo && !empty($estado['PERIODO_DESDE']) && !empty($estado['PERIODO_HASTA']) && $estado['PERIODO_DESDE']>=$periodo['MES'] && $estado['PERIODO_HASTA']<$periodo['FIN_EXCLUSIVO'];
}
function conciliacionExigirPeriodo(PDO $pdo,array $e,?array $d=null,?array $m=null): array {
    $p=conciliacionPeriodo($pdo);
    if(!conciliacionEnPeriodo($p,$e)) throw new RuntimeException('Solo se permite trabajar con estados del mes abierto. Consultá el mantenimiento de períodos.');
    foreach([$d['FECHA_DEPOSITO']??null,$m['FECHA']??null] as $fecha) if($fecha!==null && (substr($fecha,0,10)<$p['MES'] || substr($fecha,0,10)>=$p['FIN_EXCLUSIVO'])) throw new RuntimeException('La fecha del depósito o movimiento no pertenece al mes abierto.');
    if($d!==null && empty($d['FECHA_DEPOSITO'])) throw new RuntimeException('Ingresá la fecha del comprobante antes de trabajar en este período.');
    return $p;
}
function conciliacionPuedeAjustar(PDO $pdo,int $usuario,int $estado): bool {
    if(conciliacionPropietario($pdo,$usuario)) return true;
    $q=$pdo->prepare("SELECT 1 FROM conciliacion_autorizaciones a JOIN usuarios u ON u.id=a.usuario_id JOIN conciliacion_periodos p ON p.id=a.periodo_id WHERE a.usuario_id=? AND a.estado_cuenta_id=? AND NOT a.revocado AND a.vence_en>CURRENT_TIMESTAMP AND u.estado=1 AND p.estado='ABIERTO'"); $q->execute([$usuario,$estado]); return (bool)$q->fetchColumn();
}
function conciliacionAuditar(PDO $pdo,int $usuario,string $accion,string $motivo,array $datos): void {
    $q=$pdo->prepare('INSERT INTO conciliacion_control_bitacora(usuario_id,accion,motivo,datos) VALUES (?,?,?,CAST(? AS jsonb))'); $q->execute([$usuario,$accion,$motivo,json_encode($datos,JSON_THROW_ON_ERROR)]);
}
function conciliacionAdministrar(PDO $pdo,int $usuario,array $datos): void {
    if(!conciliacionPropietario($pdo,$usuario)) throw new RuntimeException('Solo el propietario del control puede gestionar períodos y permisos.');
    $motivo=trim((string)($datos['motivo']??'')); if($motivo==='' || mb_strlen($motivo)>1000) throw new RuntimeException('Ingresá un motivo de hasta 1000 caracteres.');
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT pg_advisory_xact_lock(20261014)');
        if(!conciliacionPropietario($pdo,$usuario)) throw new RuntimeException('El acceso al mantenimiento cambió.');
        $accion=$datos['accion']??'';
        if($accion==='abrir_periodo') {
            $mes=(string)($datos['mes']??''); $f=DateTimeImmutable::createFromFormat('!Y-m',$mes);
            if(!$f || $f->format('Y-m')!==$mes) throw new RuntimeException('Seleccioná un mes válido.');
            $anterior=conciliacionPeriodo($pdo);
            $pdo->exec("UPDATE conciliacion_periodos SET estado='BLOQUEADO' WHERE estado='ABIERTO'");
            $q=$pdo->prepare("INSERT INTO conciliacion_periodos(mes,estado,usuario_id) VALUES (?,'ABIERTO',?) ON CONFLICT(mes) DO UPDATE SET estado='ABIERTO',usuario_id=EXCLUDED.usuario_id,actualizado_en=CURRENT_TIMESTAMP"); $q->execute([$mes.'-01',$usuario]);
            // Switching months invalidates old delegations, including on reopening.
            $pdo->exec('UPDATE conciliacion_autorizaciones SET revocado=TRUE WHERE NOT revocado');
            conciliacionAuditar($pdo,$usuario,$accion,$motivo,['anterior'=>$anterior['MES']??null,'nuevo'=>$mes]);
        } elseif($accion==='cerrar_periodo') {
            $p=conciliacionPeriodo($pdo); if(!$p) throw new RuntimeException('No hay un mes abierto.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM estados_cuenta e WHERE e.periodo_desde>=? AND e.periodo_hasta<? AND NOT EXISTS(SELECT 1 FROM conciliacion_cierres c WHERE c.estado_cuenta_id=e.id)'); $q->execute([$p['MES'],$p['FIN_EXCLUSIVO']]);
            if((int)$q->fetchColumn()) throw new RuntimeException('Primero debés cerrar los estados de cuenta de este mes.');
            $q=$pdo->prepare("UPDATE conciliacion_periodos SET estado='CERRADO',usuario_id=?,actualizado_en=CURRENT_TIMESTAMP WHERE id=?"); $q->execute([$usuario,$p['ID']]);
            $q=$pdo->prepare('UPDATE conciliacion_autorizaciones SET revocado=TRUE WHERE periodo_id=?'); $q->execute([$p['ID']]);
            conciliacionAuditar($pdo,$usuario,$accion,$motivo,['periodo'=>$p['ID']]);
        } elseif($accion==='autorizar') {
            $id=(int)($datos['estado_cuenta']??0); $q=$pdo->prepare('SELECT * FROM estados_cuenta WHERE id=?'); $q->execute([$id]); $e=$q->fetch(); if(!$e) throw new RuntimeException('Seleccioná un estado de cuenta.');
            $p=conciliacionExigirPeriodo($pdo,$e);
            $q=$pdo->prepare('SELECT 1 FROM conciliacion_cierres WHERE estado_cuenta_id=?'); $q->execute([$id]); if($q->fetchColumn()) throw new RuntimeException('El estado ya está cerrado.');
            $destino=(int)($datos['usuario']??0); $q=$pdo->prepare('SELECT 1 FROM usuarios WHERE id=? AND estado=1 AND id_rol IN (2,4,5)'); $q->execute([$destino]); if(!$q->fetchColumn()) throw new RuntimeException('Seleccioná un usuario activo de conciliación.');
            $vence=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($datos['vence']??''),new DateTimeZone('America/Tegucigalpa'));
            $ahora=new DateTimeImmutable('now',new DateTimeZone('America/Tegucigalpa'));
            if(!$vence || $vence->format('Y-m-d\TH:i')!==($datos['vence']??'') || $vence<=$ahora || $vence>$ahora->modify('+30 days')) throw new RuntimeException('La autorización debe vencer dentro de los próximos 30 días.');
            $q=$pdo->prepare('UPDATE conciliacion_autorizaciones SET revocado=TRUE WHERE usuario_id=? AND estado_cuenta_id=?'); $q->execute([$destino,$id]);
            $q=$pdo->prepare('INSERT INTO conciliacion_autorizaciones(usuario_id,periodo_id,estado_cuenta_id,otorgado_por,vence_en,motivo) VALUES (?,?,?,?,?,?) RETURNING id'); $q->execute([$destino,$p['ID'],$id,$usuario,$vence->format(DATE_ATOM),$motivo]);
            conciliacionAuditar($pdo,$usuario,$accion,$motivo,['autorizacion'=>$q->fetchColumn(),'destino'=>$destino,'estado'=>$id]);
        } elseif($accion==='revocar') {
            $q=$pdo->prepare('UPDATE conciliacion_autorizaciones SET revocado=TRUE WHERE id=? RETURNING id'); $q->execute([(int)($datos['autorizacion']??0)]); $id=$q->fetchColumn(); if(!$id) throw new RuntimeException('Autorización inexistente.');
            conciliacionAuditar($pdo,$usuario,$accion,$motivo,['autorizacion'=>$id]);
        } else throw new RuntimeException('Acción de mantenimiento inválida.');
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
