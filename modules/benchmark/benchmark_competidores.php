<?php
session_start();
header('Cache-Control: no-store');

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

// Control de acceso por rol (5 y 6)
benchmark_requerir_pagina();

// Capturar mensaje flash de éxito o error (se muestra con SweetAlert2)
$resultado = $_SESSION['competidor_resultado'] ?? null;
unset($_SESSION['competidor_resultado']);

$iconoSwal = 'error';
if ($resultado) {
    $tipoFlash = $resultado['tipo'] ?? 'danger';
    $iconoSwal = $tipoFlash === 'success' ? 'success' : ($tipoFlash === 'warning' ? 'warning' : 'error');
}

$competidores = [];
$errorConsulta = null;

try {
    $consulta = $pdo->query('
        SELECT 
            ID AS id, 
            NOMBRE AS nombre, 
            COALESCE(DESCRIPCION, \'N/A\') AS ubicacion_referencia, 
            ESTADO AS estado,
            CASE WHEN ESTADO = 1 THEN \'Activo\' ELSE \'Inactivo\' END AS ESTADO_TEXTO,
            CASE WHEN ESTADO = 1 THEN \'badge bg-success\' ELSE \'badge bg-danger\' END AS BG
        FROM benchmark.benchmark_competidores
        ORDER BY ID DESC
    ');
    $competidores = $consulta->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorConsulta = 'No se pudieron consultar los competidores.';
}

function escapar($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
                        <div class="benchmark-hero-icon"><i class="fas fa-building"></i></div>
                        <div class="flex-grow-1">
                            <h3 class="text-white mb-1">Competidores</h3>
                            <p class="mb-0 text-white-50">Administrá los competidores activos para el levantamiento de precios.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-content">
                <?php if ($errorConsulta): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= escapar($errorConsulta) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <section class="card">
                    <div class="card-body">
                        <!-- Botón a la izquierda, igual que en Usuarios -->
                        <div class="mb-3">
                            <button type="button" class="btn btn-primary" onclick="abrirModalCompetidor()">
                                <i class="fas fa-store"></i> &nbsp;Competidor
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-borderless align-middle w-100 mb-0" id="table1">
                                <thead>
                                    <tr>
                                        <th class="text-start ps-4">N.</th>
                                        <th class="text-start">NOMBRE</th>
                                        <th class="text-start">UBICACIÓN / REFERENCIA</th>
                                        <th class="text-center">ESTADO</th>
                                        <th class="text-center">ACCIONES</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php 
                                $vr_correlativo = 0;
                                if (empty($competidores)): 
                                ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No hay competidores registrados.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($competidores as $row): 
                                        $vr_correlativo++;
                                        $nombreRow = $row['nombre'] ?? $row['NOMBRE'] ?? '';
                                        $ubicacionRow = $row['ubicacion_referencia'] ?? $row['UBICACION_REFERENCIA'] ?? 'N/A';
                                        $estadoTextoRow = $row['ESTADO_TEXTO'] ?? ($row['estado'] == 1 ? 'Activo' : 'Inactivo');
                                        $bgRow = $row['BG'] ?? ($row['estado'] == 1 ? 'badge bg-success' : 'badge bg-danger');
                                    ?>
                                        <tr>
                                            <td class="text-start ps-4"><?= htmlspecialchars($vr_correlativo, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-start"><strong><?= escapar($nombreRow) ?></strong></td>
                                            <td class="text-start"><?= escapar($ubicacionRow) ?></td>
                                            <td class="text-center"><span class="<?= escapar($bgRow) ?>"><?= escapar($estadoTextoRow) ?></span></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-warning text-dark" onclick='editarCompetidor(<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>
                                                    <i class="fas fa-edit"></i> Editar
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <?php include('../../includes/menu_footer.php'); ?>
        </div>
    </div>
    
    <?php include __DIR__ . '/../../modals/modals_benchmark/benchmark_modal_agregar_competidor.php'; ?>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script>
        let table1 = document.querySelector('#table1');
        if (table1) {
            let dataTable = new simpleDatatables.DataTable(table1);
        }
    </script>
    <script src="../../assets/js/main.js"></script>
    <script src="../../assets/vendors/sweetalert2/sweetalert2.all.min.js"></script>
    <script src="../../js/benchmark_js/benchmark_competidores.js?v=<?= filemtime(__DIR__ . '/../../js/benchmark_js/benchmark_competidores.js') ?>"></script>

    <?php if ($resultado): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const esExito = <?= json_encode($iconoSwal === 'success') ?>;
            Swal.fire({
                icon: <?= json_encode($iconoSwal) ?>,
                title: esExito ? '¡Listo!' : 'No se pudo guardar',
                text: <?= json_encode((string)($resultado['mensaje'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                confirmButtonColor: '#435ebe',
                confirmButtonText: 'Aceptar',
                timer: esExito ? 3000 : undefined,
                timerProgressBar: esExito
            });
        });
    </script>
    <?php endif; ?>

    <script>
        // Desvanece la alerta de error de consulta (si la hay)
        document.addEventListener('DOMContentLoaded', function () {
            const alerta = document.querySelector('.alert');
            if (alerta) {
                setTimeout(() => {
                    alerta.classList.remove('show');
                    alerta.classList.add('fade');
                    setTimeout(() => {
                        alerta.remove();
                    }, 300);
                }, 4000); 
            }
        });
    </script>
</body>
</html>