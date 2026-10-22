<?php
session_start();
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) { header('Location: ../../index.html?sesion=requerida'); exit; }
require_once __DIR__ . '/../../connection/database.php';
require_once __DIR__ . '/../../includes/centros_venta.php';
require_once __DIR__ . '/../../includes/deposito_ciclo.php';
function esc($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$_SESSION['depositos_csrf'] = $_SESSION['depositos_csrf'] ?? bin2hex(random_bytes(32));
$depositos = []; $bancos = []; $centros = []; $error = '';
// Filtros del listado (no modifican los datos del deposito).
$fechaDesde = is_string($_GET['fecha_desde'] ?? '') ? trim($_GET['fecha_desde'] ?? '') : '';
$fechaHasta = is_string($_GET['fecha_hasta'] ?? '') ? trim($_GET['fecha_hasta'] ?? '') : '';
$bancoFiltro = is_string($_GET['banco'] ?? '') ? trim($_GET['banco'] ?? '') : '';
$porCorregir = ($_GET['por_corregir'] ?? '') === '1';
$errorFiltro = '';
foreach ([$fechaDesde, $fechaHasta] as $fecha) {
    if ($fecha === '') continue;
    $fechaValida = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha) {
        $errorFiltro = 'Ingresá fechas válidas para realizar la búsqueda.';
    }
}
if (!$errorFiltro && $fechaDesde !== '' && $fechaHasta !== '' && $fechaDesde > $fechaHasta) {
    $errorFiltro = 'La fecha desde no puede ser mayor que la fecha hasta.';
}
if ($bancoFiltro !== '' && (!ctype_digit($bancoFiltro) || (int) $bancoFiltro < 1)) {
    $errorFiltro = 'Seleccioná un banco válido.';
}
try {
    $pdo = database();
    $usuario = $pdo->prepare('SELECT ID FROM usuarios WHERE ID = ? AND ESTADO = 1');
    $usuario->execute([$_SESSION['user_id']]);
    if (!$usuario->fetchColumn()) { http_response_code(403); exit('El usuario no está activo.'); }
    // Mis depositos: solo los registrados por el usuario que inicio sesion.
    $sql = 'SELECT A.*, B.DESCRIPCION BANCO, C.CENTRO_VENTA, C.ID ID_CENTRO_VENTA,
        E.DESCRIPCION ESTADO_NOMBRE, D.URL FOTO_URL,
        S.OBSERVACION MOTIVO_CORRECCION, S.FECHA_CREO FECHA_SOLICITUD_CORRECCION,
        U.NOMBRE_COMPLETO SOLICITANTE_CORRECCION,
        '.depositoAprobacionVigenteSql('A.ID').' FUE_APROBADO
        FROM depositos A
        LEFT JOIN bancos B ON B.ID = A.ID_BANCO
        LEFT JOIN centro_de_venta C ON C.ID = A.ID_CENTRO_VENTA
        LEFT JOIN estados E ON E.ID = A.ESTADO
        LEFT JOIN depositos_bitacora S ON S.ID = (
            SELECT MAX(X.ID) FROM depositos_bitacora X WHERE X.ID_DEPOSITO = A.ID
            AND X.ACCION = \'SOLICITUD_CORRECCION\' AND X.ESTADO_NUEVO = 7)
        LEFT JOIN usuarios U ON U.ID = S.USUARIO_CREO
        LEFT JOIN depositos_detalle D ON D.ID_DEPOSITO_DETALLE =
            (SELECT MAX(X.ID_DEPOSITO_DETALLE) FROM depositos_detalle X WHERE X.ID_DEPOSITO = A.ID)
        WHERE A.USUARIO_CREO = ?';
    $parametros = [$_SESSION['user_id']];
    if (!$errorFiltro) {
        if ($fechaDesde !== '') {
            $sql .= ' AND A.FECHA_CREO >= ?';
            $parametros[] = $fechaDesde . ' 00:00:00';
        }
        if ($fechaHasta !== '') {
            // Incluye todo el ultimo dia seleccionado.
            $sql .= ' AND A.FECHA_CREO <= ?';
            $parametros[] = $fechaHasta . ' 23:59:59';
        }
        if ($bancoFiltro !== '') {
            $sql .= ' AND A.ID_BANCO = ?';
            $parametros[] = $bancoFiltro;
        }
        if ($porCorregir) {
            $sql .= ' AND A.ESTADO = 7';
        }
        $sql .= ' ORDER BY A.ID DESC';
        $consulta = $pdo->prepare($sql);
        $consulta->execute($parametros);
        $depositos = $consulta->fetchAll();
    }
    $bancosFiltro = $pdo->query('SELECT ID, DESCRIPCION, N_CUENTA FROM bancos WHERE ESTADO = 1 ORDER BY DESCRIPCION')->fetchAll();
    $bancos = $pdo->query('SELECT ID, DESCRIPCION FROM bancos WHERE ESTADO = 1 ORDER BY DESCRIPCION')->fetchAll();
    $centros = centrosVentaUsuario($pdo, (int) $_SESSION['user_id']);



} catch (Throwable $e) { error_log($e->getMessage()); $error = 'No se pudieron consultar los depósitos. Intentá nuevamente.'; }
?>
<!DOCTYPE html>
<html lang="es">
    <head>
        <?php include __DIR__ . '/../../includes/head.php'; ?>
        <link rel="stylesheet" href="../../assets/vendors/simple-datatables/style.css">
    </head>
    <body>
        <div id="app">
            <div id="sidebar" class="active"><?php include __DIR__ . '/../../includes/menu_sidebar.php'; ?></div>
            <main id="main">
                <header class="mb-3"><a href="#" class="burger-btn d-block d-xl-none"><i class="bi bi-justify fs-3"></i></a></header>
                <h3>Mis depósitos</h3>
                <div id="panelAlertasSeguimiento" data-pantalla="DEPOSITOS">
                    <?php if (!$error) { $pantallaAlertas = 'DEPOSITOS'; include __DIR__ . '/../../includes/panel_alertas_depositos.php'; } ?>
                </div>
                
                <?php if ($error): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>
                <?php if (isset($_SESSION['deposito_mensaje'])): ?>
                    <div class="alert alert-<?= esc($_SESSION['deposito_tipo']) ?>" role="alert"><?= esc($_SESSION['deposito_mensaje']) ?></div>
                    <?php unset($_SESSION['deposito_mensaje'], $_SESSION['deposito_tipo']); ?>
                <?php endif; ?>
               

                <div class="card"><div class="card-body">
                    <form method="GET" action="mis_depositos.php" class="row g-3 align-items-end mb-4">
                        <div class="col-md-3">
                            <label for="fecha_desde" class="form-label">Fecha desde</label>
                            <input type="date" id="fecha_desde" name="fecha_desde" class="form-control" value="<?= esc($fechaDesde) ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="fecha_hasta" class="form-label">Fecha hasta</label>
                            <input type="date" id="fecha_hasta" name="fecha_hasta" class="form-control" value="<?= esc($fechaHasta) ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="bancoFiltro" class="form-label">Banco</label>
                            <select id="bancoFiltro" name="banco" class="form-select">
                                <option value="">Todos los bancos</option>
                                <?php foreach ($bancosFiltro ?? [] as $banco): ?>
                                    <option value="<?= esc($banco['ID']) ?>" <?= (string) $banco['ID'] === $bancoFiltro ? 'selected' : '' ?>><?= esc($banco['DESCRIPCION'].' - '.$banco['N_CUENTA']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mb-2">
                                <input type="checkbox" id="por_corregir" name="por_corregir" value="1" class="form-check-input" <?= $porCorregir ? 'checked' : '' ?>>
                                <label for="por_corregir" class="form-check-label">Solo por corregir</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                            <a href="mis_depositos.php" class="btn btn-light-secondary">Limpiar</a>
                            <span class="small text-muted ms-2">Rango según la fecha de registro del depósito.</span>
                        </div>
                    </form>
                    <?php if ($errorFiltro): ?><div class="alert alert-warning"><?= esc($errorFiltro) ?></div><?php endif; ?>
                    <?php if (!$depositos && !$error && !$errorFiltro): ?><p>No se encontraron depósitos para esta búsqueda.</p><?php endif; ?>
                    <div class="table-responsive"><table class="table table-striped" id="table1">
                        <thead>
                            <tr>
                                <th>CÓDIGO</th>
                                <th>CENTRO VENTA</th>
                                <th>REF / DOC</th>
                                <th>BANCO</th>
                                <th>MONTO</th>
                                <th>ESTADO</th>
                                <th>FECHA</th>
                                <th>ACCIONES</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($depositos as $row): 
                                    $std = $row['ESTADO_NOMBRE'] == 'REPORTE ENVIADO'
                                        ? '<span class="badge bg-primary text-white"><i class="fas fa-paper-plane"></i> REPORTE ENVIADO</span>'
                                        : ($row['ESTADO_NOMBRE'] == 'APROBADO'
                                            ? '<span class="badge bg-success text-white"><i class="fas fa-check-circle"></i> APROBADO</span>'
                                            
                                            : ($row['ESTADO_NOMBRE'] == 'CORREGIDA Y APROBADA'
                                                ? '<span class="badge bg-success text-white"><i class="fas fa-check-circle"></i> APROBADA</span>'
                                                : '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle"></i> POR CORREGIR</span>'
                                            )
                                        );
                            ?>
                            <tr>
                                <td><?= esc($row['CODIGO'] ?: '#' . $row['ID']) ?></td>
                                <td><?= esc($row['ID_CENTRO_VENTA'].' - '.$row['CENTRO_VENTA']) ?></td>
                                
                                <td style="text-align: right;"><?= esc($row['NUMERO_REFERENCIA']) ?></td>
                                <td><?= '<strong>'.$row['BANCO'].'</strong> - '.esc($row['NUMERO_CUENTA'])  ?></td>
                                <td style="text-align: right;"><?= 'L. '.number_format((float) $row['MONTO'], 2) ?></td>
                                <td><?= $std ?></td>
                                <td style="text-align: right; width: 5;"><?= esc($row['FECHA_CREO']) ?></td>
                                <td>
                                    <?php /*if ($row['FOTO_URL'] && preg_match('~^files/depositos/[a-zA-Z0-9_.-]+$~D', $row['FOTO_URL'])): ?>
                                        <a class="btn btn-info btn-sm" target="_blank" rel="noopener" href="../../<?= esc($row['FOTO_URL']) ?>">Ver foto</a>
                                    <?php endif; */?>
                                    <?php if (!in_array((int) $row['ESTADO'], [5, 6], true) && !$row['FUE_APROBADO']): ?>
                                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editarDeposito" data-deposito="<?= esc(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-edit"></i> Editar</button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled title="El depósito ya fue aprobado o cerrado"><i class="fas fa-edit"></i>Editar</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div></div>
                <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
            </main>
        </div>
        <?php include __DIR__ . '/../../modals/modals_conciliacion/modal_deposito_editar.php'; ?>
        <script src="../../assets/js/bootstrap.bundle.min.js"></script>
        <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
        <script>new simpleDatatables.DataTable(document.getElementById('table1'));</script>
        <script src="../../assets/js/main.js"></script>
        <script src="../../js/alertas-seguimiento.js"></script>
        <script src="../../js/editar-deposito.js?v=<?= filemtime(__DIR__ . '/../../js/editar-deposito.js') ?>"></script>
    </body>
</html>
