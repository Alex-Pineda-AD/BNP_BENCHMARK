<?php
session_start();
require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

benchmark_requerir_pagina();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Token del formulario: bloquea envíos desde otros sitios
    if (!benchmark_csrf_valido($_POST['csrf_token'] ?? null)) {
        $_SESSION['competidor_resultado'] = [
            'tipo' => 'danger',
            'mensaje' => 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.'
        ];
        header('Location: ../../modules/benchmark/benchmark_competidores.php');
        exit;
    }

    $id = trim($_POST['id'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $ubicacion = trim($_POST['ubicacion_referencia'] ?? '');
    $estado = filter_var($_POST['estado'] ?? 1, FILTER_VALIDATE_INT);

    $error = null;
    if ($nombre === '') {
        $error = 'El nombre del competidor es obligatorio.';
    } elseif (mb_strlen($nombre) > 100) {
        $error = 'El nombre no puede pasar de 100 caracteres.';
    } elseif (mb_strlen($ubicacion) > 500) {
        $error = 'La ubicación no puede pasar de 500 caracteres.';
    } elseif (!in_array($estado, [0, 1], true)) {
        $error = 'El estado no es válido.';
    } elseif ($id !== '' && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        $error = 'El competidor a editar no es válido.';
    }

    if ($error) {
        $_SESSION['competidor_resultado'] = [
            'tipo' => 'danger',
            'mensaje' => $error
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
        error_log('[benchmark_competidores] ' . $e->getMessage());
        $_SESSION['competidor_resultado'] = [
            'tipo' => 'danger',
            'mensaje' => 'No se pudo guardar el competidor. Intentá de nuevo.'
        ];
    }

    header('Location: ../../modules/benchmark/benchmark_competidores.php');
    exit;
}