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
ini_set('memory_limit', '256M');
set_time_limit(120);

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';
require_once __DIR__ . '/../../includes/benchmark_filtros.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
    $stmt = $pdo->prepare("
        SELECT " . BENCHMARK_COMPETIDOR_NOMBRE . " AS competidor, l.fecha_levantamiento,
               f.nombre AS familia, p.clasificacion2 AS categoria, p.categoria AS subcategoria,
               p.nombre_producto AS producto,
               " . BENCHMARK_PRECIO_PROPIO . " AS precio_propio,
               d.precio_competencia, d.observaciones
        " . BENCHMARK_FROM_BASE . "
        WHERE $where
        ORDER BY f.orden, c.nombre, c.sucursal, p.nombre_producto, l.fecha_levantamiento DESC, d.id DESC
    ");
    $stmt->execute($params);
    $filas = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));

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
        $partes[] = 'Fechas: ' . ($filtros['desde'] ? date('d/m/Y', strtotime($filtros['desde'])) : 'inicio')
                  . ' al ' . ($filtros['hasta'] ? date('d/m/Y', strtotime($filtros['hasta'])) : 'hoy');
    }
    if ($filtros['estatus'] !== null) {
        $partes[] = 'Estatus: ' . ['barato' => 'Banasupro más barato', 'caro' => 'Banasupro más caro', 'igual' => 'Mismo precio'][$filtros['estatus']];
    }
    $textoFiltros = $partes ? implode(' · ', $partes) : 'Todos los competidores y familias';
} catch (PDOException $e) {
    error_log('benchmark_exportar: ' . $e->getMessage());
    bx_fin(500, 'No se pudo consultar la información para exportar.');
}

foreach ($filas as &$f) {
    $propio = (float)$f['precio_propio'];
    $comp   = (float)$f['precio_competencia'];
    $f['dif'] = $comp - $propio;
    $f['pct'] = $propio > 0 ? ($f['dif'] / $propio) * 100 : 0;
    $f['est'] = $f['dif'] > 0 ? 'barato' : ($f['dif'] < 0 ? 'caro' : 'igual');
}
unset($f);
$kpi = benchmark_kpis_desde_resumen($resumen);

$slug = iconv('UTF-8', 'ASCII//TRANSLIT', $nombreCompetidor);
$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $slug !== false ? $slug : 'Todos'), '_');
$ctx = [
    'competidor' => $nombreCompetidor,
    'filtros'    => $textoFiltros,
    'usuario'    => (string)($_SESSION['user_nombre'] ?? 'Usuario'),
    'generado'   => date('d/m/Y H:i'),
    'archivo'    => 'Benchmark_Precios_' . ($slug !== '' ? $slug : 'Todos') . '_' . date('Ymd_Hi'),
];

// ═════════════════════════════════════════════════════════════════════════════
//  EXCEL  (una hoja: resumen arriba y detalle de todos los artículos abajo, igual que el PDF)
// ═════════════════════════════════════════════════════════════════════════════

/** Franja de marca: logo, título, filtros y bandas azul/dorada (filas 1 a 6). */
function bx_excel_cabecera(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, array $ctx, string $ultimaCol, string $colTitulo, string $titulo): void
{
    $ws->setShowGridlines(false);
    foreach ([1 => 26, 2 => 20, 3 => 18, 4 => 6, 5 => 3, 6 => 10] as $r => $h) {
        $ws->getRowDimension($r)->setRowHeight($h);
    }
    if (is_file(BX_LOGO)) {
        $logo = new Drawing();
        $logo->setName('Banasupro')->setPath(BX_LOGO)->setHeight(54)->setCoordinates('A1')->setOffsetX(6)->setOffsetY(4);
        $logo->setWorksheet($ws);
    }
    $ws->setCellValue("{$colTitulo}1", $titulo);
    $ws->getStyle("{$colTitulo}1")->getFont()->setBold(true)->setSize(20)->getColor()->setRGB(BX_AZUL);
    $ws->setCellValueExplicit("{$colTitulo}2", 'Precios por unidad · ' . $ctx['filtros'], DataType::TYPE_STRING);
    $ws->getStyle("{$colTitulo}2")->getFont()->setSize(11)->getColor()->setRGB(BX_GRIS);
    $ws->setCellValueExplicit("{$colTitulo}3", 'Generado el ' . $ctx['generado'] . ' por ' . $ctx['usuario'], DataType::TYPE_STRING);
    $ws->getStyle("{$colTitulo}3")->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB(BX_GRIS);
    $ws->getStyle("{$colTitulo}1:{$colTitulo}3")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getStyle("A4:{$ultimaCol}4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_AZUL);
    $ws->getStyle("A5:{$ultimaCol}5")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_DORADO);
}

/** Encabezado azul de una tabla en la fila $fila. */
function bx_excel_encabezado_tabla(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, array $titulos, int $fila, string $ultimaCol): void
{
    foreach ($titulos as $i => $t) {
        $ws->setCellValueExplicit([$i + 1, $fila], $t, DataType::TYPE_STRING);
    }
    $ws->getRowDimension($fila)->setRowHeight(28);
    $st = $ws->getStyle("A{$fila}:{$ultimaCol}{$fila}");
    $st->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('FFFFFF');
    $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_AZUL);
    $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $st->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(BX_DORADO);
}

function bx_excel_impresion(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $filaEncabezado, string $areaHasta, string $ultimaCol): void
{
    $ws->freezePane('A' . ($filaEncabezado + 1));
    $ps = $ws->getPageSetup();
    $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_LETTER);
    $ps->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    $ps->setRowsToRepeatAtTopByStartAndEnd($filaEncabezado, $filaEncabezado);
    $ps->setPrintArea("A1:{$ultimaCol}{$areaHasta}");
    $ps->setHorizontalCentered(true);
    $ws->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
    $ws->getHeaderFooter()->setOddFooter('&L&8Banasupro · Benchmark de Precios&R&8Página &P de &N');
    $ws->setSelectedCell('A1');
}

function bx_excel(array $filas, array $resumen, array $kpi, array $ctx): void
{
    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('Banasupro')->setTitle('Benchmark de Precios')
        ->setSubject('Comparativa de precios contra la competencia');

    // Una sola hoja, igual que el PDF: resumen arriba y detalle de todos los artículos abajo.
    $ws = $ss->getActiveSheet();
    $ws->setTitle('Benchmark');
    foreach (['A' => 6, 'B' => 22, 'C' => 12, 'D' => 16, 'E' => 40, 'F' => 15, 'G' => 15, 'H' => 12, 'I' => 10, 'J' => 16, 'K' => 18, 'L' => 22, 'M' => 34] as $col => $w) {
        $ws->getColumnDimension($col)->setWidth($w);
    }
    $ult = 'M';
    bx_excel_cabecera($ws, $ctx, $ult, 'D', 'BENCHMARK DE PRECIOS');

    // ── Indicadores (filas 7 y 8) ──
    $ws->getRowDimension(7)->setRowHeight(18);
    $ws->getRowDimension(8)->setRowHeight(30);
    $ws->getRowDimension(9)->setRowHeight(10);
    $bloques = [
        ['A', 'D', 'PRODUCTOS COMPARADOS', number_format($kpi['total']), BX_AZUL],
        ['E', 'G', 'BANASUPRO MÁS BARATO', number_format($kpi['barato']) . '  (' . $kpi['pct_barato'] . '%)', BX_VERDE],
        ['H', 'K', 'BANASUPRO MÁS CARO', number_format($kpi['caro']) . '  (' . $kpi['pct_caro'] . '%)', BX_ROJO],
        ['L', 'M', 'BRECHA PROMEDIO', $kpi['brecha'] === null ? '—' : bx_signo($kpi['brecha'], 2, '%'), BX_AZUL],
    ];
    foreach ($bloques as [$c1, $c2, $etiqueta, $valor, $color]) {
        $ws->mergeCells("{$c1}7:{$c2}7");
        $ws->mergeCells("{$c1}8:{$c2}8");
        $ws->setCellValueExplicit("{$c1}7", $etiqueta, DataType::TYPE_STRING);
        $ws->setCellValueExplicit("{$c1}8", $valor, DataType::TYPE_STRING);
        $st = $ws->getStyle("{$c1}7:{$c2}8");
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_FONDO);
        $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $st->getBorders()->getRight()->setBorderStyle(Border::BORDER_THICK)->getColor()->setRGB('FFFFFF');
        $ws->getStyle("{$c1}7:{$c2}7")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THICK)->getColor()->setRGB($color);
        $ws->getStyle("{$c1}7")->getFont()->setSize(9)->setBold(true)->getColor()->setRGB(BX_GRIS);
        $ws->getStyle("{$c1}8")->getFont()->setSize(16)->setBold(true)->getColor()->setRGB($color);
    }

    // Título de sección
    $seccion = function (int $fila, string $texto) use ($ws, $ult): void {
        $ws->mergeCells("A{$fila}:{$ult}{$fila}");
        $ws->setCellValueExplicit("A{$fila}", $texto, DataType::TYPE_STRING);
        $ws->getRowDimension($fila)->setRowHeight(24);
        $st = $ws->getStyle("A{$fila}:{$ult}{$fila}");
        $st->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(BX_AZUL);
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEFB');
        $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        $st->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THICK)->getColor()->setRGB(BX_DORADO);
    };

    // ═══════════ RESUMEN: familia x competidor ═══════════
    $seccion(10, 'RESUMEN POR FAMILIA Y COMPETIDOR');
    $hdr = 11;
    $cols = [
        ['A', 'B', 'FAMILIA'], ['C', 'D', 'COMPETIDOR'], ['E', 'E', 'RESULTADO'], ['F', 'F', 'PRODUCTOS'],
        ['G', 'G', 'MÁS BARATO'], ['H', 'H', 'MÁS CARO'], ['I', 'I', 'IGUAL'],
        ['J', 'J', '% MÁS BARATO'], ['K', 'K', '% MÁS CARO'], ['L', 'L', 'BRECHA PROM.'],
    ];
    foreach ($cols as [$c1, $c2, $t]) {
        if ($c1 !== $c2) {
            $ws->mergeCells("{$c1}{$hdr}:{$c2}{$hdr}");
        }
        $ws->setCellValueExplicit("{$c1}{$hdr}", $t, DataType::TYPE_STRING);
    }
    $ws->getRowDimension($hdr)->setRowHeight(28);
    $st = $ws->getStyle("A{$hdr}:L{$hdr}");
    $st->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('FFFFFF');
    $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_AZUL);
    $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $st->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(BX_DORADO);

    $r = $hdr;
    if (!$resumen) {
        $r++;
        $ws->mergeCells("A{$r}:L{$r}");
        $ws->setCellValue("A{$r}", 'No hay datos para exportar con los filtros seleccionados.');
        $ws->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("A{$r}")->getFont()->setItalic(true)->getColor()->setRGB(BX_GRIS);
        $ws->getRowDimension($r)->setRowHeight(30);
    }
    $primeraResumen = $hdr + 1;
    $famPrev = null;
    foreach ($resumen as $g) {
        $r++;
        $prod = max(1, (int)$g['productos']);
        $brecha = $g['brecha'] === null ? null : (float)$g['brecha'];
        $nueva = $famPrev !== $g['id_familia'];
        $famPrev = $g['id_familia'];
        $ws->mergeCells("A{$r}:B{$r}");
        $ws->mergeCells("C{$r}:D{$r}");
        $ws->setCellValueExplicit("A{$r}", (string)$g['familia'], DataType::TYPE_STRING);
        $ws->setCellValueExplicit("C{$r}", (string)$g['competidor'], DataType::TYPE_STRING);
        $ws->setCellValueExplicit("E{$r}",
            (int)$g['barato'] . ' más barato · ' . (int)$g['caro'] . ' más caro' . ((int)$g['igual'] > 0 ? ' · ' . (int)$g['igual'] . ' igual' : ''),
            DataType::TYPE_STRING);
        $ws->setCellValue("F{$r}", (int)$g['productos']);
        $ws->setCellValue("G{$r}", (int)$g['barato']);
        $ws->setCellValue("H{$r}", (int)$g['caro']);
        $ws->setCellValue("I{$r}", (int)$g['igual']);
        $ws->setCellValue("J{$r}", (int)$g['barato'] / $prod);
        $ws->setCellValue("K{$r}", (int)$g['caro'] / $prod);
        if ($brecha !== null) {
            $ws->setCellValue("L{$r}", round($brecha, 2));
        }
        $ws->getRowDimension($r)->setRowHeight(21);
        $fila = $ws->getStyle("A{$r}:L{$r}");
        $fila->getFont()->setSize(10);
        $fila->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $fila->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(BX_LINEA);
        if ($nueva) {
            $fila->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(BX_AZUL);
        }
        $ws->getStyle("A{$r}")->getFont()->setBold($nueva)->getColor()->setRGB($nueva ? BX_AZUL : 'A0A8B5');
        $ws->getStyle("A{$r}")->getAlignment()->setIndent(1);
        $ws->getStyle("C{$r}")->getFont()->setBold(true);
        $ws->getStyle("E{$r}")->getFont()->getColor()->setRGB(BX_GRIS);
        $ws->getStyle("F{$r}:K{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("L{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("G{$r}")->getFont()->setBold(true)->getColor()->setRGB(BX_VERDE);
        $ws->getStyle("J{$r}")->getFont()->getColor()->setRGB(BX_VERDE);
        $ws->getStyle("H{$r}")->getFont()->setBold(true)->getColor()->setRGB(BX_ROJO);
        $ws->getStyle("K{$r}")->getFont()->getColor()->setRGB(BX_ROJO);
        $ws->getStyle("L{$r}")->getFont()->setBold(true)->getColor()->setRGB(($brecha ?? 0) >= 0 ? BX_VERDE : BX_ROJO);
        if ((int)$g['barato'] > (int)$g['caro']) {
            $ws->getStyle("G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3F4E6');
        } elseif ((int)$g['caro'] > (int)$g['barato']) {
            $ws->getStyle("H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FBE4E1');
        }
    }
    $ultimaResumen = max($r, $hdr + 1);
    if ($resumen) {
        $d = $primeraResumen;
        $ws->getStyle("J{$d}:K{$ultimaResumen}")->getNumberFormat()->setFormatCode('0%');
        $ws->getStyle("L{$d}:L{$ultimaResumen}")->getNumberFormat()->setFormatCode('+0.00"%";-0.00"%";0.00"%"');
        // Fila de totales
        $t = $ultimaResumen + 1;
        $ws->mergeCells("A{$t}:E{$t}");
        $ws->setCellValue("A{$t}", 'TOTAL');
        $ws->setCellValue("F{$t}", "=SUM(F{$d}:F{$ultimaResumen})");
        $ws->setCellValue("G{$t}", "=SUM(G{$d}:G{$ultimaResumen})");
        $ws->setCellValue("H{$t}", "=SUM(H{$d}:H{$ultimaResumen})");
        $ws->setCellValue("I{$t}", "=SUM(I{$d}:I{$ultimaResumen})");
        $ws->setCellValue("J{$t}", "=IF(F{$t}=0,0,G{$t}/F{$t})");
        $ws->setCellValue("K{$t}", "=IF(F{$t}=0,0,H{$t}/F{$t})");
        if ($kpi['brecha'] !== null) {
            $ws->setCellValue("L{$t}", round($kpi['brecha'], 2));
        }
        $ws->getRowDimension($t)->setRowHeight(24);
        $st = $ws->getStyle("A{$t}:L{$t}");
        $st->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('FFFFFF');
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_AZUL);
        $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getStyle("A{$t}")->getAlignment()->setIndent(1);
        $ws->getStyle("F{$t}:L{$t}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("J{$t}:K{$t}")->getNumberFormat()->setFormatCode('0%');
        $ws->getStyle("L{$t}")->getNumberFormat()->setFormatCode('+0.00"%";-0.00"%";0.00"%"');
        $ultimaResumen = $t;
    }
    $nota = $ultimaResumen + 1;
    $ws->mergeCells("A{$nota}:{$ult}{$nota}");
    $ws->setCellValue("A{$nota}", 'En el resumen cada producto cuenta una vez por competidor, con su último precio levantado en el periodo. Más barato = el precio de Banasupro es menor. Brecha positiva = la competencia es más cara.');
    $ws->getStyle("A{$nota}")->getFont()->setSize(8)->setItalic(true)->getColor()->setRGB(BX_GRIS);

    // ═══════════ DETALLE: todos los artículos ═══════════
    $sec2 = $nota + 2;
    $seccion($sec2, 'DETALLE DE LEVANTAMIENTOS');
    $hdrD = $sec2 + 1;
    bx_excel_encabezado_tabla($ws, ['N.', 'COMPETIDOR', 'FECHA', 'FAMILIA', 'PRODUCTO Y PRESENTACIÓN', 'PRECIO BANASUPRO (C/U)', 'PRECIO COMPETENCIA (C/U)', 'DIF. (L.)', 'DIF. %', 'ESTATUS', 'CATEGORÍA', 'SUBCATEGORÍA', 'NOTAS'], $hdrD, $ult);
    $r = $hdrD;
    if (!$filas) {
        $r++;
        $ws->mergeCells("A{$r}:{$ult}{$r}");
        $ws->setCellValue("A{$r}", 'No hay datos para exportar con los filtros seleccionados.');
        $ws->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("A{$r}")->getFont()->setItalic(true)->getColor()->setRGB(BX_GRIS);
        $ws->getRowDimension($r)->setRowHeight(30);
    }
    $n = 0;
    foreach ($filas as $f) {
        $r++;
        $n++;
        $e = BX_ESTATUS[$f['est']];
        $ws->setCellValue("A{$r}", $n);
        $ws->setCellValueExplicit("B{$r}", (string)$f['competidor'], DataType::TYPE_STRING);
        $ts = strtotime((string)$f['fecha_levantamiento']);
        if ($ts) {
            $ws->setCellValue("C{$r}", XlsDate::PHPToExcel($ts));
        }
        $ws->setCellValueExplicit("D{$r}", (string)$f['familia'], DataType::TYPE_STRING);
        $ws->setCellValueExplicit("E{$r}", benchmark_etiqueta($f['producto']), DataType::TYPE_STRING);
        $ws->setCellValue("F{$r}", (float)$f['precio_propio']);
        $ws->setCellValue("G{$r}", (float)$f['precio_competencia']);
        $ws->setCellValue("H{$r}", round($f['dif'], 2));
        $ws->setCellValue("I{$r}", round($f['pct'], 2));
        $ws->setCellValueExplicit("J{$r}", $e['txt'], DataType::TYPE_STRING);
        $ws->setCellValueExplicit("K{$r}", benchmark_etiqueta($f['categoria']), DataType::TYPE_STRING);
        $ws->setCellValueExplicit("L{$r}", benchmark_etiqueta($f['subcategoria']), DataType::TYPE_STRING);
        $obs = trim((string)($f['observaciones'] ?? ''));
        $ws->setCellValueExplicit("M{$r}", ($obs === '' ? '-' : $obs), DataType::TYPE_STRING);

        $ws->getRowDimension($r)->setRowHeight(20);
        $fila = $ws->getStyle("A{$r}:{$ult}{$r}");
        $fila->getFont()->setSize(10);
        $fila->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $fila->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(BX_LINEA);
        if ($n % 2 === 0) {
            $fila->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_FONDO);
        }
        foreach (['A', 'C', 'J'] as $c) {
            $ws->getStyle("{$c}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $ws->getStyle("B{$r}")->getFont()->setBold(true);
        $ws->getStyle("D{$r}")->getFont()->setBold(true)->getColor()->setRGB(BX_AZUL);
        $ws->getStyle("F{$r}:I{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("E{$r}")->getAlignment()->setWrapText(true);
        $ws->getStyle("M{$r}")->getAlignment()->setWrapText(true)->setIndent(1);
        $ws->getStyle("K{$r}:L{$r}")->getFont()->getColor()->setRGB(BX_GRIS);
        $ws->getStyle("F{$r}")->getFont()->setBold(true)->getColor()->setRGB(BX_AZUL);
        $ws->getStyle("G{$r}")->getFont()->setBold(true);
        $ws->getStyle("H{$r}:J{$r}")->getFont()->setBold(true)->getColor()->setRGB($e['color']);
        $ws->getStyle("J{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($e['fondo']);
    }
    $ultimaD = max($r, $hdrD + 1);
    if ($filas) {
        $d = $hdrD + 1;
        $ws->getStyle("C{$d}:C{$ultimaD}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle("F{$d}:G{$ultimaD}")->getNumberFormat()->setFormatCode('"L. "#,##0.00');
        $ws->getStyle("H{$d}:H{$ultimaD}")->getNumberFormat()->setFormatCode('+#,##0.00;-#,##0.00;0.00');
        $ws->getStyle("I{$d}:I{$ultimaD}")->getNumberFormat()->setFormatCode('+0.00"%";-0.00"%";0.00"%"');
        $ws->setAutoFilter("A{$hdrD}:{$ult}{$ultimaD}");
    }
    $pie = $ultimaD + 2;
    $ws->mergeCells("A{$pie}:{$ult}{$pie}");
    $ws->setCellValue("A{$pie}", 'En el detalle cada fila es una visita. Dif. = precio de la competencia menos precio de Banasupro (positivo = Banasupro más barato).');
    $ws->getStyle("A{$pie}")->getFont()->setSize(8)->setItalic(true)->getColor()->setRGB(BX_GRIS);
    $ws->getStyle("A{$pie}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Vista e impresión: se fija solo la franja de marca; el detalle se imprime con su encabezado repetido
    $ws->freezePane('A7');
    $ps = $ws->getPageSetup();
    $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_LETTER);
    $ps->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    $ps->setPrintArea("A1:{$ult}{$pie}");
    $ps->setHorizontalCentered(true);
    $ws->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
    $ws->getHeaderFooter()->setOddFooter('&L&8Banasupro · Benchmark de Precios&R&8Página &P de &N');
    $ws->setSelectedCell('A1');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $ctx['archivo'] . '.xlsx"');
    header('Cache-Control: no-store');
    (new Xlsx($ss))->save('php://output');
    exit;
}

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
    if ($filas) {
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
    bx_excel($filas, $resumen, $kpi, $ctx);
} else {
    bx_pdf($filas, $resumen, $kpi, $ctx);
}
