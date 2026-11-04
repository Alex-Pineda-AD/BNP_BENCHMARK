<?php
session_start();

// Sincroniza los precios con Softland en segundo plano: la pantalla de levantamientos lo pide por AJAX
// cuando los datos guardados tienen más de BENCHMARK_SYNC_TTL segundos. Solo lectura sobre Softland.
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Tegucigalpa');

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';
require_once __DIR__ . '/../../includes/benchmark_sync_softland.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

if (!benchmark_tiene_acceso()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !benchmark_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Solicitud no válida.']);
    exit;
}

// No retener la sesión mientras dura la sincronización (otras pantallas del mismo usuario seguirían esperando)
session_write_close();
ignore_user_abort(true);
set_time_limit(180);

try {
    $r = benchmark_sincronizar_si_toca($pdo, 'sync_automatica', BENCHMARK_SYNC_TTL, false);
    echo json_encode([
        'success'     => true,
        'actualizado' => !$r['desde_cache'],
        'hora'        => date('H:i', $r['ts']),
    ]);
} catch (Throwable $e) {
    error_log('[benchmark_sincronizar_ajax] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se pudo consultar Softland.']);
}
