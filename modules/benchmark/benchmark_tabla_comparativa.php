<?php
session_start();
// Ocultar warnings molestos de índices si alguna columna cambia de mayúscula a minúscula
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
    // Consulta general unificada
    $stmt = $pdo->query("
        SELECT 
            d.id AS id_detalle,
            l.id AS id_levantamiento,
            c.nombre AS competidor,
            l.fecha_levantamiento,
            p.nombre_producto AS producto_banasupro,
            p.precio_referencia AS precio_propio,
            d.nombre_producto_competencia,
            d.precio_competencia,
            d.observaciones
        FROM benchmark_precios_detalle d
        INNER JOIN benchmark_levantamientos l ON d.id_levantamiento = l.id
        INNER JOIN benchmark_competidores c ON l.id_competidor = c.id
        INNER JOIN benchmark_productos p ON d.id_producto = p.id
        ORDER BY l.fecha_levantamiento DESC, c.nombre ASC
    ");
    $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errorConsulta = 'Error al consultar la matriz comparativa general.';
}

function escapar($valor) {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include '../../includes/head.php'; ?>
    <link rel="stylesheet" href="../../assets/vendors/simple-datatables/style.css">
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
                <h3>Tabla Comparativa General de Precios</h3>
            </div>
<div class="card-body">
    <div class="table-responsive">
        <table class="table table-striped" id="table1">
            <thead>
                <tr>
                    <th>N.</th>
                    <th>COMPETIDOR</th>
                    <th>FECHA</th>
                    <th>PRODUCTO BANASUPRO</th>
                    <th class="text-end">PRECIO PROPIO</th>
                    <th>PRODUCTO COMPETENCIA</th>
                    <th class="text-end">PRECIO COMP.</th>
                    <th class="text-end">DIF. (L.)</th>
                    <th class="text-end">DIF. %</th>
                    <th class="text-center">ESTATUS</th>
                    <th>OBS.</th>
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
                        $prodComp = $row['nombre_producto_competencia'] ?? $row['NOMBRE_PRODUCTO_COMPETENCIA'] ?? '';
                        $precioComp = (float)($row['precio_competencia'] ?? $row['PRECIO_COMPETENCIA'] ?? 0);
                        $obs = $row['observaciones'] ?? $row['OBSERVACIONES'] ?? 'N/A';

                        $difLempiras = $precioComp - $precioPropio;
                        $difPorcentaje = ($precioPropio > 0) ? ($difLempiras / $precioPropio) * 100 : 0;
                        
                        // Estatus y colores institucionales
                        if ($difLempiras > 0) {
                            $estatusHtml = '<span class="badge bg-danger">Más Caro</span>';
                            $badgeColor = 'text-danger fw-bold';
                        } elseif ($difLempiras < 0) {
                            $estatusHtml = '<span class="badge bg-success">Más Barato</span>';
                            $badgeColor = 'text-success fw-bold';
                        } else {
                            $estatusHtml = '<span class="badge bg-secondary">Igual</span>';
                            $badgeColor = 'text-muted';
                        }
                    ?>
                        <tr>
                            <td><?= $vr_correlativo ?></td>
                            <td><strong><?= escapar($competidor) ?></strong></td>
                            <td><?= !empty($fecha) ? date('d/m/Y', strtotime($fecha)) : '' ?></td>
                            <td><?= escapar($prodBana) ?></td>
                            <td class="text-end text-nowrap">L. <?= number_format($precioPropio, 2) ?></td>
                            <td><?= escapar($prodComp) ?></td>
                            <td class="text-end text-nowrap">L. <?= number_format($precioComp, 2) ?></td>
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

            <?php include('../../includes/menu_footer.php'); ?>
        </div>
    </div>
    
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
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