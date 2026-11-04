<?php
session_start();
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

header('Cache-Control: no-store');

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';
require_once __DIR__ . '/../../includes/benchmark_filtros.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

// Control de acceso por rol (5 y 6)
benchmark_requerir_pagina();

date_default_timezone_set('America/Tegucigalpa');
$hoy = date('Y-m-d');
const LIMITE_FILAS = 5000;

$filtros = benchmark_leer_filtros($_GET);
[$where, $params] = benchmark_armar_where($filtros);
$hayFiltros = count(array_filter($filtros, fn($v) => $v !== null)) > 0;

$detalles = [];
$globalFamComp = [];
$kpi = ['muestras' => 0, 'barato' => 0, 'caro' => 0, 'igual' => 0, 'brecha' => null];
$opciones = ['competidores' => [], 'familias' => [], 'categorias' => []];
$errorConsulta = null;

function minusculas(array $r): array { return array_change_key_case($r, CASE_LOWER); }

try {
    $opciones = benchmark_opciones_filtro($pdo);

    // Detalle (más reciente primero). Nada del usuario se concatena: todo va por parámetros.
    $stmt = $pdo->prepare("
        SELECT d.id AS id_detalle, " . BENCHMARK_COMPETIDOR_NOMBRE . " AS competidor, l.fecha_levantamiento,
               p.id AS id_producto, p.nombre_producto, p.clasificacion2 AS categoria, p.categoria AS subcategoria,
               f.nombre AS familia,
               " . BENCHMARK_PRECIO_PROPIO . " AS precio_propio,
               d.precio_competencia, d.observaciones
        " . BENCHMARK_FROM_BASE . "
        WHERE $where
        ORDER BY l.fecha_levantamiento DESC, d.id DESC
        LIMIT " . LIMITE_FILAS . "
    ");
    $stmt->execute($params);
    $detalles = array_map('minusculas', $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Comparativa global y KPIs (misma lógica que el Excel y el PDF)
    $globalFamComp = benchmark_resumen_familia_competidor($pdo, $where, $params);
    $kpi = benchmark_kpis_desde_resumen($globalFamComp);
} catch (PDOException $e) {
    error_log('benchmark_tabla_comparativa: ' . $e->getMessage());
    $errorConsulta = 'Error al consultar la matriz comparativa.';
}

$pct = fn(int $n) => $kpi['muestras'] > 0 ? round($n / $kpi['muestras'] * 100, 1) : 0;
$truncado = count($detalles) >= LIMITE_FILAS;
$qExport = benchmark_filtros_query($filtros);

function escapar($valor) {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function signo(float $n, int $dec = 2): string {
    return ($n > 0 ? '+' : '') . number_format($n, $dec);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include '../../includes/head.php'; ?>
    <link rel="stylesheet" href="../../assets/vendors/simple-datatables/style.css">
    <link rel="stylesheet" href="../../assets/css/benchmark.css">
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
                <div class="card benchmark-hero mb-4">
                    <div class="card-body p-4 d-flex align-items-center flex-wrap gap-3">
                        <div class="benchmark-hero-icon"><i class="fas fa-chart-bar"></i></div>
                        <div class="flex-grow-1">
                            <h3 class="text-white mb-1">Benchmark: Comparativa de Precios</h3>
                            <p class="mb-0 text-white-50">Precio por unidad de Banasupro contra la competencia, agrupado por familia</p>
                        </div>
                        <a href="benchmark_levantamientos.php" class="btn btn-light text-primary fw-bold"><i class="fas fa-plus-circle"></i> Nuevo Levantamiento</a>
                    </div>
                </div>

                <?php if ($errorConsulta): ?>
                    <div class="alert alert-danger"><?= escapar($errorConsulta) ?></div>
                <?php endif; ?>

                <!-- FILTROS -->
                <div class="card bm-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-filter text-primary"></i> Filtros</h6>
                        <div class="d-flex gap-2">
                            <a href="../../controllers/benchmark_controllers/benchmark_exportar_controller.php?formato=xlsx&<?= escapar($qExport) ?>" class="btn btn-success btn-sm" title="Descargar en Excel con los filtros aplicados"><i class="fas fa-file-excel"></i> Excel</a>
                            <a href="../../controllers/benchmark_controllers/benchmark_exportar_controller.php?formato=pdf&<?= escapar($qExport) ?>" class="btn btn-danger btn-sm" title="Descargar en PDF con los filtros aplicados"><i class="fas fa-file-pdf"></i> PDF</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="get" id="formFiltros" class="row g-3">
                            <div class="col-12 col-md-6 col-xl-3">
                                <label for="fCompetidor" class="form-label small fw-bold"><i class="fas fa-store text-primary"></i> Competidor</label>
                                <select id="fCompetidor" name="id_competidor" class="form-select form-select-sm">
                                    <option value="">Todos los competidores</option>
                                    <?php foreach ($opciones['competidores'] as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>" <?= $filtros['id_competidor'] === (int)$c['id'] ? 'selected' : '' ?>>
                                            <?= escapar($c['nombre']) ?><?= ((int)$c['estado'] === 1) ? '' : ' (inactivo)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6 col-xl-3">
                                <label for="fFamilia" class="form-label small fw-bold"><i class="fas fa-tags text-primary"></i> Familia</label>
                                <select id="fFamilia" name="id_familia" class="form-select form-select-sm">
                                    <option value="">Todas las familias</option>
                                    <?php foreach ($opciones['familias'] as $fam): ?>
                                        <option value="<?= (int)$fam['id'] ?>" <?= $filtros['id_familia'] === (int)$fam['id'] ? 'selected' : '' ?>><?= escapar($fam['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3 col-xl-2">
                                <label for="fDesde" class="form-label small fw-bold"><i class="far fa-calendar text-primary"></i> Desde</label>
                                <input type="date" id="fDesde" name="desde" class="form-control form-control-sm" max="<?= $hoy ?>" value="<?= escapar($filtros['desde']) ?>">
                            </div>
                            <div class="col-6 col-md-3 col-xl-2">
                                <label for="fHasta" class="form-label small fw-bold"><i class="far fa-calendar-check text-primary"></i> Hasta</label>
                                <input type="date" id="fHasta" name="hasta" class="form-control form-control-sm" max="<?= $hoy ?>" value="<?= escapar($filtros['hasta']) ?>">
                            </div>
                            <div class="col-12 col-xl-auto ms-xl-auto d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Aplicar filtros</button>
                                <?php if ($hayFiltros): ?>
                                    <a href="benchmark_tabla_comparativa.php" class="btn btn-light-secondary btn-sm"><i class="fas fa-eraser"></i> Limpiar</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- KPIs -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="card bm-card bm-kpi"><div class="card-body p-3 d-flex align-items-center">
                            <div class="bm-kpi-icon bg-light-primary text-primary me-3"><i class="fas fa-layer-group"></i></div>
                            <div><h6 class="text-muted small fw-bold mb-0">Productos comparados</h6><h4 class="fw-bold mb-0"><?= number_format($kpi['muestras']) ?></h4></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card bm-card bm-kpi"><div class="card-body p-3 d-flex align-items-center">
                            <div class="bm-kpi-icon bg-light-success text-success me-3"><i class="fas fa-check-circle"></i></div>
                            <div><h6 class="text-muted small fw-bold mb-0">Banasupro más barato</h6>
                                <h4 class="fw-bold text-success mb-0"><?= number_format($kpi['barato']) ?> <span class="fs-6 fw-normal text-muted">(<?= $pct($kpi['barato']) ?>%)</span></h4></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card bm-card bm-kpi"><div class="card-body p-3 d-flex align-items-center">
                            <div class="bm-kpi-icon bg-light-danger text-danger me-3"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><h6 class="text-muted small fw-bold mb-0">Banasupro más caro</h6>
                                <h4 class="fw-bold text-danger mb-0"><?= number_format($kpi['caro']) ?> <span class="fs-6 fw-normal text-muted">(<?= $pct($kpi['caro']) ?>%)</span></h4></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card bm-card bm-kpi"><div class="card-body p-3 d-flex align-items-center">
                            <div class="bm-kpi-icon bg-light-info text-info me-3"><i class="fas fa-percent"></i></div>
                            <div><h6 class="text-muted small fw-bold mb-0">Brecha promedio</h6>
                                <h4 class="fw-bold mb-0 <?= ($kpi['brecha'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?= $kpi['brecha'] === null ? '—' : signo($kpi['brecha']) . '%' ?></h4>
                                <small class="text-muted">+ = competencia más cara</small></div>
                        </div></div>
                    </div>
                </div>

                <!-- COMPARATIVA GLOBAL: FAMILIA x COMPETIDOR -->
                <div class="card bm-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-globe text-primary"></i> Comparativa global por familia y competidor</h6>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <small class="text-muted d-none d-md-inline">Productos según su último precio levantado</small>
                            <?php
                            $famGlobal = [];
                            foreach ($globalFamComp as $g) { $famGlobal[(int)$g['id_familia']] = $g['familia']; }
                            ?>
                            <?php if (count($famGlobal) > 1): ?>
                                <label for="fFamiliaGlobal" class="visually-hidden">Familia</label>
                                <select id="fFamiliaGlobal" class="form-select form-select-sm" style="min-width: 190px">
                                    <?php $primera = true; foreach ($famGlobal as $idF => $nomF): ?>
                                        <option value="<?= (int)$idF ?>" <?= $primera ? 'selected' : '' ?>><?= escapar($nomF) ?></option>
                                    <?php $primera = false; endforeach; ?>
                                    <option value="">Todas las familias (<?= count($famGlobal) ?>)</option>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 bm-tabla-movil">
                                <thead class="table-light">
                                    <tr>
                                        <th>Familia</th>
                                        <th>Competidor</th>
                                        <th class="text-center">Productos</th>
                                        <th class="text-center text-success">Banasupro más barato</th>
                                        <th class="text-center text-danger">Banasupro más caro</th>
                                        <th class="text-center">Igual</th>
                                        <th style="min-width:180px">Proporción</th>
                                        <th class="text-end">Brecha prom.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($globalFamComp)): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">No hay datos con los filtros elegidos.</td></tr>
                                <?php else:
                                    $famPrev = null;
                                    foreach ($globalFamComp as $g):
                                        $n = max(1, (int)$g['productos']);
                                        $gB = round((int)$g['barato'] / $n * 100); $gC = round((int)$g['caro'] / $n * 100); $gI = max(0, 100 - $gB - $gC);
                                        $gBr = $g['brecha'] === null ? null : (float)$g['brecha'];
                                        $nuevaFam = $famPrev !== $g['id_familia'];
                                        $famPrev = $g['id_familia'];
                                ?>
                                    <tr data-familia="<?= (int)$g['id_familia'] ?>" class="<?= $nuevaFam ? 'border-top border-2 bm-fam-nueva' : 'bm-fam-repetida' ?><?= (count($famGlobal) > 1 && (int)$g['id_familia'] !== (int)array_key_first($famGlobal)) ? ' d-none' : '' ?>">
                                        <td class="fw-bold bm-td-titulo" data-label="Familia"><?= escapar($g['familia']) ?></td>
                                        <td data-label="Competidor"><i class="fas fa-store text-muted"></i> <?= escapar($g['competidor']) ?></td>
                                        <td class="text-center" data-label="Productos"><?= (int)$g['productos'] ?></td>
                                        <td class="text-center" data-label="Más barato"><span class="badge bg-light-success text-success"><?= (int)$g['barato'] ?></span></td>
                                        <td class="text-center" data-label="Más caro"><span class="badge bg-light-danger text-danger"><?= (int)$g['caro'] ?></span></td>
                                        <td class="text-center" data-label="Igual"><span class="badge bg-light-secondary text-secondary"><?= (int)$g['igual'] ?></span></td>
                                        <td class="bm-td-barra">
                                            <div class="progress bm-barra" title="Más barato <?= $gB ?>% · Más caro <?= $gC ?>% · Igual <?= $gI ?>%">
                                                <div class="progress-bar bg-success" style="width: <?= $gB ?>%"></div>
                                                <div class="progress-bar bg-secondary" style="width: <?= $gI ?>%"></div>
                                                <div class="progress-bar bg-danger" style="width: <?= $gC ?>%"></div>
                                            </div>
                                        </td>
                                        <td class="text-end fw-bold text-nowrap <?= ($gBr ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>" data-label="Brecha prom."><?= $gBr === null ? '—' : signo($gBr) . '%' ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer small text-muted">
                        Ejemplo de lectura: <em>Leches · Competidor X · 5 más barato / 2 más caro</em> = de 7 productos comparados, Banasupro cobra menos en 5 y más en 2. Brecha positiva = la competencia es más cara.
                    </div>
                </div>

                <!-- DETALLE -->
                <div class="card bm-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-table text-primary"></i> Detalle de levantamientos</h6>
                        <small class="text-muted">Precios por unidad (L.)</small>
                    </div>
                    <div class="card-body">
                        <?php if ($truncado): ?>
                            <div class="alert alert-warning py-2 small">Se muestran las <?= number_format(LIMITE_FILAS) ?> visitas más recientes. Afina los filtros o exporta para ver todo.</div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="table1">
                                <thead class="table-light">
                                    <tr>
                                        <th>N.</th>
                                        <th>Competidor</th>
                                        <th>Fecha</th>
                                        <th>Familia</th>
                                        <th>Categoría</th>
                                        <th>Subcategoría</th>
                                        <th>Producto y presentación</th>
                                        <th class="text-end">Precio Banasupro (c/u)</th>
                                        <th class="text-end">Precio competencia (c/u)</th>
                                        <th class="text-end">Dif. (L.)</th>
                                        <th class="text-end">Dif. %</th>
                                        <th class="text-center">Estatus</th>
                                        <th>Notas</th>
                                        <th class="text-center">Ver</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $n = 0; foreach ($detalles as $row):
                                    $n++;
                                    $propio = (float)$row['precio_propio'];
                                    $comp = (float)$row['precio_competencia'];
                                    $dif = $comp - $propio;
                                    $difPct = $propio > 0 ? $dif / $propio * 100 : 0;
                                    if ($dif > 0)      { $estatus = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Más barato</span>'; $color = 'text-success fw-bold'; }
                                    elseif ($dif < 0)  { $estatus = '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Más caro</span>'; $color = 'text-danger fw-bold'; }
                                    else               { $estatus = '<span class="badge bg-secondary">Mismo precio</span>'; $color = 'text-muted'; }
                                    $linkAnalisis = 'benchmark_analisis.php?' . http_build_query([
                                        'id_producto' => (int)$row['id_producto'],
                                        'desde' => date('Y-m-d', strtotime('-89 days')),
                                        'hasta' => $hoy,
                                    ]);
                                ?>
                                    <tr>
                                        <td><?= $n ?></td>
                                        <td><strong><?= escapar($row['competidor']) ?></strong></td>
                                        <td data-order="<?= escapar($row['fecha_levantamiento']) ?>"><?= !empty($row['fecha_levantamiento']) ? date('d/m/Y', strtotime($row['fecha_levantamiento'])) : '-' ?></td>
                                        <td><span class="badge bg-light-primary"><?= escapar($row['familia']) ?></span></td>
                                        <td class="small"><?= escapar(benchmark_etiqueta($row['categoria'])) ?></td>
                                        <td class="small"><?= escapar(benchmark_etiqueta($row['subcategoria'])) ?></td>
                                        <td><?= escapar(benchmark_etiqueta($row['nombre_producto'])) ?></td>
                                        <td class="text-end text-nowrap fw-bold text-primary">L. <?= number_format($propio, 2) ?></td>
                                        <td class="text-end text-nowrap fw-bold">L. <?= number_format($comp, 2) ?></td>
                                        <td class="text-end text-nowrap <?= $color ?>"><?= signo($dif) ?></td>
                                        <td class="text-end text-nowrap <?= $color ?>"><?= signo($difPct) ?>%</td>
                                        <td class="text-center"><?= $estatus ?></td>
                                        <td><?= escapar($row['observaciones'] ?: '-') ?></td>
                                        <td class="text-center">
                                            <a href="<?= escapar($linkAnalisis) ?>" class="btn btn-sm btn-outline-primary" title="Ver evolución de este producto en el análisis"><i class="fas fa-chart-line"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($n === 0): ?>
                                    <tr><td colspan="14" class="text-center text-muted py-4">No hay registros con los filtros elegidos.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

            <?php include('../../includes/menu_footer.php'); ?>
        </div>
    </div>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Comparativa global: ver una familia a la vez (o todas)
            const selFam = document.getElementById('fFamiliaGlobal');
            if (selFam) {
                const filas = document.querySelectorAll('tr[data-familia]');
                const aplicar = function () {
                    filas.forEach(function (tr) {
                        tr.classList.toggle('d-none', selFam.value !== '' && tr.dataset.familia !== selFam.value);
                    });
                };
                selFam.addEventListener('change', aplicar);
                aplicar();
            }

            const tabla = document.querySelector('#table1');
            if (tabla && tabla.querySelector('tbody tr td[colspan]') === null) {
                new simpleDatatables.DataTable(tabla, { perPageSelect: [10, 25, 50, 100], perPage: 10 });
            }
        });
    </script>
    <script src="../../assets/js/main.js"></script>
</body>
</html>
