<?php
// Datos agregados para el módulo "Análisis de Precios" (solo lectura, respuesta JSON).
session_start();

// Evita que warnings/notices de PHP contaminen la respuesta JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// php.ini de XAMPP trae Europe/Berlin; las fechas deben ser las de Honduras
date_default_timezone_set('America/Tegucigalpa');

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

function responder(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    responder(['success' => false, 'message' => 'Sesión expirada o no autorizada.'], 401);
}
if (!benchmark_tiene_acceso()) {
    responder(['success' => false, 'message' => 'No tenés permiso para ver el análisis.'], 403);
}
// Solo GET desde la página (el token CSRF viaja en la cabecera)
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !benchmark_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responder(['success' => false, 'message' => 'Solicitud no válida. Recargá la página e intentá de nuevo.'], 403);
}

// ---------- Filtros ----------
function leer_fecha($valor): ?DateTimeImmutable
{
    if (!is_string($valor)) return null;
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return ($f && $f->format('Y-m-d') === $valor) ? $f : null;
}

function leer_entero($valor): ?int
{
    $n = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $n === false ? null : $n;
}

$hoy     = new DateTimeImmutable('today');
$hasta   = leer_fecha($_GET['hasta'] ?? null) ?? $hoy;
$desde   = leer_fecha($_GET['desde'] ?? null) ?? $hasta->modify('-29 days');
$agrupar = (($_GET['agrupar'] ?? 'semana') === 'dia') ? 'dia' : 'semana';
$idComp  = leer_entero($_GET['id_competidor'] ?? null);
$idProd  = leer_entero($_GET['id_producto'] ?? null);

if ($desde > $hasta) {
    responder(['success' => false, 'message' => 'La fecha "desde" no puede ser mayor que "hasta".'], 422);
}
$diasRango = (int)$desde->diff($hasta)->days + 1;
if ($diasRango > 731) {
    responder(['success' => false, 'message' => 'El rango máximo es de 2 años.'], 422);
}

// Periodo anterior de la misma duración (para la variación)
$hastaAnt = $desde->modify('-1 day');
$desdeAnt = $hastaAnt->modify('-' . ($diasRango - 1) . ' days');

$d  = $desde->format('Y-m-d');
$h  = $hasta->format('Y-m-d');
$dA = $desdeAnt->format('Y-m-d');
$hA = $hastaAnt->format('Y-m-d');

// ---------- Piezas de SQL (solo constantes: nada del usuario se concatena) ----------
const FROM_BASE = '
    FROM benchmark_precios_detalle d
    INNER JOIN benchmark_levantamientos l ON l.id = d.id_levantamiento
    INNER JOIN benchmark_competidores c   ON c.id = l.id_competidor
    INNER JOIN benchmark_productos p      ON p.id = d.id_producto';
// Precio propio: snapshot del levantamiento, con el precio actual como respaldo (igual que la tabla comparativa)
const PROPIO = 'COALESCE(NULLIF(d.precio_banasupro_referencia, 0), p.precio_referencia)';
$periodo = ($agrupar === 'dia')
    ? 'l.fecha_levantamiento'
    : "date_trunc('week', l.fecha_levantamiento)::date";

/** WHERE común con parámetros posicionales. */
function armar_filtro(string $desde, string $hasta, ?int $idComp): array
{
    $sql = 'l.fecha_levantamiento BETWEEN ? AND ?';
    $params = [$desde, $hasta];
    if ($idComp !== null) {
        $sql .= ' AND l.id_competidor = ?';
        $params[] = $idComp;
    }
    return [$sql, $params];
}

function filas(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll());
}

try {
    [$where, $params] = armar_filtro($d, $h, $idComp);

    // ---------- KPIs ----------
    $k = filas($pdo, '
        SELECT COUNT(*) AS muestras,
               COUNT(DISTINCT d.id_producto) AS productos,
               COUNT(DISTINCT l.id) AS visitas,
               COUNT(*) FILTER (WHERE d.precio_competencia > ' . PROPIO . ') AS barato,
               COUNT(*) FILTER (WHERE d.precio_competencia < ' . PROPIO . ') AS caro,
               AVG(CASE WHEN ' . PROPIO . ' > 0
                        THEN (d.precio_competencia - ' . PROPIO . ') / ' . PROPIO . ' * 100 END) AS brecha
        ' . FROM_BASE . ' WHERE ' . $where, $params)[0];

    $muestras = (int)$k['muestras'];
    $kpis = [
        'muestras'  => $muestras,
        'productos' => (int)$k['productos'],
        'visitas'   => (int)$k['visitas'],
        'barato'    => (int)$k['barato'],
        'caro'      => (int)$k['caro'],
        'pct_barato' => $muestras > 0 ? round((int)$k['barato'] / $muestras * 100, 1) : null,
        'brecha'     => $k['brecha'] !== null ? round((float)$k['brecha'], 2) : null,
        'variacion'  => null, // se completa abajo
    ];

    // ---------- Productos con datos (para el selector de la gráfica 1) ----------
    $productos = array_map(
        fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'n' => (int)$r['n']],
        filas($pdo, '
            SELECT p.id, p.nombre_producto AS nombre, COUNT(*) AS n
            ' . FROM_BASE . ' WHERE ' . $where . '
            GROUP BY p.id, p.nombre_producto
            ORDER BY n DESC, p.nombre_producto
            LIMIT 300', $params)
    );
    $idsDisponibles = array_column($productos, 'id');
    $productoSel = ($idProd !== null && in_array($idProd, $idsDisponibles, true))
        ? $idProd
        : ($productos[0]['id'] ?? null);

    // ---------- Gráfica 1: evolución del precio de un producto ----------
    $evolucion = ['banasupro' => [], 'competidores' => []];
    if ($productoSel !== null) {
        $rows = filas($pdo, '
            SELECT ' . $periodo . ' AS periodo, c.id AS id_comp, c.nombre AS competidor,
                   AVG(d.precio_competencia) AS precio_comp, AVG(' . PROPIO . ') AS precio_propio
            ' . FROM_BASE . ' WHERE ' . $where . ' AND p.id = ?
            GROUP BY ' . $periodo . ', c.id, c.nombre
            ORDER BY 1', array_merge($params, [$productoSel]));

        $propioPorPeriodo = [];
        $porCompetidor = [];
        foreach ($rows as $r) {
            $propioPorPeriodo[$r['periodo']][] = (float)$r['precio_propio'];
            $porCompetidor[$r['competidor']][] = [$r['periodo'], round((float)$r['precio_comp'], 2)];
        }
        foreach ($propioPorPeriodo as $per => $vals) {
            $evolucion['banasupro'][] = [$per, round(array_sum($vals) / count($vals), 2)];
        }
        foreach ($porCompetidor as $nombre => $serie) {
            $evolucion['competidores'][] = ['nombre' => $nombre, 'data' => $serie];
        }
    }

    // ---------- Gráfica 2: variación % del precio de la competencia vs periodo anterior ----------
    [$whereVar, $paramsVar] = armar_filtro($dA, $h, $idComp);
    $rows = filas($pdo, '
        SELECT p.id, p.nombre_producto AS nombre,
               AVG(CASE WHEN l.fecha_levantamiento BETWEEN ? AND ? THEN d.precio_competencia END) AS actual,
               AVG(CASE WHEN l.fecha_levantamiento BETWEEN ? AND ? THEN d.precio_competencia END) AS anterior
        ' . FROM_BASE . ' WHERE ' . $whereVar . '
        GROUP BY p.id, p.nombre_producto',
        array_merge([$d, $h, $dA, $hA], $paramsVar));

    $variaciones = [];
    $resumenVar = ['suben' => 0, 'bajan' => 0, 'total' => 0];
    foreach ($rows as $r) {
        if ($r['actual'] === null || $r['anterior'] === null || (float)$r['anterior'] <= 0) continue;
        $variaciones[] = [
            'nombre'   => (string)$r['nombre'],
            'anterior' => round((float)$r['anterior'], 2),
            'actual'   => round((float)$r['actual'], 2),
            'pct'      => round(((float)$r['actual'] - (float)$r['anterior']) / (float)$r['anterior'] * 100, 2),
        ];
    }
    if ($variaciones) {
        $resumenVar = [
            'suben' => count(array_filter($variaciones, fn($v) => $v['pct'] > 0)),
            'bajan' => count(array_filter($variaciones, fn($v) => $v['pct'] < 0)),
            'total' => count($variaciones),
        ];
        usort($variaciones, fn($a, $b) => $b['pct'] <=> $a['pct']);
        $kpis['variacion'] = round(array_sum(array_column($variaciones, 'pct')) / count($variaciones), 2);
        // Las 8 mayores subidas y las 8 mayores bajadas
        if (count($variaciones) > 16) {
            $variaciones = array_merge(array_slice($variaciones, 0, 8), array_slice($variaciones, -8));
        }
    }

    // ---------- Gráfica 3: competitividad en el tiempo ----------
    $competitividad = array_map(function ($r) {
        $total = (int)$r['total'];
        return [
            'periodo'    => $r['periodo'],
            'total'      => $total,
            'pct_barato' => $total > 0 ? round((int)$r['barato'] / $total * 100, 1) : 0,
            'pct_caro'   => $total > 0 ? round((int)$r['caro'] / $total * 100, 1) : 0,
        ];
    }, filas($pdo, '
        SELECT ' . $periodo . ' AS periodo, COUNT(*) AS total,
               COUNT(*) FILTER (WHERE d.precio_competencia > ' . PROPIO . ') AS barato,
               COUNT(*) FILTER (WHERE d.precio_competencia < ' . PROPIO . ') AS caro
        ' . FROM_BASE . ' WHERE ' . $where . '
        GROUP BY ' . $periodo . ' ORDER BY 1', $params));

    // ---------- Gráfica 4: cobertura de datos por competidor ----------
    $cobertura = array_map(fn($r) => [
        'competidor' => (string)$r['competidor'],
        'visitas'    => (int)$r['visitas'],
        'productos'  => (int)$r['productos'],
    ], filas($pdo, '
        SELECT c.nombre AS competidor, COUNT(DISTINCT l.id) AS visitas, COUNT(DISTINCT d.id_producto) AS productos
        ' . FROM_BASE . ' WHERE ' . $where . '
        GROUP BY c.id, c.nombre ORDER BY visitas DESC, c.nombre', $params));

    responder([
        'success' => true,
        'filtros' => [
            'desde' => $d, 'hasta' => $h, 'agrupar' => $agrupar,
            'desde_anterior' => $dA, 'hasta_anterior' => $hA, 'dias' => $diasRango,
        ],
        'kpis'           => $kpis,
        'productos'      => $productos,
        'producto_id'    => $productoSel,
        'evolucion'      => $evolucion,
        'variaciones'    => $variaciones,
        'variacion_resumen' => $resumenVar,
        'competitividad' => $competitividad,
        'cobertura'      => $cobertura,
    ]);
} catch (PDOException $e) {
    error_log('benchmark_analisis_controller: ' . $e->getMessage());
    responder(['success' => false, 'message' => 'No se pudo consultar el análisis. Intentá de nuevo.'], 500);
}
