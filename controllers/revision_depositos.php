<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../includes/deposito_revision.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); exit('Debes iniciar sesión.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../modules/conciliacion/depositos_revision.php'); exit; }
try {
    if (empty($_SESSION['depositos_csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['depositos_csrf'], $_POST['csrf'])) {
        throw new InvalidArgumentException('La sesión del formulario venció. Recargá la pantalla.');
    }
    switch ($_POST['bande'] ?? '') {
        case '3':
            $id = filter_var($_POST['idDeposito'] ?? null, FILTER_VALIDATE_INT);
            $estado = filter_var($_POST['estado'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1 || !in_array($estado, [5,7,8], true)) throw new InvalidArgumentException('El depósito y el estado son obligatorios.');
            if (($_POST['CONFIRMADO'] ?? '') !== '1') throw new InvalidArgumentException('Confirmá que comparaste la foto con los datos.');
            $observacion = $_POST['observacion'] ?? '';
            $version = $_POST['version'] ?? '';
            if (!is_string($observacion) || !is_string($version)) throw new InvalidArgumentException('El formulario no es válido.');
            $pdo = database();
            $pdo->beginTransaction();
            revisarDeposito($pdo, $id, $estado, $observacion, (int) $_SESSION['user_id'], $version, $_POST);
            $pdo->commit();
            $_SESSION['deposito_mensaje'] = $estado !== 7 ? 'Depósito aprobado. Los cambios y la revisión quedaron registrados en bitácora.' : 'Se solicitó corregir el depósito. Los cambios y el motivo quedaron registrados en bitácora.';
            $_SESSION['deposito_tipo'] = 'success';
            break;
        default:
            throw new InvalidArgumentException('La acción no es válida.');
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!($e instanceof InvalidArgumentException)) error_log($e->getMessage());
    $_SESSION['deposito_mensaje'] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo guardar la revisión. Intentá nuevamente.';
    $_SESSION['deposito_tipo'] = 'danger';
}
header('Location: ../modules/conciliacion/depositos_revision.php');
exit;
