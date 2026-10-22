<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/postgresql_helpers.php';
require_once __DIR__ . '/../includes/guardar_estado_cuenta.php';
require_once __DIR__ . '/../includes/lectores/comun.php';
$pdo = database();
foreach (['estados_cuenta', 'transacciones_estado_cuenta', 'resumen_estado_cuenta'] as $tabla) {
    clonarTablaTemporal($pdo, $tabla);
}
$datos = nuevoEstadoBancario();
$datos['cuenta'] = array_replace($datos['cuenta'], [
    'NOMBRE' => 'Prueba', 'MONEDA' => 'HNL', 'NUMERO_CUENTA' => '123',
]);
$datos['transacciones'][] = movimientoBancario('2026-10-14', 'TEST', null, 'Prueba', '0.00', '12.34', '12.34');
$datos['resumen'][] = array_replace(resumenBancario(), ['CONCEPTO' => 'Créditos', 'CANTIDAD' => 1, 'MONTO' => '12.34']);
$original = "Prueba\r\n\x00\xff\\archivo";
$id = guardarEstadoCuenta($pdo, 1, 1, 'prueba.txt', $original, $datos);
$archivo = $pdo->query('SELECT ARCHIVO_ORIGINAL FROM estados_cuenta')->fetchColumn();
if (is_resource($archivo)) $archivo = stream_get_contents($archivo);
if ($archivo !== $original) throw new RuntimeException('Se alteraron los bytes originales.');
if ((string) $pdo->query('SELECT ID_ESTADO_CIENTA FROM transacciones_estado_cuenta')->fetchColumn() !== $id) {
    throw new RuntimeException('Movimiento sin vínculo con el encabezado.');
}
try {
    guardarEstadoCuenta($pdo, 1, 1, 'duplicado.txt', $original, $datos);
    throw new RuntimeException('No se rechazó el duplicado.');
} catch (PDOException $e) {
    if ($e->getCode() !== '23505') throw $e;
}
$datos['transacciones'][0]['FECHA'] = 'fecha inválida';
try {
    guardarEstadoCuenta($pdo, 1, 1, 'fallo.txt', 'otro original', $datos);
    throw new RuntimeException('Se aceptó una fecha inválida.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) throw new RuntimeException('Transacción abierta tras error.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM estados_cuenta')->fetchColumn() !== 1) {
    throw new RuntimeException('Quedó un encabezado huérfano.');
}
echo "OK: estado, movimientos, resumen, bytes originales, duplicados y rollback en PostgreSQL temporal.\n";
