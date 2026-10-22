<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/../../connection/database.php';
require_once __DIR__.'/../../includes/conciliacion_service.php';
if (empty($_SESSION['user_id'])) { header('Location: ../../index.html?sesion=requerida'); exit; }
$pdo=database();
try { $rolActual=conciliacionUsuario($pdo,(int)$_SESSION['user_id']); } catch (Throwable $e) { http_response_code(403); exit('Acceso restringido.'); }
function ce($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$_SESSION['cruce_csrf']=$_SESSION['cruce_csrf']??bin2hex(random_bytes(32));
$error=''; $mensaje=$_SESSION['cruce_mensaje']??''; unset($_SESSION['cruce_mensaje']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['cruce_csrf'],$_POST['csrf'])) throw new RuntimeException('La sesión del formulario venció.');
        conciliacionAccion($pdo,(int)$_SESSION['user_id'],$_POST);
        $_SESSION['cruce_mensaje']=($_POST['accion']??'')==='solicitar_correccion'
            ? 'Depósito devuelto para corrección. El analista lo encontrará en Validar depósitos → Por corregir. Se requiere una nueva aprobación antes del cruce.'
            : 'Operación guardada correctamente.';
        $destino=in_array($_POST['accion']??'', ['aprobar','devolver'],true)?'prevalidacion':(in_array($_POST['accion']??'',['cerrar','cerrar_forzado','registrar_ajuste'],true)?'cierre':(($_POST['accion']??'')==='partida'?'movimientos':'cruce'));
        header('Location: cruce_depositos.php?estado_cuenta='.(int)($_POST['estado_cuenta']??0).'&vista='.$destino); exit;
    } catch (Throwable $e) { error_log($e->getMessage()); $error=$e instanceof PDOException?'No se pudo guardar. Verificá que el registro no esté vinculado, justificado o cerrado.':$e->getMessage(); }
}
$estados=$depositos=$movimientos=$vinculos=$cierres=[]; $seleccion=null;
$eid=max(0,(int)($_GET['estado_cuenta']??$_POST['estado_cuenta']??0));
$did=max(0,(int)($_POST['deposito']??$_GET['deposito']??0));
try {
    $estados=$pdo->query('SELECT e.*,b.descripcion banco FROM estados_cuenta e LEFT JOIN bancos b ON b.id=e.id_banco ORDER BY e.id DESC')->fetchAll();
    if (!$eid && $estados) $eid=(int)$estados[0]['ID'];
    foreach ($estados as $e) if ((int)$e['ID']===$eid) $seleccion=$e;
    if ($seleccion) {
        $q=$pdo->prepare("SELECT d.* FROM depositos d WHERE d.id_banco=? AND d.estado IN (5,8) AND d.fecha_deposito>=CAST(? AS date) AND d.fecha_deposito<CAST(? AS date)+INTERVAL '1 day' AND NOT EXISTS (SELECT 1 FROM conciliacion_vinculos v WHERE v.deposito_id=d.id AND v.estado<>'DEVUELTO') ORDER BY d.fecha_deposito DESC,d.id DESC"); $q->execute([$seleccion['ID_BANCO'],$seleccion['PERIODO_DESDE']??'1000-01-01',$seleccion['PERIODO_HASTA']??'9999-12-30']); $depositos=$q->fetchAll();
        $q=$pdo->prepare("SELECT t.*,v.estado vinculo_estado,v.deposito_id,p.motivo partida FROM transacciones_estado_cuenta t LEFT JOIN conciliacion_vinculos v ON v.movimiento_id=t.id AND v.estado<>'DEVUELTO' LEFT JOIN conciliacion_partidas p ON p.movimiento_id=t.id WHERE t.id_estado_cienta=? ORDER BY t.fecha,t.hora NULLS LAST,t.id"); $q->execute([$eid]); $movimientos=$q->fetchAll();
        $q=$pdo->prepare('SELECT v.*,d.codigo,d.numero_cuenta,d.moneda,(SELECT dd.url FROM depositos_detalle dd WHERE dd.id_deposito=d.id ORDER BY dd.id_deposito_detalle DESC LIMIT 1) foto_url,d.numero_referencia,d.monto,d.fecha_deposito,t.referencia,t.fecha,t.hora,t.credito,t.debito,t.balance,u.nombre_completo revisor FROM conciliacion_vinculos v JOIN depositos d ON d.id=v.deposito_id JOIN transacciones_estado_cuenta t ON t.id=v.movimiento_id JOIN usuarios u ON u.id=v.creado_por WHERE t.id_estado_cienta=? ORDER BY v.id DESC'); $q->execute([$eid]); $vinculos=$q->fetchAll();
    }
    $cierres=$pdo->query('SELECT c.*,u.nombre_completo FROM conciliacion_cierres c JOIN usuarios u ON u.id=c.usuario_id ORDER BY c.id DESC')->fetchAll();
} catch (Throwable $e) { error_log($e->getMessage()); $error='No se pudo cargar el flujo de conciliación. Verificá que la migración 20261014_conciliacion.sql esté aplicada.'; }
$dep=null; foreach ($depositos as $d) if ((int)$d['ID']===$did) $dep=$d;
if (!$dep && $depositos) { $dep=$depositos[0]; $did=(int)$dep['ID']; }
$cerrado=false; foreach ($cierres as $c) if ((int)$c['ESTADO_CUENTA_ID']===$eid) $cerrado=true;
$supervisor=in_array($rolActual,[4,5],true);
$periodoActual=conciliacionPeriodo($pdo);
$periodoHabilitado=$seleccion && conciliacionEnPeriodo($periodoActual,$seleccion);
$cerradoReal=$cerrado;
$cerrado=$cerradoReal || !$periodoHabilitado;
$puedeExcepcion=$periodoHabilitado && !$cerradoReal && conciliacionPuedeAjustar($pdo,(int)$_SESSION['user_id'],$eid);
function camposCruce(int $eid): void { echo '<input type="hidden" name="csrf" value="'.ce($_SESSION['cruce_csrf']).'"><input type="hidden" name="estado_cuenta" value="'.$eid.'">'; }
$vista=$_GET['vista']??'cruce';
if (!in_array($vista,['cruce','prevalidacion','movimientos','cierre'],true)) $vista='cruce';
$candidatos=[]; $elegido=null;
$diagnostico=['referencia'=>0,'monto'=>0,'ocupados'=>0,'creditos'=>0];
$depositosPorAprobar=0;
if($seleccion) {
    $q=$pdo->prepare("SELECT COUNT(DISTINCT d.id) FROM depositos d JOIN transacciones_estado_cuenta t ON (t.credito=d.monto AND t.debito=0 OR (NULLIF(TRIM(d.numero_referencia),'') IS NOT NULL AND UPPER(TRIM(d.numero_referencia))=UPPER(TRIM(t.referencia)))) WHERE t.id_estado_cienta=? AND d.id_banco=? AND d.estado IN (2,4,7)");
    $q->execute([$eid,$seleccion['ID_BANCO']]); $depositosPorAprobar=(int)$q->fetchColumn();
}
$mid=max(0,(int)($_POST['movimiento']??$_GET['movimiento']??0));
$pendientes=0; $porAprobar=0;
foreach($vinculos as $v) if($v['ESTADO']==='PREVALIDADO') $porAprobar++;
foreach($movimientos as $m) {
    if(conciliacionDecimal($m['CREDITO'])!=='0.00') $diagnostico['creditos']++;
    if($dep) {
        $comparacion=conciliacionCandidato($dep,$m,$seleccion);
        $diagnostico['referencia']+=(int)$comparacion['referencia'];
        $diagnostico['monto']+=(int)$comparacion['monto'];
        if(($comparacion['referencia'] || $comparacion['monto']) && ($m['VINCULO_ESTADO'] || $m['PARTIDA'])) $diagnostico['ocupados']++;
    }
    if(!$m['VINCULO_ESTADO'] && !$m['PARTIDA']) $pendientes++;
}
$candidatos=$dep?conciliacionCandidatos($dep,$movimientos,$seleccion,$mid):[];
foreach($candidatos as $m) if((int)$m['ID']===$mid) $elegido=$m;
if(!$elegido && $candidatos) $elegido=$candidatos[0];
$foto=null;
if($dep) { $q=$pdo->prepare('SELECT url FROM depositos_detalle WHERE id_deposito=? ORDER BY id_deposito_detalle DESC LIMIT 1'); $q->execute([$did]); $url=$q->fetchColumn(); if(is_string($url) && preg_match('~^(?:\.\./\.\./)?files/depositos/[a-zA-Z0-9_.-]+$~D',$url)) $foto=strpos($url,'../../')===0?$url:'../../'.$url; }
function cruceUrl(int $eid,string $vista='cruce',int $did=0,int $mid=0): string { return 'cruce_depositos.php?'.http_build_query(['estado_cuenta'=>$eid,'vista'=>$vista,'deposito'=>$did,'movimiento'=>$mid]); }
define('CONCILIACION_VISTA', true);
require __DIR__.'/../../includes/conciliacion_vista.php';
