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
               d.precio_competencia, d.observaciones, d.base_comparacion
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

// Rankings del panel "Lo más importante" (si fallan, solo se oculta el panel)
$rank = ['caros' => [], 'baratos' => [], 'familias' => [], 'competidores' => []];
if (!$errorConsulta) {
    try {
        $rank = benchmark_rankings($pdo, $where, $params);
    } catch (PDOException $e) {
        error_log('benchmark_tabla_comparativa (rankings): ' . $e->getMessage());
    }
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
                            <div class="col-12 col-md-6 col-xl-3">
                                <label class="form-label small fw-bold"><i class="far fa-calendar text-primary"></i> Desde la semana</label>
                                <input type="hidden" id="fDesde" name="desde" value="<?= escapar($filtros['desde']) ?>">
                                <div class="bm-semana" data-target="fDesde" data-edge="inicio" data-par="fHasta" data-vacio="Sin límite"></div>
                            </div>
                            <div class="col-12 col-md-6 col-xl-3">
                                <label class="form-label small fw-bold"><i class="far fa-calendar-check text-primary"></i> Hasta la semana</label>
                                <input type="hidden" id="fHasta" name="hasta" value="<?= escapar($filtros['hasta']) ?>">
                                <div class="bm-semana" data-target="fHasta" data-edge="fin" data-par="fDesde" data-vacio="Sin límite"></div>
                            </div>
                            <?php if ($hayFiltros): ?>
                            <div class="col-12 col-xl-auto d-flex align-items-end">
                                <a href="benchmark_tabla_comparativa.php" class="btn btn-light-secondary btn-sm" title="Quitar todos los filtros"><i class="fas fa-eraser"></i> Limpiar</a>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- KPIs -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="card bm-card bm-kpi"><div class="card-body p-2 px-3 d-flex align-items-center">
                            <div class="bm-kpi-icon bg-light-primary text-primary me-3"><i class="fas fa-layer-group"></i></div>
                            <div><h6 class="text-muted small fw-bold mb-0">Productos comparados</h6><h4 class="fw-bold mb-0"><?= number_format($kpi['muestras']) ?></h4><small class="text-muted">Con su último precio levantado</small></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card bm-card bm-kpi"><div class="card-body p-2 px-3 d-flex align-items-center">
                            <div class="bm-kpi-icon bg-light-success text-success me-3"><i class="fas fa-check-circle"></i></div>
                            <div><h6 class="text-muted small fw-bold mb-0">Banasupro más barato</h6>
                                <h4 class="fw-bold text-success mb-0"><?= number_format($kpi['barato']) ?> <span class="fs-6 fw-normal text-muted">(<?= $pct($kpi['barato']) ?>%)</span></h4><small class="text-muted">Cobramos menos que la competencia</small></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card bm-card bm-kpi"><div class="card-body p-2 px-3 d-flex align-items-center">
                            <div class="bm-kpi-icon bg-light-danger text-danger me-3"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><h6 class="text-muted small fw-bold mb-0">Banasupro más caro</h6>
                                <h4 class="fw-bold text-danger mb-0"><?= number_format($kpi['caro']) ?> <span class="fs-6 fw-normal text-muted">(<?= $pct($kpi['caro']) ?>%)</span></h4><small class="text-muted">Cobramos más: conviene revisarlos</small></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card bm-card bm-kpi"><div class="card-body p-2 px-3 d-flex align-items-center">
                            <div class="bm-kpi-icon bg-light-info text-info me-3"><i class="fas fa-percent"></i></div>
                            <div><h6 class="text-muted small fw-bold mb-0">Brecha promedio</h6>
                                <h4 class="fw-bold mb-0 <?= ($kpi['brecha'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?= $kpi['brecha'] === null ? '—' : signo($kpi['brecha']) . '%' ?></h4>
                                <small class="text-muted">+ Banasupro gana · − Banasupro más caro</small></div>
                        </div></div>
                    </div>
                </div>

                <!-- LO MÁS IMPORTANTE -->
                <?php
                $filaProducto = function (array $r, int $i, bool $caro) {
                    $baseTxt = ($r['base_comparacion'] ?? 'unidad') === 'paquete' ? 'paquete' : 'unidad';
                    $color = $caro ? 'text-danger' : 'text-success';
                    ?>
                    <tr>
                        <td class="text-muted"><?= $i ?></td>
                        <td>
                            <strong class="d-block"><?= escapar(benchmark_etiqueta($r['nombre_producto'])) ?></strong>
                            <small class="text-muted"><?= escapar($r['familia']) ?> · <?= escapar($r['competidor']) ?> · por <?= $baseTxt ?></small>
                        </td>
                        <td class="text-end text-nowrap">L. <?= number_format((float)$r['pp'], 2) ?></td>
                        <td class="text-end text-nowrap">L. <?= number_format((float)$r['pc'], 2) ?></td>
                        <td class="text-end text-nowrap fw-bold <?= $color ?>"><?= signo((float)$r['brecha'], 1) ?>%</td>
                    </tr>
                    <?php
                };
                $encabezadoProducto = '<thead class="table-light"><tr><th>#</th><th>Producto</th><th class="text-end">Banasupro</th><th class="text-end">Competencia</th><th class="text-end">Brecha</th></tr></thead>';
                ?>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-xl-8">
                        <div class="card bm-card h-100">
                            <div class="card-header pb-0 border-bottom-0">
                                <ul class="nav nav-tabs card-header-tabs bm-tabs" role="tablist">
                                    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabCaros" type="button" role="tab"><i class="fas fa-exclamation-triangle"></i> Para revisar · más caros</button></li>
                                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabBaratos" type="button" role="tab"><i class="fas fa-thumbs-up"></i> Mayor ventaja</button></li>
                                </ul>
                            </div>
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="tabCaros" role="tabpanel">
<div class="bm-ayuda"><i class="fas fa-info-circle"></i><span>Productos donde Banasupro cobra <strong>más</strong> que ese competidor. Una brecha de <strong>−12%</strong> significa que la competencia vende 12% más barato: son los primeros candidatos a revisar.</span></div>
                                    <?php if (empty($rank['caros'])): ?>
                                        <p class="text-center text-muted py-4 mb-0">Banasupro no está más caro en ningún producto con estos filtros.</p>
                                    <?php else: ?>
                                        <div class="bm-scroll"><table class="table table-sm align-middle mb-0"><?= $encabezadoProducto ?><tbody>
                                            <?php foreach ($rank['caros'] as $i => $r) { $filaProducto($r, $i + 1, true); } ?>
                                        </tbody></table></div>
                                        <div class="bm-nota">De mayor a menor brecha · top <?= count($rank['caros']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="tab-pane fade" id="tabBaratos" role="tabpanel">
<div class="bm-ayuda"><i class="fas fa-info-circle"></i><span>Productos donde Banasupro cobra <strong>menos</strong>. Una brecha de <strong>+25%</strong> significa que la competencia cobra 25% más que nosotros: es nuestra ventaja.</span></div>
                                    <?php if (empty($rank['baratos'])): ?>
                                        <p class="text-center text-muted py-4 mb-0">Sin productos donde Banasupro sea más barato con estos filtros.</p>
                                    <?php else: ?>
                                        <div class="bm-scroll"><table class="table table-sm align-middle mb-0"><?= $encabezadoProducto ?><tbody>
                                            <?php foreach ($rank['baratos'] as $i => $r) { $filaProducto($r, $i + 1, false); } ?>
                                        </tbody></table></div>
                                        <div class="bm-nota">De mayor a menor brecha · top <?= count($rank['baratos']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-xl-4">
                        <div class="card bm-card h-100">
                            <div class="card-header pb-0 border-bottom-0">
                                <ul class="nav nav-tabs card-header-tabs bm-tabs" role="tablist">
                                    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabFamilias" type="button" role="tab"><i class="fas fa-tags"></i> Por familia</button></li>
                                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCompetidores" type="button" role="tab"><i class="fas fa-store"></i> Por competidor</button></li>
                                </ul>
                            </div>
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="tabFamilias" role="tabpanel">
<div class="bm-ayuda"><i class="fas fa-info-circle"></i><span>Cuántos productos de cada familia están más caros que la competencia. Barra más llena = más productos por revisar.</span></div>
                                    <div class="bm-scroll bm-scroll-lista">
                                    <?php if (empty($rank['familias'])): ?>
                                        <p class="text-muted text-center py-4 mb-0">Sin familias con productos más caros.</p>
                                    <?php else: foreach ($rank['familias'] as $r): $p = $r['productos'] > 0 ? round($r['caro'] / $r['productos'] * 100) : 0; ?>
                                        <div class="bm-item-barra">
                                            <div class="d-flex justify-content-between small"><strong><?= escapar($r['familia']) ?></strong><span class="text-danger fw-bold"><?= (int)$r['caro'] ?> de <?= (int)$r['productos'] ?> · <?= $p ?>%</span></div>
                                            <div class="progress bm-barra"><div class="progress-bar bg-danger" style="width: <?= $p ?>%"></div></div>
                                        </div>
                                    <?php endforeach; endif; ?>
                                    </div>
                                    <div class="bm-nota">Productos donde Banasupro es más caro</div>
                                </div>
                                <div class="tab-pane fade" id="tabCompetidores" role="tabpanel">
<div class="bm-ayuda"><i class="fas fa-info-circle"></i><span>En cuántos de los productos comparados cada cadena nos gana en precio. Barra más llena = competidor más agresivo.</span></div>
                                    <div class="bm-scroll bm-scroll-lista">
                                    <?php if (empty($rank['competidores'])): ?>
                                        <p class="text-muted text-center py-4 mb-0">Sin datos con estos filtros.</p>
                                    <?php else: foreach ($rank['competidores'] as $r): $p = $r['productos'] > 0 ? round($r['caro'] / $r['productos'] * 100) : 0; ?>
                                        <div class="bm-item-barra">
                                            <div class="d-flex justify-content-between small"><strong><?= escapar($r['competidor']) ?></strong><span class="text-danger fw-bold"><?= (int)$r['caro'] ?> de <?= (int)$r['productos'] ?> · <?= $p ?>%</span></div>
                                            <div class="progress bm-barra"><div class="progress-bar bg-danger" style="width: <?= $p ?>%"></div></div>
                                        </div>
                                    <?php endforeach; endif; ?>
                                    </div>
                                    <div class="bm-nota">Productos donde nos superan</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RESUMEN POR FAMILIA (el desglose por competidor se abre con un clic) -->
                <?php
                $resumenFam = [];
                foreach ($globalFamComp as $g) {
                    $id = (int)$g['id_familia'];
                    if (!isset($resumenFam[$id])) { $resumenFam[$id] = ['familia' => $g['familia'], 'productos' => 0, 'barato' => 0, 'caro' => 0, 'igual' => 0, 'suma' => 0.0, 'filas' => []]; }
                    $resumenFam[$id]['productos'] += (int)$g['productos'];
                    $resumenFam[$id]['barato'] += (int)$g['barato'];
                    $resumenFam[$id]['caro'] += (int)$g['caro'];
                    $resumenFam[$id]['igual'] += (int)$g['igual'];
                    $resumenFam[$id]['suma'] += (float)$g['brecha'] * (int)$g['productos'];
                    $resumenFam[$id]['filas'][] = $g;
                }
                // Primero las familias con mayor proporción de productos más caros
                uasort($resumenFam, function ($a, $b) {
                    $pa = $a['productos'] ? $a['caro'] / $a['productos'] : 0; $pb = $b['productos'] ? $b['caro'] / $b['productos'] : 0;
                    return [$pb, $b['caro']] <=> [$pa, $a['caro']];
                });
                $barraProporcion = function (int $n, int $b, int $c) {
                    $n = max(1, $n); $pb = round($b / $n * 100); $pc = round($c / $n * 100); $pi = max(0, 100 - $pb - $pc);
                    echo '<div class="progress bm-barra" title="Más barato ' . $pb . '% · Más caro ' . $pc . '% · Igual ' . $pi . '%">'
                       . '<div class="progress-bar bg-success" style="width:' . $pb . '%"></div><div class="progress-bar bg-secondary" style="width:' . $pi . '%"></div><div class="progress-bar bg-danger" style="width:' . $pc . '%"></div></div>';
                };
                ?>
                <div class="card bm-card">
                    <div class="card-header pb-0 border-bottom-0 d-flex justify-content-between align-items-end flex-wrap gap-2">
                        <ul class="nav nav-tabs card-header-tabs bm-tabs" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabDetalle" type="button" role="tab"><i class="bi bi-table"></i> Detalle de levantamientos <span class="badge bm-tab-badge"><?= number_format(count($detalles)) ?></span></button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabResumen" type="button" role="tab"><i class="fas fa-layer-group"></i> Resumen por familia <span class="badge bm-tab-badge"><?= count($resumenFam) ?></span></button></li>
                        </ul>
                    </div>
                    <div class="tab-content">
                    <div class="tab-pane fade" id="tabResumen" role="tabpanel">
<div class="bm-ayuda"><i class="fas fa-info-circle"></i><span>Una fila por familia, de más a menos productos caros. Barra: <span class="text-success fw-bold">verde</span> = Banasupro más barato, <span class="text-secondary fw-bold">gris</span> = igual, <span class="text-danger fw-bold">rojo</span> = más caro. Clic en una familia para ver cada competidor.</span></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Familia</th>
                                        <th class="text-center">Productos</th>
                                        <th class="text-center text-danger">Más caro</th>
                                        <th class="text-center text-success">Más barato</th>
                                        <th style="min-width:160px">Proporción</th>
                                        <th class="text-end">Brecha prom.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($resumenFam)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No hay datos con los filtros elegidos.</td></tr>
                                <?php else: foreach ($resumenFam as $idF => $rf):
                                    $br = $rf['productos'] > 0 ? $rf['suma'] / $rf['productos'] : null; ?>
                                    <tr class="bm-fam-toggle" data-fam="<?= (int)$idF ?>" role="button" tabindex="0" aria-expanded="false">
                                        <td class="fw-bold"><i class="fas fa-chevron-right bm-chev text-muted me-2"></i><?= escapar($rf['familia']) ?></td>
                                        <td class="text-center"><?= (int)$rf['productos'] ?></td>
                                        <td class="text-center"><span class="badge bg-light-danger text-danger"><?= (int)$rf['caro'] ?></span></td>
                                        <td class="text-center"><span class="badge bg-light-success text-success"><?= (int)$rf['barato'] ?></span></td>
                                        <td><?php $barraProporcion($rf['productos'], $rf['barato'], $rf['caro']); ?></td>
                                        <td class="text-end fw-bold text-nowrap <?= ($br ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?= $br === null ? '—' : signo($br) . '%' ?></td>
                                    </tr>
                                    <?php foreach ($rf['filas'] as $g): $gBr = $g['brecha'] === null ? null : (float)$g['brecha']; ?>
                                    <tr class="bm-fam-det d-none" data-fam="<?= (int)$idF ?>">
                                        <td class="ps-5 small"><i class="fas fa-store text-muted"></i> <?= escapar($g['competidor']) ?></td>
                                        <td class="text-center small"><?= (int)$g['productos'] ?></td>
                                        <td class="text-center small"><?= (int)$g['caro'] ?></td>
                                        <td class="text-center small"><?= (int)$g['barato'] ?></td>
                                        <td><?php $barraProporcion((int)$g['productos'], (int)$g['barato'], (int)$g['caro']); ?></td>
                                        <td class="text-end small fw-bold text-nowrap <?= ($gBr ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?= $gBr === null ? '—' : signo($gBr) . '%' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer small text-muted">
                        Cuenta productos según su último precio levantado dentro de los filtros. Brecha positiva = la competencia es más cara.
                    </div>
                    </div>

                    <!-- DETALLE -->
                    <div class="tab-pane fade show active" id="tabDetalle" role="tabpanel">
<div class="bm-ayuda"><i class="fas fa-info-circle"></i><span>Cada fila es un precio levantado en una visita. <strong>Dif. %</strong> = (competencia − Banasupro) ÷ Banasupro: positivo = Banasupro más barato, negativo = más caro. Precios por unidad, salvo donde diga paquete.</span></div>
                    <div class="card-body">
                        <?php if ($truncado): ?>
                            <div class="alert alert-warning py-2 small">Se muestran las <?= number_format(LIMITE_FILAS) ?> visitas más recientes. Afina los filtros o exporta para ver todo.</div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="table1">
                                <thead class="table-light">
                                    <tr>
                                        <th>Competidor</th>
                                        <th>Fecha</th>
                                        <th>Familia</th>
                                        <th>Producto y presentación</th>
                                        <th class="text-end">Precio Banasupro</th>
                                        <th class="text-end">Precio competencia</th>
                                        <th class="text-end">Dif. %</th>
                                        <th class="text-center">Estatus</th>
                                        <th class="text-center">Ver</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $n = 0; foreach ($detalles as $row):
                                    $n++;
                                    $propio = (float)$row['precio_propio'];
                                    $comp = (float)$row['precio_competencia'];
                                    $baseTxt = ($row['base_comparacion'] ?? 'unidad') === 'paquete' ? 'por paquete' : 'por unidad';
                                    $dif = $comp - $propio;
                                    $difPct = $propio > 0 ? $dif / $propio * 100 : 0;
                                    if ($dif > 0)      { $estatus = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Más barato</span>'; $color = 'text-success fw-bold'; }
                                    elseif ($dif < 0)  { $estatus = '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Más caro</span>'; $color = 'text-danger fw-bold'; }
                                    else               { $estatus = '<span class="badge bg-secondary">Mismo precio</span>'; $color = 'text-muted'; }
                                    $linkAnalisis = 'benchmark_analisis.php?' . http_build_query([
                                        'id_producto' => (int)$row['id_producto'],
                                        'desde' => date('Y-m-d', strtotime('monday this week -11 weeks')),
                                        'hasta' => $hoy,
                                    ]);
                                ?>
                                    <tr>
                                        <td><strong><?= escapar($row['competidor']) ?></strong></td>
                                        <td data-order="<?= escapar($row['fecha_levantamiento']) ?>"><?= !empty($row['fecha_levantamiento']) ? date('d/m/Y', strtotime($row['fecha_levantamiento'])) : '-' ?></td>
                                        <td><span class="badge bg-light-primary"><?= escapar($row['familia']) ?></span></td>
                                        <td><?= escapar(benchmark_etiqueta($row['nombre_producto'])) ?></td>
                                        <td class="text-end text-nowrap fw-bold text-primary">L. <?= number_format($propio, 2) ?><small class="d-block fw-normal text-muted"><?= $baseTxt ?></small></td>
                                        <td class="text-end text-nowrap fw-bold">L. <?= number_format($comp, 2) ?><small class="d-block fw-normal text-muted"><?= $baseTxt ?></small></td>
                                        <td class="text-end text-nowrap <?= $color ?>"><?= signo($difPct) ?>%</td>
                                        <td class="text-center"><?= $estatus ?></td>
                                        <td class="text-center">
                                            <a href="<?= escapar($linkAnalisis) ?>" class="btn btn-sm btn-outline-primary" title="Ver evolución de este producto en el análisis"><i class="fas fa-chart-line"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($n === 0): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No hay registros con los filtros elegidos.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    </div>
                    </div>
                </div>

            </div>

            <?php include('../../includes/menu_footer.php'); ?>
        </div>
    </div>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script src="../../js/benchmark_js/benchmark_semanas.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Filtros automáticos: cualquier cambio aplica de inmediato (sin botón)
            const formFiltros = document.getElementById('formFiltros');
            if (formFiltros) formFiltros.addEventListener('change', function () { formFiltros.submit(); });

            // Resumen por familia: clic (o Enter) despliega el detalle por competidor
            document.querySelectorAll('.bm-fam-toggle').forEach(function (fila) {
                const alternar = function () {
                    const abierta = fila.classList.toggle('abierta');
                    fila.setAttribute('aria-expanded', abierta ? 'true' : 'false');
                    document.querySelectorAll('tr.bm-fam-det[data-fam="' + fila.dataset.fam + '"]').forEach(function (d) { d.classList.toggle('d-none', !abierta); });
                };
                fila.addEventListener('click', alternar);
                fila.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); alternar(); } });
            });

            const tabla = document.querySelector('#table1');
            if (tabla && tabla.querySelector('tbody tr td[colspan]') === null) {
                new simpleDatatables.DataTable(tabla, { perPageSelect: [10, 25, 50, 100], perPage: 10 });
            }
        });
    </script>
    <script src="../../assets/js/main.js"></script>
</body>
</html>
