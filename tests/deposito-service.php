<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/postgresql_helpers.php';
require_once __DIR__ . '/../includes/deposito_service.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$input = ['NUMERO_CUENTA' => '754231111', 'NUMERO_REFERENCIA' => 'TEST-REFERENCE', 'REMITENTE' => 'Remitente prueba', 'BENEFICIARIO' => 'Beneficiario prueba', 'COMENTARIO' => 'Prueba automática', 'MONTO' => '200.57', 'MONEDA' => 'HNL', 'ID_BANCO' => '1', 'CONFIRMADO' => '1'];
$input['NUMERO_REFERENCIA'] = '99' . random_int(100000000, 999999999);
$input['ID_CENTRO_VENTA'] = '5001';
$data = validarDeposito($input);
$data['FECHA_DEPOSITO'] = '2026-10-14T10:00';
$before = new DateTimeImmutable('now', new DateTimeZone('America/Tegucigalpa'));
$withFakeDate = validarDeposito($input + ['FECHA' => '2001-01-01', 'HORA' => '00:00', 'FECHA_HORA' => '2001-01-01 00:00:00']);
$after = new DateTimeImmutable('now', new DateTimeZone('America/Tegucigalpa'));
check($withFakeDate['FECHA_CREO'] >= $before->format('Y-m-d H:i:s') && $withFakeDate['FECHA_CREO'] <= $after->format('Y-m-d H:i:s'), 'No se utilizó la fecha del servidor');

foreach (['MONTO' => '200.579', 'CONFIRMADO' => '0', 'MONEDA' => 'XYZ', 'ID_CENTRO_VENTA' => ''] as $field => $value) {
    try { validarDeposito(array_replace($input, [$field => $value])); throw new RuntimeException("Se aceptó $field inválido"); }
    catch (InvalidArgumentException $expected) {}
}
// El modo manual exige todos los campos y no requiere texto OCR.
$manual = $input + ['TIPO_CAPTURA' => 'manual'];
validarDeposito($manual);

foreach (array_diff(array_keys($input), ['NUMERO_REFERENCIA']) as $field) {
    foreach (['', '   '] as $empty) {
        try { validarDeposito(array_replace($manual, [$field => $empty])); throw new RuntimeException("Manual aceptó $field vacío"); }
        catch (InvalidArgumentException $expected) {}
    }
}
foreach (['inventado', [], null] as $type) {
    if ($type === null) continue; // Ausente usa OCR por compatibilidad.
    try { validarDeposito(array_replace($manual, ['TIPO_CAPTURA' => $type])); throw new RuntimeException('Tipo inválido aceptado'); }
    catch (InvalidArgumentException $expected) {}
}
// También se permite completar un impreso manualmente cuando falla el OCR.
validarDeposito($input + ['TIPO_CAPTURA' => 'ocr']);
validarDeposito($input + ['TIPO_CAPTURA' => 'ocr', 'ocr_confidence' => 0, 'ocr_text' => '']);
validarDeposito(array_replace($manual, ['NUMERO_CUENTA' => '4421949/010102347700', 'NUMERO_REFERENCIA' => 'TT26194C1KZK']));

$pdo = database();
$pdo->exec('CREATE TEMPORARY TABLE usuarios (ID DOUBLE PRECISION, ESTADO INT)');
$pdo->exec('CREATE TEMPORARY TABLE centro_de_venta (ID DOUBLE PRECISION, CENTRO_VENTA TEXT)');
$pdo->exec('CREATE TEMPORARY TABLE usuarios_centros_venta (ID_USUARIO DOUBLE PRECISION, ID_CENTRO_VENTA DOUBLE PRECISION, ESTADO INT)');
$pdo->exec('INSERT INTO usuarios VALUES (1,1),(2,1),(3,0)');
$pdo->exec("INSERT INTO centro_de_venta VALUES (5001,'Centro propio'),(5002,'Centro inactivo'),(5003,'Centro ajeno')");
$pdo->exec('INSERT INTO usuarios_centros_venta VALUES (1,5001,1),(1,5001,1),(1,5002,0),(2,5003,1),(3,5001,1)');
check(count(centrosVentaUsuario($pdo, 1)) === 1, 'La lista incluyó centros ajenos, inactivos o duplicados');
check(centrosVentaUsuario($pdo, 3) === [], 'Usuario inactivo obtuvo centros');
check(centrosVentaUsuario($pdo, 999) === [], 'Usuario inexistente obtuvo centros');
foreach ([[1,5002],[1,5003],[1,9999],[3,5001]] as [$userId, $centerId]) {
    try { validarCentroVentaUsuario($pdo, $userId, $centerId); throw new RuntimeException('Se autorizó un centro inválido'); }
    catch (InvalidArgumentException $expected) {}
}
// Temporary tables shadow the real ones: tests cannot leave test deposits in production.
foreach (['depositos', 'depositos_detalle', 'depositos_correlativos'] as $table) {
    clonarTablaTemporal($pdo, $table);
}
$pdo->exec('CREATE TEMPORARY TABLE bancos (ID BIGINT PRIMARY KEY)');
$pdo->exec('INSERT INTO bancos VALUES (1)');
$pdo->beginTransaction();
$id = insertarDeposito($pdo, $data, 1, 'prueba.jpg', 'files/depositos/prueba.jpg');
$row = $pdo->query('SELECT * FROM depositos')->fetch();
check($row['FECHA_CREO'] === $data['FECHA_CREO'], 'Fecha del encabezado incorrecta');
check($pdo->query('SELECT FECHA_CREO FROM depositos_detalle')->fetchColumn() === $data['FECHA_CREO'], 'Fecha del detalle incorrecta');
check($row['MONTO'] === '200.57', 'Los centavos no se conservaron');
check((int)$row['ID_CENTRO_VENTA'] === 5001, 'No se guardó el centro seleccionado');
check($row['REMITENTE'] === $input['REMITENTE'], 'No se guardó remitente');
check((string)$pdo->query('SELECT ID_DEPOSITO FROM depositos_detalle')->fetchColumn() === $id, 'Detalle sin vínculo');
try { insertarDeposito($pdo, $data, 1, 'otra.jpg', 'otra.jpg'); throw new RuntimeException('Se permitió un duplicado'); }
catch (InvalidArgumentException $expected) { check(strpos($expected->getMessage(), 'ya está registrado') !== false, 'Error distinto de duplicado'); }
$pdo->rollBack();
check((int)$pdo->query('SELECT COUNT(*) FROM depositos')->fetchColumn() === 0, 'Rollback incompleto');
check((int)$pdo->query('SELECT COUNT(*) FROM depositos_detalle')->fetchColumn() === 0, 'Rollback de detalle incompleto');
// Force detail insert failure and confirm that no header survives the rollback.
$pdo->exec('DROP TABLE pg_temp.depositos_detalle');
$pdo->exec('CREATE TEMPORARY TABLE depositos_detalle (ID_DEPOSITO INT)');
$pdo->beginTransaction();
try { insertarDeposito($pdo, $data, 1, 'fallo.jpg', 'fallo.jpg'); throw new RuntimeException('Faltó error de detalle'); }
catch (PDOException $expected) { $pdo->rollBack(); }
check((int)$pdo->query('SELECT COUNT(*) FROM depositos')->fetchColumn() === 0, 'Encabezado huérfano');
echo "OK: validación, centavos, relación, duplicados y rollback ante error del detalle.\n";
