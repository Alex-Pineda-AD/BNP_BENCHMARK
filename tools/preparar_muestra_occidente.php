<?php
// Explicitly authorized test-data maintenance, never exposed as an HTTP action.
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
require_once __DIR__.'/../includes/conciliacion_service.php';
$ruta=$argv[1]??'';
if(!$ruta || !is_dir(dirname($ruta)) || file_exists($ruta)) throw new RuntimeException('Se requiere un archivo nuevo de respaldo.');
$p=database(); $p->beginTransaction();
try {
    $p->exec("SET LOCAL lock_timeout='10s'"); $p->query('SELECT pg_advisory_xact_lock(20261014)');
    $tablas=['conciliacion_vinculos','conciliacion_partidas','conciliacion_cierres','conciliacion_ajustes','conciliacion_autorizaciones','depositos_bitacora','depositos_detalle','depositos','resumen_estado_cuenta','transacciones_estado_cuenta','estados_cuenta','conciliacion_control_bitacora'];
    $p->exec('LOCK TABLE '.implode(',',$tablas).' IN SHARE ROW EXCLUSIVE MODE');
    $b=$p->query("SELECT id FROM bancos WHERE UPPER(descripcion) LIKE '%OCCIDENTE%'")->fetchAll(PDO::FETCH_COLUMN);
    if(count($b)!==1) throw new RuntimeException('No se pudo identificar un único banco Occidente.'); $banco=(int)$b[0];
    $q=$p->prepare("SELECT d.id deposito_id,t.id movimiento_id,e.id estado_id FROM depositos d JOIN estados_cuenta e ON e.id_banco=d.id_banco AND e.moneda=d.moneda AND regexp_replace(e.numero_cuenta,'[^0-9]','','g')=regexp_replace(d.numero_cuenta,'[^0-9]','','g') JOIN transacciones_estado_cuenta t ON t.id_estado_cienta=e.id AND TRIM(t.referencia)=TRIM(d.numero_referencia) AND t.credito=d.monto AND t.debito=0 AND t.fecha=d.fecha_deposito::date WHERE d.id_banco=? AND d.numero_referencia IN ('61831725','62741429') ORDER BY d.id"); $q->execute([$banco]); $pares=$q->fetchAll();
    if(count($pares)!==2 || count(array_unique(array_column($pares,'ESTADO_ID')))!==1 || count(array_unique(array_column($pares,'DEPOSITO_ID')))!==2) throw new RuntimeException('Se requieren exactamente los dos pares coincidentes en un mismo estado.');
    $estado=(int)$pares[0]['ESTADO_ID']; $depositos=array_map('intval',array_column($pares,'DEPOSITO_ID')); $movimientos=array_map('intval',array_column($pares,'MOVIMIENTO_ID'));
    $consultaEstados='SELECT id FROM estados_cuenta WHERE id_banco='.$banco;
    if((int)$p->query('SELECT COUNT(*) FROM conciliacion_cierres WHERE estado_cuenta_id IN ('.$consultaEstados.')')->fetchColumn()) throw new RuntimeException('Occidente tiene cierres. No se alterará una conciliación cerrada.');
    if((int)$p->query('SELECT COUNT(*) FROM conciliacion_ajustes WHERE estado_cuenta_id IN ('.$consultaEstados.')')->fetchColumn()) throw new RuntimeException('Hay ajustes registrados. No se eliminarán silenciosamente.');
    $q=$p->prepare('SELECT * FROM estados_cuenta WHERE id=?'); $q->execute([$estado]); $e=$q->fetch();
    conciliacionExigirPeriodo($p,$e);
    $vinculos=$p->query('SELECT * FROM conciliacion_vinculos WHERE deposito_id IN ('.implode(',',$depositos).") AND estado<>'DEVUELTO'")->fetchAll();
    foreach($vinculos as $v) {
        if($v['ESTADO']!=='PREVALIDADO') throw new RuntimeException('El vínculo ya fue aprobado o cerrado. Revisar antes de preparar la muestra.');
        if(!in_array((int)$v['MOVIMIENTO_ID'],$movimientos,true)) throw new RuntimeException('Uno de los depósitos tiene otro vínculo activo.');
        if(!hash_equals($v['HUELLA'],conciliacionHuella(conciliacionDatos($p,(int)$v['DEPOSITO_ID'],(int)$v['MOVIMIENTO_ID'])))) throw new RuntimeException('Un vínculo ya tenía datos modificados antes de preparar la muestra.');
    }
    $respaldo=['fecha'=>(new DateTimeImmutable('now',new DateTimeZone('America/Tegucigalpa')))->format(DATE_ATOM),'operacion'=>'MUESTRA DE PRUEBA OCCIDENTE: conservar dos pares y recalcular saldos','banco_id'=>$banco,'pares'=>$pares,'tablas'=>[]];
    foreach($tablas as $tabla) {
        $filas=$p->query('SELECT * FROM '.$tabla)->fetchAll();
        foreach($filas as &$fila) foreach($fila as &$valor) if(is_resource($valor)) { rewind($valor); $valor=['__tipo'=>'bytea_base64','contenido'=>base64_encode(stream_get_contents($valor))]; }
        unset($fila,$valor); $respaldo['tablas'][$tabla]=$filas;
    }
    $contenido=gzencode(json_encode($respaldo,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),9); if($contenido===false) throw new RuntimeException('No se pudo comprimir el respaldo.');
    $f=fopen($ruta,'xb'); if(!$f) throw new RuntimeException('No se pudo crear el respaldo.');
    try { if(fwrite($f,$contenido)!==strlen($contenido) || !fflush($f)) throw new RuntimeException('Respaldo incompleto.'); } finally { fclose($f); }
    $verificar=json_decode(gzdecode(file_get_contents($ruta)),true,512,JSON_THROW_ON_ERROR);
    foreach($tablas as $tabla) if(count($verificar['tablas'][$tabla])!==count($respaldo['tablas'][$tabla])) throw new RuntimeException('Respaldo no verificable.');
    $ds=implode(',',$depositos); $ms=implode(',',$movimientos);
    $movEliminar='SELECT t.id FROM transacciones_estado_cuenta t JOIN estados_cuenta e ON e.id=t.id_estado_cienta WHERE e.id_banco='.$banco.' AND t.id NOT IN ('.$ms.')';
    $depEliminar='SELECT id FROM depositos WHERE id_banco='.$banco.' AND id NOT IN ('.$ds.')';
    $eliminados=[];
    $eliminados['vinculos']=$p->exec('DELETE FROM conciliacion_vinculos WHERE movimiento_id IN ('.$movEliminar.') OR deposito_id IN ('.$depEliminar.')');
    $eliminados['partidas']=$p->exec('DELETE FROM conciliacion_partidas WHERE movimiento_id IN ('.$movEliminar.')');
    $eliminados['bitacora_depositos']=$p->exec('DELETE FROM depositos_bitacora WHERE id_deposito IN ('.$depEliminar.')');
    $eliminados['comprobantes']=$p->exec('DELETE FROM depositos_detalle WHERE id_deposito IN ('.$depEliminar.')');
    $eliminados['depositos']=$p->exec('DELETE FROM depositos WHERE id IN ('.$depEliminar.')');
    $eliminados['movimientos']=$p->exec('DELETE FROM transacciones_estado_cuenta WHERE id IN ('.$movEliminar.')');
    $eliminados['resumenes']=$p->exec('DELETE FROM resumen_estado_cuenta WHERE id_estado_cuenta IN ('.$consultaEstados.')');
    $p->exec('DELETE FROM conciliacion_autorizaciones WHERE estado_cuenta_id IN ('.$consultaEstados.') AND estado_cuenta_id<>'.$estado);
    $eliminados['estados']=$p->exec('DELETE FROM estados_cuenta WHERE id_banco='.$banco.' AND id<>'.$estado);
    $q=$p->prepare("WITH saldos AS (SELECT t.id,e.saldo_inicial+SUM(t.credito-t.debito) OVER (ORDER BY t.fecha,t.hora NULLS LAST,t.id) nuevo_balance FROM transacciones_estado_cuenta t JOIN estados_cuenta e ON e.id=t.id_estado_cienta WHERE e.id=?) UPDATE transacciones_estado_cuenta t SET balance=s.nuevo_balance FROM saldos s WHERE t.id=s.id"); $q->execute([$estado]);
    $q=$p->prepare("UPDATE estados_cuenta e SET saldo_final=e.saldo_inicial+(SELECT SUM(t.credito-t.debito) FROM transacciones_estado_cuenta t WHERE t.id_estado_cienta=e.id),nombre_archivo=LEFT('PRUEBA_2_MOVIMIENTOS_'||e.nombre_archivo,255) WHERE e.id=?"); $q->execute([$estado]);
    $q=$p->prepare("INSERT INTO resumen_estado_cuenta(id_estado_cuenta,codigo,cantidad_debitos,monto_debitos,cantidad_creditos,monto_creditos,concepto,cantidad,monto) SELECT ?,'PRUEBA',COUNT(*) FILTER(WHERE debito>0),SUM(debito),COUNT(*) FILTER(WHERE credito>0),SUM(credito),'MUESTRA DE PRUEBA: DOS COINCIDENCIAS',COUNT(*),SUM(credito-debito) FROM transacciones_estado_cuenta WHERE id_estado_cienta=?"); $q->execute([$estado,$estado]);
    foreach($vinculos as $v) {
        $huella=conciliacionHuella(conciliacionDatos($p,(int)$v['DEPOSITO_ID'],(int)$v['MOVIMIENTO_ID']));
        $q=$p->prepare("UPDATE conciliacion_vinculos SET huella=?,observacion=observacion||CAST(? AS text) WHERE id=?");
        $q->execute([$huella,"\n[MUESTRA DE PRUEBA: estado reducido a dos movimientos y balances recalculados por solicitud del usuario; respaldo y cambio registrados en bitácora.]",$v['ID']]);
    }
    $q=$p->prepare('SELECT saldo_inicial,saldo_final,(SELECT SUM(credito) FROM transacciones_estado_cuenta WHERE id_estado_cienta=e.id) creditos,(SELECT COUNT(*) FROM transacciones_estado_cuenta WHERE id_estado_cienta=e.id) movimientos FROM estados_cuenta e WHERE id=?'); $q->execute([$estado]); $resultado=$q->fetch();
    $q=$p->prepare('SELECT COUNT(*) FROM (SELECT balance,credito,debito,LAG(balance,1,CAST(? AS numeric)) OVER(ORDER BY fecha,hora NULLS LAST,id) anterior FROM transacciones_estado_cuenta WHERE id_estado_cienta=?) s WHERE anterior+credito-debito<>balance'); $q->execute([$resultado['SALDO_INICIAL'],$estado]);
    if((int)$q->fetchColumn() || (int)$resultado['MOVIMIENTOS']!==2) throw new RuntimeException('La muestra no pasó la verificación de balances.');
    foreach($vinculos as $v) { $q=$p->prepare('SELECT huella FROM conciliacion_vinculos WHERE id=?'); $q->execute([$v['ID']]); if(!hash_equals($q->fetchColumn(),conciliacionHuella(conciliacionDatos($p,(int)$v['DEPOSITO_ID'],(int)$v['MOVIMIENTO_ID'])))) throw new RuntimeException('La prevalidación de muestra no pasó la verificación.'); }
    $owner=(int)$p->query('SELECT propietario_id FROM conciliacion_control WHERE id=1')->fetchColumn();
    conciliacionAuditar($p,$owner,'PREPARAR_MUESTRA_PRUEBA','Solicitud del usuario: conservar los dos pares coincidentes de Occidente y recalcular saldos para probar el cierre.', ['pares'=>$pares,'eliminados'=>$eliminados,'saldo_inicial_original'=>$e['SALDO_INICIAL'],'saldo_final_original'=>$e['SALDO_FINAL'],'resultado'=>$resultado,'respaldo'=>$ruta,'archivo_original_conservado'=>true]);
    $p->commit();
    echo json_encode(['muestra'=>$resultado,'eliminados'=>$eliminados,'prevalidaciones_conservadas'=>count($vinculos),'respaldo'=>$ruta],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) { if($p->inTransaction()) $p->rollBack(); throw $e; }
