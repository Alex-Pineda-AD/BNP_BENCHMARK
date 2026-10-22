<?php
session_start();
require_once __DIR__ . '/../../connection/connection.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = trim($_POST['id'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $ubicacion = trim($_POST['ubicacion_referencia'] ?? '');
    $estado = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;

    if (empty($nombre)) {
        $_SESSION['competidor_resultado'] = [
            'tipo' => 'danger',
            'mensaje' => 'El nombre del competidor es obligatorio.'
        ];
        header('Location: ../../modules/benchmark/benchmark_competidores.php');
        exit;
    }

    try {
        if (empty($id)) {
            // INSERTAR NUEVO
            $stmt = $pdo->prepare('INSERT INTO benchmark_competidores (nombre, descripcion, estado) VALUES (?, ?, ?)');
            $stmt->execute([$nombre, $ubicacion, $estado]);
            $mensaje = '¡Competidor registrado correctamente!';
        } else {
            // ACTUALIZAR / EDITAR
            $stmt = $pdo->prepare('UPDATE benchmark_competidores SET nombre = ?, descripcion = ?, estado = ? WHERE id = ?');
            $stmt->execute([$nombre, $ubicacion, $estado, (int)$id]);
            $mensaje = '¡Competidor actualizado correctamente!';
        }

        $_SESSION['competidor_resultado'] = [
            'tipo' => 'success',
            'mensaje' => $mensaje
        ];
    } catch (PDOException $e) {
        $_SESSION['competidor_resultado'] = [
            'tipo' => 'danger',
            'mensaje' => 'Error en la base de datos: ' . $e->getMessage()
        ];
    }

    header('Location: ../../modules/benchmark/benchmark_competidores.php');
    exit;
}