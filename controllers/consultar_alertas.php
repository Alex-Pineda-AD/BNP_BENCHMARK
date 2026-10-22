<?php
session_start();
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../includes/alertas_depositos.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); exit; }
$pantallaAlertas = $_GET['pantalla'] ?? '';
if (!in_array($pantallaAlertas, ['DEPOSITOS','VALIDACION'], true)) { http_response_code(400); exit; }
try {
    $pdo = database();
    if (!rolAlertasDepositos($pdo, $pantallaAlertas, (int) $_SESSION['user_id'])) { http_response_code(403); exit; }
    function esc($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    include __DIR__ . '/../includes/panel_alertas_depositos.php';
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
}
