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

// Control de Acceso por Rol (Permitido solo para roles 5 y 6)
$rolUsuario = (int)($_SESSION['id_rol'] ?? $_SESSION['rol'] ?? 0);
if (!in_array($rolUsuario, [5, 6])) {
    header('Location: ../../access_denied.php');
    exit;
}

// Capturar mensaje flash de éxito o error
$resultado = $_SESSION['levantamiento_resultado'] ?? null;
unset($_SESSION['levantamiento_resultado']);

$levantamientos = [];
$errorConsulta = null;

try {
    $consulta = $pdo->query('
        SELECT 
            L.id AS id,
            C.nombre AS competidor,
            COALESCE(U.nombre_completo, \'Sistema\') AS usuario,
            L.fecha_levantamiento AS fecha_levantamiento,
            COALESCE(L.observaciones, \'N/A\') AS observaciones,
            (SELECT COUNT(*) FROM benchmark_precios_detalle D WHERE D.id_levantamiento = L.id) AS total_items
        FROM benchmark_levantamientos L
        INNER JOIN benchmark_competidores C ON L.id_competidor = C.id
        LEFT JOIN usuarios U ON L.id_usuario = U.id
        ORDER BY L.id DESC
    ');
    $levantamientos = $consulta->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorConsulta = 'No se pudieron consultar los levantamientos de precios.';
}

// Cargar competidores activos para el listbox del modal
$competidores = [];
try {
    $stmtC = $pdo->query("SELECT id AS id, nombre AS nombre FROM benchmark_competidores WHERE estado = 1 ORDER BY nombre ASC");
    $competidores = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $competidores = [];
}

// Cargar los 30 productos base Banasupro con alias seguros en minúsculas para el JSON del modal dinámico
$productosSoftland = [];
try {
    $stmtP = $pdo->query("
        SELECT 
            id AS id, 
            codigo AS codigo, 
            nombre_producto AS nombre_producto, 
            precio_referencia AS precio_referencia 
        FROM benchmark_productos 
        WHERE estado = 1 
        ORDER BY id ASC
    ");
    $productosSoftland = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $productosSoftland = [];
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
                <h3>Comparativo y Levantamiento de Precios</h3>
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
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalLevantamiento">
                            <i class="bi bi-graph-up"></i> &nbsp; Nuevo Levantamiento
                        </button>
                    </div>
                    <div class="card-body table-responsive">
                        <table class="table table-striped" id="table1">
                            <thead>
                                <tr>
                                    <th>N.</th>
                                    <th>COMPETIDOR</th>
                                    <th>FECHA INSPECCIÓN</th>
                                    <th>REGISTRADO POR</th>
                                    <th class="text-center">PRODUCTOS COMPARADOS</th>
                                    <th>OBSERVACIONES</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            $vr_correlativo = 0;
                            foreach ($levantamientos as $row): 
                                $vr_correlativo++;
                                $competidorRow = $row['competidor'] ?? $row['COMPETIDOR'] ?? '';
                                $fechaRow = $row['fecha_levantamiento'] ?? $row['FECHA_LEVANTAMIENTO'] ?? '';
                                $usuarioRow = $row['usuario'] ?? $row['USUARIO'] ?? 'Sistema';
                                $totalItemsRow = $row['total_items'] ?? $row['TOTAL_ITEMS'] ?? 0;
                                $obsRow = $row['observaciones'] ?? $row['OBSERVACIONES'] ?? 'N/A';
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($vr_correlativo, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><strong><?= escapar($competidorRow) ?></strong></td>
                                    <td><?= date('d/m/Y', strtotime($fechaRow)) ?></td>
                                    <td><?= escapar($usuarioRow) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-info text-dark"><?= escapar($totalItemsRow) ?> Ítems</span>
                                    </td>
                                    <td><?= escapar($obsRow) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <?php include('../../includes/menu_footer.php'); ?>
        </div>
    </div>
    
    <!-- Incluir el Modal de Matriz Dinámica por Filas -->
    <?php include __DIR__ . '/../../modals/modals_benchmark/benchmark_modal_agregar_levantamiento.php'; ?>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script>
        let table1 = document.querySelector('#table1');
        let dataTable = new simpleDatatables.DataTable(table1);
    </script>
    <script src="../../assets/js/main.js"></script>
    <script src="../../js/benchmark_js/benchmark_levantamientos.js?v=<?= filemtime(__DIR__ . '/../../js/benchmark_js/benchmark_levantamientos.js') ?>"></script>

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