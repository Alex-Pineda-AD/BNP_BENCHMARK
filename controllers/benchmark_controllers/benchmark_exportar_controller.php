<?php
/**
 * Exportación de la Tabla Comparativa de Benchmark a Excel (.xlsx) y PDF.
 * Solo lectura (no escribe en la base de datos).
 *
 * Uso: benchmark_exportar_controller.php?formato=xlsx|pdf[&id_competidor=N&id_familia=N&categoria=..&subcategoria=..&desde=..&hasta=..&estatus=..]
 * Usa los mismos filtros que la tabla comparativa (includes/benchmark_filtros.php).
 * Acceso: roles de Benchmark (5 y 6), igual que la página de la tabla comparativa.
 */
session_start();
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);
// Capacidad: el Excel y el PDF se arman en memoria. Se sube el límite y se corta con un mensaje claro
// si el filtro devuelve demasiados registros (en vez de caerse con un error 500).
ini_set('memory_limit', '512M'); // el Excel va por flujo (memoria casi constante); solo el PDF necesita margen
set_time_limit(300);

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';
require_once __DIR__ . '/../../includes/benchmark_filtros.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/benchmark_exportar_excel.php';


benchmark_requerir_pagina();
date_default_timezone_set('America/Tegucigalpa');

// ── Paleta de marca (tomada del logo de Banasupro) ───────────────────────────
const BX_AZUL   = '12306E';
const BX_DORADO = 'FFC72C';
const BX_VERDE  = '2E7D32';
const BX_ROJO   = 'C0392B';
const BX_GRIS   = '5F6B7A';
const BX_FONDO  = 'F4F6FB';
const BX_LINEA  = 'D9DEEA';
// Máximo de filas de detalle: Excel completo hasta este número; el PDF lleva el detalle solo si cabe cómodo
// (Dompdf gasta mucha memoria por fila); por encima de BX_MAX_FILAS_PDF el PDF trae solo el resumen.
const BX_MAX_FILAS_EXCEL = 500000; // el Excel se escribe por flujo (casi no usa memoria); el límite de una hoja de Excel es 1,048,576
const BX_MAX_FILAS_PDF   = 500;
const BX_LOGO   = __DIR__ . '/../../assets/images/logo/logo_banasupro_horizontal.png';

const BX_ESTATUS = [
    'barato' => ['txt' => 'Más barato',   'color' => BX_VERDE, 'fondo' => 'E3F4E6'],
    'caro'   => ['txt' => 'Más caro',     'color' => BX_ROJO,  'fondo' => 'FBE4E1'],
    'igual'  => ['txt' => 'Mismo precio', 'color' => BX_GRIS,  'fondo' => 'ECEFF4'],
];

function bx_h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bx_signo(float $n, int $dec = 2, string $suf = ''): string
{
    return ($n > 0 ? '+' : '') . number_format($n, $dec) . $suf;
}

function bx_fin(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    exit($mensaje);
}

// ── Parámetros ───────────────────────────────────────────────────────────────
$formato = strtolower((string)($_GET['formato'] ?? ''));
if (!in_array($formato, ['xlsx', 'pdf'], true)) {
    bx_fin(400, 'Formato no válido. Use xlsx o pdf.');
}

$filtros = benchmark_leer_filtros($_GET);
[$where, $params] = benchmark_armar_where($filtros);

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

// ── Datos (misma consulta y filtros que la tabla comparativa) ─────────────────
try {
    // Conteo barato antes de cargar nada
    $cnt = $pdo->prepare('SELECT COUNT(*) ' . BENCHMARK_FROM_BASE . ' WHERE ' . $where);
    $cnt->execute($params);
    $totalFilas = (int)$cnt->fetchColumn();
    if ($formato === 'xlsx' && $totalFilas > BX_MAX_FILAS_EXCEL) {
        bx_fin(413, 'Hay ' . number_format($totalFilas) . ' registros con estos filtros y el Excel admite hasta ' . number_format(BX_MAX_FILAS_EXCEL)
            . '. Filtra por competidor, familia o fechas y vuelve a exportar.');
    }
    $detalleOmitido = $formato === 'pdf' && $totalFilas > BX_MAX_FILAS_PDF;

    $sqlDetalle = "
        SELECT " . BENCHMARK_COMPETIDOR_NOMBRE . " AS competidor, l.fecha_levantamiento,
               f.nombre AS familia, p.clasificacion2 AS categoria, p.categoria AS subcategoria,
               p.nombre_producto || CASE WHEN d.base_comparacion = 'paquete' THEN ' (paquete)' ELSE '' END AS producto,
               " . BENCHMARK_PRECIO_PROPIO . " AS precio_propio,
               d.precio_competencia, d.observaciones
        " . BENCHMARK_FROM_BASE . "
        WHERE $where
        ORDER BY f.orden, c.nombre, c.sucursal, p.nombre_producto, l.fecha_levantamiento DESC, d.id DESC
    ";
    // El PDF carga el detalle (si cabe); el Excel lo lee por lotes mientras escribe (ver $leerLote más abajo)
    $filas = [];
    if ($formato === 'pdf' && !$detalleOmitido) {
        $stmt = $pdo->prepare($sqlDetalle);
        $stmt->execute($params);
        $filas = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Resumen familia x competidor (mismos números que la pantalla)
    $resumen = benchmark_resumen_familia_competidor($pdo, $where, $params);

    // Texto de los filtros aplicados (para el encabezado del archivo)
    $opc = benchmark_opciones_filtro($pdo);
    $nombreCompetidor = 'Todos los competidores';
    $partes = [];
    foreach ($opc['competidores'] as $o) {
        if ($filtros['id_competidor'] === (int)$o['id']) { $nombreCompetidor = (string)$o['nombre']; $partes[] = 'Competidor: ' . $o['nombre']; }
    }
    foreach ($opc['familias'] as $o) {
        if ($filtros['id_familia'] === (int)$o['id']) { $partes[] = 'Familia: ' . $o['nombre']; }
    }
    if ($filtros['categoria'] !== null)    { $partes[] = 'Categoría: ' . benchmark_etiqueta($filtros['categoria']); }
    if ($filtros['subcategoria'] !== null) { $partes[] = 'Subcategoría: ' . benchmark_etiqueta($filtros['subcategoria']); }
    if ($filtros['desde'] !== null || $filtros['hasta'] !== null) {
        $partes[] = benchmark_texto_semanas($filtros['desde'], $filtros['hasta']);
    }
    if ($filtros['estatus'] !== null) {
        $partes[] = 'Estatus: ' . ['barato' => 'Banasupro más barato', 'caro' => 'Banasupro más caro', 'igual' => 'Mismo precio'][$filtros['estatus']];
    }
    $textoFiltros = $partes ? implode(' · ', $partes) : 'Todos los competidores y familias';
} catch (PDOException $e) {
    error_log('benchmark_exportar: ' . $e->getMessage());
    bx_fin(500, 'No se pudo consultar la información para exportar.');
}

/** Agrega diferencia, porcentaje y estatus a una fila de detalle. */
function bx_enriquecer(array $f): array
{
    $propio = (float)$f['precio_propio'];
    $comp   = (float)$f['precio_competencia'];
    $f['dif'] = $comp - $propio;
    $f['pct'] = $propio > 0 ? ($f['dif'] / $propio) * 100 : 0;
    $f['est'] = $f['dif'] > 0 ? 'barato' : ($f['dif'] < 0 ? 'caro' : 'igual');
    return $f;
}
$filas = array_map('bx_enriquecer', $filas);
$kpi = benchmark_kpis_desde_resumen($resumen);

$slug = iconv('UTF-8', 'ASCII//TRANSLIT', $nombreCompetidor);
$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $slug !== false ? $slug : 'Todos'), '_');
$ctx = [
    'competidor' => $nombreCompetidor,
    'filtros'    => $textoFiltros,
    'usuario'    => (string)($_SESSION['user_nombre'] ?? 'Usuario'),
    'generado'   => date('d/m/Y H:i'),
    'total'      => $totalFilas,
    'detalle_omitido' => $detalleOmitido,
    'archivo'    => 'Benchmark_Precios_' . ($slug !== '' ? $slug : 'Todos') . '_' . date('Ymd_Hi'),
];

// Lector por lotes para el Excel: pide 5,000 filas a la vez (LIMIT/OFFSET sobre un orden estable, d.id desempata)
$leerLote = function (int $desde, int $cantidad) use ($pdo, $sqlDetalle, $params): array {
    $st = $pdo->prepare($sqlDetalle . ' LIMIT ' . (int)$cantidad . ' OFFSET ' . (int)$desde);
    $st->execute($params);
    return array_map(fn($r) => bx_enriquecer(array_change_key_case($r, CASE_LOWER)), $st->fetchAll(PDO::FETCH_ASSOC));
};

// ═════════════════════════════════════════════════════════════════════════════
//  PDF (Dompdf): resumen por familia y competidor + detalle de todos los artículos
// ═════════════════════════════════════════════════════════════════════════════
function bx_pdf(array $filas, array $resumen, array $kpi, array $ctx): void
{
    if (!class_exists(\Dompdf\Dompdf::class)) {
        error_log('benchmark_exportar: falta dompdf/dompdf (ejecutar composer require dompdf/dompdf).');
        bx_fin(500, 'La librería de PDF no está instalada en este equipo. Contacte al administrador.');
    }

    $logo = is_file(BX_LOGO) ? 'data:image/png;base64,' . base64_encode(file_get_contents(BX_LOGO)) : '';
    $azul = '#' . BX_AZUL;
    $dorado = '#' . BX_DORADO;
    $verde = '#' . BX_VERDE;
    $rojo = '#' . BX_ROJO;
    $gris = '#' . BX_GRIS;
    $fondo = '#' . BX_FONDO;
    $linea = '#' . BX_LINEA;

    $tarjeta = fn(string $etq, string $val, string $color) =>
        '<td class="kpi" style="border-top:3px solid ' . $color . '"><div class="kpi-l">' . bx_h($etq)
        . '</div><div class="kpi-v" style="color:' . $color . '">' . $val . '</div></td>';

    $filasHtml = '';
    if (!$resumen) {
        $filasHtml = '<tr><td colspan="8" style="text-align:center;padding:18px;color:' . $gris
            . '"><i>No hay datos para exportar con los filtros seleccionados.</i></td></tr>';
    }
    $famPrev = null;
    foreach ($resumen as $g) {
        $prod = max(1, (int)$g['productos']);
        $pB = (int)round((int)$g['barato'] / $prod * 100);
        $pC = (int)round((int)$g['caro'] / $prod * 100);
        $pI = max(0, 100 - $pB - $pC);
        $br = $g['brecha'] === null ? null : (float)$g['brecha'];
        $nueva = $famPrev !== $g['id_familia'];
        $famPrev = $g['id_familia'];
        $segmentos = '';
        foreach ([[$pB, $verde], [$pI, '#C9CFDA'], [$pC, $rojo]] as [$ancho, $color]) {
            if ($ancho > 0) {
                $segmentos .= '<td style="width:' . $ancho . '%;background:' . $color . ';height:8px;padding:0;border:0"></td>';
            }
        }
        $filasHtml .= '<tr' . ($nueva ? ' class="nf"' : '') . '>'
            . '<td class="fam">' . ($nueva ? bx_h($g['familia']) : '') . '</td>'
            . '<td>' . bx_h($g['competidor']) . '</td>'
            . '<td class="c">' . (int)$g['productos'] . '</td>'
            . '<td class="c"><span class="pill" style="background:#E3F4E6;color:' . $verde . '">' . (int)$g['barato'] . '</span></td>'
            . '<td class="c"><span class="pill" style="background:#FBE4E1;color:' . $rojo . '">' . (int)$g['caro'] . '</span></td>'
            . '<td class="c" style="color:' . $gris . '">' . (int)$g['igual'] . '</td>'
            . '<td><table style="width:100%;border-collapse:collapse"><tr>' . $segmentos . '</tr></table></td>'
            . '<td class="r" style="font-weight:bold;color:' . (($br ?? 0) >= 0 ? $verde : $rojo) . '">' . ($br === null ? '—' : bx_signo($br, 2, '%')) . '</td>'
            . '</tr>';
    }

    // Detalle: todos los artículos levantados, agrupados por familia y competidor
    $detalleHtml = '';
    $n = 0;
    foreach ($filas as $f) {
        $n++;
        $e = BX_ESTATUS[$f['est']];
        $obs = trim((string)($f['observaciones'] ?? ''));
        $ts = strtotime((string)$f['fecha_levantamiento']);
        $detalleHtml .= '<tr' . ($n % 2 === 0 ? ' class="z"' : '') . '>'
            . '<td class="c">' . $n . '</td>'
            . '<td><b>' . bx_h($f['competidor']) . '</b></td>'
            . '<td class="c">' . ($ts ? date('d/m/Y', $ts) : '-') . '</td>'
            . '<td><span class="pill" style="background:#E8EEFB;color:' . $azul . '">' . bx_h($f['familia']) . '</span></td>'
            . '<td style="font-size:7px">' . bx_h(benchmark_etiqueta($f['categoria'])) . '<br><span style="color:' . $gris . '">' . bx_h(benchmark_etiqueta($f['subcategoria'])) . '</span></td>'
            . '<td>' . bx_h(benchmark_etiqueta($f['producto'])) . '</td>'
            . '<td class="r" style="color:' . $azul . ';font-weight:bold">L. ' . number_format((float)$f['precio_propio'], 2) . '</td>'
            . '<td class="r"><b>L. ' . number_format((float)$f['precio_competencia'], 2) . '</b></td>'
            . '<td class="r" style="color:#' . $e['color'] . ';font-weight:bold">' . bx_signo($f['dif'])
            . '<br><span style="font-size:7px">' . bx_signo($f['pct'], 2, '%') . '</span></td>'
            . '<td class="c"><span class="pill" style="background:#' . $e['fondo'] . ';color:#' . $e['color'] . '">' . $e['txt'] . '</span></td>'
            . '<td>' . bx_h($obs === '' ? '-' : $obs) . '</td>'
            . '</tr>';
    }
    $detalleSeccion = '';
    if (!empty($ctx['detalle_omitido'])) {
        $detalleSeccion = '<div class="nota" style="margin-top:14px;font-size:9px"><b>Detalle no incluido en el PDF:</b> hay '
            . number_format((int)$ctx['total']) . ' registros con estos filtros. El detalle completo está en el Excel; este PDF muestra el resumen por familia y competidor.</div>';
    } elseif ($filas) {
        $detalleSeccion = '<div style="page-break-before:always"></div>
    <div class="titulo" style="font-size:14px;margin-bottom:6px">DETALLE DE LEVANTAMIENTOS</div>
    <div class="band1"></div><div class="band2"></div>
    <table class="dt" style="margin-top:8px"><thead><tr>
        <th style="width:3%">N.</th><th style="width:10%;text-align:left">COMPETIDOR</th><th style="width:7%">FECHA</th>
        <th style="width:8%">FAMILIA</th><th style="width:11%;text-align:left">CATEGORÍA / SUBCAT.</th>
        <th style="width:20%;text-align:left">PRODUCTO Y PRESENTACIÓN</th><th style="width:8%">P. BANASUPRO</th><th style="width:8%">P. COMPETENCIA</th>
        <th style="width:8%">DIFERENCIA</th><th style="width:8%">ESTATUS</th><th style="text-align:left">NOTAS</th>
    </tr></thead><tbody>' . $detalleHtml . '</tbody></table>
    <div class="nota">Cada fila es una visita. Diferencia = precio de la competencia menos precio de Banasupro (positivo = Banasupro más barato).</div>';
    }

    $logoHtml = $logo !== ''
        ? '<img src="' . $logo . '" style="height:46px">'
        : '<b style="font-size:16px;color:' . $azul . '">BANASUPRO</b>';

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
        @page { margin: 26px 28px 44px 28px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #1f2937; }
        .band1 { height: 5px; background: ' . $azul . '; } .band2 { height: 3px; background: ' . $dorado . '; }
        .titulo { font-size: 19px; font-weight: bold; color: ' . $azul . '; letter-spacing: .5px; }
        .sub { font-size: 9.5px; color: ' . $gris . '; margin-top: 3px; }
        .meta { font-size: 8px; color: ' . $gris . '; margin-top: 2px; font-style: italic; }
        table.kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 12px -6px 14px -6px; }
        td.kpi { background: ' . $fondo . '; text-align: center; padding: 8px 4px; width: 25%; }
        .kpi-l { font-size: 7px; font-weight: bold; color: ' . $gris . '; letter-spacing: .4px; }
        .kpi-v { font-size: 15px; font-weight: bold; margin-top: 3px; }
        table.dt { width: 100%; border-collapse: collapse; }
        table.dt thead { display: table-header-group; }
        table.dt th { background: ' . $azul . '; color: #fff; font-size: 8px; padding: 7px 5px; border-bottom: 2px solid ' . $dorado . '; }
        table.dt td { padding: 6px 5px; border-bottom: 1px solid ' . $linea . '; vertical-align: middle; }
        table.dt tr { page-break-inside: avoid; }
        tr.nf td { border-top: 1.5px solid ' . $azul . '; }
        tr.z td { background: ' . $fondo . '; }
        td.fam { font-weight: bold; color: ' . $azul . '; font-size: 9.5px; }
        .c { text-align: center; } .r { text-align: right; white-space: nowrap; }
        .pill { padding: 2px 9px; border-radius: 8px; font-size: 8px; font-weight: bold; white-space: nowrap; }
        .nota { margin-top: 10px; font-size: 7.5px; color: ' . $gris . '; font-style: italic; }
    </style></head><body>
    <table width="100%" style="border-collapse:collapse"><tr>
        <td style="width:34%;vertical-align:middle;padding-bottom:6px">' . $logoHtml . '</td>
        <td style="text-align:right;vertical-align:middle;padding-bottom:6px">
            <div class="titulo">BENCHMARK DE PRECIOS</div>
            <div class="sub">Resumen por familia y competidor · <b>' . bx_h($ctx['filtros']) . '</b></div>
            <div class="meta">Generado el ' . bx_h($ctx['generado']) . ' por ' . bx_h($ctx['usuario']) . '</div>
        </td></tr></table>
    <div class="band1"></div><div class="band2"></div>
    <table class="kpis"><tr>'
        . $tarjeta('PRODUCTOS COMPARADOS', number_format($kpi['total']), $azul)
        . $tarjeta('BANASUPRO MÁS BARATO', number_format($kpi['barato']) . ' <span style="font-size:9px;font-weight:normal">(' . $kpi['pct_barato'] . '%)</span>', $verde)
        . $tarjeta('BANASUPRO MÁS CARO', number_format($kpi['caro']) . ' <span style="font-size:9px;font-weight:normal">(' . $kpi['pct_caro'] . '%)</span>', $rojo)
        . $tarjeta('BRECHA PROMEDIO', $kpi['brecha'] === null ? '—' : bx_signo($kpi['brecha'], 2, '%'), $azul)
    . '</tr></table>
    <table class="dt"><thead><tr>
        <th style="width:14%;text-align:left">FAMILIA</th><th style="width:20%;text-align:left">COMPETIDOR</th>
        <th style="width:9%">PRODUCTOS</th><th style="width:10%">MÁS BARATO</th><th style="width:10%">MÁS CARO</th><th style="width:8%">IGUAL</th>
        <th style="width:20%">PROPORCIÓN</th><th style="width:9%">BRECHA</th>
    </tr></thead><tbody>' . $filasHtml . '</tbody></table>
    <div class="nota">Cada producto cuenta una vez por competidor, con su último precio levantado en el periodo. Más barato = el precio de Banasupro es menor. Brecha positiva = la competencia es más cara.</div>' . $detalleSeccion . '
    </body></html>';

    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('isHtml5ParserEnabled', true);
    $opt->set('isFontSubsettingEnabled', true);
    $opt->set('defaultFont', 'DejaVu Sans');
    $pdf = new \Dompdf\Dompdf($opt);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('letter', 'landscape');
    $pdf->render();

    // Pie en todas las páginas
    $canvas = $pdf->getCanvas();
    $fuente = $pdf->getFontMetrics()->getFont('DejaVu Sans');
    $w = $canvas->get_width();
    $h = $canvas->get_height();
    $canvas->page_text(28, $h - 28, 'Banasupro · Benchmark de Precios', $fuente, 7.5, [0.37, 0.42, 0.48]);
    $canvas->page_text($w - 100, $h - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 7.5, [0.37, 0.42, 0.48]);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $pdf->stream($ctx['archivo'] . '.pdf', ['Attachment' => true]);
    exit;
}

if ($formato === 'xlsx') {
    try {
        benchmark_excel_armar($leerLote, $totalFilas, $resumen, $kpi, $ctx)->enviar($ctx['archivo'] . '.xlsx');
    } catch (Throwable $e) {
        error_log('benchmark_exportar (xlsx): ' . $e->getMessage());
        bx_fin(500, 'No se pudo generar el Excel.');
    }
} else {
    bx_pdf($filas, $resumen, $kpi, $ctx);
}
