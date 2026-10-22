<?php
require_once __DIR__.'/../connection/database.php';
$pdo=database();
$u=$pdo->query('SELECT id,id_rol FROM usuarios WHERE estado=1 AND id_rol IN (2,4,5) ORDER BY id LIMIT 1')->fetch();
if(!$u) throw new RuntimeException('Se requiere un usuario revisor activo.');
session_start(); $_SESSION=['user_id'=>$u['ID'],'id_rol'=>$u['ID_ROL']];
$_SERVER['SCRIPT_NAME']='/modules/conciliacion/cierres_conciliacion.php'; $_GET=[];
ob_start(); require __DIR__.'/../modules/conciliacion/cierres_conciliacion.php'; $html=ob_get_clean(); session_destroy();
if(strpos($html,'No se pudieron consultar')!==false || strpos($html,'Fatal error')!==false || strpos($html,'Períodos cerrados')===false) throw new RuntimeException('Falló la carga del historial.');
echo "OK: historial de cierres y filtros cargados con consultas reales.\n";
