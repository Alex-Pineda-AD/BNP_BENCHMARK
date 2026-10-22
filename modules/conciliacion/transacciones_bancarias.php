<?php
session_start();

require_once __DIR__ . '/../../connection/database.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Debes iniciar sesión.');
}



$pdo = database();

// Filtros independientes del buscador y la paginacion del DataTable.
$bancos = $pdo->query('SELECT ID, DESCRIPCION FROM bancos WHERE ESTADO != 0 ORDER BY DESCRIPCION')->fetchAll();
$hoy = new DateTimeImmutable('now', new DateTimeZone('America/Tegucigalpa'));
$desde = $_GET['desde'] ?? $hoy->format('Y-m-01');
$hasta = $_GET['hasta'] ?? $hoy->format('Y-m-t');
if ($desde === '') $desde = $hoy->format('Y-m-01');
if ($hasta === '') $hasta = $hoy->format('Y-m-t');
$banco = $_GET['banco'] ?? '';
$errorFiltro = '';
$condiciones = ['E.ID_BANCO IN (SELECT ID FROM bancos WHERE ESTADO != 0)'];
$parametros = [];
if (!is_string($desde) || !is_string($hasta) || !is_string($banco)) {
    $errorFiltro = 'Los filtros no son válidos.';
    $desde = $hasta = $banco = '';
}
foreach ([$desde, $hasta] as $valor) {
    if ($valor === '') continue;
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    if (!$fecha || $fecha->format('Y-m-d') !== $valor) $errorFiltro = 'Ingresá fechas válidas.';
}
if ($desde !== '' && $hasta !== '' && $desde > $hasta) $errorFiltro = 'Desde no puede ser posterior a Hasta.';
if ($banco !== '' && (!ctype_digit($banco) || (int) $banco < 1)) $errorFiltro = 'Seleccioná un banco válido.';
if ($desde !== '') { $condiciones[] = 'T.FECHA >= ?'; $parametros[] = $desde; }
if ($hasta !== '') { $condiciones[] = 'T.FECHA < (CAST(? AS date) + INTERVAL \'1 day\')'; $parametros[] = $hasta; }
if ($banco !== '') { $condiciones[] = 'E.ID_BANCO = ?'; $parametros[] = $banco; }
$movimientos = [];
$resumen = [];
if ($errorFiltro === '') {
    $sql = 'SELECT T.FECHA, T.REFERENCIA, COALESCE(T.CODIGO, \'\') CODIGO,
        T.DESCRIPCION, TO_CHAR(T.DEBITO, \'FM999,999,999,999,999,990.00\') DEBITO, TO_CHAR(T.CREDITO, \'FM999,999,999,999,999,990.00\') CREDITO,
        TO_CHAR(T.BALANCE, \'FM999,999,999,999,999,990.00\') BALANCE
        FROM transacciones_estado_cuenta T
        INNER JOIN estados_cuenta E ON E.ID = T.ID_ESTADO_CIENTA';
    if ($condiciones) $sql .= ' WHERE ' . implode(' AND ', $condiciones);
    $sql .= ' ORDER BY T.ID ASC';
    $consulta = $pdo->prepare($sql);
    $consulta->execute($parametros);
    $movimientos = $consulta->fetchAll();
    // El balance es acumulado: tomar el ultimo saldo por cuenta de cada mes.
    // Mantener separadas las monedas para no sumar lempiras con dolares.
    $sqlResumen = "SELECT R.ID_BANCO, B.DESCRIPCION BANCO, R.MES, R.MONEDA,
        TO_CHAR(SUM(R.DEBITO), 'FM999,999,999,999,999,990.00') DEBITO, TO_CHAR(SUM(R.CREDITO), 'FM999,999,999,999,999,990.00') CREDITO,
        TO_CHAR(SUM(CASE WHEN R.ORDEN = 1 THEN R.BALANCE ELSE 0 END), 'FM999,999,999,999,999,990.00') BALANCE
        FROM (
            SELECT E.ID_BANCO, E.MONEDA, TO_CHAR(T.FECHA, 'YYYY-MM') MES,
                T.DEBITO, T.CREDITO, T.BALANCE,
                ROW_NUMBER() OVER (
                    PARTITION BY E.ID_BANCO, E.MONEDA, E.NUMERO_CUENTA, TO_CHAR(T.FECHA, 'YYYY-MM')
                    ORDER BY T.FECHA DESC, T.HORA DESC, T.ID DESC
                ) ORDEN
            FROM transacciones_estado_cuenta T
            INNER JOIN estados_cuenta E ON E.ID = T.ID_ESTADO_CIENTA";
    if ($condiciones) $sqlResumen .= ' WHERE ' . implode(' AND ', $condiciones);
    $sqlResumen .= ') R INNER JOIN bancos B ON B.ID = R.ID_BANCO
        GROUP BY R.ID_BANCO, B.DESCRIPCION, R.MES, R.MONEDA
        ORDER BY R.MES DESC, B.DESCRIPCION, R.MONEDA';
    $consultaResumen = $pdo->prepare($sqlResumen);
    $consultaResumen->execute($parametros);
    $resumen = $consultaResumen->fetchAll();
}

// Escapar cualquier dato que se vaya a mostrar como HTML.
function escapar($valor)
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
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
            <header class="mb-3">
                <a href="#" class="burger-btn d-block d-xl-none">
                    <i class="bi bi-justify fs-3"></i>
                </a>
            </header>

            <div class="page-heading">
                <div class="page-title">
                    <div class="row">
                        <div class="col-12 col-md-6 order-md-1 order-last">
                            <h3>Estados de cuenta</h3>
                            <!-- <p class="text-subtitle text-muted">For user to check they list</p> -->
                        </div>
                      
                    </div>
                </div>
                <section class="section">
                    <div class="card">
                        <div class="card-body">
                            <form method="GET" class="row g-3 align-items-end mb-3">
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <label for="filtro-desde" class="form-label">Fecha desde</label>
                                    <input id="filtro-desde" type="date" class="form-control" name="desde" value="<?= escapar($desde) ?>">
                                </div>
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <label for="filtro-hasta" class="form-label">Hasta</label>
                                    <input id="filtro-hasta" type="date" class="form-control" name="hasta" value="<?= escapar($hasta) ?>">
                                </div>
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <label for="filtro-banco" class="form-label">Banco</label>
                                    <select id="filtro-banco" name="banco" class="form-select">
                                        <option value="">Todos los bancos</option>
                                        <?php foreach ($bancos as $filaBanco): ?>
                                            <option value="<?= (int) $filaBanco['ID'] ?>" <?= (string) $filaBanco['ID'] === $banco ? 'selected' : '' ?>><?= escapar($filaBanco['DESCRIPCION']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-6 col-lg-3 d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Buscar</button>
                                    <a href="transacciones_bancarias.php" class="btn btn-outline-secondary">Limpiar</a>
                                </div>
                            </form>
                            <?php if ($errorFiltro !== ''): ?>
                                <div class="alert alert-danger" role="alert"><?= escapar($errorFiltro) ?></div>
                            <?php endif; ?>
                            <?php if ($errorFiltro === ''): ?>
                                <h5>Totales mensuales por banco</h5>
                                <p class="small text-muted">Del <?= escapar($desde) ?> al <?= escapar($hasta) ?>. Balance: suma del último saldo disponible por cuenta en cada mes del rango. El buscador de la tabla filtra el detalle; estos totales corresponden al rango y banco seleccionados.</p>
                                <div class="table-responsive mb-4">
                                    <table class="table table-bordered">
                                        <thead><tr><th>Mes</th><th>Banco</th><th>Moneda</th><th class="text-end">Débitos</th><th class="text-end">Créditos</th><th class="text-end">Balance</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($resumen as $total): ?>
                                                <tr>
                                                    <td><?= escapar($total['MES']) ?></td>
                                                    <td><?= escapar($total['BANCO']) ?></td>
                                                    <td><?= escapar($total['MONEDA']) ?></td>
                                                    <td class="text-end"><?= escapar($total['DEBITO']) ?></td>
                                                    <td class="text-end"><?= escapar($total['CREDITO']) ?></td>
                                                    <td class="text-end"><?= escapar($total['BALANCE']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (!$resumen): ?><tr><td colspan="6">No hay movimientos para este período.</td></tr><?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <details class="mb-3">
                                <summary>Columnas a mostrar</summary>
                                <div id="columnas-transacciones" class="d-flex flex-wrap gap-3 mt-2">
                                    <?php foreach (['Fecha', 'Referencia', 'Código', 'Descripción', 'Débito', 'Crédito', 'Saldo'] as $indice => $columna): ?>
                                        <label class="form-check"><input type="checkbox" class="form-check-input" value="<?= $indice ?>" checked> <?= escapar($columna) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <table class="table table-striped " id="table1">
                                <thead>
                                    <tr>
                                        <th>FECHA</th>
                                        <th>REFERENCIA</th>
                                        <th>CODIGO</th>
                                        <th>DESCRIPCION</th>
                                        <th>DEBITO</th>
                                        <th>CREDITO</th>
                                        <th>BALANCE</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    //$depositos = [];

                                   
                                            foreach ($movimientos as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['FECHA']) ?></td>
                                            <td><?= htmlspecialchars($row['REFERENCIA']) ?></td>
                                            <td><?= htmlspecialchars($row['CODIGO']) ?></td>
                                            <td><?= htmlspecialchars($row['DESCRIPCION']) ?></td>
                                            <td><?= 'L. '.$row['DEBITO'] ?></td>
                                            <td><?= 'L. '.$row['CREDITO'] ?></td>
                                            <td><?= 'L. '.$row['BALANCE'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                  
                                </tbody>
                            </table>
                        </div>
                    </div>

                </section>
            </div>
                <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
        </div>
</main>
    </div>
    <script src="../../assets/vendors/perfect-scrollbar/perfect-scrollbar.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>

    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script>
        // Simple Datatable
        let table1 = document.querySelector('#table1');
        let dataTable = new simpleDatatables.DataTable(table1);
        // Usar la API de columnas conserva los controles nativos del DataTable.
        document.getElementById('columnas-transacciones').addEventListener('change', function (evento) {
            const opcion = evento.target;
            const indice = Number(opcion.value);
            if (opcion.checked) dataTable.columns().show([indice]);
            else dataTable.columns().hide([indice]);
            const opciones = this.querySelectorAll('input');
            const visibles = this.querySelectorAll('input:checked').length;
            opciones.forEach(campo => campo.disabled = visibles === 1 && campo.checked);
        });
    </script>
    <script src="../../assets/js/main.js"></script>
</body>
</html>