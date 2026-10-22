<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../includes/deposito_editar.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); exit('Debes iniciar sesión.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../modules/conciliacion/mis_depositos.php'); exit; }
$destino = null;
try {
    if (empty($_SESSION['depositos_csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['depositos_csrf'], $_POST['csrf'])) {
        throw new InvalidArgumentException('La sesión del formulario venció. Recargá la pantalla.');
    }
    $pdo = database();
    switch ($_POST['bande'] ?? '') {
        case '2':
            $id = filter_var($_POST['idDeposito'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new InvalidArgumentException('El depósito no es válido.');
            $datos = validarDeposito($_POST);
            $nombre = null; $url = null;
            $archivo = $_FILES['foto'] ?? ['error' => UPLOAD_ERR_NO_FILE];
            if ($archivo['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) throw new InvalidArgumentException('No se pudo recibir la foto.');
                if ($archivo['size'] > 8 * 1024 * 1024) throw new InvalidArgumentException('La foto supera los 8 MB.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
                $extensiones = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
                $tamano = @getimagesize($archivo['tmp_name']);
                if (!isset($extensiones[$mime]) || !$tamano || $tamano[0] < 250 || $tamano[1] < 250 || $tamano[0] * $tamano[1] > 25000000) {
                    throw new InvalidArgumentException('Usá JPG, PNG o WebP de al menos 250 × 250 píxeles y hasta 25 megapíxeles.');
                }
                $nombre = bin2hex(random_bytes(20)) . '.' . $extensiones[$mime];
                $url = 'files/depositos/' . $nombre;
                $destino = __DIR__ . '/../' . $url;
            }
            $pdo->beginTransaction();
            editarDeposito($pdo, $id, $datos, (int) $_SESSION['user_id'], $nombre, $url);
            if ($destino && !move_uploaded_file($archivo['tmp_name'], $destino)) throw new RuntimeException('No se pudo guardar la foto.');
            $pdo->commit();
            $_SESSION['deposito_mensaje'] = 'Depósito actualizado. Quedó pendiente de revisión.';
            $_SESSION['deposito_tipo'] = 'success';
            break;
        default:
            throw new InvalidArgumentException('La acción no es válida.');
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($destino && is_file($destino)) unlink($destino);
    if (!($e instanceof InvalidArgumentException)) error_log($e->getMessage());
    $_SESSION['deposito_mensaje'] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudieron guardar los cambios. Intentá nuevamente.';
    $_SESSION['deposito_tipo'] = 'danger';
}
header('Location: ../modules/conciliacion/mis_depositos.php');
exit;
