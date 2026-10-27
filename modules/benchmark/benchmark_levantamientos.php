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

// Cargar productos desde Softland y sincronizar con PostgreSQL para respetar las claves foráneas
$productosSoftland = [];
try {
    require_once __DIR__ . '/../../connection/database_sqlserver.php';
    $pdoSql = database_sqlserver();
    $sqlSoftland = "
        WITH PreciosRecientes AS (
            SELECT 
                ARTICULO,
                ArticuloDescripcion,
                COALESCE(Clasificacion2, Clasificacion1, 'General') AS Categoria,
                COALESCE(Clasificacion3, TipoArticulo, '') AS Gramaje,
                Precio,
                NivelPrecio,
                ROW_NUMBER() OVER(
                    PARTITION BY ARTICULO 
                    ORDER BY 
                        TRY_CONVERT(DATE, LEFT(PeriodoPrecio, CHARINDEX(':::', PeriodoPrecio + ':::') - 1)) DESC,
                        VersionNivelPrecio DESC
                ) AS rn
            FROM BANASUPRO.SoftlandBI_FA_listaPrecios
            WHERE ArticuloActivo LIKE 'S%'
              AND EstVersionNivPrecio = 'A'
              AND (Clasificacion2 = 'ABARROTES' OR Clasificacion1 = 'ARTICULOS COMERCIALES')
        )
        SELECT TOP 30 
            ARTICULO AS codigo, 
            ArticuloDescripcion AS nombre_producto, 
            Categoria AS categoria,
            Gramaje AS gramaje,
            CAST(Precio AS DECIMAL(10,2)) AS precio_referencia 
        FROM PreciosRecientes 
        WHERE rn = 1
        ORDER BY ArticuloDescripcion ASC
    ";
    $softlandProds = $pdoSql->query($sqlSoftland)->fetchAll(PDO::FETCH_ASSOC);

    // Sincronizar con PostgreSQL benchmark_productos para obtener los IDs reales (evitar violación de FK)
    $stmtSync = $pdo->prepare("
        INSERT INTO benchmark_productos (codigo, nombre_producto, categoria, gramaje, precio_referencia, estado)
        VALUES (?, ?, ?, ?, ?, 1)
        ON CONFLICT (codigo) DO UPDATE SET
            nombre_producto = EXCLUDED.nombre_producto,
            categoria = EXCLUDED.categoria,
            gramaje = EXCLUDED.gramaje,
            precio_referencia = EXCLUDED.precio_referencia,
            fecha_actualizacion = CURRENT_TIMESTAMP
        RETURNING id, codigo, nombre_producto, categoria, gramaje, precio_referencia
    ");

    foreach ($softlandProds as $sp) {
        $stmtSync->execute([
            $sp['codigo'],
            $sp['nombre_producto'],
            $sp['categoria'],
            $sp['gramaje'],
            $sp['precio_referencia']
        ]);
        $rowSync = $stmtSync->fetch(PDO::FETCH_ASSOC);
        $productosSoftland[] = [
            'id' => (int)($rowSync['ID'] ?? $rowSync['id']),
            'codigo' => $rowSync['CODIGO'] ?? $rowSync['codigo'],
            'nombre_producto' => $rowSync['NOMBRE_PRODUCTO'] ?? $rowSync['nombre_producto'],
            'categoria' => $rowSync['CATEGORIA'] ?? $rowSync['categoria'],
            'gramaje' => $rowSync['GRAMAJE'] ?? $rowSync['gramaje'],
            'precio_referencia' => $rowSync['PRECIO_REFERENCIA'] ?? $rowSync['precio_referencia']
        ];
    }
} catch (Throwable $e) {
    // Si SQL Server no responde, cargar directamente desde la tabla local de PostgreSQL
    try {
        $stmtP = $pdo->query("
            SELECT 
                id AS id, 
                codigo AS codigo, 
                nombre_producto AS nombre_producto,
                COALESCE(categoria, 'General') AS categoria,
                COALESCE(gramaje, '') AS gramaje,
                precio_referencia AS precio_referencia 
            FROM benchmark_productos 
            WHERE estado = 1 
            ORDER BY id DESC 
            LIMIT 30
        ");
        $productosSoftland = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $productosSoftland = [];
    }
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
                <div class="page-title">
                    <div class="row">
                        <div class="col-12 col-md-8 order-md-1 order-last">
                            <h3><i class="fas fa-clipboard-list text-primary"></i> Levantamiento de Precios</h3>
                            <p class="text-subtitle text-muted">Historial de levantamientos registrados por usuario contra la competencia.</p>
                        </div>
                        <div class="col-12 col-md-4 order-md-2 order-first text-md-end mt-2 mt-md-0">
                            <button type="button" class="btn btn-primary" onclick="abrirModalLevantamiento()">
                                <i class="fas fa-plus-circle"></i> &nbsp; Nuevo Levantamiento
                            </button>
                        </div>
                    </div>
                </div>
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