<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Debes iniciar sesión.');
}
if (!in_array((int) ($_SESSION['id_rol'] ?? 0), [4, 5, 6], true)) {
    http_response_code(403);
    exit('No tienes permiso para administrar usuarios.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../modules/usuarios/usuarios.php');
    exit;
}
if (!is_string($_POST['token'] ?? null) || !hash_equals($_SESSION['usuarios_token'] ?? '', $_POST['token']) || empty($_SESSION['usuarios_token'])) {
    http_response_code(403);
    exit('La sesión del formulario venció. Recarga la pantalla.');
}

$pdo = database();
try {
    // Recibimos los mismos nombres que tienen los campos de los modales.
    foreach (['nombreCompleto', 'dni', 'telefono', 'correo', 'usuario', 'pass', 'idRol', 'idUsuario', 'bande'] as $campo) {
        if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
            throw new Exception('Los datos del formulario no son válidos.');
        }
    }
    $vr_nombre = trim($_POST['nombreCompleto'] ?? '');
    $vr_dni = trim($_POST['dni'] ?? '');
    $vr_telefono = trim($_POST['telefono'] ?? '');
    $vr_correo = trim($_POST['correo'] ?? '');
    $vr_usuario = trim($_POST['usuario'] ?? '');
    $vr_password = $_POST['pass'] ?? '';
    $vr_id_rol = $_POST['idRol'] ?? '';
    $id_usuario = $_POST['idUsuario'] ?? '0';
    $bandera = $_POST['bande'] ?? '';
    $vr_idCentroventas = $_POST['idCentroventas'] ?? [];
    $vr_fecha = date('Y-m-d H:i:s');
    $usuario_creo = $_SESSION['user_id'];

    if (!in_array($bandera, ['1', '2'], true)) {
        throw new Exception('La acción no es válida.');
    }


    if ($vr_nombre === '' || $vr_dni === '' || $vr_usuario === '' || ($bandera === '1' && $vr_password === '')) {
        throw new Exception('Completa nombre, DNI, usuario y contraseña para registrar.');
    }


    foreach ([[$vr_nombre, 60], [$vr_dni, 20], [$vr_usuario, 20], [$vr_correo, 50], [$vr_telefono, 15]] as [$valor, $limite]) {
        if (mb_strlen((string) $valor, 'UTF-8') > $limite) {
            throw new Exception('Uno de los campos supera la longitud permitida.');
        }
    }


    if ($vr_correo !== '' && !filter_var($vr_correo, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('El correo no es válido.');
    }


    if (!ctype_digit($vr_id_rol) || !ctype_digit($id_usuario) || !is_array($vr_idCentroventas)) {
        throw new Exception('Selecciona un rol y centros de venta válidos.');
    }


    foreach ($vr_idCentroventas as $centro) {
        if (!is_string($centro) || !ctype_digit($centro) || (int) $centro < 1) {
            throw new Exception('Uno de los centros de venta no es válido.');
        }
    }


    $vr_idCentroventas = array_unique($vr_idCentroventas);

    // Una transacción evita guardar el usuario sin sus centros si ocurre un error.
    $pdo->beginTransaction();
    $rol = $pdo->prepare('SELECT ID FROM roles WHERE ID = ? AND ESTADO != 0');
    $rol->execute([$vr_id_rol]);
    if (!$rol->fetch()) {
        throw new Exception('El rol seleccionado no está disponible.');
    }
    $centros = $pdo->prepare('SELECT ID FROM centro_de_venta WHERE ID = ?');
    foreach ($vr_idCentroventas as $centro) {
        $centros->execute([$centro]);
        if (!$centros->fetch()) {
            throw new Exception('Uno de los centros de venta no existe.');
        }
    }
    $duplicado = $pdo->prepare('SELECT ID FROM usuarios WHERE USUARIO = ? AND ID != ?');
    $duplicado->execute([$vr_usuario, $bandera === '2' ? $id_usuario : 0]);
    if ($duplicado->fetch()) {
        throw new Exception('Ese nombre de usuario ya está registrado.');
    }

    // BANDE = 1: registrar. BANDE = 2: editar.
    switch ($bandera) {
        case '1':
            $usuarios = $pdo->prepare('INSERT INTO usuarios
                (USUARIO, PASSWORD, NOMBRE_COMPLETO, DNI, CORREO, FECHA_CREO,
                 USUARIO_CREO, ID_ROL, NUM_TELEFONO, USUARIO_HASH, ESTADO)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1) RETURNING ID');
            $usuarios->execute([$vr_usuario, password_hash($vr_password, PASSWORD_DEFAULT),
                $vr_nombre, $vr_dni, $vr_correo === '' ? null : $vr_correo, $vr_fecha,
                $usuario_creo, $vr_id_rol, $vr_telefono, password_hash($vr_usuario, PASSWORD_DEFAULT)]);
            $id_usuario = (string) $usuarios->fetchColumn();
            break;

        case '2':
            $usuarios = $pdo->prepare('SELECT ID FROM usuarios WHERE ID = ? FOR UPDATE');
            $usuarios->execute([$id_usuario]);
            if (!$usuarios->fetch()) {
                throw new Exception('El usuario que intentas editar no existe.');
            }
            $usuarios = $pdo->prepare('UPDATE usuarios SET USUARIO = ?, NOMBRE_COMPLETO = ?,
                DNI = ?, CORREO = ?, ID_ROL = ?, NUM_TELEFONO = ?,
                FECHA_MODIFICO = ?, USUARIO_MODIFICO = ? WHERE ID = ?');
            $usuarios->execute([$vr_usuario, $vr_nombre, $vr_dni,
                $vr_correo === '' ? null : $vr_correo, $vr_id_rol, $vr_telefono,
                $vr_fecha, $usuario_creo, $id_usuario]);

            // Si la contraseña queda vacía, conservamos la contraseña actual.
            if ($vr_password !== '') {
                $password = $pdo->prepare('UPDATE usuarios SET PASSWORD = ? WHERE ID = ?');
                $password->execute([password_hash($vr_password, PASSWORD_DEFAULT), $id_usuario]);
            }
            // Solo desactivamos los centros que se quitaron del formulario.
            // No eliminamos registros ni modificamos los que siguen seleccionados.
            $centros = $pdo->prepare('SELECT ID, ID_CENTRO_VENTA FROM usuarios_centros_venta
                WHERE ID_USUARIO = ? AND ESTADO = 1');
            $centros->execute([$id_usuario]);
            $quitarCentro = $pdo->prepare('UPDATE usuarios_centros_venta SET ESTADO = 0,
                USUARIO_MODIFICO = ?, FECHA_MODIFICO = ? WHERE ID = ? AND ID_USUARIO = ?');
            foreach ($centros->fetchAll() as $centroAsignado) {
                if (!in_array((string) $centroAsignado['ID_CENTRO_VENTA'], $vr_idCentroventas, true)) {
                    $quitarCentro->execute([$usuario_creo, $vr_fecha, $centroAsignado['ID'], $id_usuario]);
                }
            }
            break;
    }

    $buscarCentro = $pdo->prepare('SELECT ID, ESTADO FROM usuarios_centros_venta WHERE ID_USUARIO = ? AND ID_CENTRO_VENTA = ? ORDER BY ESTADO DESC, ID LIMIT 1');
    $activarCentro = $pdo->prepare('UPDATE usuarios_centros_venta SET ESTADO = 1, USUARIO_MODIFICO = ?, FECHA_MODIFICO = ? WHERE ID = ?');
    $usuariosCentros = $pdo->prepare('INSERT INTO usuarios_centros_venta
        (ID_CENTRO_VENTA, ID_USUARIO, USUARIO_CREO, FECHA_CREO, ESTADO) VALUES (?, ?, ?, ?, 1)');
    foreach ($vr_idCentroventas as $centro) {
        $buscarCentro->execute([$id_usuario, $centro]);
        $asignacion = $buscarCentro->fetch();
        if ($asignacion) {
            if ((int) $asignacion['ESTADO'] !== 1) {
                $activarCentro->execute([$usuario_creo, $vr_fecha, $asignacion['ID']]);
            }
        } else {
            $usuariosCentros->execute([$centro, $id_usuario, $usuario_creo, $vr_fecha]);
        }
    }
    
    $pdo->commit();
    $_SESSION['usuarios_mensaje'] = $bandera === '1' ? 'Usuario registrado correctamente.' : 'Usuario actualizado correctamente.';
    $_SESSION['usuarios_tipo'] = 'success';
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException) {
        error_log($e->getMessage());
    }
    $_SESSION['usuarios_mensaje'] = $e instanceof PDOException ? 'No se pudieron guardar los cambios. Intenta nuevamente.' : $e->getMessage();
    $_SESSION['usuarios_tipo'] = 'danger';
}
header('Location: ../modules/usuarios/usuarios.php');
exit;
