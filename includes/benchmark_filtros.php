<?php
// Filtros compartidos de Benchmark (tabla comparativa, exportación y análisis).
// Solo lectura. Nada que venga del usuario se concatena al SQL: todo va por parámetros.
//
// Regla de negocio: solo se analizan artículos que pertenecen a una de las 11 familias.

// Precio propio: snapshot del levantamiento, con el precio actual como respaldo.
const BENCHMARK_PRECIO_PROPIO = 'COALESCE(NULLIF(d.precio_banasupro_referencia, 0), p.precio_referencia)';

// Factor para llevar un precio capturado "por paquete" a precio por unidad (precio_referencia ÷ precio_lista).
// Se usa solo cuando se promedian precios de un mismo producto a lo largo del tiempo, para no mezclar
// precios de paquete con precios de unidad. Las comparaciones fila por fila no lo necesitan: en cada fila
// el precio propio y el de la competencia ya están en la misma base.
const BENCHMARK_A_UNIDAD = "(CASE WHEN d.base_comparacion = 'paquete' AND p.precio_lista > 0 THEN p.precio_referencia / p.precio_lista ELSE 1 END)";

// Nombre completo del competidor: cadena + sucursal ("La Colonia – Las Hadas"). Sin sucursal, solo la cadena.
// Alias c = benchmark_competidores. Usar siempre esta constante para mostrar competidores.
const BENCHMARK_COMPETIDOR_NOMBRE = "(c.nombre || COALESCE(' – ' || NULLIF(TRIM(c.sucursal), ''), ''))";

// Tablas base con los alias que usa benchmark_armar_where(): d, l, c, p, f.
const BENCHMARK_FROM_BASE = '
    FROM benchmark.benchmark_precios_detalle d
    INNER JOIN benchmark.benchmark_levantamientos l ON l.id = d.id_levantamiento
    INNER JOIN benchmark.benchmark_competidores c   ON c.id = l.id_competidor
    INNER JOIN benchmark.benchmark_productos p      ON p.id = d.id_producto
    INNER JOIN benchmark.benchmark_familias f       ON f.id = p.id_familia';

function benchmark_filtro_fecha($valor): ?string
{
    if (!is_string($valor)) { return null; }
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return ($f && $f->format('Y-m-d') === $valor) ? $valor : null;
}

function benchmark_filtro_entero($valor): ?int
{
    $n = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $n === false ? null : $n;
}

function benchmark_filtro_texto($valor): ?string
{
    if (!is_string($valor)) { return null; }
    $t = trim($valor);
    return $t === '' ? null : mb_substr($t, 0, 100, 'UTF-8');
}

/** Lee y valida los filtros desde $_GET. Un filtro vacío o inválido queda en null. */
function benchmark_leer_filtros(array $get): array
{
    $estatus = $get['estatus'] ?? null;
    return [
        'id_competidor' => benchmark_filtro_entero($get['id_competidor'] ?? null),
        'id_familia'    => benchmark_filtro_entero($get['id_familia'] ?? null),
        'categoria'     => benchmark_filtro_texto($get['categoria'] ?? null),
        'subcategoria'  => benchmark_filtro_texto($get['subcategoria'] ?? null),
        'desde'         => benchmark_filtro_fecha($get['desde'] ?? null),
        'hasta'         => benchmark_filtro_fecha($get['hasta'] ?? null),
        'estatus'       => in_array($estatus, ['barato', 'caro', 'igual'], true) ? $estatus : null,
    ];
}

/** @return array{0:string,1:array} WHERE (sin la palabra WHERE) y sus parámetros posicionales. */
function benchmark_armar_where(array $f, bool $conEstatus = true): array
{
    $w = ['p.id_familia IS NOT NULL'];
    $p = [];
    if ($f['id_competidor'] !== null) { $w[] = 'l.id_competidor = ?';            $p[] = $f['id_competidor']; }
    if ($f['id_familia'] !== null)    { $w[] = 'p.id_familia = ?';               $p[] = $f['id_familia']; }
    if ($f['categoria'] !== null)     { $w[] = 'UPPER(p.clasificacion2) = UPPER(?)'; $p[] = $f['categoria']; }
    if ($f['subcategoria'] !== null)  { $w[] = 'UPPER(p.categoria) = UPPER(?)';  $p[] = $f['subcategoria']; }
    if ($f['desde'] !== null)         { $w[] = 'l.fecha_levantamiento >= ?';     $p[] = $f['desde']; }
    if ($f['hasta'] !== null)         { $w[] = 'l.fecha_levantamiento <= ?';     $p[] = $f['hasta']; }
    if ($conEstatus && $f['estatus'] !== null) {
        $op = ['barato' => '>', 'caro' => '<', 'igual' => '='][$f['estatus']];
        $w[] = 'd.precio_competencia ' . $op . ' ' . BENCHMARK_PRECIO_PROPIO;
    }
    return [implode(' AND ', $w), $p];
}

/** Opciones para los selectores: competidores, familias y árbol categoría → subcategorías. */
function benchmark_opciones_filtro(PDO $pdo): array
{
    $minus = fn(array $r) => array_change_key_case($r, CASE_LOWER);
    $competidores = array_map($minus, $pdo->query(
        'SELECT c.id, ' . BENCHMARK_COMPETIDOR_NOMBRE . ' AS nombre, c.estado
         FROM benchmark.benchmark_competidores c ORDER BY c.nombre, c.sucursal'
    )->fetchAll(PDO::FETCH_ASSOC));
    $familias = array_map($minus, $pdo->query(
        'SELECT id, nombre FROM benchmark.benchmark_familias WHERE estado = 1 ORDER BY orden, nombre'
    )->fetchAll(PDO::FETCH_ASSOC));

    $arbol = [];
    $filas = $pdo->query(
        'SELECT DISTINCT clasificacion2 AS categoria, categoria AS subcategoria
         FROM benchmark.benchmark_productos
         WHERE estado = 1 AND id_familia IS NOT NULL AND clasificacion2 IS NOT NULL
         ORDER BY 1, 2'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as $fila) {
        $fila = $minus($fila);
        $arbol[(string)$fila['categoria']][] = (string)$fila['subcategoria'];
    }
    return ['competidores' => $competidores, 'familias' => $familias, 'categorias' => $arbol];
}

/** Filtros activos como query string (para enlaces y exportaciones). */
function benchmark_filtros_query(array $f, array $extra = []): string
{
    return http_build_query(array_merge(array_filter($f, fn($v) => $v !== null), $extra));
}

/** Texto bonito para mostrar nombres de Softland (vienen en MAYÚSCULAS). */
function benchmark_etiqueta($texto): string
{
    return ucfirst(mb_strtolower((string)$texto, 'UTF-8'));
}


/**
 * Comparativa global familia x competidor. Cuenta PRODUCTOS (no visitas): para cada producto y
 * competidor se toma el último precio levantado dentro de los filtros.
 * Usada por la pantalla, el Excel y el PDF para que los números coincidan siempre.
 */
function benchmark_resumen_familia_competidor(PDO $pdo, string $where, array $params): array
{
    $stmt = $pdo->prepare("
        SELECT u.id_familia, u.familia, u.orden, u.id_competidor, u.competidor,
               COUNT(*) AS productos,
               COUNT(*) FILTER (WHERE u.pc >  u.pp) AS barato,
               COUNT(*) FILTER (WHERE u.pc <  u.pp) AS caro,
               COUNT(*) FILTER (WHERE u.pc =  u.pp) AS igual,
               AVG(CASE WHEN u.pp > 0 THEN (u.pc - u.pp) / u.pp * 100 END) AS brecha
        FROM (
            SELECT DISTINCT ON (l.id_competidor, d.id_producto)
                   f.id AS id_familia, f.nombre AS familia, f.orden,
                   c.id AS id_competidor, " . BENCHMARK_COMPETIDOR_NOMBRE . " AS competidor,
                   d.precio_competencia AS pc, " . BENCHMARK_PRECIO_PROPIO . " AS pp
            " . BENCHMARK_FROM_BASE . "
            WHERE $where
            ORDER BY l.id_competidor, d.id_producto, l.fecha_levantamiento DESC, d.id DESC
        ) u
        GROUP BY u.id_familia, u.familia, u.orden, u.id_competidor, u.competidor
        ORDER BY u.orden, u.familia, u.competidor
    ");
    $stmt->execute($params);
    return array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Totales (indicadores) a partir del resumen familia x competidor. */
function benchmark_kpis_desde_resumen(array $resumen): array
{
    $k = ['muestras' => 0, 'total' => 0, 'barato' => 0, 'caro' => 0, 'igual' => 0, 'brecha' => null, 'pct_barato' => 0, 'pct_caro' => 0];
    $suma = 0.0;
    foreach ($resumen as $r) {
        $n = (int)$r['productos'];
        $k['total'] += $n;
        $k['barato'] += (int)$r['barato'];
        $k['caro']   += (int)$r['caro'];
        $k['igual']  += (int)$r['igual'];
        $suma += (float)$r['brecha'] * $n;
    }
    $k['muestras'] = $k['total'];
    if ($k['total'] > 0) {
        $k['brecha']     = $suma / $k['total'];
        $k['pct_barato'] = round($k['barato'] / $k['total'] * 100, 1);
        $k['pct_caro']   = round($k['caro'] / $k['total'] * 100, 1);
    }
    return $k;
}

/**
 * Texto del rango de fechas por semanas ISO (lunes a domingo) para encabezados de exportes.
 * "Semana 36 (31/08 al 06/09/2026)" o "Semanas 36 a 40 (31/08/2026 al 04/10/2026)".
 */
function benchmark_texto_semanas(?string $desde, ?string $hasta): string
{
    $d = $desde ? new DateTimeImmutable($desde) : null;
    $h = $hasta ? new DateTimeImmutable($hasta) : null;
    if ($d === null && $h === null) { return ''; }
    if ($d === null) { return 'Hasta la semana ' . (int)$h->format('W') . ' (' . $h->format('d/m/Y') . ')'; }
    if ($h === null) { return 'Desde la semana ' . (int)$d->format('W') . ' (' . $d->format('d/m/Y') . ')'; }
    if ($d->format('o-W') === $h->format('o-W')) {
        return 'Semana ' . (int)$d->format('W') . ' (' . $d->format('d/m') . ' al ' . $h->format('d/m/Y') . ')';
    }
    return 'Semanas ' . (int)$d->format('W') . ' a ' . (int)$h->format('W') . ' (' . $d->format('d/m/Y') . ' al ' . $h->format('d/m/Y') . ')';
}

/**
 * Rankings para el panel "Lo más importante" de la tabla comparativa. Misma base que el resumen por
 * familia (último precio levantado de cada producto por competidor, dentro de los filtros), así los
 * números coinciden. Brecha = (competencia - Banasupro) / Banasupro: negativa = Banasupro más caro.
 * @return array{caros:array,baratos:array,familias:array,competidores:array}
 */
function benchmark_rankings(PDO $pdo, string $where, array $params, int $limiteCaros = 10, int $limiteBaratos = 5): array
{
    $ultimos = "
        SELECT DISTINCT ON (l.id_competidor, d.id_producto)
               f.id AS id_familia, f.nombre AS familia,
               c.id AS id_competidor, " . BENCHMARK_COMPETIDOR_NOMBRE . " AS competidor,
               p.id AS id_producto, p.nombre_producto, d.base_comparacion,
               d.precio_competencia AS pc, " . BENCHMARK_PRECIO_PROPIO . " AS pp
        " . BENCHMARK_FROM_BASE . "
        WHERE $where
        ORDER BY l.id_competidor, d.id_producto, l.fecha_levantamiento DESC, d.id DESC";

    $consulta = function (string $sql) use ($pdo, $params): array {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $st->fetchAll(PDO::FETCH_ASSOC));
    };

    return [
        'caros' => $consulta("SELECT u.*, (u.pc - u.pp) / u.pp * 100 AS brecha FROM ($ultimos) u
                              WHERE u.pp > 0 AND u.pc < u.pp ORDER BY brecha ASC, u.nombre_producto LIMIT " . (int)$limiteCaros),
        'baratos' => $consulta("SELECT u.*, (u.pc - u.pp) / u.pp * 100 AS brecha FROM ($ultimos) u
                                WHERE u.pp > 0 AND u.pc > u.pp ORDER BY brecha DESC, u.nombre_producto LIMIT " . (int)$limiteBaratos),
        'familias' => $consulta("SELECT u.id_familia, u.familia, COUNT(*) AS productos,
                                        COUNT(*) FILTER (WHERE u.pc < u.pp) AS caro, COUNT(*) FILTER (WHERE u.pc > u.pp) AS barato
                                 FROM ($ultimos) u GROUP BY u.id_familia, u.familia
                                 HAVING COUNT(*) FILTER (WHERE u.pc < u.pp) > 0
                                 ORDER BY caro DESC, productos ASC, u.familia LIMIT 6"),
        'competidores' => $consulta("SELECT u.id_competidor, u.competidor, COUNT(*) AS productos,
                                            COUNT(*) FILTER (WHERE u.pc < u.pp) AS caro, COUNT(*) FILTER (WHERE u.pc > u.pp) AS barato
                                     FROM ($ultimos) u GROUP BY u.id_competidor, u.competidor
                                     ORDER BY caro DESC, u.competidor"),
    ];
}
