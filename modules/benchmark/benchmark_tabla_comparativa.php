<?php
session_start();
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

header('Cache-Control: no-store');

require_once __DIR__ . '/../../connection/connection.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}

$detalles = [];
$errorConsulta = null;

try {
    // Consulta general unificada con prioridad al precio histórico snapshot
    $campoPrecioBana = "p.precio_referencia AS precio_propio";
    try {
        $pdo->query("SELECT precio_banasupro_referencia FROM benchmark_precios_detalle LIMIT 1");
        $campoPrecioBana = "COALESCE(NULLIF(d.precio_banasupro_referencia, 0), p.precio_referencia) AS precio_propio";
    } catch (Throwable $ePrecio) {
        $campoPrecioBana = "p.precio_referencia AS precio_propio";
    }

    $stmt = $pdo->query("
        SELECT 
            d.id AS id_detalle,
            l.id AS id_levantamiento,
            c.nombre AS competidor,
            l.fecha_levantamiento,
            p.nombre_producto AS producto_banasupro,
            {$campoPrecioBana},
            d.nombre_producto_competencia,
            d.precio_competencia,
            d.observaciones
        FROM benchmark_precios_detalle d
        INNER JOIN benchmark_levantamientos l ON d.id_levantamiento = l.id
        INNER JOIN benchmark_competidores c ON l.id_competidor = c.id
        INNER JOIN benchmark_productos p ON d.id_producto = p.id
        ORDER BY l.fecha_levantamiento DESC, d.id DESC
    ");
    $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errorConsulta = 'Error al consultar la matriz comparativa general.';
}

// Cálculo de Métricas para el Mini Dashboard
$totalComparados = count($detalles);
$totalBanaBarato = 0;
$totalBanaCaro = 0;
$totalIgual = 0;
$totalAhorro = 0;

$chartLabels = [];
$chartBanaPrecios = [];
$chartCompPrecios = [];

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

// Muestras para la gráfica de barras comparativas (primeros 7 levantamientos)
$muestrasChart = array_slice($detalles, 0, 7);
foreach ($muestrasChart as $m) {
    $pName = (string)($m['producto_banasupro'] ?? $m['PRODUCTO_BANASUPRO'] ?? '');
    if (mb_strlen($pName) > 18) {
        $pName = mb_substr($pName, 0, 16) . '..';
    }
    $cName = (string)($m['competidor'] ?? $m['COMPETIDOR'] ?? '');
    $chartLabels[] = $pName . ' (' . $cName . ')';
    $chartBanaPrecios[] = round((float)($m['precio_propio'] ?? $m['PRECIO_PROPIO'] ?? 0), 2);
    $chartCompPrecios[] = round((float)($m['precio_competencia'] ?? $m['PRECIO_COMPETENCIA'] ?? 0), 2);
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
    <link rel="stylesheet" href="../../assets/vendors/apexcharts/apexcharts.css">
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
                <div class="page-title mb-3">
                    <div class="row">
                        <div class="col-12 col-md-8">
                            <h3 class="mb-1"><i class="fas fa-chart-bar text-primary"></i> Benchmark: Comparativa de Precios</h3>
                            <p class="text-subtitle text-muted mb-0">Panel analítico y matriz de precios contra la competencia</p>
                        </div>
                        <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0">
                            <a href="benchmark_levantamientos.php" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> Nuevo Levantamiento
                            </a>
                        </div>
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

                <!-- MINI DASHBOARD: 2 GRÁFICAS APEXCHARTS DE MAZER -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-lg-4">
                        <div class="card kpi-card h-100">
                            <div class="card-header bg-transparent pb-0">
                                <h5 class="card-title fs-6 fw-bold mb-1"><i class="bi bi-pie-chart text-primary"></i> Ventaja en Precios</h5>
                                <p class="text-muted small mb-0">Proporción general Banasupro vs Competencia</p>
                            </div>
                            <div class="card-body d-flex align-items-center justify-content-center p-2">
                                <div id="chartDonut" style="width: 100%;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-8">
                        <div class="card kpi-card h-100">
                            <div class="card-header bg-transparent pb-0">
                                <h5 class="card-title fs-6 fw-bold mb-1"><i class="bi bi-bar-chart-steps text-primary"></i> Comparativa de Precios por Producto (L.)</h5>
                                <p class="text-muted small mb-0">Muestra comparativa directa de los últimos levantamientos</p>
                            </div>
                            <div class="card-body p-2">
                                <div id="chartBarras"></div>
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
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($detalles)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No hay registros comparativos disponibles.</td>
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
    <script src="../../assets/vendors/apexcharts/apexcharts.min.js"></script>
    
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

            // Datos PHP a JS
            const totalBarato = <?= (int)$totalBanaBarato ?>;
            const totalCaro = <?= (int)$totalBanaCaro ?>;
            const totalIgual = <?= (int)$totalIgual ?>;

            // 1. Gráfica Donut (Ventaja Competitiva)
            const donutOptions = {
                series: (totalBarato === 0 && totalCaro === 0 && totalIgual === 0) ? [1] : [totalBarato, totalCaro, totalIgual],
                labels: (totalBarato === 0 && totalCaro === 0 && totalIgual === 0) ? ['Sin datos'] : ['Banasupro más Barato', 'Banasupro más Caro', 'Mismo Precio'],
                colors: (totalBarato === 0 && totalCaro === 0 && totalIgual === 0) ? ['#e0e0e0'] : ['#198754', '#dc3545', '#0dcaf0'],
                chart: {
                    type: 'donut',
                    height: 250,
                    toolbar: { show: false }
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '68%',
                            labels: {
                                show: true,
                                total: {
                                    show: true,
                                    label: 'Muestras',
                                    formatter: () => '<?= $totalComparados ?>'
                                }
                            }
                        }
                    }
                },
                legend: {
                    position: 'bottom',
                    fontSize: '12px'
                },
                dataLabels: { enabled: false }
            };
            const donutChart = new ApexCharts(document.querySelector("#chartDonut"), donutOptions);
            donutChart.render();

            // 2. Gráfica de Barras Comparativa (Banasupro vs Competidor)
            const categoriasBar = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
            const banaPrecios = <?= json_encode($chartBanaPrecios) ?>;
            const compPrecios = <?= json_encode($chartCompPrecios) ?>;

            const barOptions = {
                series: [{
                    name: 'Banasupro',
                    data: banaPrecios
                }, {
                    name: 'Competidor',
                    data: compPrecios
                }],
                chart: {
                    type: 'bar',
                    height: 250,
                    toolbar: { show: false }
                },
                colors: ['#435ebe', '#f59e0b'],
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '50%',
                        borderRadius: 4
                    }
                },
                dataLabels: { enabled: false },
                stroke: {
                    show: true,
                    width: 2,
                    colors: ['transparent']
                },
                xaxis: {
                    categories: categoriasBar.length > 0 ? categoriasBar : ['Sin registros'],
                    labels: {
                        style: { fontSize: '11px' },
                        rotate: -20
                    }
                },
                yaxis: {
                    title: { text: 'Precio (L.)' }
                },
                fill: { opacity: 1 },
                tooltip: {
                    y: {
                        formatter: val => "L. " + parseFloat(val).toFixed(2)
                    }
                },
                legend: {
                    position: 'top',
                    fontSize: '12px'
                }
            };
            const barChart = new ApexCharts(document.querySelector("#chartBarras"), barOptions);
            barChart.render();
        });
    </script>
    <script src="../../assets/js/main.js"></script>
</body>
</html>