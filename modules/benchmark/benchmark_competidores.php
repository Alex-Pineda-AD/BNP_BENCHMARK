<?php
session_start();
header('Cache-Control: no-store');

require_once __DIR__ . '/../../connection/connection.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}

// Capturar mensaje flash de éxito o error
$resultado = $_SESSION['competidor_resultado'] ?? null;
unset($_SESSION['competidor_resultado']);

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
        FROM benchmark_competidores
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
                <h3>Competidores</h3>
            </div>

            <div class="page-content">
                <!-- Alerta Flash Dinámica con desvanecimiento automático -->
                <?php if ($resultado): ?>
                    <div class="alert alert-<?= escapar($resultado['tipo']) ?> alert-dismissible fade show" role="alert">
                        <?= escapar($resultado['mensaje']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($errorConsulta): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= escapar($errorConsulta) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <section class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-primary" onclick="abrirModalCompetidor()">
                            <i class="fas fa-store"></i> &nbsp; Nuevo Competidor
                        </button>
                    </div>
                    <div class="card-body px-0">
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
    <script src="../../js/benchmark_js/benchmark_competidores.js?v=<?= filemtime(__DIR__ . '/../../js/benchmark_js/benchmark_competidores.js') ?>"></script>

    <script>
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