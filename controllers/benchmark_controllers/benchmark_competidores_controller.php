<?php
// controllers/benchmark_controllers/benchmark_competidores_controller.php
header('Content-Type: application/json');

// Incluimos la conexión usando la ruta absoluta basada en __DIR__
require_once __DIR__ . '/../../connection/connection.php';

<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../connection/connection.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'listar') {
        $stmt = $pdo->query("SELECT id, nombre, COALESCE(ubicacion_referencia, 'N/A') AS ubicacion_referencia, estado FROM benchmark_competidores ORDER BY id DESC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'guardar') {
        $id = $_POST['id'] ?? '';
        $nombre = trim($_POST['nombre'] ?? '');
        $ubicacion = trim($_POST['ubicacion_referencia'] ?? '');
        $estado = $_POST['estado'] ?? 'activo';

        if (empty($nombre)) {
            echo json_encode(['status' => 'error', 'message' => 'El nombre es obligatorio.']);
            exit;
        }

        if (empty($id)) {
            // Insertar
            $stmt = $pdo->prepare("INSERT INTO benchmark_competidores (nombre, ubicacion_referencia, estado) VALUES (?, ?, ?)");
            $stmt->execute([$nombre, $ubicacion, $estado]);
            $msg = 'Competidor registrado correctamente.';
        } else {
            // Actualizar
            $stmt = $pdo->prepare("UPDATE benchmark_competidores SET nombre = ?, ubicacion_referencia = ?, estado = ? WHERE id = ?");
            $stmt->execute([$nombre, $ubicacion, $estado, $id]);
            $msg = 'Competidor actualizado correctamente.';
        }

        echo json_encode(['status' => 'success', 'message' => $msg]);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
    exit;
}