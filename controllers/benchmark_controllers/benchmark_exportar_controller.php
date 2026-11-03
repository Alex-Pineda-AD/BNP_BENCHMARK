<?php
/**
 * Exportación de la Tabla Comparativa de Benchmark a Excel (.xlsx) y PDF.
 * Solo lectura (no escribe en la base de datos).
 *
 * Uso: benchmark_exportar_controller.php?formato=xlsx|pdf[&id_competidor=N]
 * Acceso: roles de Benchmark (5 y 6), igual que la página de la tabla comparativa.
 */
session_start();
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);
ini_set('memory_limit', '256M');
set_time_limit(120);

require_once __DIR__ . '/../../connection/connection.php';
require_once __DIR__ . '/../../includes/benchmark_seguridad.php';
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

$fCompetidor = filter_var($_GET['id_competidor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fCompetidor = ($fCompetidor === false) ? null : $fCompetidor;

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

// ── Datos (misma consulta y cálculos que la tabla comparativa) ───────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            c.nombre AS competidor,
            l.fecha_levantamiento,
            p.nombre_producto AS producto,
            COALESCE(NULLIF(d.precio_banasupro_referencia, 0), p.precio_referencia) AS precio_propio,
            d.precio_competencia,
            d.observaciones
        FROM benchmark.benchmark_precios_detalle d
        INNER JOIN benchmark.benchmark_levantamientos l ON d.id_levantamiento = l.id
        INNER JOIN benchmark.benchmark_competidores c ON l.id_competidor = c.id
        INNER JOIN benchmark.benchmark_productos p ON d.id_producto = p.id
        " . ($fCompetidor !== null ? 'WHERE l.id_competidor = ?' : '') . "
        ORDER BY l.fecha_levantamiento DESC, d.id DESC
    ");
    $stmt->execute($fCompetidor !== null ? [$fCompetidor] : []);
    $filas = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));

    $nombreCompetidor = 'Todos los competidores';
    if ($fCompetidor !== null) {
        $q = $pdo->prepare('SELECT nombre FROM benchmark.benchmark_competidores WHERE id = ?');
        $q->execute([$fCompetidor]);
        $n = $q->fetchColumn();
        if ($n !== false) {
            $nombreCompetidor = (string)$n;
        }
    }
} catch (PDOException $e) {
    error_log('benchmark_exportar: ' . $e->getMessage());
    bx_fin(500, 'No se pudo consultar la información para exportar.');
}

$kpi = ['total' => 0, 'barato' => 0, 'caro' => 0, 'igual' => 0, 'ahorro' => 0.0];
foreach ($filas as &$f) {
    $propio = (float)$f['precio_propio'];
    $comp   = (float)$f['precio_competencia'];
    $f['dif'] = $comp - $propio;
    $f['pct'] = $propio > 0 ? ($f['dif'] / $propio) * 100 : 0;
    $f['est'] = $f['dif'] > 0 ? 'barato' : ($f['dif'] < 0 ? 'caro' : 'igual');
    $kpi['total']++;
    $kpi[$f['est']]++;
    if ($f['dif'] > 0) {
        $kpi['ahorro'] += $f['dif'];
    }
}
unset($f);
$kpi['pct_barato'] = $kpi['total'] > 0 ? round($kpi['barato'] / $kpi['total'] * 100, 1) : 0;
$kpi['pct_caro']   = $kpi['total'] > 0 ? round($kpi['caro'] / $kpi['total'] * 100, 1) : 0;

$slug = iconv('UTF-8', 'ASCII//TRANSLIT', $nombreCompetidor);
$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $slug !== false ? $slug : 'Todos'), '_');
$ctx = [
    'competidor' => $nombreCompetidor,
    'usuario'    => (string)($_SESSION['user_nombre'] ?? 'Usuario'),
    'generado'   => date('d/m/Y H:i'),
    'archivo'    => 'Benchmark_Precios_' . ($slug !== '' ? $slug : 'Todos') . '_' . date('Ymd_Hi'),
];

// ═════════════════════════════════════════════════════════════════════════════
//  EXCEL
// ═════════════════════════════════════════════════════════════════════════════
function bx_excel(array $filas, array $kpi, array $ctx): void
{
    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('Banasupro')->setTitle('Benchmark de Precios')
        ->setSubject('Comparativa de precios contra la competencia');
    $ws = $ss->getActiveSheet();
    $ws->setTitle('Comparativa');
    $ws->setShowGridlines(false);

    $anchos = ['A' => 6, 'B' => 22, 'C' => 12, 'D' => 40, 'E' => 15, 'F' => 15, 'G' => 14, 'H' => 11, 'I' => 16, 'J' => 38];
    foreach ($anchos as $col => $w) {
        $ws->getColumnDimension($col)->setWidth($w);
    }
    $ultimaCol = 'J';

    // Encabezado: logo + títulos
    foreach ([1 => 26, 2 => 20, 3 => 18, 4 => 6, 5 => 3, 6 => 10] as $r => $h) {
        $ws->getRowDimension($r)->setRowHeight($h);
    }
    if (is_file(BX_LOGO)) {
        $logo = new Drawing();
        $logo->setName('Banasupro')->setPath(BX_LOGO)->setHeight(54)->setCoordinates('A1')->setOffsetX(6)->setOffsetY(4);
        $logo->setWorksheet($ws);
    }
    $ws->setCellValue('D1', 'BENCHMARK DE PRECIOS');
    $ws->getStyle('D1')->getFont()->setBold(true)->setSize(20)->getColor()->setRGB(BX_AZUL);
    $ws->setCellValueExplicit('D2', 'Comparativa de precios contra la competencia · ' . $ctx['competidor'], DataType::TYPE_STRING);
    $ws->getStyle('D2')->getFont()->setSize(11)->getColor()->setRGB(BX_GRIS);
    $ws->setCellValueExplicit('D3', 'Generado el ' . $ctx['generado'] . ' por ' . $ctx['usuario'], DataType::TYPE_STRING);
    $ws->getStyle('D3')->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB(BX_GRIS);
    $ws->getStyle('D1:D3')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getStyle("A4:{$ultimaCol}4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_AZUL);
    $ws->getStyle("A5:{$ultimaCol}5")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_DORADO);

    // KPIs (filas 7 y 8)
    $ws->getRowDimension(7)->setRowHeight(18);
    $ws->getRowDimension(8)->setRowHeight(30);
    $ws->getRowDimension(9)->setRowHeight(10);
    $bloques = [
        ['A', 'C', 'TOTAL DE MUESTRAS', number_format($kpi['total']), BX_AZUL],
        ['D', 'D', 'BANASUPRO MÁS BARATO', number_format($kpi['barato']) . '  (' . $kpi['pct_barato'] . '%)', BX_VERDE],
        ['E', 'G', 'BANASUPRO MÁS CARO', number_format($kpi['caro']) . '  (' . $kpi['pct_caro'] . '%)', BX_ROJO],
        ['H', 'J', 'AHORRO TOTAL VS. COMPETENCIA', 'L. ' . number_format($kpi['ahorro'], 2), BX_AZUL],
    ];
    foreach ($bloques as [$c1, $c2, $etiqueta, $valor, $color]) {
        if ($c1 !== $c2) {
            $ws->mergeCells("{$c1}7:{$c2}7");
            $ws->mergeCells("{$c1}8:{$c2}8");
        }
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

    // Encabezado de la tabla
    $hdr = 10;
    $titulos = ['N.', 'COMPETIDOR', 'FECHA', 'ARTÍCULO BANASUPRO', 'PRECIO BANA.', 'PRECIO COMP.', 'DIF. (L.)', 'DIF. %', 'ESTATUS', 'NOTAS'];
    foreach ($titulos as $i => $t) {
        $ws->setCellValueExplicit([$i + 1, $hdr], $t, DataType::TYPE_STRING);
    }
    $ws->getRowDimension($hdr)->setRowHeight(26);
    $st = $ws->getStyle("A{$hdr}:{$ultimaCol}{$hdr}");
    $st->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('FFFFFF');
    $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_AZUL);
    $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $st->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(BX_DORADO);

    // Filas
    $r = $hdr;
    if (!$filas) {
        $r++;
        $ws->mergeCells("A{$r}:{$ultimaCol}{$r}");
        $ws->setCellValue("A{$r}", 'No hay datos para exportar con el filtro seleccionado.');
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
        $ws->setCellValueExplicit("D{$r}", (string)$f['producto'], DataType::TYPE_STRING);
        $ws->setCellValue("E{$r}", (float)$f['precio_propio']);
        $ws->setCellValue("F{$r}", (float)$f['precio_competencia']);
        $ws->setCellValue("G{$r}", round($f['dif'], 2));
        $ws->setCellValue("H{$r}", round($f['pct'], 2));
        $ws->setCellValueExplicit("I{$r}", $e['txt'], DataType::TYPE_STRING);
        $obs = trim((string)($f['observaciones'] ?? ''));
        $ws->setCellValueExplicit("J{$r}", ($obs === '' ? '-' : $obs), DataType::TYPE_STRING);

        $ws->getRowDimension($r)->setRowHeight(20);
        $fila = $ws->getStyle("A{$r}:{$ultimaCol}{$r}");
        $fila->getFont()->setSize(10);
        $fila->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $fila->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(BX_LINEA);
        if ($n % 2 === 0) {
            $fila->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(BX_FONDO);
        }
        foreach (['A', 'C', 'I'] as $c) {
            $ws->getStyle("{$c}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $ws->getStyle("B{$r}")->getFont()->setBold(true);
        $ws->getStyle("E{$r}:H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("D{$r}")->getAlignment()->setWrapText(true);
        $ws->getStyle("J{$r}")->getAlignment()->setWrapText(true)->setIndent(1);
        $ws->getStyle("E{$r}")->getFont()->setBold(true)->getColor()->setRGB(BX_AZUL);
        $ws->getStyle("F{$r}")->getFont()->setBold(true);
        $ws->getStyle("G{$r}:I{$r}")->getFont()->setBold(true)->getColor()->setRGB($e['color']);
        $ws->getStyle("I{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($e['fondo']);
    }
    $ultima = max($r, $hdr + 1);
    if ($filas) {
        $d = $hdr + 1;
        $ws->getStyle("C{$d}:C{$ultima}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle("E{$d}:F{$ultima}")->getNumberFormat()->setFormatCode('"L. "#,##0.00');
        $ws->getStyle("G{$d}:G{$ultima}")->getNumberFormat()->setFormatCode('+#,##0.00;-#,##0.00;0.00');
        $ws->getStyle("H{$d}:H{$ultima}")->getNumberFormat()->setFormatCode('+0.00"%";-0.00"%";0.00"%"');
        $ws->setAutoFilter("A{$hdr}:{$ultimaCol}{$ultima}");
    }

    // Pie
    $pie = $ultima + 2;
    $ws->mergeCells("A{$pie}:{$ultimaCol}{$pie}");
    $ws->setCellValue("A{$pie}", 'Banasupro · Sistema Benchmark de Precios · Más barato = el precio de Banasupro es menor que el de la competencia');
    $ws->getStyle("A{$pie}")->getFont()->setSize(8)->setItalic(true)->getColor()->setRGB(BX_GRIS);
    $ws->getStyle("A{$pie}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Vista e impresión
    $ws->freezePane('A' . ($hdr + 1));
    $ps = $ws->getPageSetup();
    $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_LETTER);
    $ps->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    $ps->setRowsToRepeatAtTopByStartAndEnd($hdr, $hdr);
    $ps->setPrintArea("A1:{$ultimaCol}{$pie}");
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
//  PDF (Dompdf)
// ═════════════════════════════════════════════════════════════════════════════
function bx_pdf(array $filas, array $kpi, array $ctx): void
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
    if (!$filas) {
        $filasHtml = '<tr><td colspan="9" style="text-align:center;padding:18px;color:' . $gris
            . '"><i>No hay datos para exportar con el filtro seleccionado.</i></td></tr>';
    }
    $n = 0;
    foreach ($filas as $f) {
        $n++;
        $e = BX_ESTATUS[$f['est']];
        $obs = trim((string)($f['observaciones'] ?? ''));
        $ts = strtotime((string)$f['fecha_levantamiento']);
        $filasHtml .= '<tr' . ($n % 2 === 0 ? ' class="z"' : '') . '>'
            . '<td class="c">' . $n . '</td>'
            . '<td><b>' . bx_h($f['competidor']) . '</b></td>'
            . '<td class="c">' . ($ts ? date('d/m/Y', $ts) : '-') . '</td>'
            . '<td>' . bx_h($f['producto']) . '</td>'
            . '<td class="r" style="color:' . $azul . ';font-weight:bold">L. ' . number_format((float)$f['precio_propio'], 2) . '</td>'
            . '<td class="r"><b>L. ' . number_format((float)$f['precio_competencia'], 2) . '</b></td>'
            . '<td class="r" style="color:#' . $e['color'] . ';font-weight:bold">' . bx_signo($f['dif'])
            . '<br><span style="font-size:7px">' . bx_signo($f['pct'], 2, '%') . '</span></td>'
            . '<td class="c"><span class="pill" style="background:#' . $e['fondo'] . ';color:#' . $e['color'] . '">' . $e['txt'] . '</span></td>'
            . '<td>' . bx_h($obs === '' ? '-' : $obs) . '</td>'
            . '</tr>';
    }

    $logoHtml = $logo !== ''
        ? '<img src="' . $logo . '" style="height:46px">'
        : '<b style="font-size:16px;color:' . $azul . '">BANASUPRO</b>';

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
        @page { margin: 26px 28px 44px 28px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #1f2937; }
        .band1 { height: 5px; background: ' . $azul . '; } .band2 { height: 3px; background: ' . $dorado . '; }
        .titulo { font-size: 19px; font-weight: bold; color: ' . $azul . '; letter-spacing: .5px; }
        .sub { font-size: 9.5px; color: ' . $gris . '; margin-top: 3px; }
        .meta { font-size: 8px; color: ' . $gris . '; margin-top: 2px; font-style: italic; }
        table.kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 12px -6px 12px -6px; }
        td.kpi { background: ' . $fondo . '; text-align: center; padding: 8px 4px; width: 25%; }
        .kpi-l { font-size: 7px; font-weight: bold; color: ' . $gris . '; letter-spacing: .4px; }
        .kpi-v { font-size: 15px; font-weight: bold; margin-top: 3px; }
        table.dt { width: 100%; border-collapse: collapse; }
        table.dt thead { display: table-header-group; }
        table.dt th { background: ' . $azul . '; color: #fff; font-size: 8px; padding: 7px 5px; border-bottom: 2px solid ' . $dorado . '; }
        table.dt td { padding: 5px; border-bottom: 1px solid ' . $linea . '; vertical-align: middle; }
        table.dt tr { page-break-inside: avoid; }
        tr.z td { background: ' . $fondo . '; }
        .c { text-align: center; } .r { text-align: right; white-space: nowrap; }
        .pill { padding: 2px 7px; border-radius: 8px; font-size: 7.5px; font-weight: bold; white-space: nowrap; }
        .nota { margin-top: 10px; font-size: 7.5px; color: ' . $gris . '; font-style: italic; }
    </style></head><body>
    <table width="100%" style="border-collapse:collapse"><tr>
        <td style="width:34%;vertical-align:middle;padding-bottom:6px">' . $logoHtml . '</td>
        <td style="text-align:right;vertical-align:middle;padding-bottom:6px">
            <div class="titulo">BENCHMARK DE PRECIOS</div>
            <div class="sub">Comparativa de precios contra la competencia · <b>' . bx_h($ctx['competidor']) . '</b></div>
            <div class="meta">Generado el ' . bx_h($ctx['generado']) . ' por ' . bx_h($ctx['usuario']) . '</div>
        </td></tr></table>
    <div class="band1"></div><div class="band2"></div>
    <table class="kpis"><tr>'
        . $tarjeta('TOTAL DE MUESTRAS', number_format($kpi['total']), $azul)
        . $tarjeta('BANASUPRO MÁS BARATO', number_format($kpi['barato']) . ' <span style="font-size:9px;font-weight:normal">(' . $kpi['pct_barato'] . '%)</span>', $verde)
        . $tarjeta('BANASUPRO MÁS CARO', number_format($kpi['caro']) . ' <span style="font-size:9px;font-weight:normal">(' . $kpi['pct_caro'] . '%)</span>', $rojo)
        . $tarjeta('AHORRO TOTAL VS. COMPETENCIA', 'L. ' . number_format($kpi['ahorro'], 2), $azul)
    . '</tr></table>
    <table class="dt"><thead><tr>
        <th style="width:3%">N.</th><th style="width:12%;text-align:left">COMPETIDOR</th><th style="width:8%">FECHA</th>
        <th style="width:23%;text-align:left">ARTÍCULO BANASUPRO</th><th style="width:9%">PRECIO BANA.</th><th style="width:9%">PRECIO COMP.</th>
        <th style="width:9%">DIFERENCIA</th><th style="width:9%">ESTATUS</th><th style="text-align:left">NOTAS</th>
    </tr></thead><tbody>' . $filasHtml . '</tbody></table>
    <div class="nota">Más barato = el precio de Banasupro es menor que el de la competencia. La diferencia se calcula como precio de la competencia menos precio de Banasupro.</div>
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
    bx_excel($filas, $kpi, $ctx);
} else {
    bx_pdf($filas, $kpi, $ctx);
}
