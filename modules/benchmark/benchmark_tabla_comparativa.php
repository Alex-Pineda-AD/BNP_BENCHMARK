<?php
session_start();
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

header('Cache-Control: no-store');

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

// Control de acceso por rol (5 y 6)
benchmark_requerir_pagina();

$detalles = [];
$errorConsulta = null;

date_default_timezone_set('America/Tegucigalpa');
$hoy = date('Y-m-d');
$competidores = [];

// Filtro opcional por competidor (?id_competidor=N)
$fCompetidor = filter_var($_GET['id_competidor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fCompetidor = ($fCompetidor === false) ? null : $fCompetidor;

try {
    // Consulta general unificada con prioridad al precio histórico snapshot
    $stmt = $pdo->prepare("
        SELECT
            d.id AS id_detalle,
            l.id AS id_levantamiento,
            c.nombre AS competidor,
            l.fecha_levantamiento,
            p.id AS id_producto,
            p.nombre_producto AS producto_banasupro,
            COALESCE(NULLIF(d.precio_banasupro_referencia, 0), p.precio_referencia) AS precio_propio,
            d.precio_competencia,
            d.observaciones
        FROM benchmark_precios_detalle d
        INNER JOIN benchmark_levantamientos l ON d.id_levantamiento = l.id
        INNER JOIN benchmark_competidores c ON l.id_competidor = c.id
        INNER JOIN benchmark_productos p ON d.id_producto = p.id
        " . ($fCompetidor !== null ? 'WHERE l.id_competidor = ?' : '') . "
        ORDER BY l.fecha_levantamiento DESC, d.id DESC
    ");
    $stmt->execute($fCompetidor !== null ? [$fCompetidor] : []);
    $detalles = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Competidores para el selector del filtro
    $competidores = array_map(fn($r) => array_change_key_case($r, CASE_LOWER),
        $pdo->query('SELECT id, nombre, estado FROM benchmark_competidores ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    error_log('benchmark_tabla_comparativa: ' . $e->getMessage());
    $errorConsulta = 'Error al consultar la matriz comparativa general.';
}

// Cálculo de Métricas para el Mini Dashboard
$totalComparados = count($detalles);
$totalBanaBarato = 0;
$totalBanaCaro = 0;
$totalIgual = 0;
$totalAhorro = 0;


foreach ($detalles as $row) {
    $pPropio = (float)($row['precio_propio'] ?? $row['PRECIO_PROPIO'] ?? 0);
    $pComp = (float)($row['precio_competencia'] ?? $row['PRECIO_COMPETENCIA'] ?? 0);
    $dif = $pComp - $pPropio;

    if ($dif > 0) {
        $totalBanaBarato++;
        $totalAhorro += $dif;
    } elseif ($dif < 0) {
        $totalBanaCaro++;
    } else {
        $totalIgual++;
    }
}

$pctBanaBarato = $totalComparados > 0 ? round(($totalBanaBarato / $totalComparados) * 100, 1) : 0;
$pctBanaCaro = $totalComparados > 0 ? round(($totalBanaCaro / $totalComparados) * 100, 1) : 0;

function escapar($valor) {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include '../../includes/head.php'; ?>
    <link rel="stylesheet" href="../../assets/vendors/simple-datatables/style.css">
    <style>
        .kpi-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        }
        .stats-icon-custom {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }
    </style>
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
                            <p class="mb-0 text-white-50">Panel analítico y matriz de precios contra la competencia</p>
                        </div>
                        <a href="benchmark_levantamientos.php" class="btn btn-light text-primary fw-bold"><i class="fas fa-plus-circle"></i> Nuevo Levantamiento</a>
                    </div>
                </div>

                <!-- FILTRO POR COMPETIDOR -->
                <div class="card kpi-card mb-4">
                    <div class="card-body py-3">
                        <form method="get" class="row g-2 align-items-center">
                            <div class="col-auto">
                                <label for="fCompetidor" class="col-form-label fw-bold small"><i class="fas fa-building text-primary"></i> Competidor</label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                                <select id="fCompetidor" name="id_competidor" class="form-select" onchange="this.form.submit()">
                                    <option value="">Todos los competidores</option>
                                    <?php foreach ($competidores as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>" <?= ($fCompetidor === (int)$c['id']) ? 'selected' : '' ?>>
                                            <?= escapar($c['nombre']) ?><?= ((int)$c['estado'] === 1) ? '' : ' (inactivo)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($fCompetidor !== null): ?>
                                <div class="col-auto">
                                    <a href="benchmark_tabla_comparativa.php" class="btn btn-light-secondary btn-sm"><i class="fas fa-eraser"></i> Quitar filtro</a>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- MINI DASHBOARD: 4 KPI CARDS SEGÚN MAZER -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon-custom bg-light-primary text-primary me-3">
                                        <i class="fas fa-layer-group"></i>
                                    </div>
                                    <div>
                                        <h6 class="text-muted small fw-bold mb-0">Total Muestras</h6>
                                        <h4 class="fw-bold mb-0"><?= number_format($totalComparados) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon-custom bg-light-success text-success me-3">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div>
                                        <h6 class="text-muted small fw-bold mb-0">Banasupro Más Barato</h6>
                                        <h4 class="fw-bold text-success mb-0">
                                            <?= number_format($totalBanaBarato) ?> 
                                            <span class="fs-6 fw-normal text-muted">(<?= $pctBanaBarato ?>%)</span>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon-custom bg-light-danger text-danger me-3">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div>
                                        <h6 class="text-muted small fw-bold mb-0">Banasupro Más Caro</h6>
                                        <h4 class="fw-bold text-danger mb-0">
                                            <?= number_format($totalBanaCaro) ?> 
                                            <span class="fs-6 fw-normal text-muted">(<?= $pctBanaCaro ?>%)</span>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon-custom bg-light-info text-info me-3">
                                        <i class="fas fa-coins"></i>
                                    </div>
                                    <div>
                                        <h6 class="text-muted small fw-bold mb-0">Ahorro Promedio</h6>
                                        <h4 class="fw-bold text-primary mb-0">L. <?= number_format($totalAhorro, 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TABLA DETALLADA -->
                <div class="card kpi-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title fs-6 fw-bold mb-0"><i class="bi bi-table text-primary"></i> Detalle de Levantamientos Registrados</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="table1">
                                <thead class="table-light">
                                    <tr>
                                        <th>N.</th>
                                        <th>COMPETIDOR</th>
                                        <th>FECHA</th>
                                        <th>ARTÍCULO BANASUPRO</th>
                                        <th class="text-end">PRECIO BANA.</th>
                                        <th class="text-end">PRECIO COMP.</th>
                                        <th class="text-end">DIF. (L.)</th>
                                        <th class="text-end">DIF. %</th>
                                        <th class="text-center">ESTATUS</th>
                                        <th>NOTAS</th>
                                        <th class="text-center">VER</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($detalles)): ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted py-4">No hay registros comparativos disponibles.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $vr_correlativo = 0;
                                        foreach ($detalles as $row): 
                                            $vr_correlativo++;
                                            $competidor = $row['competidor'] ?? $row['COMPETIDOR'] ?? '';
                                            $fecha = $row['fecha_levantamiento'] ?? $row['FECHA_LEVANTAMIENTO'] ?? '';
                                            $prodBana = $row['producto_banasupro'] ?? $row['PRODUCTO_BANASUPRO'] ?? '';
                                            $precioPropio = (float)($row['precio_propio'] ?? $row['PRECIO_PROPIO'] ?? 0);
                                            $precioComp = (float)($row['precio_competencia'] ?? $row['PRECIO_COMPETENCIA'] ?? 0);
                                            $obs = $row['observaciones'] ?? $row['OBSERVACIONES'] ?? '-';
                                            $linkAnalisis = 'benchmark_analisis.php?' . http_build_query([
                                                'id_producto' => (int)($row['id_producto'] ?? 0),
                                                'desde' => date('Y-m-d', strtotime('-89 days')),
                                                'hasta' => $hoy,
                                            ]);

                                            $difLempiras = $precioComp - $precioPropio;
                                            $difPorcentaje = ($precioPropio > 0) ? ($difLempiras / $precioPropio) * 100 : 0;
                                            
                                            // Si Banasupro es más barato = VERDE, Si es más caro = ROJO
                                            if ($difLempiras > 0) {
                                                $estatusHtml = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Más Barato</span>';
                                                $badgeColor = 'text-success fw-bold';
                                            } elseif ($difLempiras < 0) {
                                                $estatusHtml = '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Más Caro</span>';
                                                $badgeColor = 'text-danger fw-bold';
                                            } else {
                                                $estatusHtml = '<span class="badge bg-secondary">Mismo Precio</span>';
                                                $badgeColor = 'text-muted';
                                            }
                                        ?>
                                            <tr>
                                                <td><?= $vr_correlativo ?></td>
                                                <td><strong><?= escapar($competidor) ?></strong></td>
                                                <td><?= !empty($fecha) ? date('d/m/Y', strtotime($fecha)) : '-' ?></td>
                                                <td><?= escapar($prodBana) ?></td>
                                                <td class="text-end text-nowrap fw-bold text-primary">L. <?= number_format($precioPropio, 2) ?></td>
                                                <td class="text-end text-nowrap fw-bold">L. <?= number_format($precioComp, 2) ?></td>
                                                <td class="text-end text-nowrap <?= $badgeColor ?>">
                                                    <?= ($difLempiras > 0 ? '+' : '') . number_format($difLempiras, 2) ?>
                                                </td>
                                                <td class="text-end text-nowrap <?= $badgeColor ?>">
                                                    <?= ($difPorcentaje > 0 ? '+' : '') . number_format($difPorcentaje, 2) ?>%
                                                </td>
                                                <td class="text-center"><?= $estatusHtml ?></td>
                                                <td><?= escapar($obs) ?></td>
                                                <td class="text-center">
                                                    <a href="<?= escapar($linkAnalisis) ?>" class="btn btn-sm btn-outline-primary" title="Ver evolución de este producto en el análisis">
                                                        <i class="fas fa-chart-line"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
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
            // Inicializar DataTables
            let table1 = document.querySelector('#table1');
            if (table1) {
                new simpleDatatables.DataTable(table1, {
                    perPageSelect: [10, 25, 50, 100],
                    perPage: 10
                });
            }

        });
    </script>
    <script src="../../assets/js/main.js"></script>
</body>
</html>