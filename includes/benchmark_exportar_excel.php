<?php
/**
 * Excel de la Tabla Comparativa de Benchmark armado por flujo (ver benchmark_xlsx_stream.php).
 * Una hoja: indicadores y resumen por familia y competidor arriba, detalle de todos los levantamientos abajo.
 *
 * Requiere (los define benchmark_exportar_controller.php): constantes BX_AZUL, BX_DORADO, BX_VERDE, BX_ROJO,
 * BX_GRIS, BX_FONDO, BX_LINEA, BX_LOGO, BX_ESTATUS y las funciones bx_signo() y benchmark_etiqueta().
 */
require_once __DIR__ . '/benchmark_xlsx_stream.php';

const BX_FMT_LPS   = '"L. "#,##0.00';
const BX_FMT_DIF   = '+#,##0.00;-#,##0.00;0.00';
const BX_FMT_PCT_S = '+0.00"%";-0.00"%";0.00"%"';
const BX_LOTE_FILAS = 5000;

/**
 * @param callable $leerLote  fn(int $desde, int $cantidad): array  → filas de detalle ya enriquecidas (dif, pct, est)
 * @param int      $totalFilas cantidad de filas de detalle que devolverá $leerLote en total
 */
function benchmark_excel_armar(callable $leerLote, int $totalFilas, array $resumen, array $kpi, array $ctx): BenchmarkXlsxStream
{
    $anchos = [6, 22, 12, 16, 40, 15, 15, 12, 10, 16, 18, 22, 34];
    $n = count($anchos);
    $x = new BenchmarkXlsxStream('Benchmark', $anchos, 6);
    $x->logo(BX_LOGO);
    $x->pie('Banasupro · Benchmark de Precios');

    $base = ['sz' => 10, 'v' => 'center'];

    // ── Franja de marca (filas 1 a 6) ──
    $x->fila([3 => ['BENCHMARK DE PRECIOS', $x->estilo(['b' => true, 'sz' => 20, 'color' => BX_AZUL, 'v' => 'center'])]], 26);
    $x->fila([3 => ['Precios por unidad · ' . $ctx['filtros'], $x->estilo(['sz' => 11, 'color' => BX_GRIS, 'v' => 'center'])]], 20);
    $x->fila([3 => ['Generado el ' . $ctx['generado'] . ' por ' . $ctx['usuario'], $x->estilo(['sz' => 9, 'i' => true, 'color' => BX_GRIS, 'v' => 'center'])]], 18);
    $x->fila([], 6, $x->estilo(['bg' => BX_AZUL]));
    $x->fila([], 3, $x->estilo(['bg' => BX_DORADO]));
    $x->filaVacia(10);

    // ── Indicadores (filas 7 y 8) ──
    $bloques = [
        [0, 3, 'PRODUCTOS COMPARADOS', number_format($kpi['total']), BX_AZUL],
        [4, 6, 'BANASUPRO MÁS BARATO', number_format($kpi['barato']) . '  (' . $kpi['pct_barato'] . '%)', BX_VERDE],
        [7, 10, 'BANASUPRO MÁS CARO', number_format($kpi['caro']) . '  (' . $kpi['pct_caro'] . '%)', BX_ROJO],
        [11, 12, 'BRECHA PROMEDIO', $kpi['brecha'] === null ? '—' : bx_signo($kpi['brecha'], 2, '%'), BX_AZUL],
    ];
    $filaEtq = [];
    $filaVal = [];
    foreach ($bloques as [$c1, $c2, $etiqueta, $valor, $color]) {
        $sEtq = $x->estilo(['b' => true, 'sz' => 9, 'color' => BX_GRIS, 'bg' => BX_FONDO, 'h' => 'center', 'v' => 'center',
            'bt' => ['thick', $color], 'br' => ['thick', 'FFFFFF']]);
        $sVal = $x->estilo(['b' => true, 'sz' => 16, 'color' => $color, 'bg' => BX_FONDO, 'h' => 'center', 'v' => 'center',
            'br' => ['thick', 'FFFFFF']]);
        for ($c = $c1; $c <= $c2; $c++) {
            $filaEtq[$c] = [$c === $c1 ? $etiqueta : '', $sEtq];
            $filaVal[$c] = [$c === $c1 ? $valor : '', $sVal];
        }
    }
    $r = $x->fila($filaEtq, 18);
    foreach ($bloques as [$c1, $c2]) {
        $x->combinar(BenchmarkXlsxStream::columna($c1) . $r . ':' . BenchmarkXlsxStream::columna($c2) . $r);
        $x->combinar(BenchmarkXlsxStream::columna($c1) . ($r + 1) . ':' . BenchmarkXlsxStream::columna($c2) . ($r + 1));
    }
    $x->fila($filaVal, 30);
    $x->filaVacia(10);

    $ult = BenchmarkXlsxStream::columna($n - 1);
    $seccion = function (string $texto) use ($x, $ult): void {
        $s = $x->estilo(['b' => true, 'sz' => 12, 'color' => BX_AZUL, 'bg' => 'E8EEFB', 'v' => 'center', 'indent' => 1, 'h' => 'left',
            'bl' => ['thick', BX_DORADO]]);
        $r = $x->fila([0 => [$texto, $s]], 24, $s);
        $x->combinar("A{$r}:{$ult}{$r}");
    };
    $sEnc = $x->estilo(['b' => true, 'sz' => 10, 'color' => 'FFFFFF', 'bg' => BX_AZUL, 'h' => 'center', 'v' => 'center', 'wrap' => true,
        'bb' => ['medium', BX_DORADO]]);

    // ═══════════ RESUMEN: familia x competidor ═══════════
    $seccion('RESUMEN POR FAMILIA Y COMPETIDOR');
    $r = $x->fila([
        0 => ['FAMILIA', $sEnc], 2 => ['COMPETIDOR', $sEnc], 4 => ['RESULTADO', $sEnc], 5 => ['PRODUCTOS', $sEnc],
        6 => ['MÁS BARATO', $sEnc], 7 => ['MÁS CARO', $sEnc], 8 => ['IGUAL', $sEnc],
        9 => ['% MÁS BARATO', $sEnc], 10 => ['% MÁS CARO', $sEnc], 11 => ['BRECHA PROM.', $sEnc],
    ], 28, $sEnc);
    $x->combinar("A{$r}:B{$r}");
    $x->combinar("C{$r}:D{$r}");
    // El encabezado no cubre la columna M en el resumen
    $primeraResumen = $r + 1;

    if (!$resumen) {
        $sVacio = $x->estilo(['i' => true, 'color' => BX_GRIS, 'h' => 'center', 'v' => 'center']);
        $r2 = $x->fila([0 => ['No hay datos para exportar con los filtros seleccionados.', $sVacio]], 30);
        $x->combinar("A{$r2}:L{$r2}");
        $ultimaResumen = $r;
    } else {
        $famPrev = null;
        foreach ($resumen as $g) {
            $prod = max(1, (int)$g['productos']);
            $barato = (int)$g['barato'];
            $caro = (int)$g['caro'];
            $igual = (int)$g['igual'];
            $brecha = $g['brecha'] === null ? null : round((float)$g['brecha'], 2);
            $nueva = $famPrev !== $g['id_familia'];
            $famPrev = $g['id_familia'];

            $fila = $base + ['bb' => ['thin', BX_LINEA]];
            if ($nueva) {
                $fila['bt'] = ['medium', BX_AZUL];
            }
            $centro = $fila + ['h' => 'center'];
            $sFam = $x->estilo($fila + ['b' => $nueva, 'color' => $nueva ? BX_AZUL : 'A0A8B5', 'indent' => 1, 'h' => 'left']);
            $sComp = $x->estilo($fila + ['b' => true]);
            $sRes = $x->estilo($fila + ['color' => BX_GRIS]);
            $sBar = $x->estilo($centro + ['b' => true, 'color' => BX_VERDE] + ($barato > $caro ? ['bg' => 'E3F4E6'] : []));
            $sCar = $x->estilo($centro + ['b' => true, 'color' => BX_ROJO] + ($caro > $barato ? ['bg' => 'FBE4E1'] : []));
            $texto = $barato . ' más barato · ' . $caro . ' más caro' . ($igual > 0 ? ' · ' . $igual . ' igual' : '');
            $r = $x->fila([
                0 => [(string)$g['familia'], $sFam], 1 => ['', $sFam],
                2 => [(string)$g['competidor'], $sComp], 3 => ['', $sComp],
                4 => [$texto, $sRes],
                5 => [(int)$g['productos'], $x->estilo($centro)],
                6 => [$barato, $sBar], 7 => [$caro, $sCar], 8 => [$igual, $x->estilo($centro + ['color' => BX_GRIS])],
                9 => [$barato / $prod, $x->estilo($centro + ['color' => BX_VERDE, 'fmt' => '0%'])],
                10 => [$caro / $prod, $x->estilo($centro + ['color' => BX_ROJO, 'fmt' => '0%'])],
                11 => [$brecha, $x->estilo($centro + ['b' => true, 'color' => ($brecha ?? 0) >= 0 ? BX_VERDE : BX_ROJO, 'fmt' => BX_FMT_PCT_S])],
            ], 21);
            $x->combinar("A{$r}:B{$r}");
            $x->combinar("C{$r}:D{$r}");
        }
        $ultimaResumen = $r;
        $d = $primeraResumen;

        // Fila de totales
        $sTot = $x->estilo(['b' => true, 'sz' => 10, 'color' => 'FFFFFF', 'bg' => BX_AZUL, 'v' => 'center', 'h' => 'center']);
        $sTotL = $x->estilo(['b' => true, 'sz' => 10, 'color' => 'FFFFFF', 'bg' => BX_AZUL, 'v' => 'center', 'h' => 'left', 'indent' => 1]);
        $sTotP = $x->estilo(['b' => true, 'sz' => 10, 'color' => 'FFFFFF', 'bg' => BX_AZUL, 'v' => 'center', 'h' => 'center', 'fmt' => '0%']);
        $sTotB = $x->estilo(['b' => true, 'sz' => 10, 'color' => 'FFFFFF', 'bg' => BX_AZUL, 'v' => 'center', 'h' => 'center', 'fmt' => BX_FMT_PCT_S]);
        $t = $ultimaResumen + 1;
        $celdasTot = [0 => ['TOTAL', $sTotL]];
        foreach ([5 => 'F', 6 => 'G', 7 => 'H', 8 => 'I'] as $c => $L) {
            $celdasTot[$c] = [['f' => "SUM({$L}{$d}:{$L}{$ultimaResumen})"], $sTot];
        }
        $celdasTot[9] = [['f' => "IF(F{$t}=0,0,G{$t}/F{$t})"], $sTotP];
        $celdasTot[10] = [['f' => "IF(F{$t}=0,0,H{$t}/F{$t})"], $sTotP];
        $celdasTot[11] = [$kpi['brecha'] === null ? null : round($kpi['brecha'], 2), $sTotB];
        $x->fila($celdasTot, 24, $sTotL);
        $x->combinar("A{$t}:E{$t}");
        $ultimaResumen = $t;
    }
    $sNota = $x->estilo(['sz' => 8, 'i' => true, 'color' => BX_GRIS]);
    $r = $x->fila([0 => ['En el resumen cada producto cuenta una vez por competidor, con su último precio levantado en el periodo. Más barato = el precio de Banasupro es menor. Brecha positiva = la competencia es más cara.', $sNota]]);
    $x->combinar("A{$r}:{$ult}{$r}");
    $x->filaVacia(10);

    // ═══════════ DETALLE: todos los levantamientos ═══════════
    $seccion('DETALLE DE LEVANTAMIENTOS');
    $titulos = ['N.', 'COMPETIDOR', 'FECHA', 'FAMILIA', 'PRODUCTO Y PRESENTACIÓN', 'PRECIO BANASUPRO (C/U)', 'PRECIO COMPETENCIA (C/U)',
        'DIF. (L.)', 'DIF. %', 'ESTATUS', 'CATEGORÍA', 'SUBCATEGORÍA', 'NOTAS'];
    $celdasEnc = [];
    foreach ($titulos as $i => $tit) {
        $celdasEnc[$i] = [$tit, $sEnc];
    }
    $hdrD = $x->fila($celdasEnc, 28);
    $x->repetirFila($hdrD);

    if ($totalFilas === 0) {
        $sVacio = $x->estilo(['i' => true, 'color' => BX_GRIS, 'h' => 'center', 'v' => 'center']);
        $r = $x->fila([0 => ['No hay datos para exportar con los filtros seleccionados.', $sVacio]], 30);
        $x->combinar("A{$r}:{$ult}{$r}");
    } else {
        // Estilos de la fila de detalle por estatus y fila alterna (6 combinaciones; se reutilizan en todas las filas)
        $estilosFila = [];
        foreach (BX_ESTATUS as $k => $e) {
            foreach ([0, 1] as $z) {
                $b = $base + ['bb' => ['thin', BX_LINEA]] + ($z ? ['bg' => BX_FONDO] : []);
                $estilosFila[$k][$z] = [
                    $x->estilo($b + ['h' => 'center']),
                    $x->estilo($b + ['b' => true]),
                    $x->estilo($b + ['h' => 'center', 'fmt' => 'dd/mm/yyyy']),
                    $x->estilo($b + ['b' => true, 'color' => BX_AZUL]),
                    $x->estilo($b),
                    $x->estilo($b + ['b' => true, 'color' => BX_AZUL, 'h' => 'right', 'fmt' => BX_FMT_LPS]),
                    $x->estilo($b + ['b' => true, 'h' => 'right', 'fmt' => BX_FMT_LPS]),
                    $x->estilo($b + ['b' => true, 'color' => $e['color'], 'h' => 'right', 'fmt' => BX_FMT_DIF]),
                    $x->estilo($b + ['b' => true, 'color' => $e['color'], 'h' => 'right', 'fmt' => BX_FMT_PCT_S]),
                    $x->estilo(['bg' => $e['fondo']] + $b + ['b' => true, 'color' => $e['color'], 'h' => 'center']),
                    $x->estilo($b + ['color' => BX_GRIS]),
                    $x->estilo($b + ['color' => BX_GRIS]),
                    $x->estilo($b + ['h' => 'left', 'indent' => 1]),
                ];
            }
        }
        $i = 0;
        for ($desde = 0; $desde < $totalFilas; $desde += BX_LOTE_FILAS) {
            $lote = $leerLote($desde, BX_LOTE_FILAS);
            foreach ($lote as $f) {
                $i++;
                $s = $estilosFila[$f['est']][$i % 2 === 0 ? 1 : 0];
                $ts = strtotime((string)$f['fecha_levantamiento'] . ' UTC');
                $obs = trim((string)($f['observaciones'] ?? ''));
                $x->fila([
                    0 => [$i, $s[0]],
                    1 => [(string)$f['competidor'], $s[1]],
                    2 => [$ts ? (int)floor($ts / 86400) + 25569 : null, $s[2]],
                    3 => [(string)$f['familia'], $s[3]],
                    4 => [benchmark_etiqueta($f['producto']), $s[4]],
                    5 => [(float)$f['precio_propio'], $s[5]],
                    6 => [(float)$f['precio_competencia'], $s[6]],
                    7 => [round($f['dif'], 2), $s[7]],
                    8 => [round($f['pct'], 2), $s[8]],
                    9 => [BX_ESTATUS[$f['est']]['txt'], $s[9]],
                    10 => [benchmark_etiqueta($f['categoria']), $s[10]],
                    11 => [benchmark_etiqueta($f['subcategoria']), $s[11]],
                    12 => [$obs === '' ? '-' : $obs, $s[12]],
                ], 20);
            }
            unset($lote);
        }
        $x->autofiltro("A{$hdrD}:{$ult}" . $x->filaActual());
    }
    $x->filaVacia(10);
    $sPie = $x->estilo(['sz' => 8, 'i' => true, 'color' => BX_GRIS, 'h' => 'center']);
    $r = $x->fila([0 => ['En el detalle cada fila es una visita. Dif. = precio de la competencia menos precio de Banasupro (positivo = Banasupro más barato).', $sPie]]);
    $x->combinar("A{$r}:{$ult}{$r}");
    return $x;
}
