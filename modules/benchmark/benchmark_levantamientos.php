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

// Cargar competidores activos para el listbox superior
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
    <!-- Iconos FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <!-- Definir la ruta base absoluta para evitar problemas en localhost -->
    <script>
        const BASE_URL = window.location.origin + '/BNP-Benchmark';
    </script>

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

            <div class="container-fluid px-2 px-md-4">
                <div class="page-heading mb-3">
                    <div class="row align-items-center">
                        <div class="col-12">
                            <h3><i class="fas fa-clipboard-list text-primary"></i> Levantamiento de Precios</h3>
                            <p class="text-subtitle text-muted">Módulo de captura masiva directa contra la competencia.</p>
                        </div>
                    </div>
                </div>

                <div class="page-content">
                    <!-- Alerta Flash Dinámica -->
                    <?php if ($resultado): ?>
                        <div class="alert alert-<?= escapar($resultado['tipo']) ?> alert-dismissible fade show" role="alert">
                            <?= escapar($resultado['mensaje']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Tarjeta Superior: Selección de Competidor y Observaciones -->
                    <section class="card mb-3 shadow-sm border-0">
                        <div class="card-body bg-light rounded py-3">
                            <div class="row g-3 align-items-end">
                                <div class="col-12 col-md-5">
                                    <label for="global_id_competidor" class="form-label small fw-bold text-primary mb-1">
                                        <i class="fas fa-store"></i> Competidor en Curso <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select form-select-sm" id="global_id_competidor" required>
                                        <option value="">-- Seleccionar Competidor --</option>
                                        <?php if (!empty($competidores)): ?>
                                            <?php foreach ($competidores as $comp): 
                                                $idComp = $comp['id'] ?? $comp['ID'] ?? 0;
                                                $nombreComp = $comp['nombre'] ?? $comp['NOMBRE'] ?? '';
                                            ?>
                                                <option value="<?= $idComp ?>"><?= escapar($nombreComp) ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-7">
                                    <label for="global_observaciones" class="form-label small fw-bold text-muted mb-1">Observaciones Visita</label>
                                    <input type="text" class="form-control form-control-sm" id="global_observaciones" placeholder="Ej. Sucursal principal, pasillos de abarrotes...">
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Matriz de captura: grilla Bootstrap (se apila sola en celular, sin CSS personalizado) -->
                    <section class="card shadow-sm border-0">
                        <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 text-white"><i class="fas fa-basket-shopping"></i> Matriz de Precios (Softland)</h6>
                            <span class="badge bg-light text-primary"><?= count($productosSoftland) ?> Ítems</span>
                        </div>

                        <div class="card-body p-0" id="matrizProductos">
                            <!-- Encabezado: solo en pantallas medianas o más grandes -->
                            <div class="d-none d-md-block bg-dark text-white small fw-bold px-3 py-2" style="position: sticky; top: 0; z-index: 5;">
                                <div class="row g-2 align-items-center text-center">
                                    <div class="col-md-4 text-start">PRODUCTO BANASUPRO</div>
                                    <div class="col-md-2">PRECIO PROPIO</div>
                                    <div class="col-md-2">PRECIO COMPETENCIA (L.)</div>
                                    <div class="col-md-3">OBSERVACIONES</div>
                                    <div class="col-md-1">ACC</div>
                                </div>
                            </div>

                            <?php if (!empty($productosSoftland)): ?>
                                <?php foreach ($productosSoftland as $index => $prod): 
                                    $idProd = (int)($prod['id'] ?? 0);
                                    $codigoProd = $prod['codigo'] ?? '';
                                    $nombreProd = $prod['nombre_producto'] ?? '';
                                    $precioRef = (float)($prod['precio_referencia'] ?? 0);
                                ?>
                                    <div class="fila-producto border-bottom px-3 py-2 <?= $index % 2 ? 'bg-light' : '' ?>" data-id-producto="<?= $idProd ?>">
                                        <div class="row g-2 align-items-center">
                                            <!-- Producto -->
                                            <div class="col-12 col-md-4">
                                                <div class="d-flex align-items-start gap-2">
                                                    <span class="text-muted fw-bold small"><?= $index + 1 ?>.</span>
                                                    <div>
                                                        <strong class="d-block text-dark nombre-producto" style="font-size: 0.9rem;"><?= escapar($nombreProd) ?></strong>
                                                        <code class="text-primary small"><?= escapar($codigoProd) ?></code>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Precio propio -->
                                            <div class="col-5 col-md-2 text-md-center">
                                                <div class="small text-muted d-md-none">Precio propio</div>
                                                <span class="fw-bold text-muted">L. <?= number_format($precioRef, 2) ?></span>
                                            </div>

                                            <!-- Precio competencia -->
                                            <div class="col-7 col-md-2">
                                                <div class="small text-muted d-md-none">Competencia</div>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text bg-white">L.</span>
                                                    <input type="number" step="0.01" min="0" inputmode="decimal" class="form-control text-end fw-bold input-precio-comp" placeholder="0.00">
                                                </div>
                                            </div>

                                            <!-- Observaciones -->
                                            <div class="col-8 col-md-3">
                                                <input type="text" class="form-control form-control-sm input-obs-comp" placeholder="Observación (opcional)">
                                            </div>

                                            <!-- Guardar -->
                                            <div class="col-4 col-md-1 d-grid">
                                                <button type="button" class="btn btn-sm btn-outline-success btn-guardar-fila" title="Guardar producto" onclick="guardarFilaIndividual(this, <?= $idProd ?>, <?= $precioRef ?>)">
                                                    <i class="fas fa-save"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">No hay productos sincronizados desde Softland.</div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>

            <?php include('../../includes/menu_footer.php'); ?>
        </div>
    </div>
    
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/main.js"></script>
    <script src="../../js/benchmark_js/benchmark_levantamientos.js?v=<?= filemtime(__DIR__ . '/../../js/benchmark_js/benchmark_levantamientos.js') ?>"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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