<?php
// Solicitudes de creación de usuarios (flujo replicado del sistema de conciliación).
// Las reglas de rol están centralizadas en este archivo para ajustarlas en un solo lugar.
require_once __DIR__ . '/usuarios_roles.php';

const SOLICITUDES_ROL_SUPERUSUARIO    = 5; // atiende solicitudes y crea usuarios
const SOLICITUDES_ROL_SUPERVISOR      = 2; // supervisor de ventas: solicita cuentas de ventas (exige centros de venta)
const SOLICITUDES_ROL_ADMIN_BENCHMARK = 6; // administrador de Benchmark: solicita cuentas de Benchmark (roles 6 y 7)
const SOLICITUDES_ROLES_VENTAS        = ['SUPERVISOR DE VENTAS', 'ADMINISTRADOR DE VENTAS'];

function rolSolicitudUsuario(PDO $pdo, int $usuario): int
{
    $consulta = $pdo->prepare('SELECT U.ID_ROL FROM USUARIOS U JOIN ROLES R ON R.ID = U.ID_ROL
        WHERE U.ID = ? AND U.ESTADO = 1 AND R.ESTADO <> 0');
    $consulta->execute([$usuario]);
    return (int) $consulta->fetchColumn();
}

function esAdminIT(PDO $pdo, int $usuario): bool
{
    $consulta = $pdo->prepare("SELECT 1 FROM USUARIOS U JOIN ROLES R ON R.ID = U.ID_ROL
        WHERE U.ID = ? AND U.ESTADO = 1 AND R.ESTADO <> 0
            AND UPPER(TRIM(R.ROL)) = 'ADMINISTRADOR IT'");
    $consulta->execute([$usuario]);
    return (bool) $consulta->fetchColumn();
}

// Quién atiende las solicitudes (ve la bandeja y crea la cuenta)
function puedeVerSolicitudesUsuarios(PDO $pdo, int $usuario, ?int $rol = null): bool
{
    $rol ??= rolSolicitudUsuario($pdo, $usuario);
    return $rol === SOLICITUDES_ROL_SUPERUSUARIO || esAdminIT($pdo, $usuario);
}

// Quién crea usuarios directamente desde la pantalla de Usuarios (alta manual)
function puedeCrearUsuariosDirecto(PDO $pdo, int $usuario, ?int $rol = null): bool
{
    return puedeVerSolicitudesUsuarios($pdo, $usuario, $rol);
}

// Quién envía solicitudes
function puedeEnviarSolicitudesUsuarios(int $rol): bool
{
    return in_array($rol, [SOLICITUDES_ROL_SUPERVISOR, SOLICITUDES_ROL_ADMIN_BENCHMARK], true);
}

// Las cuentas de ventas necesitan centros de venta; las de Benchmark no.
function solicitudRequiereCentros(int $rol): bool
{
    return $rol === SOLICITUDES_ROL_SUPERVISOR;
}

// Roles que cada solicitante puede pedir (filas ID, ROL)
function rolesSolicitablesPara(PDO $pdo, int $rol): array
{
    if ($rol === SOLICITUDES_ROL_ADMIN_BENCHMARK) {
        $ids = usuarios_roles_gestionables(SOLICITUDES_ROL_ADMIN_BENCHMARK) ?? [];
        if (!$ids) {
            return [];
        }
        $consulta = $pdo->query('SELECT ID, ROL FROM ROLES WHERE ESTADO <> 0 AND ID IN ('
            . implode(',', array_map('intval', $ids)) . ') ORDER BY ROL');
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($rol === SOLICITUDES_ROL_SUPERVISOR) {
        $marcas = implode(',', array_fill(0, count(SOLICITUDES_ROLES_VENTAS), '?'));
        $consulta = $pdo->prepare("SELECT ID, ROL FROM ROLES WHERE ESTADO <> 0
            AND UPPER(TRIM(ROL)) IN ($marcas) ORDER BY ROL");
        $consulta->execute(SOLICITUDES_ROLES_VENTAS);
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }
    return [];
}

function centrosAsignadosAlSupervisor(PDO $pdo, int $usuario): array
{
    $consulta = $pdo->prepare('SELECT C.ID, C.CENTRO_VENTA
        FROM CENTRO_DE_VENTA C
        WHERE EXISTS (
            SELECT 1 FROM USUARIOS_CENTROS_VENTA S
            WHERE S.ID_USUARIO = ? AND S.ESTADO = 1 AND S.ID_CENTRO_VENTA = C.ID
        )
        ORDER BY C.CENTRO_VENTA, C.ID');
    $consulta->execute([$usuario]);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}
