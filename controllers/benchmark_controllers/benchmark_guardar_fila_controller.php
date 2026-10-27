<?php
session_start();

// Evita que warnings/notices de PHP contaminen la respuesta JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../connection/connection.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión expirada o no autorizada.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'No se recibieron datos válidos.']);
    exit;
}

$id_usuario           = $_SESSION['user_id'];
$id_competidor        = trim((string)($data['id_competidor'] ?? ''));
$observaciones_visita = trim((string)($data['observaciones_visita'] ?? ''));
$id_producto          = trim((string)($data['id_producto'] ?? ''));
$precio_banasupro     = (float)($data['precio_banasupro'] ?? 0);
$precio_competencia   = trim((string)($data['precio_competencia'] ?? ''));
$observaciones_item   = trim((string)($data['observaciones_item'] ?? ''));
$actualizar           = !empty($data['actualizar']); // true solo si el usuario confirmó la actualización
$fecha_hoy            = date('Y-m-d');

if ($id_competidor === '' || $id_producto === '' || $precio_competencia === '' || !is_numeric($precio_competencia) || (float)$precio_competencia <= 0) {
    echo json_encode(['success' => false, 'message' => 'Faltan datos obligatorios o el precio de la competencia no es válido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Buscar cabecera de levantamiento del día para este competidor y usuario
    $stmtCheck = $pdo->prepare("
        SELECT id FROM benchmark_levantamientos
        WHERE id_competidor = ? AND fecha_levantamiento = ? AND id_usuario = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmtCheck->execute([$id_competidor, $fecha_hoy, $id_usuario]);
    $id_levantamiento = $stmtCheck->fetchColumn();

    if (!$id_levantamiento) {
        $stmtL = $pdo->prepare('
            INSERT INTO benchmark_levantamientos (id_usuario, id_competidor, fecha_levantamiento, observaciones)
            VALUES (?, ?, ?, ?) RETURNING id
        ');
        $stmtL->execute([$id_usuario, $id_competidor, $fecha_hoy, $observaciones_visita]);
        $id_levantamiento = $stmtL->fetchColumn();
    }

    // 2. ¿Ya existe un precio de este producto en esta visita?
    $stmtExist = $pdo->prepare("
        SELECT id, precio_competencia FROM benchmark_precios_detalle
        WHERE id_levantamiento = ? AND id_producto = ? LIMIT 1
    ");
    $stmtExist->execute([$id_levantamiento, (int)$id_producto]);
    $detalle = $stmtExist->fetch(PDO::FETCH_ASSOC);

    if ($detalle && !$actualizar) {
        // Existe y el usuario aún no confirmó: no se toca nada, se le avisa al cliente
        $pdo->rollBack();
        echo json_encode([
            'success'       => false,
            'existe'        => true,
            'precio_actual' => (float)($detalle['precio_competencia'] ?? $detalle['PRECIO_COMPETENCIA']),
            'message'       => 'Ya existe un precio para este artículo con este competidor.'
        ]);
        exit;
    }

    // Actualizar observaciones generales de la visita si se escribieron
    if ($observaciones_visita !== '') {
        $stmtUpd = $pdo->prepare("UPDATE benchmark_levantamientos SET observaciones = ? WHERE id = ?");
        $stmtUpd->execute([$observaciones_visita, $id_levantamiento]);
    }

    if ($detalle) {
        $idDetalle = $detalle['id'] ?? $detalle['ID'];
        $stmtUpdDet = $pdo->prepare("
            UPDATE benchmark_precios_detalle
            SET precio_banasupro_referencia = ?, precio_competencia = ?, observaciones = ?
            WHERE id = ?
        ");
        $stmtUpdDet->execute([$precio_banasupro, (float)$precio_competencia, $observaciones_item, $idDetalle]);
    } else {
        $stmtProd = $pdo->prepare("SELECT nombre_producto FROM benchmark_productos WHERE id = ? LIMIT 1");
        $stmtProd->execute([(int)$id_producto]);
        $nombreProducto = $stmtProd->fetchColumn() ?: 'Producto General';

        $stmtInsDet = $pdo->prepare("
            INSERT INTO benchmark_precios_detalle
            (id_levantamiento, id_producto, precio_banasupro_referencia, nombre_producto_competencia, precio_competencia, observaciones)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtInsDet->execute([
            $id_levantamiento,
            (int)$id_producto,
            $precio_banasupro,
            $nombreProducto,
            (float)$precio_competencia,
            $observaciones_item
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success'          => true,
        'actualizado'      => (bool)$detalle,
        'message'          => $detalle ? 'Precio actualizado correctamente' : 'Precio guardado correctamente',
        'id_levantamiento' => $id_levantamiento
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}