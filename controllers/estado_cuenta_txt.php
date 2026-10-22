<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../includes/lectores/bancos.php';
require_once __DIR__ . '/../includes/lectores/bac.php';
require_once __DIR__ . '/../includes/lectores/atlantida.php';
require_once __DIR__ . '/../includes/lectores/ficohsa.php';
require_once __DIR__ . '/../includes/lectores/occidente.php';
require_once __DIR__ . '/../includes/guardar_estado_cuenta.php';

function responderTxt(int $estado, array $respuesta): void
{
    http_response_code($estado);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderTxt(405, ['mensaje' => 'Usa el formulario para subir el TXT.']);
}
if (empty($_SESSION['user_id'])) {
    responderTxt(401, ['mensaje' => 'Inicia sesión antes de cargar el archivo.']);
}
if (empty($_SESSION['estado_txt_csrf']) || !is_string($_POST['csrf'] ?? null)
    || !hash_equals($_SESSION['estado_txt_csrf'], $_POST['csrf'])) {
    responderTxt(403, ['mensaje' => 'Recarga el formulario e intenta nuevamente.']);
}

$pdo = null;
try {
    // 1. Validar y leer el archivo temporal recibido desde el formulario.
    $archivo = $_FILES['archivo'] ?? [];
    if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_string($archivo['tmp_name'] ?? null)
        || !is_uploaded_file($archivo['tmp_name'])) {
        throw new InvalidArgumentException('Selecciona un archivo TXT válido.');
    }
    if (!is_string($archivo['name'] ?? null)
        || strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'txt') {
        throw new InvalidArgumentException('El archivo debe tener extensión .txt.');
    }
    if (filesize($archivo['tmp_name']) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('El archivo no debe superar 5 MB.');
    }
    $original = file_get_contents($archivo['tmp_name']);
    if ($original === false || trim($original) === '') {
        throw new InvalidArgumentException('El archivo está vacío o no se puede leer.');
    }

    // 2. Consultar el banco seleccionado: no confiar en nombres enviados por POST.
    $bancoId = filter_var($_POST['banco_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$bancoId || $bancoId < 1) {
        throw new InvalidArgumentException('Selecciona un banco.');
    }
    $pdo = database();
    $usuario = $pdo->prepare('SELECT ID FROM usuarios WHERE ID = ? AND ESTADO = 1');
    $usuario->execute([$_SESSION['user_id']]);
    if (!$usuario->fetchColumn()) {
        responderTxt(401, ['mensaje' => 'El usuario no está activo.']);
    }

    $consulta = $pdo->prepare('SELECT DESCRIPCION FROM bancos WHERE ID = ? AND ESTADO = 1');
    $consulta->execute([$bancoId]);
    $nombreBanco = $consulta->fetchColumn();
    if (!$nombreBanco) {
        throw new InvalidArgumentException('El banco no existe o está inactivo.');
    }
    // 3. Cada lector conoce su formato y devuelve exactamente la misma estructura.
    switch (codigoLectorBanco($nombreBanco)) {
        case 'BAC':
            $datos = leerBac($original);
            break;
        case 'ATLANTIDA':
            $datos = leerAtlantida($original);
            break;
        case 'FICOHSA':
            $datos = leerFicohsa($original);
            break;
        case 'OCCIDENTE':
            $datos = leerOccidente($original);
            break;
        default:
            throw new InvalidArgumentException('Todavía no existe un lector para este banco.');
    }

    // 4. El guardado es el mismo para todos los bancos, dentro de una transacción.
    $id = guardarEstadoCuenta($pdo, (int) $_SESSION['user_id'], $bancoId,
        basename($archivo['name']), $original, $datos);
    responderTxt(201, [
        'mensaje' => 'Estado de cuenta guardado correctamente.',
        'id' => $id,
        'transacciones' => count($datos['transacciones']),
        'filas_resumen' => count($datos['resumen']),
        'advertencias' => $datos['advertencias'],
    ]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof InvalidArgumentException) {
        responderTxt(422, ['mensaje' => $e->getMessage()]);
    }
    if ($e instanceof PDOException && $e->getCode() === '23505') {
        responderTxt(409, ['mensaje' => 'Este mismo archivo ya fue importado.']);
    }
    error_log('Carga de estado TXT: ' . $e->getMessage());
    responderTxt(500, ['mensaje' => 'No se pudo guardar. Revisa el registro de PHP y que las tablas estén creadas.']);
}
