<?php
session_start();

// Evita que warnings/notices de PHP contaminen la respuesta JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// php.ini de XAMPP trae Europe/Berlin; la fecha del levantamiento debe ser la de Honduras
date_default_timezone_set('America/Tegucigalpa');

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';
require_once __DIR__ . '/../../includes/benchmark_auditoria.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión expirada o no autorizada.']);
    exit;
}

if (!benchmark_tiene_acceso()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tenés permiso para registrar precios.']);
    exit;
}

// Solo POST JSON con el token de la página (bloquea envíos desde otros sitios)
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0
    || !benchmark_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Solicitud no válida. Recargá la página e intentá de nuevo.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'No se recibieron datos válidos.']);
    exit;
}

$id_usuario           = (int)$_SESSION['user_id'];
$id_competidor        = filter_var($data['id_competidor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$id_producto          = filter_var($data['id_producto'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$observaciones_visita = mb_substr(trim((string)($data['observaciones_visita'] ?? '')), 0, 500);
$precio_competencia   = trim((string)($data['precio_competencia'] ?? ''));
$observaciones_item   = mb_substr(trim((string)($data['observaciones_item'] ?? '')), 0, 500);
$actualizar           = !empty($data['actualizar']); // true solo si el usuario confirmó la actualización
$fecha_hoy            = date('Y-m-d');

// numeric(10,2): máximo 99,999,999.99
if ($id_competidor === false || $id_producto === false || !is_numeric($precio_competencia)
    || (float)$precio_competencia <= 0 || (float)$precio_competencia >= 100000000) {
    echo json_encode(['success' => false, 'message' => 'Faltan datos obligatorios o el precio de la competencia no es válido.']);
    exit;
}
$precio_competencia = round((float)$precio_competencia, 2);

try {
    // El competidor debe existir y estar activo
    $stmtComp = $pdo->prepare("SELECT 1 FROM benchmark.benchmark_competidores WHERE id = ? AND estado = 1");
    $stmtComp->execute([$id_competidor]);
    if (!$stmtComp->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'El competidor no existe o está inactivo.']);
        exit;
    }

    // El precio propio se toma de la base, no del navegador
    $stmtPrecio = $pdo->prepare("SELECT precio_referencia FROM benchmark.benchmark_productos WHERE id = ? AND estado = 1");
    $stmtPrecio->execute([$id_producto]);
    $precio_banasupro = $stmtPrecio->fetchColumn();
    if ($precio_banasupro === false) {
        echo json_encode(['success' => false, 'message' => 'El producto no existe o está inactivo.']);
        exit;
    }

    $pdo->beginTransaction();
    benchmark_auditoria_contexto($pdo); // auditoría: quién guarda el precio

    // 1. Buscar cabecera de levantamiento del día para este competidor y usuario
    $stmtCheck = $pdo->prepare("
        SELECT id FROM benchmark.benchmark_levantamientos
        WHERE id_competidor = ? AND fecha_levantamiento = ? AND id_usuario = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmtCheck->execute([$id_competidor, $fecha_hoy, $id_usuario]);
    $id_levantamiento = $stmtCheck->fetchColumn();

    if (!$id_levantamiento) {
        $stmtL = $pdo->prepare('
            INSERT INTO benchmark.benchmark_levantamientos (id_usuario, id_competidor, fecha_levantamiento, observaciones)
            VALUES (?, ?, ?, ?) RETURNING id
        ');
        $stmtL->execute([$id_usuario, $id_competidor, $fecha_hoy, $observaciones_visita]);
        $id_levantamiento = $stmtL->fetchColumn();
    }

    // 2. ¿Ya existe un precio de este producto en esta visita?
    $stmtExist = $pdo->prepare("
        SELECT id, precio_competencia FROM benchmark.benchmark_precios_detalle
        WHERE id_levantamiento = ? AND id_producto = ? LIMIT 1
    ");
    $stmtExist->execute([$id_levantamiento, $id_producto]);
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
        $stmtUpd = $pdo->prepare("UPDATE benchmark.benchmark_levantamientos SET observaciones = ? WHERE id = ?");
        $stmtUpd->execute([$observaciones_visita, $id_levantamiento]);
    }

    if ($detalle) {
        $idDetalle = $detalle['id'] ?? $detalle['ID'];
        $stmtUpdDet = $pdo->prepare("
            UPDATE benchmark.benchmark_precios_detalle
            SET precio_banasupro_referencia = ?, precio_competencia = ?, observaciones = ?
            WHERE id = ?
        ");
        $stmtUpdDet->execute([$precio_banasupro, $precio_competencia, $observaciones_item, $idDetalle]);
    } else {
        $stmtInsDet = $pdo->prepare("
            INSERT INTO benchmark.benchmark_precios_detalle
            (id_levantamiento, id_producto, precio_banasupro_referencia, precio_competencia, observaciones)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtInsDet->execute([
            $id_levantamiento,
            $id_producto,
            $precio_banasupro,
            $precio_competencia,
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
    error_log('[benchmark_guardar_fila] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se pudo guardar el precio. Intentá de nuevo.']);
}