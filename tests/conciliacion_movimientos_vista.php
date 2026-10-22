<?php
define('CONCILIACION_VISTA',true);
function ce($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function cruceUrl(int $e,string $v='cruce',int $d=0,int $m=0): string { return '?estado_cuenta='.$e.'&vista='.$v.'&deposito='.$d.'&movimiento='.$m; }
function camposCruce(int $e): void { echo '<input name="csrf" value="prueba" type="hidden">'; }
$eid=5; $did=0; $dep=null; $cerrado=$cerradoReal=false; $porAprobar=2;
$movimientos=[['ID'=>1,'FECHA'=>'2026-09-01','HORA'=>'09:03:07','REFERENCIA'=>'REF','DESCRIPCION'=>'Prueba','DEBITO'=>'0.00','CREDITO'=>'100.00','BALANCE'=>'100.00','VINCULO_ESTADO'=>null,'PARTIDA'=>null]];
foreach([false,true] as $supervisor) {
    ob_start(); require __DIR__.'/../includes/conciliacion_movimientos_vista.php'; $html=ob_get_clean();
    if(strpos($html,'Pendiente de resolver')===false || strpos($html,'Ver acciones')===false) throw new RuntimeException('Columna vacía para un movimiento pendiente.');
    if((strpos($html,'value="partida"')!==false)!==$supervisor) throw new RuntimeException('Formulario de justificación con permisos incorrectos.');
}
$cerrado=true;
ob_start(); require __DIR__.'/../includes/conciliacion_movimientos_vista.php'; $html=ob_get_clean();
if(strpos($html,'período está bloqueado')===false || strpos($html,'value="partida"')!==false) throw new RuntimeException('Acciones habilitadas en período bloqueado.');
echo "OK: estado visible, acciones para analista/supervisor y bloqueo por período.\n";
