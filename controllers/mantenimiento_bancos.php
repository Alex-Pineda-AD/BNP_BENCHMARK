<?php
session_start();

require_once __DIR__ . '/../connection/database.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Debes iniciar sesión.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../modules/usuarios/bancos.php');
    exit;
}

// Validar token CSRF
$token = $_POST['token'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['usuarios_token']) ||
    !hash_equals($_SESSION['usuarios_token'], $token)
) {
    http_response_code(403);
    exit('La sesión del formulario venció. Recarga la pantalla.');
}

$pdo = database();

try {
    // Validar que los campos sean strings
    foreach (['descripcion', 'nuemroCuenta', 'bande'] as $campo) {
        if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
            throw new Exception('Los datos del formulario no son válidos.');
        }
    }

    // Recibir datos
    $descripcion = trim($_POST['descripcion'] ?? '');
    $numeroCuenta = trim($_POST['nuemroCuenta'] ?? '');
    $idBanco = $_POST['idBanco'] ?? '';
    $bandera = $_POST['bande'] ?? '';
    $usuarioCreo = $_SESSION['user_id'];
    $estado = $_POST['estado'];

    // Validar acción
    if (!in_array($bandera, ['1', '2'], true)) {
        throw new Exception('La acción no es válida.');
    }

    // Validar ID cuando se está editando
    if ($bandera === '2') {
        if (!is_numeric($idBanco) || (int)$idBanco <= 0) {
            throw new Exception('El banco seleccionado no es válido.');
        }

        $idBanco = (int)$idBanco;
    } else {
        $idBanco = 0;
    }

    // Validar campos obligatorios
    if ($descripcion === '' || $numeroCuenta === '') {
        throw new Exception('Completa la descripción y el número de cuenta.');
    }

    // Validar longitud
    if (mb_strlen($descripcion, 'UTF-8') > 60) {
        throw new Exception('La descripción no puede superar los 60 caracteres.');
    }

    if (mb_strlen($numeroCuenta, 'UTF-8') > 50) {
        throw new Exception('El número de cuenta no puede superar los 50 caracteres.');
    }

    // Iniciar transacción
    $pdo->beginTransaction();

    // Validar banco duplicado
    $duplicado = $pdo->prepare(
        'SELECT ID 
         FROM bancos 
         WHERE DESCRIPCION = ? 
         AND ID != ? 
         LIMIT 1'
    );

    $duplicado->execute([
        $descripcion,
        $idBanco
    ]);

    if ($duplicado->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Ese banco ya está registrado.');
    }

    // Registrar
    if ($bandera === '1') {

        $stmt = $pdo->prepare(
            'INSERT INTO bancos (DESCRIPCION, N_CUENTA)
             VALUES (?, ?) RETURNING ID'
        );

        $stmt->execute([
            $descripcion,
            $numeroCuenta
        ]);

        $idBanco = (string) $stmt->fetchColumn();

        $mensaje = 'Banco registrado correctamente.';
    }

    // Editar
    elseif ($bandera === '2') {

        // IMPORTANTE:
        // Antes se estaba consultando la tabla "usuarios".
        $stmt = $pdo->prepare(
            'SELECT ID
             FROM bancos
             WHERE ID = ?
             FOR UPDATE'
        );

        $stmt->execute([$idBanco]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('El banco que intentas editar no existe.');
        }

        $stmt = $pdo->prepare(
            'UPDATE bancos
             SET DESCRIPCION = ?, N_CUENTA = ?, ESTADO = ?
             WHERE ID = ?'
        );

        $stmt->execute([
            $descripcion,
            $numeroCuenta,
            $estado,
            $idBanco
        ]);

        $mensaje = 'Banco actualizado correctamente.';
    }

    // Confirmar transacción
    $pdo->commit();

    $_SESSION['usuarios_mensaje'] = $mensaje;
    $_SESSION['usuarios_tipo'] = 'success';

} catch (Throwable $e) {

    // Revertir transacción si está activa
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Registrar error real en el log
    error_log($e->getMessage());

    // Mostrar mensaje controlado al usuario
    if ($e instanceof PDOException) {
        $_SESSION['usuarios_mensaje'] =
            'No se pudieron guardar los cambios. Intenta nuevamente.';
    } else {
        $_SESSION['usuarios_mensaje'] = $e->getMessage();
    }

    $_SESSION['usuarios_tipo'] = 'danger';
}

header('Location: ../modules/usuarios/bancos.php');
exit;