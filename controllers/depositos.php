<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../includes/deposito_service.php';
function respond(int $status, array $body): void { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); respond(405, ['message' => 'Método no permitido.']); }
if (empty($_SESSION['user_id'])) respond(401, ['message' => 'Iniciá sesión para guardar el depósito.']);
if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['depositos_csrf'] ?? '', $_POST['csrf']) || empty($_SESSION['depositos_csrf'])) respond(403, ['message' => 'La sesión expiró. Recargá la página.']);
$destination = null;
try {

    $tipoCaptura = validarTipoCaptura($_POST);
    $textoOcr = $_POST['ocr_text'] ?? '';
    if (!is_string($textoOcr) || strlen($textoOcr) > 60000) throw new InvalidArgumentException('El texto leído supera el límite permitido.');
    $pdo = database();
    $user = $pdo->prepare('SELECT ID FROM usuarios WHERE ID = ? AND ESTADO = 1');
    $user->execute([$_SESSION['user_id']]);
    if (!$user->fetchColumn()) respond(401, ['message' => 'El usuario no está activo. Iniciá sesión nuevamente.']);
    $bank = $pdo->prepare('SELECT N_CUENTA FROM bancos WHERE ID = ? AND ESTADO = 1');
    $bancoId = filter_var($_POST['ID_BANCO'] ?? null, FILTER_VALIDATE_INT);
    if (!$bancoId || $bancoId < 1) throw new InvalidArgumentException('Seleccioná un banco válido.');
    $bank->execute([$bancoId]);
    $cuentaBanco = $bank->fetchColumn();
    if ($cuentaBanco === false) throw new InvalidArgumentException('El banco seleccionado no está disponible.');
    if (trim((string) $cuentaBanco) === '') throw new InvalidArgumentException('El banco no tiene una cuenta configurada. Solicitá que se complete en el mantenimiento de bancos.');
    // La cuenta se obtiene del banco, nunca del valor enviado por el navegador.
    $input = $_POST;
    $input['NUMERO_CUENTA'] = trim((string) $cuentaBanco);
    $data = validarDeposito($input);
    $data['FECHA_DEPOSITO'] = validarFechaDeposito($_POST['FECHA_DEPOSITO'] ?? null);
    $file = $_FILES['foto'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new InvalidArgumentException('Cargá nuevamente una imagen válida (máximo 8 MB).');
    if ($file['size'] > 8 * 1024 * 1024) throw new InvalidArgumentException('La imagen supera los 8 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $size = @getimagesize($file['tmp_name']);
    if (!isset($extensions[$mime]) || !$size || $size[0] < 250 || $size[1] < 250 || $size[0] * $size[1] > 25000000) throw new InvalidArgumentException('Usá una foto legible JPG, PNG o WebP, de al menos 250 × 250 píxeles y hasta 25 megapíxeles.');
    // La foto y los campos son obligatorios; la extracción OCR es opcional.
    $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $directory = __DIR__ . '/../files/depositos';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('No se pudo crear el directorio de imágenes.');
    $url = 'files/depositos/' . $filename;
    $destination = $directory . '/' . $filename;
    $pdo->beginTransaction();
    $id = insertarDeposito($pdo, $data, (int)$_SESSION['user_id'], $filename, $url, $tipoCaptura, $textoOcr === '' ? null : $textoOcr);
    $consultaCodigo = $pdo->prepare('SELECT CODIGO FROM depositos WHERE ID = ?');
    $consultaCodigo->execute([$id]);
    $codigo = $consultaCodigo->fetchColumn();
    if (!move_uploaded_file($file['tmp_name'], $destination)) throw new RuntimeException('No se pudo guardar la imagen.');
    $pdo->commit();
    respond(201, ['message' => "Depósito $codigo guardado correctamente. Pendiente de revisión.", 'id' => $id, 'codigo' => $codigo]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($destination && is_file($destination)) unlink($destination);
    if ($e instanceof InvalidArgumentException) respond(422, ['message' => $e->getMessage()]);
    if ($e instanceof PDOException && $e->getCode() === '23505') respond(409, ['message' => 'Ya existe un depósito con esa referencia para este banco. Revisá la referencia antes de continuar.']);
    error_log('Error guardando depósito: ' . $e->getMessage());
    respond(500, ['message' => 'No se pudo guardar el depósito. Tus datos siguen en pantalla; intentá nuevamente.']);
}
