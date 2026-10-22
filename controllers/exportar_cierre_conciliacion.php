<?php
session_start();
require_once __DIR__.'/../connection/database.php';
require_once __DIR__.'/../includes/conciliacion_service.php';
require_once __DIR__.'/../includes/conciliacion_reporte.php';
require_once __DIR__.'/../includes/conciliacion_excel.php';
header('Cache-Control: no-store');
if(empty($_SESSION['user_id'])) { http_response_code(401); exit('Iniciá sesión para exportar.'); }
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id || $id<1) { http_response_code(400); exit('Cierre inválido.'); }
$ruta=null;
try {
    $pdo=database();
    try { conciliacionUsuario($pdo,(int)$_SESSION['user_id']); } catch(RuntimeException $e) { http_response_code(403); exit('No tenés permiso para exportar cierres.'); }
    $reporte=conciliacionCierre($pdo,$id); $ruta=conciliacionExcel($reporte);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="conciliacion-cierre-'.$id.'.xlsx"');
    header('Content-Length: '.filesize($ruta)); readfile($ruta);
} catch(Throwable $e) { error_log($e->getMessage()); http_response_code(500); echo 'No se pudo exportar el cierre. Verificá que exista e intentá nuevamente.'; }
finally { if($ruta && is_file($ruta)) unlink($ruta); }
