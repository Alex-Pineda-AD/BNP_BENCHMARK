<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
$pdo=database(); $pdo->beginTransaction();
try {
    $pdo->query('SELECT pg_advisory_xact_lock(20261014)');
    $pdo->exec(file_get_contents(__DIR__.'/../migrations/postgresql/20261019_periodos_control.sql'));
    $propietario=$pdo->query('SELECT u.usuario FROM conciliacion_control c JOIN usuarios u ON u.id=c.propietario_id')->fetchColumn();
    if(!$propietario) throw new RuntimeException('Se requiere un Superusuario activo (rol 5) para instalar el control.');
    $pdo->commit(); echo 'Propietario exclusivo: '.$propietario."\n";
    echo 'Mes abierto: '.$pdo->query("SELECT mes FROM conciliacion_periodos WHERE estado='ABIERTO'")->fetchColumn()."\n";
} catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
