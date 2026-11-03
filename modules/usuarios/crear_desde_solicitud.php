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
if (!puedeVerSolicitudesUsuarios($pdo, $usuario, $rol)) {
    http_response_code(403);
    exit('Solo el superusuario o el administrador de IT puede crear usuarios desde una solicitud.');
}
$idSolicitud = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idSolicitud || $idSolicitud < 1) {
    http_response_code(400);
    exit('Solicitud no válida.');
}
$consulta = $pdo->prepare("SELECT S.ID, S.NOMBRE_COMPLETO, S.DNI, S.TELEFONO, S.CORREO,
        S.MOTIVO, S.ESTADO, S.USUARIO_SOLICITA, A.DESCRIPCION AS AREA, R.ROL AS ROL_SOLICITADO
    FROM SOLICITUDES_CREACION_USUARIOS S
    JOIN AREAS A ON A.ID = S.ID_AREA
    JOIN ROLES R ON R.ID = S.ID_ROL_SOLICITADO
    WHERE S.ID = ?");
$consulta->execute([$idSolicitud]);
$solicitud = $consulta->fetch(PDO::FETCH_ASSOC);
if (!$solicitud) {
    http_response_code(404);
    exit('La solicitud no existe.');
}
if ($solicitud['ESTADO'] !== 'PENDIENTE') {
    http_response_code(409);
    exit('La solicitud ya fue atendida.');
}
$centrosConsulta = $pdo->prepare('SELECT C.ID, C.CENTRO_VENTA
    FROM SOLICITUDES_CREACION_USUARIOS_CENTROS SC
    JOIN CENTRO_DE_VENTA C ON C.ID = SC.ID_CENTRO_VENTA
    WHERE SC.ID_SOLICITUD = ? ORDER BY C.CENTRO_VENTA');
$centrosConsulta->execute([$idSolicitud]);
$centros = $centrosConsulta->fetchAll(PDO::FETCH_ASSOC);
$requiereCentros = solicitudRequiereCentros(rolSolicitudUsuario($pdo, (int) $solicitud['USUARIO_SOLICITA']));
$_SESSION['solicitudes_usuarios_csrf'] ??= bin2hex(random_bytes(32));
function escCrearSolicitud($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include __DIR__ . '/../../includes/head.php'; ?>
    <link rel="stylesheet" href="../../assets/css/pages/crear-usuario-solicitud.css?v=<?=filemtime(__DIR__ . '/../../assets/css/pages/crear-usuario-solicitud.css')?>">
</head>
<body>
<div id="app">
    <div id="sidebar" class="active"><?php include __DIR__ . '/../../includes/menu_sidebar.php'; ?></div>
    <main id="main">
        <header class="mb-3"><a href="#" class="burger-btn d-block d-xl-none" aria-label="Abrir menú"><i class="bi bi-justify fs-3"></i></a></header>
        <div class="page-heading crear-usuario">
            <a class="solicitud-back" href="solicitudes.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a solicitudes</a>
            <div class="solicitud-heading">
                <div><p class="solicitud-eyebrow">GESTIÓN DE ACCESOS · SOLICITUD #<?=escCrearSolicitud($solicitud['ID'])?></p><h3>Crear cuenta de usuario</h3><p class="text-muted mb-0">Revisa los datos recibidos y define las credenciales de acceso.</p></div>
                <span class="solicitud-status"><span aria-hidden="true"></span>Pendiente de creación</span>
            </div>
            <div class="solicitud-layout">
                <div class="solicitud-details">
                    <section class="card solicitud-card">
                        <div class="card-header"><h4><i class="bi bi-person" aria-hidden="true"></i> Datos de la persona</h4><p>Información enviada por el solicitante.</p></div>
                        <div class="card-body">
                            <div class="solicitud-person"><div class="solicitud-avatar" aria-hidden="true"><i class="bi bi-person"></i></div><div><h5><?=escCrearSolicitud($solicitud['NOMBRE_COMPLETO'])?></h5><p>DNI <?=escCrearSolicitud($solicitud['DNI'])?></p></div></div>
                            <dl class="solicitud-data">
                                <div><dt>Teléfono</dt><dd><?=escCrearSolicitud($solicitud['TELEFONO'] ?: 'No proporcionado')?></dd></div>
                                <div><dt>Correo electrónico</dt><dd><?=escCrearSolicitud($solicitud['CORREO'] ?: 'No proporcionado')?></dd></div>
                                <div><dt>Área</dt><dd><?=escCrearSolicitud($solicitud['AREA'])?></dd></div>
                                <div><dt>Rol solicitado</dt><dd><?=escCrearSolicitud($solicitud['ROL_SOLICITADO'])?></dd></div>
                            </dl>
                            <div class="solicitud-motivo"><h6>Motivo de la solicitud</h6><p><?=escCrearSolicitud($solicitud['MOTIVO'])?></p></div>
                        </div>
                    </section>
                    <section class="card solicitud-card">
                        <div class="card-header solicitud-centros-heading"><div><h4><i class="bi bi-shop" aria-hidden="true"></i> Centros de venta</h4><p>Asignados por el supervisor.</p></div><span class="solicitud-count"><?=count($centros)?></span></div>
                        <div class="card-body">
                            <?php if ($centros): ?>
                            <label class="form-label visually-hidden" for="buscar-centros">Buscar en los centros asignados</label>
                            <input type="search" id="buscar-centros" class="form-control mb-3" placeholder="Buscar centro por nombre o código…" autocomplete="off" aria-controls="lista-centros">
                            <ul class="solicitud-centros" id="lista-centros"><?php foreach ($centros as $centro): ?><li><span class="solicitud-centro-id">#<?=escCrearSolicitud($centro['ID'])?></span><span><?=escCrearSolicitud($centro['CENTRO_VENTA'])?></span><i class="bi bi-check-circle text-success" aria-hidden="true"></i></li><?php endforeach; ?></ul>
                            <p id="centros-sin-resultados" class="text-muted mb-0" role="status" hidden>No hay centros que coincidan con la búsqueda.</p>
                            <?php else: ?><div class="alert alert-<?= $requiereCentros ? 'warning' : 'info' ?> mb-0"><?= $requiereCentros ? 'Esta solicitud no tiene centros asignados. No se puede crear la cuenta.' : 'Esta solicitud no incluye centros de venta; no son requeridos para este rol.' ?></div><?php endif; ?>
                        </div>
                    </section>
                </div>
                <section class="card solicitud-card solicitud-credentials">
                    <div class="card-header"><span class="solicitud-step">ACCESO AL SISTEMA</span><h4>Credenciales de la cuenta</h4><p>Completa los tres campos para crear el usuario.</p></div>
                    <div class="card-body">
                        <form id="crear-usuario-form" method="post" action="../../controllers/solicitudes_creacion_usuarios.php">
                            <input type="hidden" name="csrf" value="<?=escCrearSolicitud($_SESSION['solicitudes_usuarios_csrf'])?>">
                            <input type="hidden" name="accion" value="crear_desde_solicitud">
                            <input type="hidden" name="id_solicitud" value="<?=escCrearSolicitud($solicitud['ID'])?>">
                            <div class="mb-4"><label for="usuario_nuevo" class="form-label">Nombre de usuario <span class="text-danger">*</span></label><input id="usuario_nuevo" name="usuario_nuevo" class="form-control" minlength="3" maxlength="20" pattern="[A-Za-z0-9._-]{3,20}" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="usuario-ayuda" placeholder="Ej. jperez" required><small id="usuario-ayuda" class="form-text text-muted">Entre 3 y 20 caracteres. Puedes usar letras, números, punto, guion y guion bajo.</small></div>
                            <div class="mb-4"><label for="password_nuevo" class="form-label">Contraseña inicial <span class="text-danger">*</span></label><div class="input-group"><input id="password_nuevo" name="password_nuevo" type="password" class="form-control" maxlength="8" autocomplete="new-password" aria-describedby="password-ayuda" required><button class="btn btn-outline-secondary" type="button" data-toggle-password="password_nuevo" aria-label="Mostrar contraseña inicial" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small id="password-ayuda" class="form-text text-muted">Usa un máximo de 8 caracteres.</small></div>
                            <div class="mb-4"><label for="password_confirmar" class="form-label">Confirmar contraseña <span class="text-danger">*</span></label><div class="input-group"><input id="password_confirmar" name="password_confirmar" type="password" class="form-control" maxlength="8" autocomplete="new-password" aria-describedby="password-coincidencia" required><button class="btn btn-outline-secondary" type="button" data-toggle-password="password_confirmar" aria-label="Mostrar confirmación de contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small id="password-coincidencia" class="form-text" aria-live="polite"></small></div>
                            <div class="solicitud-result"><i class="bi bi-check-circle" aria-hidden="true"></i><p>Al crear la cuenta se guardarán su área, rol y los <strong><?=count($centros)?> centros seleccionados</strong>. La solicitud quedará atendida.</p></div>
                            <button id="crear-usuario-submit" type="submit" class="btn btn-primary w-100 py-3" <?=((!$centros && $requiereCentros) ? 'disabled' : '')?>><i class="bi bi-person-plus me-2" aria-hidden="true"></i>Crear cuenta de usuario</button>
                            <p class="solicitud-footer-note">Entrega las credenciales a la persona por un canal seguro.</p>
                        </form>
                    </div>
                </section>
            </div>
        </div>
        <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
    </main>
</div>
<script src="../../assets/js/bootstrap.bundle.min.js"></script><script src="../../assets/js/main.js"></script>
<script src="../../js/crear-usuario-solicitud.js?v=<?=filemtime(__DIR__ . '/../../js/crear-usuario-solicitud.js')?>"></script>
</body>
</html>