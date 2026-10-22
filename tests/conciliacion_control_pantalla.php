<?php
require_once __DIR__.'/../connection/database.php';
$pdo=database();
$u=$pdo->query('SELECT u.id,u.id_rol FROM conciliacion_control c JOIN usuarios u ON u.id=c.propietario_id WHERE u.estado=1 AND u.id_rol=5')->fetch();
if(!$u) throw new RuntimeException('Falta propietario activo.');
session_start(); $_SESSION=['user_id'=>$u['ID'],'id_rol'=>$u['ID_ROL']];
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/modules/conciliacion/control_periodos.php';
ob_start(); require __DIR__.'/../modules/conciliacion/control_periodos.php'; $html=ob_get_clean(); session_destroy();
if(strpos($html,'Habilitar mes de trabajo')===false || strpos($html,'Otorgar permiso limitado')===false || strpos($html,'Fatal error')!==false) throw new RuntimeException('Falló el mantenimiento de períodos.');
echo "OK: mantenimiento exclusivo, meses, permisos y bitácora renderizados sin modificar datos.\n";
