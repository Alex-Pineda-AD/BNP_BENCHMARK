<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/postgresql_helpers.php';
require_once __DIR__ . '/../includes/deposito_service.php';

function verificarCodigo($condicion, $mensaje) {
    if (!$condicion) throw new RuntimeException($mensaje);
}
$pdo = database();
// Las tablas temporales evitan modificar depositos y contadores reales.
foreach (['depositos', 'depositos_detalle', 'depositos_correlativos'] as $tabla) {
    clonarTablaTemporal($pdo, $tabla);
}
$pdo->exec('CREATE TEMPORARY TABLE usuarios (ID BIGINT, ESTADO INT)');
$pdo->exec('CREATE TEMPORARY TABLE bancos (ID BIGINT PRIMARY KEY)');
$pdo->exec('INSERT INTO bancos VALUES (1),(2)');
$pdo->exec('CREATE TEMPORARY TABLE centro_de_venta (ID BIGINT)');
$pdo->exec('CREATE TEMPORARY TABLE usuarios_centros_venta (ID_USUARIO BIGINT, ID_CENTRO_VENTA BIGINT, ESTADO INT)');
$pdo->exec('INSERT INTO usuarios VALUES (1,1)');
$pdo->exec('INSERT INTO centro_de_venta VALUES (1)');
$pdo->exec('INSERT INTO usuarios_centros_venta VALUES (1,1,1)');
$datos = validarDeposito(['NUMERO_CUENTA'=>'123', 'NUMERO_REFERENCIA'=>'',
    'REMITENTE'=>'Prueba', 'BENEFICIARIO'=>'Prueba', 'COMENTARIO'=>'Prueba',
    'MONTO'=>'10.50', 'MONEDA'=>'HNL', 'ID_BANCO'=>'1', 'ID_CENTRO_VENTA'=>'1', 'CONFIRMADO'=>'1']);
$datos['FECHA_DEPOSITO'] = '2026-09-29T14:35';
$datos['FECHA_CREO'] = '2026-09-30 10:00:00';
$pdo->beginTransaction();
foreach ([1, 2] as $numero) {
    $id = insertarDeposito($pdo, $datos, 1, 'prueba.jpg', 'prueba.jpg');
    $consulta = $pdo->prepare('SELECT CODIGO FROM depositos WHERE ID = ?');
    $consulta->execute([$id]);
    verificarCodigo($pdo->query('SELECT FECHA_DEPOSITO FROM depositos WHERE ID = ' . (int) $id)->fetchColumn() === '2026-09-29 14:35:00', 'Fecha del comprobante incorrecta');
    verificarCodigo($consulta->fetchColumn() === 'BNP-VTA-2026-000000' . $numero, 'Codigo incorrecto');
}
verificarCodigo(generarCodigoDeposito($pdo, '2027-01-01 00:00:00') === 'BNP-VTA-2027-0000001', 'No reinicia por anio');
$conReferencia = array_replace($datos, ['NUMERO_REFERENCIA' => ' Doc-543 ']);
insertarDeposito($pdo, $conReferencia, 1, 'referencia.jpg', 'referencia.jpg');
$cantidad = $pdo->query('SELECT COUNT(*) FROM depositos')->fetchColumn();
$detalles = $pdo->query('SELECT COUNT(*) FROM depositos_detalle')->fetchColumn();
$ultimo = $pdo->query('SELECT ULTIMO FROM depositos_correlativos WHERE ANIO = 2026')->fetchColumn();
try {
    insertarDeposito($pdo, array_replace($conReferencia, ['NUMERO_REFERENCIA' => 'doc-543']), 1, 'duplicado.jpg', 'duplicado.jpg');
    throw new RuntimeException('Se acepto una referencia duplicada');
} catch (InvalidArgumentException $e) {
    verificarCodigo(strpos($e->getMessage(), 'ya está registrado') !== false, 'Error inesperado');
}
verificarCodigo($cantidad == $pdo->query('SELECT COUNT(*) FROM depositos')->fetchColumn(), 'Se inserto un duplicado');
verificarCodigo($detalles == $pdo->query('SELECT COUNT(*) FROM depositos_detalle')->fetchColumn(), 'Se inserto detalle duplicado');
verificarCodigo($ultimo == $pdo->query('SELECT ULTIMO FROM depositos_correlativos WHERE ANIO = 2026')->fetchColumn(), 'Duplicado consumio correlativo');
insertarDeposito($pdo, array_replace($conReferencia, ['ID_BANCO' => 2]), 1, 'otro-banco.jpg', 'otro-banco.jpg');
$pdo->rollBack();
verificarCodigo((int)$pdo->query('SELECT COUNT(*) FROM depositos')->fetchColumn() === 0, 'Rollback del deposito');
verificarCodigo((int)$pdo->query('SELECT COUNT(*) FROM depositos_correlativos')->fetchColumn() === 0, 'Rollback del contador');

// Una foto que no puede registrarse tampoco consume el numero.
$pdo->exec('DROP TABLE pg_temp.depositos_detalle');
$pdo->exec('CREATE TEMPORARY TABLE depositos_detalle (ID_DEPOSITO BIGINT)');
$pdo->beginTransaction();
try {
    insertarDeposito($pdo, $datos, 1, 'fallo.jpg', 'fallo.jpg');
    throw new RuntimeException('Se esperaba un error en el detalle');
} catch (PDOException $e) {
    $pdo->rollBack();
}
$pdo->beginTransaction();
verificarCodigo(generarCodigoDeposito($pdo, $datos['FECHA_CREO']) === 'BNP-VTA-2026-0000001', 'Se consumio un numero tras el fallo');
$pdo->rollBack();
echo "OK: codigo, secuencia, cambio de anio, referencias duplicadas, otro banco, sin referencia y rollback.\n";
