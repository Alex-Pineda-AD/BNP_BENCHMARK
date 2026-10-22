<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
$pdo=database();
$tablas=['conciliacion_vinculos','depositos','depositos_bitacora','depositos_correlativos','depositos_detalle','estados_cuenta','resumen_estado_cuenta','transacciones_estado_cuenta','conciliacion_partidas','conciliacion_cierres'];
foreach($tablas as $tabla) echo $tabla.': '.$pdo->query('SELECT COUNT(*) FROM '.$tabla)->fetchColumn()."\n";
$q=$pdo->query("SELECT conrelid::regclass::text tabla,confrelid::regclass::text referencia,pg_get_constraintdef(oid) definicion FROM pg_constraint WHERE contype='f' AND confrelid IN ('depositos'::regclass,'estados_cuenta'::regclass,'transacciones_estado_cuenta'::regclass,'conciliacion_vinculos'::regclass) ORDER BY 1");
echo json_encode($q->fetchAll(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
