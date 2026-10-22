<?php 
session_start();

require_once __DIR__ . '/../../connection/database.php';

$resultado = $_SESSION['estado_cuenta_resultado'] ?? null;
unset($_SESSION['estado_cuenta_resultado']);
$movimientos = [];
$bancos = [];
$errorConsulta = null;
if (empty($_SESSION['estado_cuenta_csrf'])) {
    $_SESSION['estado_cuenta_csrf'] = bin2hex(random_bytes(32));
}
try {
    $bancos = database()->query('SELECT ID, DESCRIPCION FROM bancos WHERE ESTADO = 1 ORDER BY DESCRIPCION')->fetchAll();
    $movimientos = database()->query('SELECT t.FECHA AS fecha, t.REFERENCIA AS referencia,
        t.CODIGO AS tipo, t.DESCRIPCION AS descripcion, t.DEBITO AS debito,
        t.CREDITO AS credito, t.BALANCE AS saldo, b.DESCRIPCION AS banco
        FROM transacciones_estado_cuenta t
        INNER JOIN estados_cuenta e ON e.ID = t.ID_ESTADO_CIENTA
        LEFT JOIN bancos b ON b.ID = e.ID_BANCO
        ORDER BY t.ID DESC LIMIT 100')->fetchAll();
} catch (PDOException $e) {
    $errorConsulta = 'No se pudieron consultar los bancos o los estados de cuenta.';
}

 ?>
<!DOCTYPE html>
<html lang="en">
    
<head>
    <?php include '../../includes/head.php'; ?>
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
                                    <h4 class="card-title">Importar estado de cuenta</h4>
                                </div>
                                <div class="card-content">
                                    <div class="card-body">
                                        <?php if ($resultado): ?>
                                            <div class="alert alert-<?= htmlspecialchars($resultado['tipo'], ENT_QUOTES, 'UTF-8') ?>" role="alert"><?= htmlspecialchars($resultado['mensaje'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if ($errorConsulta): ?>
                                            <div class="alert alert-warning" role="alert"><?= htmlspecialchars($errorConsulta, ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <form class="form form-horizontal" method="post" action="../../controllers/estado_cuenta_xls.php" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['estado_cuenta_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="form-body">
                                                <div class="row">
                                                    <div class="col-md-12 form-group">
                                                        <label for="id_banco">Banco</label>
                                                        <select id="id_banco" name="id_banco" class="form-select" required>
                                                            <option value="">Seleccione el banco</option>
                                                            <?php foreach ($bancos as $banco): ?>
                                                                <option value="<?= (int) $banco['ID'] ?>"><?= htmlspecialchars($banco['DESCRIPCION'], ENT_QUOTES, 'UTF-8') ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-12 form-group">
                                                        <label>Estado de Cuenta (Excel)</label>
                                                        <input type="file" name="estado_cuenta" class="form-control" accept=".xlsx" required>
                                                        <small class="text-muted">Admite los tres formatos mostrados y la plantilla sin encabezados. El número de cheque se captura si existe; en los demás queda vacío.</small>
                                                    </div>
                                                    
                                                    <div class="col-sm-12 d-flex justify-content-end">
                                                        <button type="submit" class="btn btn-primary me-1 mb-1">Importar</button>
                                                        <button type="reset" class="btn btn-light-secondary me-1 mb-1">Limpiar</button>
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
                    <div class="card-header"><h4 class="card-title">Últimos movimientos importados</h4></div>
                    <div class="card-body table-responsive">
                        <table class="table table-striped">
                            <thead><tr><th>Banco</th><th>Fecha</th><th>Referencia</th><th>Tipo</th><th>Descripción</th><th>Débito</th><th>Crédito</th><th>Saldo</th></tr></thead>
                            <tbody>
                            <?php foreach ($movimientos as $movimiento): ?>
                                <tr>
                                    <?php foreach (['banco', 'fecha', 'referencia', 'tipo', 'descripcion', 'debito', 'credito', 'saldo'] as $campo): ?>
                                        <td><?= htmlspecialchars((string) $movimiento[$campo], ENT_QUOTES, 'UTF-8') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$movimientos): ?><tr><td colspan="8">No hay movimientos importados.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
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
</body>

</html>
