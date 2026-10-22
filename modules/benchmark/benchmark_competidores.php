<?php
session_start();
header('Cache-Control: no-store');

// Forzamos una sesión activa temporalmente para pruebas locales
$_SESSION['user_id'] = 1;
$_SESSION['id_rol'] = 1;
$_SESSION['nombre_usuario'] = 'Administrador Benchmark';

// Incluimos la conexión centralizada con la ruta absoluta correcta
require_once __DIR__ . '/../../connection/connection.php';

// Aseguramos la instancia de PDO si el archivo usa la función database()
if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

// Opcional: Validación de sesión si el módulo lo requiere
if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}

// Consultamos los competidores directamente desde PostgreSQL con sus estilos de estado
$consulta = $pdo->prepare('
    SELECT ID, NOMBRE, COALESCE(UBICACION_REFERENCIA, \'N/A\') AS UBICACION_REFERENCIA, 
    CASE 
        WHEN ESTADO = \'activo\' OR ESTADO = \'1\' THEN \'Activo\'
        ELSE \'Inactivo\'
    END AS ESTADO_TEXTO,
    CASE 
        WHEN ESTADO = \'activo\' OR ESTADO = \'1\' THEN \'badge bg-success\'
        ELSE \'badge bg-danger\'
    END AS BG
    FROM benchmark_competidores
    ORDER BY ID DESC
');
$consulta->execute();
$competidores = $consulta->fetchAll(PDO::FETCH_ASSOC);

// Función de escape para seguridad contra XSS
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

    <!-- Choices CSS -->
    <link rel="stylesheet" href="../../assets/vendors/choices.js/choices.min.css" />
    <!-- Simple Datatables CSS -->
    <link rel="stylesheet" href="../../assets/vendors/simple-datatables/style.css">
</head>
<body>
    <!-- Incluimos los modales del módulo de benchmark -->
    <?php 
    include __DIR__ . '/../../modals/modals_benchmark/benchmark_modal_competidor.php'; 
    ?>

    <div id="app">
        <!-- Sidebar lateral estándar de la plantilla -->
        <div id="sidebar" class="active">
            <?php include __DIR__ . '/../../includes/menu_sidebar.php'; ?>
        </div>

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
                            <h3>Gestión de Competidores</h3>
                            <p class="text-subtitle text-muted">Administración del catálogo de comercios y supermercados a evaluar.</p>
                        </div>
                    </div>
                </div>

                <section class="section">
                    <div class="card">
                        <div class="card-body">
                            <!-- Botón para disparar el modal de nuevo competidor -->
                            <button type="button" class="btn btn-primary btn-sm mb-3" data-bs-toggle="modal" data-bs-target="#addUser">
                                <i class="bi bi-plus-lg"></i> Nuevo Competidor
                            </button>

                            <table class="table table-striped table-hover" id="table1">
                                <thead>
                                    <tr>
                                        <th>N.</th>
                                        <th>NOMBRE DEL COMPETIDOR</th>
                                        <th>UBICACIÓN / REFERENCIA</th>
                                        <th>ESTADO</th>
                                        <th>ACCIONES</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $vr_correlativo = 0; 
                                    foreach ($competidores as $row): 
                                        $vr_correlativo++; 
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($vr_correlativo) ?></td>
                                            <td><?= escapar($row['NOMBRE']) ?></td>
                                            <td><?= escapar($row['UBICACION_REFERENCIA']) ?></td>
                                            <td><span class="<?= $row['BG'] ?>"><?= escapar($row['ESTADO_TEXTO']) ?></span></td>
                                            <td>
                                                <button type="button" class="btn btn-warning btn-sm text-dark" data-bs-toggle="modal" data-bs-target="#editUser" data-competidor="<?= escapar(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>">
                                                    <i class="bi bi-pencil-square"></i> Editar
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
        </main>
    </div>

    <!-- Scripts esenciales de Mazer y componentes -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script>
        // Inicialización de Simple Datatable igual al ejemplo de tu compañero
        let table1 = document.querySelector('#table1');
        if (table1) {
            let dataTable = new simpleDatatables.DataTable(table1);
        }
    </script>
    <script src="../../assets/vendors/choices.js/choices.min.js"></script>
    <script src="../../assets/js/main.js"></script>

    <!-- Script personalizado del módulo de competidores -->
    <script src="../../js/benchmark_js/benchmark_competidores.js?v=<?= filemtime(__DIR__ . '/../../js/benchmark_js/benchmark_competidores.js') ?>"></script>
</body>
</html>