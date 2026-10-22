<?php
// Explicit CLI maintenance operation. A new backup file is mandatory.
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
$ruta=$argv[1]??'';
if($ruta==='' || !is_dir(dirname($ruta)) || file_exists($ruta)) throw new RuntimeException('Indicá una ruta nueva de respaldo en un directorio existente.');
$tablas=['conciliacion_vinculos','depositos_bitacora','depositos_detalle','depositos_correlativos','depositos','resumen_estado_cuenta','transacciones_estado_cuenta','estados_cuenta'];
$reiniciarDemostracion=in_array('--reiniciar-demostracion',$argv,true);
$soloRespaldar=in_array('--solo-respaldar',$argv,true);
if($reiniciarDemostracion || $soloRespaldar) $tablas=array_merge(['conciliacion_cierres','conciliacion_partidas','conciliacion_ajustes','conciliacion_autorizaciones'],$tablas);
function respaldoValor($valor) {
    if(is_resource($valor)) { rewind($valor); return ['__tipo'=>'bytea_base64','contenido'=>base64_encode(stream_get_contents($valor))]; }
    return $valor;
}
$pdo=database(); $pdo->beginTransaction();
try {
    $pdo->exec("SET LOCAL lock_timeout='10s'");
    $pdo->query('SELECT pg_advisory_xact_lock(20261014)');
    $pdo->exec('LOCK TABLE '.implode(',',array_unique(array_merge($tablas,['conciliacion_cierres','conciliacion_partidas','conciliacion_ajustes','conciliacion_autorizaciones']))).' IN SHARE ROW EXCLUSIVE MODE');
    if(!$reiniciarDemostracion && !$soloRespaldar) foreach(['conciliacion_cierres','conciliacion_partidas','conciliacion_ajustes','conciliacion_autorizaciones'] as $dependiente) if((int)$pdo->query('SELECT COUNT(*) FROM '.$dependiente)->fetchColumn()!==0) throw new RuntimeException('La tabla '.$dependiente.' contiene datos. No se borrará información fuera de la lista autorizada.');
    $respaldo=['fecha'=>(new DateTimeImmutable('now',new DateTimeZone('America/Tegucigalpa')))->format(DATE_ATOM),'tipo'=>'respaldo_filas_postgresql','tablas'=>[]];
    foreach($tablas as $tabla) {
        $filas=$pdo->query('SELECT * FROM '.$tabla)->fetchAll();
        foreach($filas as &$fila) foreach($fila as &$valor) $valor=respaldoValor($valor);
        unset($fila,$valor); $respaldo['tablas'][$tabla]=$filas;
    }
    $datos=gzencode(json_encode($respaldo,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),9);
    if($datos===false) throw new RuntimeException('No se pudo comprimir el respaldo.');
    $archivo=fopen($ruta,'xb'); if(!$archivo) throw new RuntimeException('No se pudo crear el respaldo.');
    try { if(fwrite($archivo,$datos)!==strlen($datos) || !fflush($archivo)) throw new RuntimeException('No se pudo completar el respaldo.'); } finally { fclose($archivo); }
    $verificacion=json_decode(gzdecode(file_get_contents($ruta)),true,512,JSON_THROW_ON_ERROR);
    foreach($tablas as $tabla) if(count($verificacion['tablas'][$tabla])!==count($respaldo['tablas'][$tabla])) throw new RuntimeException('El respaldo no pasó la verificación.');
    if($soloRespaldar) { $pdo->rollBack(); echo 'Respaldo verificado, sin modificar datos: '.$ruta."\n"; exit; }
    foreach($tablas as $tabla) $pdo->exec('DELETE FROM '.$tabla);
    foreach($tablas as $tabla) if((int)$pdo->query('SELECT COUNT(*) FROM '.$tabla)->fetchColumn()!==0) throw new RuntimeException('No se pudo vaciar '.$tabla);
    if($reiniciarDemostracion) {
        require_once __DIR__.'/../includes/conciliacion_periodos.php';
        $propietario=(int)$pdo->query('SELECT propietario_id FROM conciliacion_control WHERE id=1')->fetchColumn();
        conciliacionAuditar($pdo,$propietario,'REINICIAR_DEMOSTRACION','Reinicio de depósitos, estados y sus relaciones solicitado por el usuario para una nueva demostración.', ['origen'=>'CLI mantenimiento','respaldo'=>$ruta,'filas_eliminadas'=>array_map('count',$respaldo['tablas'])]);
    }
    $pdo->commit();
    foreach($tablas as $tabla) echo $tabla.': 0 filas (eliminadas '.count($respaldo['tablas'][$tabla]).")\n";
    echo 'Respaldo: '.$ruta."\n";
} catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
