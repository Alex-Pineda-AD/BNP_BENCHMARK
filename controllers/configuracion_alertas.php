<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../includes/alertas_depositos.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); exit('Debes iniciar sesión.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../modules/conciliacion/configuracion_alertas.php'); exit; }
try {
    if (empty($_SESSION['alertas_token']) || !is_string($_POST['token'] ?? null) || !hash_equals($_SESSION['alertas_token'], $_POST['token'])) throw new InvalidArgumentException('Recargá el formulario e intentá nuevamente.');
    switch ($_POST['bande'] ?? '') {
        case '1':
            guardarAlerta(database(), $_POST, (int) $_SESSION['user_id']);
            $_SESSION['alerta_mensaje'] = 'Configuración de alerta guardada.';
            $_SESSION['alerta_tipo'] = 'success';
            break;
        default:
            throw new InvalidArgumentException('La acción no es válida.');
    }
} catch (Throwable $e) {
    if (!($e instanceof InvalidArgumentException)) error_log($e->getMessage());
    $_SESSION['alerta_mensaje'] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo guardar la configuración.';
    $_SESSION['alerta_tipo'] = 'danger';
}
header('Location: ../modules/conciliacion/configuracion_alertas.php');
exit;
