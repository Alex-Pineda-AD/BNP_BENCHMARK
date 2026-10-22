<?php
// Read-only smoke check using an existing active reviewer; no session cookie is issued.
require_once __DIR__.'/../connection/database.php';
$pdo=database();
$u=$pdo->query('SELECT id,id_rol FROM usuarios WHERE estado=1 AND id_rol IN (4,5) ORDER BY id LIMIT 1')->fetch();
if(!$u) { fwrite(STDERR,"Se requiere un supervisor activo para verificar la vista.\n"); exit(1); }
session_start();
$_SESSION=['user_id'=>$u['ID'],'id_rol'=>$u['ID_ROL']];
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/modules/conciliacion/cruce_depositos.php';
$_GET['estado_cuenta']=(int)$pdo->query('SELECT id FROM estados_cuenta ORDER BY id DESC LIMIT 1')->fetchColumn();
$q=$pdo->prepare("SELECT d.id FROM depositos d JOIN estados_cuenta e ON e.id_banco=d.id_banco WHERE e.id=? AND d.estado IN (5,8) AND NOT EXISTS (SELECT 1 FROM conciliacion_vinculos v WHERE v.deposito_id=d.id AND v.estado<>'DEVUELTO') ORDER BY d.id DESC LIMIT 1");
$q->execute([$_GET['estado_cuenta']]);
$_GET['deposito']=(int)$q->fetchColumn();
$_GET['vista']=$argv[1]??'cruce';
ob_start();
require __DIR__.'/../modules/conciliacion/cruce_depositos.php';
$html=ob_get_clean();
session_destroy();
if(strpos($html,'No se pudo cargar') !== false || strpos($html,'Fatal error') !== false || strpos($html,'Cruce de depósitos') === false) { fwrite(STDERR,"Falló la carga de la pantalla.\n"); exit(1); }
echo "OK: pantalla renderizada con sesión de supervisor y consultas reales.\n";
