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
$bancosFiltro = [];
$error = '';
try {
    $consulta = database()->query('SELECT ID, DESCRIPCION FROM bancos WHERE ESTADO != 0 ORDER BY DESCRIPCION');
    foreach ($consulta as $banco) {
        $bancosFiltro[] = $banco;
        if (codigoLectorBanco($banco['DESCRIPCION']) !== null) {
            $bancos[] = $banco;
        }
    }
} catch (Throwable $e) {
    error_log('Formulario de estado de cuenta: ' . $e->getMessage());
    $error = 'No se pudieron consultar los bancos o los movimientos importados.';
}
?>
<!DOCTYPE html>
<html lang="es">
    
<head>
    <?php include '../../includes/head.php'; ?>
<style>
    html, body { height: auto; overflow-y: auto; }
    #main { min-height: 100vh; height: auto; overflow: visible; }
    #scroll-movimientos { max-height: 50vh; overflow: auto; }
    #tabla-movimientos { margin-bottom: 0; }
    #tabla-movimientos thead th { position: sticky; top: 0; z-index: 1; background: #fff; }
</style>
</head>

<body>
    <div id="app">
        <div id="sidebar" class="active">
            <?php include('../../includes/menu_sidebar.php'); ?>
        </div>
        <div id="main">
            <header class="mb-3">
                <a href="#" class="burger-btn d-block d-xl-none">
                    <i class="bi bi-justify fs-3"></i>
                    
                </a>
            </header>

            <div class="page-heading">
                <h3>Estados de cuenta</h3>
            </div>
            <div class="page-content">
                <section id="basic-horizontal-layouts">
                    <div class="row match-height">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4 class="card-title" style="padding: 0; margin: 0;">Importar estado de cuenta</h4>
                                </div>
                                <div class="card-content">
                                    <div class="card-body">
                                        
                                        <?php if ($error !== ''): ?>
                                            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <!-- El envío AJAX está en js/subir-estado-cuenta.js. -->
                                        <form id="form-subir-estado" class="form form-horizontal" method="post" action="../../controllers/estado_cuenta_txt.php" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['estado_txt_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="form-body">
                                                <div class="row">
                                                    <div class="col-md-3 form-group">
                                                        <label for="banco_id">Banco</label>
                                                        <select id="banco_id" name="banco_id" class="form-select" required>
                                                            <option value="">Selecciona un banco</option>
                                                            <?php foreach ($bancos as $banco): ?>
                                                                <option value="<?= (int) $banco['ID'] ?>"><?= htmlspecialchars($banco['DESCRIPCION'], ENT_QUOTES, 'UTF-8') ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6 form-group">
                                                        <label for="archivo">Archivo TXT (máximo 5 MB):</label>
                                                        <input id="archivo" type="file" name="archivo" class="form-control" accept=".txt" required>
                                                        <!-- <small class="text-muted">Admite los tres formatos mostrados y la plantilla sin encabezados. El número de cheque se captura si existe; en los demás queda vacío.</small> -->
                                                    </div>
                                                    
                                                    <div class="col-sm-12 d-flex justify-content-start">
                                                        
                                                        <button type="submit" class="btn btn-secondary btn-sm">Subir y guardar</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="card">
                    <div class="card-header">
                        <h4 class="card-title">Movimientos del estado de cuenta</h4>
                        <!-- Estos filtros son independientes del formulario para subir archivos. -->
                        <form id="filtros-movimientos" class="row align-items-end">
                            <div class="col-md-3 form-group">
                                <label for="filtro-banco">Banco</label>
                                <select id="filtro-banco" name="banco_id" class="form-select">
                                    <option value="">Todos los bancos</option>
                                    <?php foreach ($bancosFiltro as $banco): ?>
                                        <option value="<?= (int) $banco['ID'] ?>"><?= htmlspecialchars($banco['DESCRIPCION'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 form-group">
                                <label for="filtro-desde">Fecha de movimiento desde</label>
                                <input id="filtro-desde" type="date" name="desde" class="form-control">
                            </div>
                            <div class="col-md-3 form-group">
                                <label for="filtro-hasta">Hasta</label>
                                <input id="filtro-hasta" type="date" name="hasta" class="form-control">
                            </div>
                            <div class="col-md-3 form-group">
                                <button type="submit" class="btn btn-primary">Buscar</button>
                                <button id="limpiar-filtros" type="button" class="btn btn-outline-secondary">Limpiar</button>
                            </div>
                        </form>
                        <div class="row justify-content-end mb-3">
                            <div class="col-md-5">
                                <label for="buscar-movimientos">Buscar en la tabla</label>
                                <input id="buscar-movimientos" name="buscar" form="filtros-movimientos" type="search" class="form-control" maxlength="150" placeholder="Referencia, monto, fecha, descripción…">
                            </div>
                        </div>
                        <details class="mb-3">
                            <summary>Columnas a mostrar</summary>
                            <div id="columnas-movimientos" class="d-flex flex-wrap gap-3 mt-2">
                                <?php foreach (['Banco', 'Fecha', 'Referencia', 'Tipo', 'Descripción', 'Débito', 'Crédito', 'Saldo'] as $columna): ?>
                                    <label class="form-check"><input type="checkbox" class="form-check-input" checked> <?= htmlspecialchars($columna, ENT_QUOTES, 'UTF-8') ?></label>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <p id="mensaje-movimientos" class="text-muted" role="status" aria-live="polite">Subí un archivo o presioná Buscar para consultar el historial.</p>
                    </div>
                    <div class="card-body table-responsive">
                        <div id="panel-resumen-mensual" hidden>
                            <h5>Totales mensuales por banco</h5>
                            <p class="small text-muted">Totales de la carga actual o del historial consultado, según fecha y banco. La búsqueda por texto filtra solo el detalle. Balance: último saldo disponible por cuenta en cada mes del rango.</p>
                            <table class="table table-bordered">
                                <thead><tr><th>Mes</th><th>Banco</th><th>Moneda</th><th>Débitos</th><th>Créditos</th><th>Balance</th></tr></thead>
                                <tbody id="resumen-mensual"></tbody>
                            </table>
                        </div>
                        <div id="scroll-movimientos" class="mb-3" tabindex="0" role="region" aria-label="Movimientos del estado de cuenta">
                        <table id="tabla-movimientos" class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Banco</th>
                                    <th>Fecha</th>
                                    <th>Referencia</th>
                                    <th>Tipo</th>
                                    <th>Descripción</th>
                                    <th>Débito</th>
                                    <th>Crédito</th>
                                    <th>Saldo</th>
                                </tr>
                            </thead>
                            <tbody id="movimientos-estado">
                                <tr>
                                    <td colspan="8">Subí un archivo o presioná Buscar para consultar el historial.</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                        <nav class="d-flex align-items-center gap-3" aria-label="Páginas de movimientos">
                            <button id="pagina-anterior" type="button" class="btn btn-outline-primary" disabled>Anterior</button>
                            <span id="numero-pagina">Página 1</span>
                            <button id="pagina-siguiente" type="button" class="btn btn-outline-primary" disabled>Siguiente</button>
                        </nav>
                    </div>
                </section>
            </div>

           <?php include('../../includes/menu_footer.php'); ?>
        </div>
    </div>
    <script src="../../assets/vendors/perfect-scrollbar/perfect-scrollbar.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>

    <script src="../../assets/vendors/apexcharts/apexcharts.js"></script>
    <script src="../../assets/js/pages/dashboard.js"></script>

    <script src="../../assets/js/main.js"></script>
<script src="../../js/filtrar-movimientos-estado.js?v=<?= filemtime(__DIR__ . "/../../js/filtrar-movimientos-estado.js") ?>"></script>
<script src="../../assets/vendors/sweetalert2/sweetalert2.all.min.js"></script>
<script src="../../js/subir-estado-cuenta.js?v=<?= filemtime(__DIR__ . "/../../js/subir-estado-cuenta.js") ?>"></script>
</body>

</html>
