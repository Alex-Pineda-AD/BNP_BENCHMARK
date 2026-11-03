<?php
session_start();
header('Cache-Control: no-store');
require_once __DIR__ . '/../../connection/database.php';
require_once __DIR__ . '/../../includes/solicitudes_creacion_usuarios.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}
$pdo = database();
$usuario = (int) $_SESSION['user_id'];
$rol = rolSolicitudUsuario($pdo, $usuario);
$esSupervisor = puedeEnviarSolicitudesUsuarios($rol); // quien envía solicitudes (supervisor de ventas o administrador de Benchmark)
$requiereCentros = solicitudRequiereCentros($rol);
$esAdmin = puedeVerSolicitudesUsuarios($pdo, $usuario, $rol);
if (!$esSupervisor && !$esAdmin) {
    http_response_code(403);
    exit('No tienes permiso para consultar solicitudes de usuarios.');
}
$_SESSION['solicitudes_usuarios_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['solicitudes_usuarios_csrf'];
$mensaje = $_SESSION['solicitudes_usuarios_mensaje'] ?? '';
$tipoMensaje = $_SESSION['solicitudes_usuarios_tipo'] ?? 'info';
unset($_SESSION['solicitudes_usuarios_mensaje'], $_SESSION['solicitudes_usuarios_tipo']);
$errorConsulta = '';
$areas = $rolesSolicitados = $centros = $solicitudes = [];
$editar = null;
$centrosSeleccionados = [];
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if (isset($_GET['editar']) && (!$esSupervisor || !$idEditar || $idEditar < 1)) {
    http_response_code(403);
    exit('No puedes editar esta solicitud.');
}

try {
    if ($esSupervisor) {
        $areas = $pdo->query('SELECT ID, DESCRIPCION AS NOMBRE FROM AREAS WHERE ESTADO = 1 ORDER BY DESCRIPCION')->fetchAll(PDO::FETCH_ASSOC);
        $rolesSolicitados = rolesSolicitablesPara($pdo, $rol);
        $centros = centrosAsignadosAlSupervisor($pdo, $usuario);
        if ($idEditar) {
            $consultaEditar = $pdo->prepare("SELECT * FROM solicitudes_creacion_usuarios
                WHERE ID = ? AND USUARIO_SOLICITA = ? AND ESTADO = 'PENDIENTE'");
            $consultaEditar->execute([$idEditar, $usuario]);
            $editar = $consultaEditar->fetch(PDO::FETCH_ASSOC);
            if (!$editar) {
                http_response_code(403);
                exit('Solo puedes editar tus solicitudes pendientes.');
            }
            $consultaCentrosEditar = $pdo->prepare('SELECT ID_CENTRO_VENTA FROM solicitudes_creacion_usuarios_centros WHERE ID_SOLICITUD = ?');
            $consultaCentrosEditar->execute([$idEditar]);
            $centrosSeleccionados = array_map('strval', $consultaCentrosEditar->fetchAll(PDO::FETCH_COLUMN));
        }
        $consulta = $pdo->prepare("SELECT S.ID, S.NOMBRE_COMPLETO, S.DNI, S.ESTADO, S.FECHA_CREO,
                UC.USUARIO AS USUARIO_CREADO,
            A.DESCRIPCION AS AREA, R.ROL AS ROL_SOLICITADO,
                (SELECT STRING_AGG(C.CENTRO_VENTA, ', ' ORDER BY C.CENTRO_VENTA)
                    FROM SOLICITUDES_CREACION_USUARIOS_CENTROS SC
                    JOIN CENTRO_DE_VENTA C ON C.ID = SC.ID_CENTRO_VENTA
                    WHERE SC.ID_SOLICITUD = S.ID) AS CENTROS
            FROM SOLICITUDES_CREACION_USUARIOS S
            JOIN AREAS A ON A.ID = S.ID_AREA
            JOIN ROLES R ON R.ID = S.ID_ROL_SOLICITADO
            LEFT JOIN USUARIOS UC ON UC.ID = S.USUARIO_CREADO_ID
            WHERE S.USUARIO_SOLICITA = ? ORDER BY S.ID DESC LIMIT 50");
        $consulta->execute([$usuario]);
        $solicitudes = $consulta->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $consulta = $pdo->query("SELECT S.ID, S.NOMBRE_COMPLETO, S.DNI, S.TELEFONO, S.CORREO,
                S.MOTIVO, S.ESTADO, S.FECHA_CREO, U.NOMBRE_COMPLETO AS SOLICITANTE,
                A.DESCRIPCION AS AREA, R.ROL AS ROL_SOLICITADO,
                (SELECT STRING_AGG(C.CENTRO_VENTA, ', ' ORDER BY C.CENTRO_VENTA)
                    FROM SOLICITUDES_CREACION_USUARIOS_CENTROS SC
                    JOIN CENTRO_DE_VENTA C ON C.ID = SC.ID_CENTRO_VENTA
                    WHERE SC.ID_SOLICITUD = S.ID) AS CENTROS
            FROM SOLICITUDES_CREACION_USUARIOS S
            JOIN USUARIOS U ON U.ID = S.USUARIO_SOLICITA
            JOIN AREAS A ON A.ID = S.ID_AREA
            JOIN ROLES R ON R.ID = S.ID_ROL_SOLICITADO
            ORDER BY CASE WHEN S.ESTADO = 'PENDIENTE' THEN 0 ELSE 1 END, S.ID DESC LIMIT 100");
        $solicitudes = $consulta->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('No se pudieron cargar las solicitudes de usuarios: ' . $e->getMessage());
    $errorConsulta = 'No se pudieron cargar los datos. Verifica que existan la tabla areas con sus registros, el rol ADMINISTRADOR IT y la migración de solicitudes.';
}

function escSolicitud($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include __DIR__ . '/../../includes/head.php'; ?>
    <?php if ($esSupervisor): ?>
    <link rel="stylesheet" href="../../assets/vendors/choices.js/choices.min.css">
    <style>
        .solicitud-centros .choices__list--multiple { max-height: 150px; overflow-y: auto; }
        .solicitud-centros .choices__list--dropdown .choices__list { max-height: 240px; }
    </style>
    <?php endif; ?>
</head>
<body>
<div id="app">
    <div id="sidebar" class="active"><?php include __DIR__ . '/../../includes/menu_sidebar.php'; ?></div>
    <main id="main">
        <header class="mb-3"><a href="#" class="burger-btn d-block d-xl-none"><i class="bi bi-justify fs-3"></i></a></header>
        <div class="page-heading">
            <div class="page-title"><h3>Solicitudes de creación de usuarios</h3></div>
            <?php if ($mensaje !== ''): ?><div class="alert alert-<?=escSolicitud($tipoMensaje)?>" role="status"><?=escSolicitud($mensaje)?></div><?php endif; ?>
            <?php if ($errorConsulta !== ''): ?><div class="alert alert-warning" role="alert"><?=escSolicitud($errorConsulta)?></div><?php endif; ?>

            <?php if ($esSupervisor): ?>
            <section class="card">
                <div class="card-header"><h4 class="card-title"><?= $editar ? 'Editar solicitud #'.escSolicitud($editar['ID']) : 'Solicitar una cuenta' ?></h4><p class="mb-0 text-muted">El superusuario o administrador de IT creará la cuenta. <?= $requiereCentros ? 'Selecciona los centros que tú supervisas para que queden incluidos en la solicitud.' : 'Indica el área, el rol y el motivo de la cuenta.' ?></p></div>
                <div class="card-body">
                    <?php if ($errorConsulta === '' && !$areas): ?><div class="alert alert-warning">No hay áreas disponibles. Verifica que estén registrados Informática, Contabilidad y Ventas en la tabla areas.</div><?php endif; ?>
                    <?php if ($errorConsulta === '' && !$rolesSolicitados): ?><div class="alert alert-warning">No hay roles disponibles para solicitar. Verifica la tabla roles.</div><?php endif; ?>
                    <?php if ($errorConsulta === '' && $requiereCentros && !$centros): ?><div class="alert alert-warning">No tienes centros de venta asignados. No podrás enviar solicitudes hasta que te asignen centros.</div><?php endif; ?>
                    <form method="post" action="../../controllers/solicitudes_creacion_usuarios.php" class="row g-3">
                        <input type="hidden" name="csrf" value="<?=escSolicitud($csrf)?>">
                        <input type="hidden" name="accion" value="<?=$editar ? 'editar_solicitud' : 'crear_solicitud'?>"><input type="hidden" name="id_solicitud" value="<?=escSolicitud($editar['ID'] ?? '')?>">
                        <div class="col-md-6"><label for="nombre" class="form-label">Nombre completo *</label><input id="nombre" name="nombre" class="form-control" value="<?=escSolicitud($editar['NOMBRE_COMPLETO'] ?? '')?>" maxlength="60" required></div>
                        <div class="col-md-6"><label for="dni" class="form-label">DNI *</label><input id="dni" name="dni" class="form-control" value="<?=escSolicitud($editar['DNI'] ?? '')?>" maxlength="20" required></div>
                        <div class="col-md-6"><label for="telefono" class="form-label">Teléfono</label><input id="telefono" name="telefono" class="form-control" value="<?=escSolicitud($editar['TELEFONO'] ?? '')?>" maxlength="15" autocomplete="tel"></div>
                        <div class="col-md-6"><label for="correo" class="form-label">Correo</label><input id="correo" type="email" name="correo" class="form-control" value="<?=escSolicitud($editar['CORREO'] ?? '')?>" maxlength="50" autocomplete="email"></div>
                        <div class="col-md-6"><label for="id_area" class="form-label">Área *</label><select id="id_area" name="id_area" class="form-select" required><option value="">Selecciona un área</option><?php foreach ($areas as $area): ?><option value="<?=escSolicitud($area['ID'])?>" <?= (string)($editar['ID_AREA'] ?? '') === (string)$area['ID'] ? 'selected' : '' ?>><?=escSolicitud($area['NOMBRE'])?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label for="id_rol" class="form-label">Rol solicitado *</label><select id="id_rol" name="id_rol" class="form-select" required><option value="">Selecciona un rol</option><?php foreach ($rolesSolicitados as $rolDisponible): ?><option value="<?=escSolicitud($rolDisponible['ID'])?>" <?= (string)($editar['ID_ROL_SOLICITADO'] ?? '') === (string)$rolDisponible['ID'] ? 'selected' : '' ?>><?=escSolicitud($rolDisponible['ROL'])?></option><?php endforeach; ?></select><small class="text-muted">La cuenta no se crea hasta que la revise IT o el superusuario.</small></div>
                        <div class="col-12 solicitud-centros">
                            <label for="centros-solicitud" id="centros-solicitud-label" class="form-label">Centros de venta<?= $requiereCentros ? ' asignados por el supervisor *' : ' (opcional)' ?></label>
                            <select id="centros-solicitud" class="form-select" name="centros[]" multiple <?=$requiereCentros ? 'required' : ''?> data-requerido="<?=$requiereCentros ? '1' : '0'?>" aria-describedby="centros-ayuda centros-error" <?=!$centros ? 'disabled' : ''?>>
                                <?php foreach ($centros as $centro): ?>
                                <option value="<?=escSolicitud($centro['ID'])?>" <?=in_array((string)$centro['ID'], $centrosSeleccionados, true) ? 'selected' : ''?>><?=escSolicitud($centro['CENTRO_VENTA'])?></option>
                                <?php endforeach; ?>
                            </select>
                            <small id="centros-ayuda" class="text-muted">Busca y selecciona uno o varios centros de venta.</small>
                            <div id="centros-error" class="text-danger small" role="alert" hidden>Selecciona al menos un centro de venta.</div>
                        </div>
                        <div class="col-12"><label for="motivo" class="form-label">Motivo de la solicitud *</label><textarea id="motivo" name="motivo" class="form-control" maxlength="500" rows="3" required><?=escSolicitud($editar['MOTIVO'] ?? '')?></textarea></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit" <?=(($requiereCentros && !$centros) || !$areas || !$rolesSolicitados || $errorConsulta !== '') ? 'disabled' : ''?>><?=$editar ? 'Guardar cambios' : 'Enviar solicitud a IT'?></button><?php if ($editar): ?> <a href="solicitudes.php" class="btn btn-light-secondary">Cancelar edición</a><?php endif; ?></div>
                    </form>
                </div>
            </section>
            <section class="card"><div class="card-header"><h4 class="card-title">Mis solicitudes recientes</h4></div><div class="card-body table-responsive"><table class="table table-striped"><thead><tr><th>Fecha</th><th>Persona</th><th>Área</th><th>Rol solicitado</th><th>Centros</th><th>Estado</th><th>Acciones</th></tr></thead><tbody><?php foreach ($solicitudes as $solicitud): ?><tr><td><?=escSolicitud($solicitud['FECHA_CREO'])?></td><td><?=escSolicitud($solicitud['NOMBRE_COMPLETO'])?> · <?=escSolicitud($solicitud['DNI'])?></td><td><?=escSolicitud($solicitud['AREA'])?></td><td><?=escSolicitud($solicitud['ROL_SOLICITADO'])?></td><td><?=escSolicitud($solicitud['CENTROS'] ?? '')?></td><td><?=escSolicitud($solicitud['ESTADO'])?><?php if (!empty($solicitud['USUARIO_CREADO'])): ?><br><small>@<?=escSolicitud($solicitud['USUARIO_CREADO'])?></small><?php endif; ?></td><td><?php if ($solicitud['ESTADO'] === 'PENDIENTE'): ?><a class="btn btn-sm btn-outline-primary" href="solicitudes.php?editar=<?=escSolicitud($solicitud['ID'])?>">Editar</a><?php else: ?><span class="text-muted">Atendida</span><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$solicitudes): ?><tr><td colspan="7" class="text-muted">Todavía no has enviado solicitudes.</td></tr><?php endif; ?></tbody></table></div></section>
            <?php else: ?>
            <section class="card"><div class="card-header"><h4 class="card-title">Bandeja de solicitudes</h4><p class="mb-0 text-muted">Revisa los datos y centros solicitados. Después de crear la cuenta, marca la solicitud como atendida.</p></div><div class="card-body table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Fecha</th><th>Solicitante</th><th>Persona</th><th>Contacto</th><th>Área y rol</th><th>Centros</th><th>Motivo</th><th>Estado / acción</th></tr></thead><tbody>
                <?php foreach ($solicitudes as $solicitud): ?><tr><td><?=escSolicitud($solicitud['FECHA_CREO'])?></td><td><?=escSolicitud($solicitud['SOLICITANTE'])?></td><td><?=escSolicitud($solicitud['NOMBRE_COMPLETO'])?><br><small>DNI: <?=escSolicitud($solicitud['DNI'])?></small></td><td><?=escSolicitud($solicitud['TELEFONO'] ?: '—')?><br><?=escSolicitud($solicitud['CORREO'] ?: '—')?></td><td><?=escSolicitud($solicitud['AREA'])?><br><?=escSolicitud($solicitud['ROL_SOLICITADO'])?></td><td><?=escSolicitud($solicitud['CENTROS'] ?? '')?></td><td><?=escSolicitud($solicitud['MOTIVO'])?></td><td><?=escSolicitud($solicitud['ESTADO'])?><?php if ($solicitud['ESTADO'] === 'PENDIENTE'): ?><a class="btn btn-sm btn-primary mt-2" href="crear_desde_solicitud.php?id=<?=escSolicitud($solicitud['ID'])?>">Crear usuario</a><?php endif; ?></td></tr><?php endforeach; ?>
                <?php if (!$solicitudes): ?><tr><td colspan="8" class="text-muted">No hay solicitudes registradas.</td></tr><?php endif; ?>
            </tbody></table></div></section>
            <?php endif; ?>
        </div>
        <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
    </main>
</div>
<script src="../../assets/js/bootstrap.bundle.min.js"></script><script src="../../assets/js/main.js"></script>
<?php if ($esSupervisor): ?>
<script src="../../assets/vendors/choices.js/choices.min.js"></script>
<script src="../../js/solicitudes-usuarios.js?v=<?=filemtime(__DIR__ . '/../../js/solicitudes-usuarios.js')?>"></script>
<?php endif; ?>
</body>
</html>
