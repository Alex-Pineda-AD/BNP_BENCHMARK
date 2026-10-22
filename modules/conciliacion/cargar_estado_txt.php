<?php
session_start();
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Inicia sesión en el sistema y luego abre este formulario.');
}
if (empty($_SESSION['estado_txt_csrf'])) {
    $_SESSION['estado_txt_csrf'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/../../connection/database.php';
require_once __DIR__ . '/../../includes/lectores/bancos.php';
// Solo ofrecer bancos activos que tengan un lector disponible.
$bancos = [];
$error = '';
try {
    $consulta = database()->query('SELECT ID, DESCRIPCION FROM bancos WHERE ESTADO = 1 ORDER BY DESCRIPCION');
    foreach ($consulta as $banco) {
        if (codigoLectorBanco($banco['DESCRIPCION']) !== null) {
            $bancos[] = $banco;
        }
    }
} catch (Throwable $e) {
    error_log('Formulario de estado de cuenta: ' . $e->getMessage());
    $error = 'No se pudo consultar el catálogo de bancos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cargar estado de cuenta TXT</title>
</head>
<body>
    <h1>Cargar estado de cuenta TXT</h1>
    <p>Se guardarán el encabezado, los movimientos, el resumen y el archivo original.</p>
    <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <form action="../../controllers/estado_cuenta_txt.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['estado_txt_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <label for="banco_id">Banco:</label>
        <select id="banco_id" name="banco_id" required>
            <option value="">Selecciona un banco</option>
            <?php foreach ($bancos as $banco): ?>
                <option value="<?= (int) $banco['ID'] ?>"><?= htmlspecialchars($banco['DESCRIPCION'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <br><br>
        <label for="archivo">Archivo TXT (máximo 5 MB):</label>
        <input id="archivo" type="file" name="archivo" accept=".txt" required>
        <button type="submit">Subir y guardar</button>
    </form>
</body>
</html>
