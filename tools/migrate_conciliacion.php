<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
$pdo=database();
$pdo->beginTransaction();
try {
    $pdo->exec(file_get_contents(__DIR__.'/../migrations/postgresql/20261014_conciliacion.sql'));
    $pdo->commit();
    echo "Tablas de conciliación disponibles.\n";
} catch(Throwable $e) { $pdo->rollBack(); throw $e; }
