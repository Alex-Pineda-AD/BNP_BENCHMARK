<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../includes/solicitudes_creacion_usuarios.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Debes iniciar sesión.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../modules/usuarios/solicitudes.php');
    exit;
}
if (empty($_SESSION['solicitudes_usuarios_csrf']) || !is_string($_POST['csrf'] ?? null)
    || !hash_equals($_SESSION['solicitudes_usuarios_csrf'], $_POST['csrf'])) {
    http_response_code(403);
    exit('La sesión del formulario venció. Recarga la pantalla.');
}

$usuario = (int) $_SESSION['user_id'];
$pdo = database();
$accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

try {
    $rol = rolSolicitudUsuario($pdo, $usuario);
    if ($rol < 1) {
        http_response_code(403);
        exit('La cuenta no está activa.');
    }

    if (in_array($accion, ['crear_solicitud', 'editar_solicitud'], true)) {
        if (!puedeEnviarSolicitudesUsuarios($rol)) {
            http_response_code(403);
            exit('Solo un supervisor de ventas o el administrador de Benchmark puede enviar solicitudes.');
        }
        $requiereCentros = solicitudRequiereCentros($rol);
        foreach (['nombre', 'dni', 'telefono', 'correo', 'motivo', 'id_area', 'id_rol'] as $campo) {
            if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
                throw new InvalidArgumentException('Los datos enviados no son válidos.');
            }
        }
        $nombre = trim($_POST['nombre'] ?? '');
        $dni = trim($_POST['dni'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $motivo = trim($_POST['motivo'] ?? '');
        $idArea = $_POST['id_area'] ?? '';
        $idRol = $_POST['id_rol'] ?? '';
        $centros = $_POST['centros'] ?? [];

        if ($nombre === '' || $dni === '' || $motivo === '' || !ctype_digit($idArea) || !ctype_digit($idRol)) {
            throw new InvalidArgumentException('Completa nombre, DNI, área, rol solicitado y motivo.');
        }
        foreach ([[$nombre, 60], [$dni, 20], [$telefono, 15], [$correo, 50], [$motivo, 500]] as [$valor, $limite]) {
            if (mb_strlen($valor, 'UTF-8') > $limite) {
                throw new InvalidArgumentException('Uno de los campos supera la longitud permitida.');
            }
        }
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El correo no tiene un formato válido.');
        }
        if (!is_array($centros) || ($requiereCentros && !$centros)) {
            throw new InvalidArgumentException('Selecciona al menos un centro de venta.');
        }
        $centros = array_values(array_unique($centros));
        foreach ($centros as $centro) {
            if (!is_string($centro) || !ctype_digit($centro) || (int) $centro < 1) {
                throw new InvalidArgumentException('Uno de los centros seleccionados no es válido.');
            }
        }

        $area = $pdo->prepare('SELECT ID FROM AREAS WHERE ID = ?');
        $area->execute([$idArea]);
        if (!$area->fetchColumn()) {
            throw new InvalidArgumentException('El área seleccionada no está disponible.');
        }
        $rolesValidos = array_map('intval', array_column(rolesSolicitablesPara($pdo, $rol), 'ID'));
        if (!in_array((int) $idRol, $rolesValidos, true)) {
            throw new InvalidArgumentException('El rol solicitado no está disponible.');
        }

        $centroValido = $pdo->prepare('SELECT 1 FROM USUARIOS_CENTROS_VENTA
            WHERE ID_USUARIO = ? AND ID_CENTRO_VENTA = ? AND ESTADO = 1');
        foreach ($centros as $centro) {
            $centroValido->execute([$usuario, $centro]);
            if (!$centroValido->fetchColumn()) {
                throw new InvalidArgumentException('Solo puedes asignar centros de venta que tienes autorizados.');
            }
        }

        $pdo->beginTransaction();
        if ($accion === 'editar_solicitud') {
            $idSolicitud = $_POST['id_solicitud'] ?? '';
            if (!is_string($idSolicitud) || !ctype_digit($idSolicitud) || (int) $idSolicitud < 1) {
                throw new InvalidArgumentException('La solicitud no es válida.');
            }
            $bloquear = $pdo->prepare("SELECT ID FROM solicitudes_creacion_usuarios
                WHERE ID = ? AND USUARIO_SOLICITA = ? AND ESTADO = 'PENDIENTE' FOR UPDATE");
            $bloquear->execute([$idSolicitud, $usuario]);
            if (!$bloquear->fetchColumn()) {
                throw new InvalidArgumentException('Solo puedes editar tus solicitudes pendientes. La solicitud pudo haber sido atendida.');
            }
            $actualizar = $pdo->prepare('UPDATE solicitudes_creacion_usuarios SET
                nombre_completo = ?, dni = ?, telefono = ?, correo = ?, id_area = ?,
                id_rol_solicitado = ?, motivo = ? WHERE id = ?');
            $actualizar->execute([$nombre, $dni, $telefono ?: null, $correo ?: null,
                $idArea, $idRol, $motivo, $idSolicitud]);
            $quitarCentros = $pdo->prepare('DELETE FROM solicitudes_creacion_usuarios_centros WHERE id_solicitud = ?');
            $quitarCentros->execute([$idSolicitud]);
        } else {
        $insertar = $pdo->prepare('INSERT INTO solicitudes_creacion_usuarios
            (nombre_completo, dni, telefono, correo, id_area, id_rol_solicitado,
             motivo, usuario_solicita, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'PENDIENTE\') RETURNING id');
        $insertar->execute([$nombre, $dni, $telefono ?: null, $correo ?: null,
            $idArea, $idRol, $motivo, $usuario]);
        $idSolicitud = (int) $insertar->fetchColumn();
        }
        $insertarCentro = $pdo->prepare('INSERT INTO solicitudes_creacion_usuarios_centros
            (id_solicitud, id_centro_venta) VALUES (?, ?)');
        foreach ($centros as $centro) {
            $insertarCentro->execute([$idSolicitud, $centro]);
        }
        $pdo->commit();
        $_SESSION['solicitudes_usuarios_mensaje'] = $accion === 'editar_solicitud'
            ? 'Solicitud actualizada correctamente.'
            : 'Solicitud enviada al superusuario y al administrador de IT.';
        $_SESSION['solicitudes_usuarios_tipo'] = 'success';
    } elseif ($accion === 'crear_desde_solicitud') {
        if (!puedeVerSolicitudesUsuarios($pdo, $usuario, $rol)) {
            http_response_code(403);
            exit('No tienes permiso para atender solicitudes.');
        }
        $idSolicitud = $_POST['id_solicitud'] ?? '';
        $login = $_POST['usuario_nuevo'] ?? '';
        $password = $_POST['password_nuevo'] ?? '';
        $confirmacion = $_POST['password_confirmar'] ?? '';
        if (!is_string($idSolicitud) || !ctype_digit($idSolicitud) || (int) $idSolicitud < 1
            || !is_string($login) || !is_string($password) || !is_string($confirmacion)) {
            throw new InvalidArgumentException('La solicitud seleccionada no es válida.');
        }
        $login = trim($login);
        if (!preg_match('/^[A-Za-z0-9._-]{3,20}$/D', $login)) {
            throw new InvalidArgumentException('El nombre de usuario debe tener de 3 a 20 letras, números, puntos, guiones o guiones bajos.');
        }
        if ($password === '' || mb_strlen($password, 'UTF-8') > 8) {
            throw new InvalidArgumentException('La contraseña es obligatoria y debe tener un máximo de 8 caracteres.');
        }
        if (!hash_equals($password, $confirmacion)) {
            throw new InvalidArgumentException('Las contraseñas no coinciden.');
        }
        $pdo->beginTransaction();
        $solicitud = $pdo->prepare("SELECT ID, NOMBRE_COMPLETO, DNI, TELEFONO, CORREO,
                ID_AREA, ID_ROL_SOLICITADO, USUARIO_SOLICITA
            FROM SOLICITUDES_CREACION_USUARIOS
            WHERE ID = ? AND ESTADO = 'PENDIENTE' FOR UPDATE");
        $solicitud->execute([$idSolicitud]);
        $datos = $solicitud->fetch(PDO::FETCH_ASSOC);
        if (!$datos) {
            throw new InvalidArgumentException('La solicitud ya fue atendida o no está disponible.');
        }
        $duplicado = $pdo->prepare('SELECT ID FROM USUARIOS WHERE UPPER(USUARIO) = UPPER(?) OR DNI = ?');
        $duplicado->execute([$login, $datos['DNI']]);
        if ($duplicado->fetch()) {
            throw new InvalidArgumentException('El usuario o DNI ya está registrado. Revisa los datos antes de continuar.');
        }
        // El rol pedido se revalida contra lo que el solicitante puede pedir hoy.
        $rolSolicitante = rolSolicitudUsuario($pdo, (int) $datos['USUARIO_SOLICITA']);
        $rolesValidos = array_map('intval', array_column(rolesSolicitablesPara($pdo, $rolSolicitante), 'ID'));
        if (!in_array((int) $datos['ID_ROL_SOLICITADO'], $rolesValidos, true)) {
            throw new InvalidArgumentException('El rol solicitado ya no está disponible.');
        }
        $areaNueva = $pdo->prepare('SELECT ID FROM AREAS WHERE ID = ?');
        $areaNueva->execute([$datos['ID_AREA']]);
        if (!$areaNueva->fetchColumn()) {
            throw new InvalidArgumentException('El área solicitada ya no está disponible.');
        }
        $insertarUsuario = $pdo->prepare('INSERT INTO USUARIOS
            (USUARIO, PASSWORD, NOMBRE_COMPLETO, DNI, CORREO, FECHA_CREO,
             USUARIO_CREO, ID_ROL, NUM_TELEFONO, USUARIO_HASH, ESTADO, ID_AREAS)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, 1, ?) RETURNING ID');
        $insertarUsuario->execute([$login, password_hash($password, PASSWORD_DEFAULT),
            $datos['NOMBRE_COMPLETO'], $datos['DNI'], $datos['CORREO'], $usuario,
            $datos['ID_ROL_SOLICITADO'], $datos['TELEFONO'],
            password_hash($login, PASSWORD_DEFAULT), $datos['ID_AREA']]);
        $nuevoUsuario = (int) $insertarUsuario->fetchColumn();

        $consultaCentros = $pdo->prepare('SELECT ID_CENTRO_VENTA
            FROM SOLICITUDES_CREACION_USUARIOS_CENTROS WHERE ID_SOLICITUD = ?');
        $consultaCentros->execute([$idSolicitud]);
        $asignaciones = $consultaCentros->fetchAll(PDO::FETCH_COLUMN);
        if (!$asignaciones && solicitudRequiereCentros($rolSolicitante)) {
            throw new InvalidArgumentException('La solicitud no tiene centros de venta asignados.');
        }
        $insertarCentro = $pdo->prepare('INSERT INTO USUARIOS_CENTROS_VENTA
            (ID_CENTRO_VENTA, ID_USUARIO, USUARIO_CREO, FECHA_CREO, ESTADO)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, 1)');
        foreach ($asignaciones as $centro) {
            $insertarCentro->execute([$centro, $nuevoUsuario, $usuario]);
        }
        $actualizar = $pdo->prepare("UPDATE SOLICITUDES_CREACION_USUARIOS
            SET ESTADO = 'ATENDIDA', ATENDIDA_POR = ?, FECHA_ATENDIDA = CURRENT_TIMESTAMP,
                USUARIO_CREADO_ID = ?
            WHERE ID = ? AND ESTADO = 'PENDIENTE'");
        $actualizar->execute([$usuario, $nuevoUsuario, $idSolicitud]);
        if (!$actualizar->rowCount()) {
            throw new RuntimeException('No se pudo cerrar la solicitud.');
        }
        $pdo->commit();
        $_SESSION['solicitudes_usuarios_mensaje'] = 'Usuario creado, área y centros de venta asignados; solicitud atendida.';
        $_SESSION['solicitudes_usuarios_tipo'] = 'success';
    } else {
        throw new InvalidArgumentException('La acción solicitada no es válida.');
    }
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['solicitudes_usuarios_mensaje'] = $e->getMessage();
    $_SESSION['solicitudes_usuarios_tipo'] = 'warning';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error al guardar solicitud de usuario: ' . $e->getMessage());
    $_SESSION['solicitudes_usuarios_mensaje'] = 'No se pudo procesar la solicitud. Verifica que la migración y las tablas de áreas y roles estén disponibles.';
    $_SESSION['solicitudes_usuarios_tipo'] = 'danger';
}

header('Location: ../modules/usuarios/solicitudes.php');
exit;

