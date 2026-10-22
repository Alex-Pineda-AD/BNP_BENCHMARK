<?php
require_once __DIR__.'/conciliacion_periodos.php';
function conciliacionUsuario(PDO $pdo, int $usuario, bool $supervisor = false): int {
    $q = $pdo->prepare('SELECT ID_ROL FROM usuarios WHERE ID = ? AND ESTADO = 1');
    $q->execute([$usuario]);
    $rol=(int)$q->fetchColumn();
    if (!in_array($rol, $supervisor ? [4,5] : [2,4,5], true)) throw new RuntimeException('No tenés permiso para esta acción.');
    return $rol;
}
function conciliacionDatos(PDO $pdo, int $deposito, int $movimiento): array {
    $q=$pdo->prepare('SELECT * FROM depositos WHERE ID = ? FOR UPDATE'); $q->execute([$deposito]); $d=$q->fetch();
    $q=$pdo->prepare('SELECT * FROM transacciones_estado_cuenta WHERE ID = ? FOR UPDATE'); $q->execute([$movimiento]); $m=$q->fetch();
    if (!$d || !$m) throw new RuntimeException('El depósito o movimiento ya no existe.');
    $q=$pdo->prepare('SELECT * FROM estados_cuenta WHERE ID = ? FOR UPDATE'); $q->execute([$m['ID_ESTADO_CIENTA']]); $e=$q->fetch();
    return [$d,$m,$e];
}
function conciliacionSerializable($valor) {
    if(is_array($valor)) {
        foreach($valor as $clave=>$dato) $valor[$clave]=conciliacionSerializable($dato);
        return $valor;
    }
    // PDO PostgreSQL exposes BYTEA as a stream. Preserve the file and cursor;
    // use its content digest to detect changes without embedding binary in JSON.
    if(is_resource($valor)) {
        if(get_resource_type($valor)!=='stream') throw new RuntimeException('Tipo de archivo no compatible con la conciliación.');
        $posicion=ftell($valor);
        if($posicion===false || fseek($valor,0)!==0) throw new RuntimeException('No se pudo leer el archivo original del estado de cuenta.');
        try {
            $contexto=hash_init('sha256');
            $bytes=hash_update_stream($contexto,$valor);
            if($bytes===false) throw new RuntimeException('No se pudo verificar el archivo original.');
            return ['tipo'=>'archivo_binario','bytes'=>$bytes,'sha256'=>hash_final($contexto)];
        } finally { fseek($valor,$posicion); }
    }
    return $valor;
}
function conciliacionHuella(array $datos): string { return hash('sha256', json_encode(conciliacionSerializable($datos), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
function conciliacionCuenta($valor): string { return preg_replace('/[^0-9]/', '', (string)$valor); }
function conciliacionFoto($url): ?string {
    if (!is_string($url) || !preg_match('~^(?:\.\./\.\./)?files/depositos/[a-zA-Z0-9_.-]+$~D',$url)) return null;
    return strpos($url,'../../')===0?$url:'../../'.$url;
}
function conciliacionDecimal($valor): string {
    $p=explode('.',(string)$valor);
    return (ltrim($p[0],'0') ?: '0').'.'.str_pad(substr($p[1]??'',0,2),2,'0');
}
function conciliacionCandidato(array $d,array $m,array $e): array {
    $referencia=trim((string)($d['NUMERO_REFERENCIA']??''));
    $ref=$referencia!=='' && strtoupper($referencia)===strtoupper(trim((string)$m['REFERENCIA']));
    $monto=conciliacionDecimal($d['MONTO'])===conciliacionDecimal($m['CREDITO']) && conciliacionDecimal($m['DEBITO'])==='0.00';
    $fecha=!empty($d['FECHA_DEPOSITO']) && substr($d['FECHA_DEPOSITO'],0,10)===substr($m['FECHA'],0,10);
    $hora=!empty($d['FECHA_DEPOSITO']) && !empty($m['HORA']) && substr($d['FECHA_DEPOSITO'],11,8)===substr($m['HORA'],0,8);
    $errores=conciliacionComparar($d,$m,$e);
    return ['referencia'=>$ref,'monto'=>$monto,'fecha'=>$fecha,'hora'=>$hora,'fecha_hora'=>$fecha && $hora,'errores'=>$errores,
        'puntaje'=>($fecha && $hora?1000:0)+($ref?200:0)+($monto?100:0)+($fecha?20:0),
        'exacto'=>!$errores && $ref && $fecha && $hora];
}
function conciliacionCandidatos(array $d,array $movimientos,array $e,int $seleccionado=0): array {
    $resultado=[];
    foreach($movimientos as $m) {
        if(!empty($m['VINCULO_ESTADO']) || !empty($m['PARTIDA'])) continue;
        $m['COMPARACION']=conciliacionCandidato($d,$m,$e);
        $c=$m['COMPARACION'];
        if($c['referencia'] || $c['monto'] || ($c['fecha'] && conciliacionDecimal($m['CREDITO'])!=='0.00') || (int)$m['ID']===$seleccionado) $resultado[]=$m;
    }
    usort($resultado,static function($a,$b) { return ($b['COMPARACION']['puntaje']<=>$a['COMPARACION']['puntaje']) ?: ((int)$a['ID']<=>(int)$b['ID']); });
    return $resultado;
}
function conciliacionComparar(array $d,array $m,array $e): array {
    $errores=[];
    if (!in_array((int)$d['ESTADO'],[5,8],true)) $errores[]='El depósito debe estar aprobado en la revisión documental.';
    if ((int)$d['ID_BANCO'] !== (int)$e['ID_BANCO']) $errores[]='El banco es diferente.';
    if (conciliacionCuenta($d['NUMERO_CUENTA']) === '' || conciliacionCuenta($d['NUMERO_CUENTA']) !== conciliacionCuenta($e['NUMERO_CUENTA'])) $errores[]='La cuenta es diferente o falta en el estado.';
    if (strtoupper(trim($d['MONEDA'])) !== strtoupper(trim($e['MONEDA']))) $errores[]='La moneda es diferente.';
    // Compare decimal strings without floating point rounding.
    $decimal=static function($v) { $p=explode('.',(string)$v); return (ltrim($p[0],'0') ?: '0').'.'.str_pad(substr($p[1]??'',0,2),2,'0'); };
    if ($decimal($d['MONTO']) !== $decimal($m['CREDITO']) || $decimal($m['CREDITO'])==='0.00' || $decimal($m['DEBITO'])!=='0.00') $errores[]='El crédito debe ser igual al depósito y el débito cero.';
    return $errores;
}
function conciliacionAccion(PDO $pdo,int $usuario,array $p): void {
    $accion=$p['accion']??'';
    conciliacionUsuario($pdo,$usuario,in_array($accion,['aprobar','devolver','partida','cerrar','solicitar_correccion'],true));
    $pdo->beginTransaction();
    try {
        // Serialize workflow changes, including the final close checks.
        $pdo->query('SELECT pg_advisory_xact_lock(20261014)');
        $motivo=trim((string)($p['observacion']??''));
        if (mb_strlen($motivo)>1000) throw new RuntimeException('La observación admite hasta 1000 caracteres.');
        if ($accion==='solicitar_correccion') {
            if($motivo==='' || mb_strlen($motivo)>500) throw new RuntimeException('Indicá qué debe corregir el analista (máximo 500 caracteres).');
            $q=$pdo->prepare('SELECT * FROM depositos WHERE id=? FOR UPDATE'); $q->execute([(int)($p['deposito']??0)]); $d=$q->fetch();
            if(!$d || !in_array((int)$d['ESTADO'],[5,8],true)) throw new RuntimeException('El depósito ya no está aprobado para solicitar esta corrección.');
            $q=$pdo->prepare('SELECT * FROM estados_cuenta WHERE id=?'); $q->execute([(int)($p['estado_cuenta']??0)]); $contexto=$q->fetch();
            if(!$contexto || (int)$contexto['ID_BANCO']!==(int)$d['ID_BANCO']) throw new RuntimeException('Seleccioná el estado de cuenta del depósito.');
            conciliacionExigirPeriodo($pdo,$contexto,$d);
            $q=$pdo->prepare("SELECT 1 FROM conciliacion_vinculos WHERE deposito_id=? AND estado='CERRADO'"); $q->execute([$d['ID']]);
            if($q->fetchColumn()) throw new RuntimeException('El depósito pertenece a un cierre y no se puede devolver.');
            $q=$pdo->prepare('SELECT id_deposito_detalle FROM depositos_detalle WHERE id_deposito=? ORDER BY id_deposito_detalle DESC LIMIT 1'); $q->execute([$d['ID']]); $detalle=$q->fetchColumn();
            if(!$detalle) throw new RuntimeException('No se encontró el comprobante del depósito.');
            $q=$pdo->prepare("UPDATE conciliacion_vinculos SET estado='DEVUELTO',motivo=CONCAT(COALESCE(motivo,''),CAST(? AS text),CAST(? AS text)) WHERE deposito_id=? AND estado IN ('PREVALIDADO','APROBADO')");
            $q->execute(["\nSolicitud de corrección por usuario #".$usuario.': ',$motivo,$d['ID']]);
            $q=$pdo->prepare('UPDATE depositos SET estado=7,usuario_modifico=?,fecha_modifico=CURRENT_TIMESTAMP WHERE id=?'); $q->execute([$usuario,$d['ID']]);
            $q=$pdo->prepare("INSERT INTO depositos_bitacora(id_deposito,id_deposito_detalle,accion,estado_anterior,estado_nuevo,observacion,usuario_creo,fecha_creo) VALUES (?,?,'SOLICITUD_CORRECCION',?,7,?,?,CURRENT_TIMESTAMP)");
            $q->execute([$d['ID'],$detalle,$d['ESTADO'],$motivo,$usuario]);
        } elseif ($accion==='vincular') {
            [$d,$m,$e]=conciliacionDatos($pdo,(int)($p['deposito']??0),(int)($p['movimiento']??0));
            conciliacionExigirPeriodo($pdo,$e,$d,$m);
            $errores=conciliacionComparar($d,$m,$e); if ($errores) throw new RuntimeException(implode(' ',$errores));
            $q=$pdo->prepare('SELECT 1 FROM conciliacion_cierres WHERE estado_cuenta_id=?'); $q->execute([$e['ID']]); if ($q->fetchColumn()) throw new RuntimeException('El estado de cuenta está cerrado.');
            $q=$pdo->prepare('SELECT 1 FROM conciliacion_partidas WHERE movimiento_id=?'); $q->execute([$m['ID']]); if ($q->fetchColumn()) throw new RuntimeException('El movimiento está registrado como partida justificada.');
            if (($p['confirmado']??'')!=='1') throw new RuntimeException('Confirmá que revisaste el comprobante y el crédito seleccionado.');
            if ($motivo==='') {
                if (!conciliacionCandidato($d,$m,$e)['exacto']) throw new RuntimeException('Explicá las diferencias de referencia, fecha u hora antes de enviar.');
                $motivo='Comprobante revisado. Cuenta, moneda, referencia, monto, fecha y hora coinciden.';
            }
            $q=$pdo->prepare("INSERT INTO conciliacion_vinculos(deposito_id,movimiento_id,estado,huella,observacion,creado_por) VALUES (?,?,'PREVALIDADO',?,?,?)");
            $q->execute([$d['ID'],$m['ID'],conciliacionHuella([$d,$m,$e]),$motivo,$usuario]);
        } elseif (in_array($accion,['aprobar','devolver'],true)) {
            $q=$pdo->prepare('SELECT * FROM conciliacion_vinculos WHERE id=? FOR UPDATE'); $q->execute([(int)($p['vinculo']??0)]); $v=$q->fetch();
            if (!$v || $v['ESTADO']!=='PREVALIDADO') throw new RuntimeException('El vínculo ya no está pendiente.');
            if ((int)$v['CREADO_POR']===$usuario) throw new RuntimeException('Otro supervisor debe revisar tu prevalidación.');
            $datos=conciliacionDatos($pdo,(int)$v['DEPOSITO_ID'],(int)$v['MOVIMIENTO_ID']);
            conciliacionExigirPeriodo($pdo,$datos[2],$datos[0],$datos[1]);
            if ($accion==='aprobar') {
                $datos=conciliacionDatos($pdo,(int)$v['DEPOSITO_ID'],(int)$v['MOVIMIENTO_ID']);
                if (!hash_equals($v['HUELLA'],conciliacionHuella($datos))) throw new RuntimeException('Los datos cambiaron. Devolvé el vínculo para revisarlo nuevamente.');
            } elseif ($motivo==='') throw new RuntimeException('Indicá el motivo de devolución.');
            $q=$pdo->prepare('UPDATE conciliacion_vinculos SET estado=?,revisado_por=?,revisado_en=CURRENT_TIMESTAMP,motivo=? WHERE id=?');
            $q->execute([$accion==='aprobar'?'APROBADO':'DEVUELTO',$usuario,$motivo,$v['ID']]);
        } elseif ($accion==='partida') {
            if ($motivo==='') throw new RuntimeException('Justificá la partida bancaria.');
            $q=$pdo->prepare('SELECT * FROM transacciones_estado_cuenta WHERE id=? FOR UPDATE'); $q->execute([(int)($p['movimiento']??0)]); $m=$q->fetch();
            if (!$m) throw new RuntimeException('Movimiento inexistente.');
            $q=$pdo->prepare('SELECT * FROM estados_cuenta WHERE id=?'); $q->execute([$m['ID_ESTADO_CIENTA']]); $contexto=$q->fetch(); conciliacionExigirPeriodo($pdo,$contexto,null,$m);
            $q=$pdo->prepare("SELECT 1 FROM conciliacion_vinculos WHERE movimiento_id=? AND estado<>'DEVUELTO' UNION ALL SELECT 1 FROM conciliacion_cierres WHERE estado_cuenta_id=?"); $q->execute([$m['ID'],$m['ID_ESTADO_CIENTA']]);
            if ($q->fetchColumn()) throw new RuntimeException('El movimiento está vinculado o el estado ya está cerrado.');
            $q=$pdo->prepare('INSERT INTO conciliacion_partidas(movimiento_id,motivo,usuario_id,huella) VALUES (?,?,?,?)'); $q->execute([$m['ID'],$motivo,$usuario,conciliacionHuella($m)]);
        } elseif ($accion==='registrar_ajuste') {
            $id=(int)($p['estado_cuenta']??0);
            if(!conciliacionPuedeAjustar($pdo,$usuario,$id)) throw new RuntimeException('No tenés autorización vigente para ajustes en este estado de cuenta.');
            $q=$pdo->prepare('SELECT * FROM estados_cuenta WHERE id=? FOR UPDATE'); $q->execute([$id]); $e=$q->fetch(); if(!$e) throw new RuntimeException('Estado inexistente.');
            $periodo=conciliacionExigirPeriodo($pdo,$e);
            $q=$pdo->prepare('SELECT 1 FROM conciliacion_cierres WHERE estado_cuenta_id=?'); $q->execute([$id]); if($q->fetchColumn()) throw new RuntimeException('El estado está cerrado.');
            $importe=(string)($p['importe']??''); $documento=trim((string)($p['documento']??'')); $fecha=(string)($p['fecha']??'');
            $f=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
            if(!$f || $f->format('Y-m-d')!==$fecha || $fecha<$periodo['MES'] || $fecha>=$periodo['FIN_EXCLUSIVO'] || $fecha<$e['PERIODO_DESDE'] || $fecha>$e['PERIODO_HASTA']) throw new RuntimeException('La fecha del ajuste debe pertenecer al mes abierto y al período del estado.');
            if(!preg_match('/^-?\d{1,13}(?:\.\d{1,2})?$/D',$importe) || !preg_match('/[1-9]/',$importe)) throw new RuntimeException('Ingresá un importe distinto de cero con hasta dos decimales.');
            if($documento==='' || mb_strlen($documento)>200 || $motivo==='') throw new RuntimeException('El ajuste requiere documento de respaldo y justificación.');
            $q=$pdo->prepare('INSERT INTO conciliacion_ajustes(estado_cuenta_id,periodo_id,usuario_id,fecha,importe,documento,motivo) VALUES (?,?,?,?,?,?,?) RETURNING id'); $q->execute([$id,$periodo['ID'],$usuario,$fecha,$importe,$documento,$motivo]);
            conciliacionAuditar($pdo,$usuario,$accion,$motivo,['ajuste'=>$q->fetchColumn(),'estado'=>$id,'importe'=>$importe,'documento'=>$documento]);
        } elseif (in_array($accion,['cerrar','cerrar_forzado'],true)) {
            $forzado=$accion==='cerrar_forzado';
            $id=(int)($p['estado_cuenta']??0);
            if($forzado && !conciliacionPuedeAjustar($pdo,$usuario,$id)) throw new RuntimeException('No tenés autorización vigente para cierre excepcional en este estado de cuenta.');
            if($forzado && ($motivo==='' || ($p['confirmar_excepcion']??'')!=='1')) throw new RuntimeException('Justificá y confirmá expresamente el cierre excepcional.');
            $q=$pdo->prepare('SELECT * FROM estados_cuenta WHERE id=? FOR UPDATE'); $q->execute([$id]); $e=$q->fetch();
            if (!$e || $e['SALDO_INICIAL']===null || $e['SALDO_FINAL']===null) throw new RuntimeException('Se requieren saldos inicial y final del estado de cuenta.');
            $periodo=conciliacionExigirPeriodo($pdo,$e);
            if (empty($e['PERIODO_DESDE']) || empty($e['PERIODO_HASTA']) || empty($e['NUMERO_CUENTA'])) throw new RuntimeException('Se requiere cuenta y período completo para cerrar.');
            $q=$pdo->prepare("SELECT COUNT(*) FROM depositos d WHERE d.id_banco=? AND regexp_replace(d.numero_cuenta,'[^0-9]','','g')=? AND d.moneda=? AND d.fecha_deposito>=CAST(? AS date) AND d.fecha_deposito<CAST(? AS date)+INTERVAL '1 day' AND d.estado<>0 AND NOT EXISTS (SELECT 1 FROM conciliacion_vinculos v WHERE v.deposito_id=d.id AND v.estado IN ('APROBADO','CERRADO'))");
            $q->execute([$e['ID_BANCO'],conciliacionCuenta($e['NUMERO_CUENTA']),$e['MONEDA'],$e['PERIODO_DESDE'],$e['PERIODO_HASTA']]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('Hay depósitos de esta cuenta y período pendientes de aprobar o conciliar.');
            $q=$pdo->prepare('SELECT * FROM transacciones_estado_cuenta WHERE id_estado_cienta=? ORDER BY fecha,hora NULLS LAST,id FOR UPDATE'); $q->execute([$id]); $ms=$q->fetchAll();
            if (!$ms) throw new RuntimeException('El estado no contiene movimientos.');
            $depositosResumen=[];
            foreach ($ms as $m) {
                $q=$pdo->prepare("SELECT * FROM conciliacion_vinculos WHERE movimiento_id=? AND estado='APROBADO'"); $q->execute([$m['ID']]); $v=$q->fetch();
                if ($v) {
                    $datos=conciliacionDatos($pdo,(int)$v['DEPOSITO_ID'],(int)$m['ID']);
                    if (!hash_equals($v['HUELLA'],conciliacionHuella($datos))) throw new RuntimeException('Un vínculo aprobado cambió. No se puede cerrar.');
                    $depositosResumen[]=$datos[0];
                } else {
                    $q=$pdo->prepare('SELECT huella FROM conciliacion_partidas WHERE movimiento_id=?'); $q->execute([$m['ID']]); $h=$q->fetchColumn();
                    if (!$h || !hash_equals($h,conciliacionHuella($m))) throw new RuntimeException('Hay movimientos pendientes de aprobación o justificación.');
                }
            }
            // PostgreSQL NUMERIC performs the balance validation exactly.
            $q=$pdo->prepare('SELECT e.saldo_final-(e.saldo_inicial+SUM(t.credito-t.debito)) diferencia FROM estados_cuenta e JOIN transacciones_estado_cuenta t ON t.id_estado_cienta=e.id WHERE e.id=? GROUP BY e.id'); $q->execute([$id]); $diferencia=(string)$q->fetchColumn();
            $q=$pdo->prepare('SELECT COALESCE(SUM(importe),0) importe,COUNT(*) cantidad FROM conciliacion_ajustes WHERE estado_cuenta_id=?'); $q->execute([$id]); $totalAjustes=$q->fetch();
            if(!$forzado && (conciliacionDecimal($diferencia)!=='0.00' || (int)$totalAjustes['CANTIDAD'])) throw new RuntimeException('Los saldos no cuadran o existen ajustes. Requiere revisión y, si corresponde, cierre excepcional autorizado.');
            if($forzado && conciliacionDecimal($diferencia)!==conciliacionDecimal($totalAjustes['IMPORTE'])) throw new RuntimeException('La suma de ajustes documentados debe coincidir exactamente con la diferencia de saldo.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM (SELECT balance,credito,debito,LAG(balance,1,CAST(? AS numeric)) OVER (ORDER BY fecha,hora NULLS LAST,id) anterior FROM transacciones_estado_cuenta WHERE id_estado_cienta=?) s WHERE balance IS NULL OR anterior+credito-debito<>balance'); $q->execute([$e['SALDO_INICIAL'],$id]);
            $balancesInconsistentes=(int)$q->fetchColumn();
            if ($balancesInconsistentes>0 && !$forzado) throw new RuntimeException('Los balances consecutivos de los movimientos no cuadran.');
            $q=$pdo->prepare('SELECT * FROM conciliacion_ajustes WHERE estado_cuenta_id=? ORDER BY id'); $q->execute([$id]); $ajustesResumen=$q->fetchAll();
            $q=$pdo->prepare('SELECT v.* FROM conciliacion_vinculos v JOIN transacciones_estado_cuenta t ON t.id=v.movimiento_id WHERE t.id_estado_cienta=?'); $q->execute([$id]); $vs=$q->fetchAll();
            $q=$pdo->prepare('SELECT p.* FROM conciliacion_partidas p JOIN transacciones_estado_cuenta t ON t.id=p.movimiento_id WHERE t.id_estado_cienta=?'); $q->execute([$id]); $ps=$q->fetchAll();
            $q=$pdo->prepare('SELECT descripcion FROM bancos WHERE id=?'); $q->execute([$e['ID_BANCO']]); $estadoResumen=$e; $estadoResumen['BANCO']=$q->fetchColumn()?:'';
            $ids=[$usuario]; foreach($vs as $v) { $ids[]=(int)$v['CREADO_POR']; if($v['REVISADO_POR']) $ids[]=(int)$v['REVISADO_POR']; } foreach($ps as $p) $ids[]=(int)$p['USUARIO_ID'];
            foreach($ajustesResumen as $ajuste) $ids[]=(int)$ajuste['USUARIO_ID'];
            $ids=array_unique($ids); $q=$pdo->prepare('SELECT id,nombre_completo FROM usuarios WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')'); $q->execute(array_values($ids)); $personas=$q->fetchAll();
            $q=$pdo->prepare('INSERT INTO conciliacion_cierres(estado_cuenta_id,usuario_id,resumen) VALUES (?,?,CAST(? AS jsonb))'); $q->execute([$id,$usuario,json_encode(conciliacionSerializable(['estado'=>$estadoResumen,'movimientos'=>$ms,'vinculos'=>$vs,'partidas'=>$ps,'depositos'=>$depositosResumen,'usuarios'=>$personas,'periodo'=>$periodo['MES'],'ajustes'=>$ajustesResumen,'excepcional'=>$forzado,'motivo_excepcional'=>$forzado?$motivo:null,'diferencia_original'=>$diferencia,'total_ajustes'=>$totalAjustes['IMPORTE'],'balances_inconsistentes'=>$balancesInconsistentes]),JSON_THROW_ON_ERROR)]);
            conciliacionAuditar($pdo,$usuario,$accion,$forzado?$motivo:'Cierre con saldos verificados.',['estado'=>$id,'periodo'=>$periodo['MES'],'diferencia'=>$diferencia,'ajustes'=>$totalAjustes['IMPORTE'],'balances_inconsistentes'=>$balancesInconsistentes]);
            $q=$pdo->prepare("UPDATE conciliacion_vinculos SET estado='CERRADO' WHERE movimiento_id IN (SELECT id FROM transacciones_estado_cuenta WHERE id_estado_cienta=?) AND estado='APROBADO'"); $q->execute([$id]);
        } else throw new RuntimeException('Acción inválida.');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
