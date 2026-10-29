<?php
session_start();
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

header('Cache-Control: no-store');
date_default_timezone_set('America/Tegucigalpa');

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

// Control de acceso por rol (5 y 6)
benchmark_requerir_pagina();

$competidores = [];
$errorCatalogos = null;

try {
    $competidores = array_map(
        fn($r) => array_change_key_case($r, CASE_LOWER),
        $pdo->query('SELECT id, nombre, estado FROM benchmark.benchmark_competidores ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC)
    );
} catch (PDOException $e) {
    error_log('benchmark_analisis: ' . $e->getMessage());
    $errorCatalogos = 'No se pudieron cargar los filtros.';
}

$hoy = date('Y-m-d');
$hace30 = date('Y-m-d', strtotime('-29 days'));

// Valores iniciales desde la URL (enlace de la tabla comparativa): fechas y producto
function an_fecha($valor, string $defecto): string
{
    if (!is_string($valor)) return $defecto;
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return ($f && $f->format('Y-m-d') === $valor) ? $valor : $defecto;
}
$desdeInicial = an_fecha($_GET['desde'] ?? null, $hace30);
$hastaInicial = an_fecha($_GET['hasta'] ?? null, $hoy);
$productoInicial = filter_var($_GET['id_producto'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$productoInicial = ($productoInicial === false) ? null : $productoInicial;

function escapar($valor) {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include '../../includes/head.php'; ?>
    <link rel="stylesheet" href="../../assets/vendors/apexcharts/apexcharts.css">
    <link rel="stylesheet" href="../../assets/css/benchmark.css">
    <style>
        .kpi-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
        .stats-icon-custom {
            width: 48px; height: 48px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 1.3rem;
        }
        .chart-wrap { position: relative; min-height: 120px; }
        .chart-vacio {
            display: none; text-align: center; color: #9aa4b2; padding: 2.5rem 1rem;
        }
        .chart-vacio i { font-size: 2.2rem; display: block; margin-bottom: .5rem; opacity: .6; }
        #cargandoAnalisis {
            display: none; position: fixed; inset: 0; z-index: 2000;
            background: rgba(255,255,255,0.55); align-items: center; justify-content: center;
        }
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
                <!-- ENCABEZADO -->
                <div class="card benchmark-hero mb-4">
                    <div class="card-body p-4 d-flex align-items-center flex-wrap gap-3">
                        <div class="benchmark-hero-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="flex-grow-1">
                            <h3 class="text-white mb-1">Análisis de Precios</h3>
                            <p class="mb-0 text-white-50">Cómo se mueven tus precios y los de la competencia en el tiempo</p>
                        </div>
                    </div>
                </div>

                <?php if ($errorCatalogos): ?>
                    <div class="alert alert-warning"><?= escapar($errorCatalogos) ?></div>
                <?php endif; ?>
                <div id="alertaAnalisis" class="alert alert-danger d-none" role="alert"></div>

                <!-- FILTROS -->
                <div class="card kpi-card mb-4">
                    <div class="card-body">
                        <form id="formFiltros" class="row g-3 align-items-end" autocomplete="off">
                            <div class="col-6 col-md-3 col-xl-2">
                                <label for="fDesde" class="form-label small fw-bold mb-1">Desde</label>
                                <input type="date" id="fDesde" class="form-control" value="<?= escapar($desdeInicial) ?>" max="<?= escapar($hoy) ?>">
                            </div>
                            <div class="col-6 col-md-3 col-xl-2">
                                <label for="fHasta" class="form-label small fw-bold mb-1">Hasta</label>
                                <input type="date" id="fHasta" class="form-control" value="<?= escapar($hastaInicial) ?>" max="<?= escapar($hoy) ?>">
                            </div>
                            <div class="col-12 col-md-6 col-xl-3">
                                <label class="form-label small fw-bold mb-1 d-block">Atajos</label>
                                <div class="btn-group w-100" role="group" aria-label="Rangos rápidos">
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-dias="7">7 días</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-dias="30">30 días</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-dias="90">90 días</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-dias="mes">Este mes</button>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-xl-2">
                                <label for="fCompetidor" class="form-label small fw-bold mb-1">Competidor</label>
                                <select id="fCompetidor" class="form-select">
                                    <option value="">Todos</option>
                                    <?php foreach ($competidores as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>">
                                            <?= escapar($c['nombre']) ?><?= ((int)$c['estado'] === 1) ? '' : ' (inactivo)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-2 col-xl-1">
                                <label for="fAgrupar" class="form-label small fw-bold mb-1">Agrupar</label>
                                <select id="fAgrupar" class="form-select">
                                    <option value="semana" selected>Semana</option>
                                    <option value="dia">Día</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2 col-xl-12 text-xl-end">
                                <button type="button" id="btnLimpiar" class="btn btn-light-secondary">
                                    <i class="fas fa-eraser"></i> Limpiar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- INDICADORES -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card h-100"><div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon-custom bg-light-primary text-primary me-3"><i class="fas fa-layer-group"></i></div>
                                <div>
                                    <h6 class="text-muted small fw-bold mb-0">Comparaciones</h6>
                                    <h4 class="fw-bold mb-0" id="kpiMuestras">–</h4>
                                    <small class="text-muted" id="kpiMuestrasSub">&nbsp;</small>
                                </div>
                            </div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card h-100"><div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon-custom bg-light-success text-success me-3"><i class="fas fa-check-circle"></i></div>
                                <div>
                                    <h6 class="text-muted small fw-bold mb-0">Banasupro más barato</h6>
                                    <h4 class="fw-bold text-success mb-0" id="kpiBarato">–</h4>
                                    <small class="text-muted" id="kpiBaratoSub">&nbsp;</small>
                                </div>
                            </div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card h-100"><div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon-custom bg-light-info text-info me-3"><i class="fas fa-balance-scale"></i></div>
                                <div>
                                    <h6 class="text-muted small fw-bold mb-0">Brecha promedio</h6>
                                    <h4 class="fw-bold mb-0" id="kpiBrecha">–</h4>
                                    <small class="text-muted" id="kpiBrechaSub">&nbsp;</small>
                                </div>
                            </div>
                        </div></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card h-100"><div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon-custom bg-light-warning text-warning me-3"><i class="fas fa-arrows-alt-v"></i></div>
                                <div>
                                    <h6 class="text-muted small fw-bold mb-0">Variación competencia</h6>
                                    <h4 class="fw-bold mb-0" id="kpiVariacion">–</h4>
                                    <small class="text-muted" id="kpiVariacionSub">&nbsp;</small>
                                </div>
                            </div>
                        </div></div>
                    </div>
                </div>

                <!-- GRÁFICA 1: EVOLUCIÓN DE UN PRODUCTO -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="card kpi-card">
                            <div class="card-header bg-transparent pb-0">
                                <div class="row g-2 align-items-center">
                                    <div class="col-12 col-lg-6">
                                        <h5 class="card-title fs-6 fw-bold mb-1"><i class="bi bi-graph-up text-primary"></i> Evolución del precio por producto</h5>
                                        <p class="text-muted small mb-0">Tu precio contra el de cada competidor a lo largo del tiempo</p>
                                    </div>
                                    <div class="col-12 col-lg-6">
                                        <select id="fProducto" class="form-select" aria-label="Producto">
                                            <option value="">Sin productos en este filtro</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-wrap">
                                    <div id="chartEvolucion"></div>
                                    <div class="chart-vacio" id="vacioEvolucion"><i class="fas fa-chart-line"></i>No hay precios de este producto en el rango elegido.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3 GRÁFICAS PEQUEÑAS EN UNA FILA -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-lg-4">
                        <div class="card kpi-card h-100">
                            <div class="card-header bg-transparent pb-0">
                                <h5 class="card-title fs-6 fw-bold mb-1"><i class="bi bi-arrow-down-up text-primary"></i> ¿Qué cambió en la competencia?</h5>
                                <p class="text-muted small mb-0" id="subVariacion">Cambio de precio contra el periodo anterior</p>
                                <p class="small mb-0"><span class="text-success fw-bold">■ Subió</span> &nbsp; <span class="text-danger fw-bold">■ Bajó</span></p>
                            </div>
                            <div class="card-body">
                                <div class="chart-wrap">
                                    <div id="chartVariacion"></div>
                                    <div class="chart-vacio" id="vacioVariacion"><i class="fas fa-exchange-alt"></i>Hace falta el mismo producto en ambos periodos para calcular el cambio.</div>
                                </div>
                                <p class="small text-muted mb-0 mt-2" id="insightVariacion"></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="card kpi-card h-100">
                            <div class="card-header bg-transparent pb-0">
                                <h5 class="card-title fs-6 fw-bold mb-1"><i class="bi bi-trophy text-primary"></i> ¿Estamos ganando en precio?</h5>
                                <p class="text-muted small mb-0">% de comparaciones donde Banasupro es más barato. Arriba de la línea de 50% vas ganando.</p>
                            </div>
                            <div class="card-body">
                                <div class="chart-wrap">
                                    <div id="chartCompetitividad"></div>
                                    <div class="chart-vacio" id="vacioCompetitividad"><i class="fas fa-trophy"></i>Sin comparaciones en este rango.</div>
                                </div>
                                <p class="small text-muted mb-0 mt-2" id="insightCompetitividad"></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="card kpi-card h-100">
                            <div class="card-header bg-transparent pb-0">
                                <h5 class="card-title fs-6 fw-bold mb-1"><i class="bi bi-shield-check text-primary"></i> ¿Qué tan confiables son los datos?</h5>
                                <p class="text-muted small mb-0">Visitas y productos capturados por competidor. Más barras altas, más confianza.</p>
                            </div>
                            <div class="card-body">
                                <div class="chart-wrap">
                                    <div id="chartCobertura"></div>
                                    <div class="chart-vacio" id="vacioCobertura"><i class="fas fa-clipboard-list"></i>Sin levantamientos en este rango.</div>
                                </div>
                                <p class="small text-muted mb-0 mt-2" id="insightCobertura"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include('../../includes/menu_footer.php'); ?>
        </div>
    </div>

    <div id="cargandoAnalisis"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando…</span></div></div>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/vendors/apexcharts/apexcharts.min.js"></script>
    <script>
        // Token CSRF para el fetch (cabecera X-CSRF-Token)
        const BENCHMARK_CSRF = <?= json_encode(benchmark_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        // Producto sugerido por el enlace de la tabla comparativa (null si se entra directo)
        const BENCHMARK_INICIAL = { id_producto: <?= $productoInicial === null ? 'null' : (int)$productoInicial ?> };
    </script>
    <script src="../../js/benchmark_js/benchmark_analisis.js"></script>
    <script src="../../assets/js/main.js"></script>
</body>
</html>
