<?php 
session_start();
header('Cache-Control: no-store');

// php.ini de XAMPP trae Europe/Berlin; las horas y fechas deben ser las de Honduras
date_default_timezone_set('America/Tegucigalpa');

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';
require_once __DIR__ . '/../../includes/benchmark_auditoria.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

// Control de acceso por rol (5 y 6)
benchmark_requerir_pagina();

// Capturar mensaje flash de éxito o error
$resultado = $_SESSION['levantamiento_resultado'] ?? null;
unset($_SESSION['levantamiento_resultado']);

// Cargar competidores activos para el listbox superior
$competidores = [];
try {
    $stmtC = $pdo->query("SELECT id AS id, (nombre || COALESCE(' – ' || NULLIF(TRIM(sucursal), ''), '')) AS nombre
                          FROM benchmark.benchmark_competidores WHERE estado = 1 ORDER BY nombre ASC, sucursal ASC");
    $competidores = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $competidores = [];
}

// Sincroniza con Softland (solo SELECT): trae las categorías/subcategorías del alcance con el
// PRECIO LISTA más reciente ÷ FACTOR_EMPAQUE. La lógica vive en includes/benchmark_sync_softland.php.
$avisoPrecios = null;
$codigosOcultos = [];
$sincVencida = false;
try {
    require_once __DIR__ . '/../../includes/benchmark_sync_softland.php';
    // La pantalla solo lee las tablas de Benchmark. Softland se consulta únicamente con el botón "Actualizar precios"
    // (o la primera vez, si todavía no hay ninguna sincronización registrada).
    $resSync = benchmark_sincronizar_si_toca($pdo, 'sync_softland', 315360000, isset($_GET['actualizar']));
    $codigosOcultos = array_merge($resSync['excluidos'], $resSync['duplicados']);
    // Datos con más de una hora: la pantalla pide la actualización en segundo plano (no hace esperar a nadie)
    $sincVencida = !empty($resSync['desde_cache']) && (time() - (int)$resSync['ts']) > BENCHMARK_SYNC_TTL;
    if (!empty($resSync['excluidos'])) {
        error_log('[benchmark_levantamientos] Sin precio válido en Softland: ' . implode(', ', $resSync['excluidos']));
    }
} catch (Throwable $e) {
    error_log('[benchmark_levantamientos] Softland no disponible: ' . $e->getMessage());
    $avisoPrecios = 'No se pudo consultar Softland. Se muestran los precios de la última sincronización; verifica que estén al día antes de comparar.';
}

// Catálogo por categoría (Clasificacion2) y subcategoría (Clasificacion3) de Softland,
// limitado al alcance configurado. Las claves vienen en MAYÚSCULAS por PDO::CASE_UPPER.
$categoriasCatalogo = [];
$totalProductosCatalogo = 0;
try {
    $stmtCat = $pdo->query("
        SELECT p.id AS id_producto, p.codigo, p.nombre_producto, p.clasificacion2,
               p.categoria AS subcategoria, p.precio_referencia, p.precio_lista, p.factor_empaque,
               f.nombre AS familia
        FROM benchmark.benchmark_productos p
        JOIN benchmark.benchmark_familias f ON f.id = p.id_familia AND f.estado = 1
        WHERE p.estado = 1
          AND p.clasificacion2 IS NOT NULL
          AND p.precio_lista IS NOT NULL
          AND p.precio_referencia IS NOT NULL
          AND EXISTS (
              SELECT 1 FROM benchmark.benchmark_alcance_categorias a
              WHERE a.estado = 1
                AND UPPER(a.clasificacion2) = UPPER(p.clasificacion2)
                AND (a.clasificacion3 IS NULL OR UPPER(a.clasificacion3) = UPPER(p.categoria))
          )
        ORDER BY p.clasificacion2, p.categoria, p.nombre_producto
    ");
    foreach ($stmtCat->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $fila = array_change_key_case($fila, CASE_LOWER);
        if (in_array(trim((string)$fila['codigo']), $codigosOcultos, true)) {
            continue; // sin precio válido o duplicado antiguo: no se captura
        }
        $cat = (string)$fila['clasificacion2'];
        $sub = (string)$fila['subcategoria'];
        if (!isset($categoriasCatalogo[$cat])) {
            $categoriasCatalogo[$cat] = ['nombre' => $cat, 'total' => 0, 'subs' => []];
        }
        $categoriasCatalogo[$cat]['subs'][$sub][] = [
            'id' => (int)$fila['id_producto'],
            'nombre_producto' => $fila['nombre_producto'],
            'precio_referencia' => (float)$fila['precio_referencia'],
            'precio_lista' => (float)$fila['precio_lista'],
            'factor_empaque' => (float)$fila['factor_empaque'],
            'familia' => $fila['familia'],
        ];
        $categoriasCatalogo[$cat]['total']++;
        $totalProductosCatalogo++;
    }
    $categoriasCatalogo = array_values($categoriasCatalogo);
} catch (PDOException $e) {
    error_log('[benchmark_levantamientos] Catálogo por categoría: ' . $e->getMessage());
    $categoriasCatalogo = [];
}

function etiqueta($texto) {
    return ucfirst(mb_strtolower((string)$texto, 'UTF-8'));
}

function escapar($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include '../../includes/head.php'; ?>
    <link rel="stylesheet" href="../../assets/css/benchmark.css">
</head>

<body>
    <script>
        const BENCHMARK_CSRF = <?= json_encode(benchmark_csrf_token()) ?>;
        const BENCHMARK_SYNC_VENCIDA = <?= $sincVencida ? 'true' : 'false' ?>;
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
                    <div class="card benchmark-hero mb-4">
                        <div class="card-body p-4 d-flex align-items-center flex-wrap gap-3">
                            <div class="benchmark-hero-icon"><i class="fas fa-clipboard-list"></i></div>
                            <div class="flex-grow-1">
                                <h3 class="text-white mb-1">Levantamiento de Precios</h3>
                                <p class="mb-0 text-white-50">Módulo de captura masiva directa contra la competencia.</p>
                            </div>
                            <a href="?actualizar=1" class="btn btn-light text-primary fw-bold btn-sm" title="Vuelve a consultar los precios en Softland">
                                <i class="fas fa-sync-alt"></i> Actualizar precios
                                <?php if (!empty($resSync['ts'])): ?><small id="bmUltimaSync" class="d-block fw-normal text-muted">Última: <?= date('H:i', $resSync['ts']) ?></small><?php endif; ?>
                            </a>
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

                    <?php if (!empty($avisoPrecios)): ?>
                        <div class="alert alert-warning" role="alert"><?= escapar($avisoPrecios) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($resSync['excluidos'])): ?>
                        <div class="alert alert-info" role="alert">
                            <?= count($resSync['excluidos']) ?> producto(s) ya guardados no se muestran porque Softland ya no devuelve precio de lista, factor de empaque o unidad de venta válida.
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

                    <!-- Matriz de captura por categoría: una pestaña por categoría, filas apiladas en celular -->
                    <section class="card shadow-sm border-0">
                        <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 text-white"><i class="fas fa-shopping-basket"></i> Precios por categoría</h6>
                            <span class="badge bg-light text-primary" id="badgeTotalCapturados">0 de <?= (int)$totalProductosCatalogo ?></span>
                        </div>

                        <div class="card-body p-0" id="matrizProductos">
                            <?php if (!empty($categoriasCatalogo)): ?>
                                <div class="bm-acordeon" id="acordeon-categorias">
                                    <?php foreach ($categoriasCatalogo as $i => $fam): ?>
                                        <div class="bm-acordeon-item bm-item-categoria">
                                            <button class="bm-acordeon-cab bm-cab-categoria collapsed" type="button"
                                                    data-bs-toggle="collapse" data-bs-target="#pane-cat-<?= $i ?>" aria-expanded="false">
                                                <i class="fas fa-folder bm-ico bm-ico-cerrado"></i><i class="fas fa-folder-open bm-ico bm-ico-abierto"></i>
                                                <span class="bm-acordeon-titulo"><?= escapar(etiqueta($fam['nombre'])) ?></span>
                                                <span class="badge bg-light text-dark contador-categoria" data-categoria="<?= $i ?>">0/<?= (int)$fam['total'] ?></span>
                                                <i class="fas fa-chevron-down bm-chevron"></i>
                                            </button>
                                            <div id="pane-cat-<?= $i ?>" class="collapse" data-bs-parent="#acordeon-categorias">
                                        <div class="panel-categoria" data-categoria="<?= $i ?>">
                                            <!-- Encabezado: solo en pantallas medianas o más grandes -->
                                            <div class="d-none d-md-block bg-dark text-white small fw-bold px-3 py-2">
                                                <div class="row g-2 align-items-center text-center">
                                                    <div class="col-md-6 text-start">PRODUCTO Y PRESENTACIÓN</div>
                                                    <div class="col-md-2">PRECIO PROPIO (C/U)</div>
                                                    <div class="col-md-3">PRECIO COMPETENCIA POR UNIDAD (L.)</div>
                                                    <div class="col-md-1">ACC</div>
                                                </div>
                                            </div>

                                            <div class="bm-acordeon" id="acordeon-cat-<?= $i ?>">
                                            <?php $k = 0; foreach ($fam['subs'] as $nombreSub => $productosSub): $k++; $idSub = 'sub-' . $i . '-' . $k; ?>
                                                <div class="bm-acordeon-item acordeon-sub" data-sub="<?= $idSub ?>">
                                                    <button class="bm-acordeon-cab collapsed" type="button"
                                                            data-bs-toggle="collapse" data-bs-target="#<?= $idSub ?>" aria-expanded="false">
                                                        <i class="fas fa-tag bm-ico"></i>
                                                        <span class="bm-acordeon-titulo"><?= escapar(etiqueta($nombreSub)) ?></span>
                                                        <span class="badge bg-light text-dark contador-sub" data-sub="<?= $idSub ?>">0/<?= count($productosSub) ?></span>
                                                        <i class="fas fa-chevron-down bm-chevron"></i>
                                                    </button>
                                                    <div id="<?= $idSub ?>" class="collapse" data-bs-parent="#acordeon-cat-<?= $i ?>">
                                                        <div class="bm-acordeon-cuerpo">
                                            <?php foreach ($productosSub as $j => $prod):
                                                $idProd = (int)$prod['id'];
                                                $precioRef = (float)$prod['precio_referencia'];
                                            ?>
                                                <div class="fila-producto border-bottom px-3 py-2 <?= $j % 2 ? 'bg-light' : '' ?>" data-id-producto="<?= $idProd ?>">
                                                    <div class="row g-2 align-items-center">
                                                        <div class="col-12 col-md-6">
                                                            <strong class="d-block text-dark nombre-producto" style="font-size: 0.9rem;"><?= escapar(etiqueta($prod['nombre_producto'])) ?></strong>
                                                            <?php if (!empty($prod['familia'])): ?>
                                                                <span class="badge bg-light-primary me-1"><?= escapar($prod['familia']) ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($prod['factor_empaque'] > 1): ?>
                                                                <small class="text-muted">Empaque ×<?= rtrim(rtrim(number_format($prod['factor_empaque'], 2), '0'), '.') ?> = L. <?= number_format($prod['precio_lista'], 2) ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="col-5 col-md-2 text-md-center">
                                                            <div class="small text-muted d-md-none">Precio propio (c/u)</div>
                                                            <span class="fw-bold text-muted">L. <?= number_format($precioRef, 2) ?></span>
                                                        </div>
                                                        <div class="col-7 col-md-3">
                                                            <div class="small text-muted d-md-none">Competencia (por unidad)</div>
                                                            <div class="input-group input-group-sm">
                                                                <span class="input-group-text bg-white">L.</span>
                                                                <input type="number" step="0.01" min="0" inputmode="decimal" class="form-control text-end fw-bold input-precio-comp" placeholder="0.00">
                                                            </div>
                                                        </div>
                                                        <div class="col-12 col-md-1 d-grid">
                                                            <button type="button" class="btn btn-sm btn-outline-success btn-guardar-fila" title="Guardar producto" onclick="guardarFilaIndividual(this, <?= $idProd ?>, <?= $precioRef ?>)">
                                                                <i class="fas fa-save"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                        </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">No hay productos en el catálogo. Verifica el alcance de categorías y la conexión con Softland.</div>
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
    <script src="../../assets/vendors/sweetalert2/sweetalert2.all.min.js"></script>
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