<?php
// Sincronización de Benchmark con Softland. Softland solo se usa para SELECT.
//
//  - Alcance: benchmark.benchmark_alcance_categorias (categoría y subcategoría de Softland).
//  - Precio:  PRECIO LISTA más reciente de cada artículo ÷ FACTOR_EMPAQUE (precio por unidad
//             de venta). Se guarda también el precio de lista original y el factor para poder
//             verificar cada número.
//  - Familia:  cada producto recibe una de las 11 familias con las reglas de
//             benchmark.benchmark_familia_reglas (incluir/excluir por nombre). Las categorías
//             marcadas requiere_familia (ej. PROYECTOS) solo dejan entrar lo que tenga familia.
//             Una familia asignada a mano (familia_manual) no se sobrescribe.
//  - Nunca entra un artículo sin precio de lista, sin factor válido o con unidad de venta
//    no soportada: no se guarda un precio de caja ni uno tomado de otro nivel de precio.
require_once __DIR__ . '/benchmark_auditoria.php';

const BENCHMARK_UNIDADES_VENTA = ['UND', 'LBS', 'LTR', 'PAQ', 'GAL', 'BOL', 'ONZ', 'ARB'];

/**
 * Unidades de venta que no son la unidad base de comparación: cuántas LIBRAS equivale una unidad.
 * El precio por unidad se calcula siempre por libra (ONZ: 16 onzas = 1 libra; ARB: 1 arroba = 25 libras).
 * Se usa dentro del SQL de sincronización (CASE) y debe mantenerse igual.
 */

/**
 * Categorías "comodín" de Softland (proyectos u otras) donde hay artículos que en realidad son de una familia.
 * Esos artículos se muestran en el lugar que les corresponde, junto a los de su misma familia.
 */
const BENCHMARK_CATEGORIAS_COMODIN = ['PROYECTOS'];

function benchmark_es_categoria_comodin(string $c2): bool
{
    return in_array(mb_strtoupper(trim($c2), 'UTF-8'), BENCHMARK_CATEGORIAS_COMODIN, true);
}

/** Primera palabra del nombre, sin acentos (JAMON DE CERDO LB -> JAMON). */
function benchmark_primera_palabra(string $nombre): string
{
    $partes = preg_split('/\s+/', trim(benchmark_sin_acentos($nombre)));
    return (string)($partes[0] ?? '');
}

/**
 * Dónde viven los artículos de cada familia fuera de las categorías comodín:
 * por familia y por familia + primera palabra del nombre (para separar, p. ej., jamones de chorizos).
 * @return array{familia: array, palabra: array}
 */
function benchmark_ubicaciones_por_familia(array $vigentes): array
{
    $u = ['familia' => [], 'palabra' => []];
    foreach ($vigentes as $f) {
        if (empty($f['id_familia']) || benchmark_es_categoria_comodin((string)$f['clasificacion2'])) {
            continue;
        }
        $lugar = trim((string)$f['clasificacion2']) . "\x1F" . trim((string)$f['subcategoria']);
        $fam = (int)$f['id_familia'];
        $u['familia'][$fam][$lugar] = ($u['familia'][$fam][$lugar] ?? 0) + 1;
        $clave = $fam . '|' . benchmark_primera_palabra((string)$f['nombre_producto']);
        $u['palabra'][$clave][$lugar] = ($u['palabra'][$clave][$lugar] ?? 0) + 1;
    }
    return $u;
}

/** Categoría y subcategoría donde debe mostrarse un artículo de categoría comodín, o null si no hay referencia. */
function benchmark_ubicacion_para(array $f, array $ubicaciones): ?array
{
    $fam = (int)$f['id_familia'];
    $candidatos = $ubicaciones['palabra'][$fam . '|' . benchmark_primera_palabra((string)$f['nombre_producto'])] ?? null;
    // Con menos de 3 artículos con esa primera palabra no hay referencia fiable: se usa la familia completa
    if ($candidatos === null || array_sum($candidatos) < 3) {
        $candidatos = $ubicaciones['familia'][$fam] ?? null;
    }
    if (!$candidatos) {
        return null;
    }
    arsort($candidatos);
    [$c2, $c3] = explode("\x1F", (string)array_key_first($candidatos), 2);
    return ['clasificacion2' => $c2, 'subcategoria' => $c3];
}

function benchmark_sin_acentos(string $texto): string
{
    return strtr(mb_strtoupper($texto, 'UTF-8'), [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ]);
}

/** Reglas activas agrupadas por familia (en el orden de las familias): [id => [incluir => [regex], excluir => [regex]]] */
function benchmark_cargar_reglas_familia(PDO $pdo): array
{
    $filas = $pdo->query("
        SELECT r.id_familia, r.tipo, r.patron
        FROM benchmark.benchmark_familia_reglas r
        JOIN benchmark.benchmark_familias f ON f.id = r.id_familia
        WHERE r.estado = 1 AND f.estado = 1
        ORDER BY f.orden, f.id, r.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $reglas = [];
    foreach ($filas as $fila) {
        $fila = array_change_key_case($fila, CASE_LOWER);
        $regex = '/^' . str_replace('%', '.*', preg_quote(benchmark_sin_acentos((string)$fila['patron']), '/')) . '$/u';
        $reglas[(int)$fila['id_familia']][$fila['tipo'] === 'excluir' ? 'excluir' : 'incluir'][] = $regex;
    }
    return $reglas;
}

/** Primera familia que incluye el nombre y no lo excluye; null si ninguna. */
function benchmark_familia_de(string $nombre, array $reglas): ?int
{
    $n = benchmark_sin_acentos($nombre);
    foreach ($reglas as $idFamilia => $grupo) {
        $incluye = false;
        foreach ($grupo['incluir'] ?? [] as $regex) {
            if (preg_match($regex, $n)) { $incluye = true; break; }
        }
        if (!$incluye) { continue; }
        $excluye = false;
        foreach ($grupo['excluir'] ?? [] as $regex) {
            if (preg_match($regex, $n)) { $excluye = true; break; }
        }
        if (!$excluye) { return (int)$idFamilia; }
    }
    return null;
}

/** true si TODAS las filas de alcance que cubren al artículo exigen familia (ej. PROYECTOS). */
function benchmark_requiere_familia(string $c2, string $c3, array $alcance): bool
{
    $hay = false;
    foreach ($alcance as $a) {
        if ($a['c2'] !== $c2) { continue; }
        if ($a['c3'] !== null && $a['c3'] !== $c3) { continue; }
        if (!$a['req']) { return false; }
        $hay = true;
    }
    return $hay;
}

function benchmark_normalizar_nombre(string $nombre): string
{
    return preg_replace('/\s+/u', ' ', trim(mb_strtoupper($nombre, 'UTF-8')));
}

/**
 * @return array{actualizados:int, con_familia:int, sin_familia:int, excluidos:string[], duplicados:string[], sin_alcance:bool}
 *   excluidos  = productos ya guardados que Softland no devuelve con precio válido
 *   duplicados = mismo nombre con otro código más reciente (se oculta el viejo)
 *   reubicados = artículos con familia mostrados junto a los de su familia (PROYECTOS, leche en otras categorías, etc.)
 */
function benchmark_sincronizar_softland(PDO $pdo, string $origen = 'sync_softland'): array
{
    $resumen = ['actualizados' => 0, 'con_familia' => 0, 'sin_familia' => 0, 'excluidos' => [], 'duplicados' => [], 'reubicados' => [], 'sin_alcance' => false];

    // Alcance configurado
    $alcance = $pdo->query("SELECT clasificacion2, clasificacion3, requiere_familia FROM benchmark.benchmark_alcance_categorias WHERE estado = 1")
                   ->fetchAll(PDO::FETCH_ASSOC);
    if (empty($alcance)) {
        $resumen['sin_alcance'] = true;
        return $resumen;
    }
    $condiciones = [];
    $params = [];
    $alcanceFilas = [];
    foreach ($alcance as $a) {
        $a = array_change_key_case($a, CASE_LOWER);
        $c2 = mb_strtoupper(trim((string)$a['clasificacion2']), 'UTF-8');
        $c3 = $a['clasificacion3'] === null ? null : mb_strtoupper(trim((string)$a['clasificacion3']), 'UTF-8');
        if ($c3 === '') { $c3 = null; }
        $alcanceFilas[] = ['c2' => $c2, 'c3' => $c3, 'req' => filter_var($a['requiere_familia'], FILTER_VALIDATE_BOOLEAN)];
        if ($c3 === null || $c3 === '') {
            $condiciones[] = "UPPER(LTRIM(RTRIM(v.Clasificacion2))) = ?";
            $params[] = $c2;
        } else {
            $condiciones[] = "(UPPER(LTRIM(RTRIM(v.Clasificacion2))) = ? AND UPPER(LTRIM(RTRIM(v.Clasificacion3))) = ?)";
            $params[] = $c2;
            $params[] = $c3;
        }
    }
    $filtroAlcance = '(' . implode(' OR ', $condiciones) . ')';
    $unidades = "'" . implode("','", BENCHMARK_UNIDADES_VENTA) . "'";

    require_once __DIR__ . '/../connection/database_sqlserver.php';
    $pdoSql = database_sqlserver();

    $sql = "
        WITH PreciosRecientes AS (
            SELECT v.ARTICULO, v.ArticuloDescripcion, v.Precio, v.Clasificacion2, v.Clasificacion3,
                   ROW_NUMBER() OVER(
                       PARTITION BY v.ARTICULO
                       ORDER BY TRY_CONVERT(DATE, LEFT(v.PeriodoPrecio, CHARINDEX(':::', v.PeriodoPrecio + ':::') - 1)) DESC,
                                v.VersionNivelPrecio DESC
                   ) AS rn
            FROM BANASUPRO.SoftlandBI_FA_listaPrecios v
            WHERE v.NivelPrecio = 'PRECIO LISTA'
              AND v.EstVersionNivPrecio = 'A'
              AND v.ArticuloActivo LIKE 'S%'
              AND $filtroAlcance
        )
        SELECT p.ARTICULO AS codigo,
               p.ArticuloDescripcion AS nombre_producto,
               LEFT(LTRIM(RTRIM(p.Clasificacion2)), 100) AS clasificacion2,
               COALESCE(NULLIF(LEFT(LTRIM(RTRIM(p.Clasificacion3)), 100), ''), 'General') AS subcategoria,
               CAST(p.Precio AS DECIMAL(12,2)) AS precio_lista,
               CAST(a.FACTOR_EMPAQUE AS DECIMAL(12,4)) AS factor_empaque,
               CAST(p.Precio / (a.FACTOR_EMPAQUE * CASE a.UNIDAD_VENTA WHEN 'ONZ' THEN 0.0625 WHEN 'ARB' THEN 25.0 ELSE 1.0 END) AS DECIMAL(10,2)) AS precio_referencia,
               a.UNIDAD_VENTA AS unidad_venta,
               CONVERT(VARCHAR(23), a.FCH_HORA_ULT_MODIF, 121) AS modificado
        FROM PreciosRecientes p
        JOIN BANASUPRO.ARTICULO a ON a.ARTICULO = p.ARTICULO
        WHERE p.rn = 1
          AND a.FACTOR_EMPAQUE > 0
          AND a.UNIDAD_VENTA IN ($unidades)
    ";
    $stmtSql = $pdoSql->prepare($sql);
    $stmtSql->execute($params);
    $filas = $stmtSql->fetchAll(PDO::FETCH_ASSOC);

    // Mismo nombre con distinto código: se queda el más reciente en Softland
    $porNombre = [];
    foreach ($filas as $f) {
        $clave = benchmark_normalizar_nombre((string)$f['nombre_producto']);
        if (!isset($porNombre[$clave]) || strcmp((string)$f['modificado'], (string)$porNombre[$clave]['modificado']) > 0) {
            if (isset($porNombre[$clave])) {
                $resumen['duplicados'][] = trim((string)$porNombre[$clave]['codigo']);
            }
            $porNombre[$clave] = $f;
        } else {
            $resumen['duplicados'][] = trim((string)$f['codigo']);
        }
    }
    $vigentes = [];
    foreach ($porNombre as $f) {
        $vigentes[trim((string)$f['codigo'])] = $f;
    }

    // Familia de cada producto; en categorías mezcladas solo entra lo que tenga familia
    $reglas = benchmark_cargar_reglas_familia($pdo);
    foreach ($vigentes as $codigo => $f) {
        $idFamilia = benchmark_familia_de((string)$f['nombre_producto'], $reglas);
        $c2 = mb_strtoupper(trim((string)$f['clasificacion2']), 'UTF-8');
        $c3 = mb_strtoupper(trim((string)$f['subcategoria']), 'UTF-8');
        if ($idFamilia === null && benchmark_requiere_familia($c2, $c3, $alcanceFilas)) {
            unset($vigentes[$codigo]);
            continue;
        }
        $vigentes[$codigo]['id_familia'] = $idFamilia;
    }

    // Todo artículo con familia se muestra donde van los de su familia (comodín PROYECTOS, leche en
    // ENLATADOS o BEBES, una pasta en HARINAS...). Solo cambia lo que ve Benchmark, no Softland.
    $ubicaciones = benchmark_ubicaciones_por_familia($vigentes);
    foreach ($vigentes as $codigo => $f) {
        if (empty($f['id_familia'])) {
            continue;
        }
        $nueva = benchmark_ubicacion_para($f, $ubicaciones);
        $mismo = $nueva !== null
            && mb_strtoupper(trim((string)$f['clasificacion2']), 'UTF-8') === mb_strtoupper($nueva['clasificacion2'], 'UTF-8')
            && mb_strtoupper(trim((string)$f['subcategoria']), 'UTF-8') === mb_strtoupper($nueva['subcategoria'], 'UTF-8');
        if ($nueva !== null && !$mismo) {
            $vigentes[$codigo]['clasificacion2'] = $nueva['clasificacion2'];
            $vigentes[$codigo]['subcategoria']   = $nueva['subcategoria'];
            $resumen['reubicados'][] = $codigo;
        }
    }

    // Productos ya guardados (de sincronizaciones anteriores) que Softland ya no devuelve válidos
    $guardados = $pdo->query("SELECT codigo FROM benchmark.benchmark_productos WHERE estado = 1 AND clasificacion2 IS NOT NULL")
                     ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($guardados as $g) {
        $g = array_change_key_case($g, CASE_LOWER);
        $codigo = trim((string)$g['codigo']);
        if (!isset($vigentes[$codigo]) && !in_array($codigo, $resumen['duplicados'], true)) {
            $resumen['excluidos'][] = $codigo;
        }
    }
    $resumen['duplicados'] = array_values(array_unique($resumen['duplicados']));

    $upsert = $pdo->prepare("
        INSERT INTO benchmark.benchmark_productos
            (codigo, nombre_producto, categoria, clasificacion2, precio_lista, factor_empaque, precio_referencia, unidad_venta, id_familia, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON CONFLICT (codigo) DO UPDATE SET
            nombre_producto     = EXCLUDED.nombre_producto,
            categoria           = EXCLUDED.categoria,
            clasificacion2      = EXCLUDED.clasificacion2,
            precio_lista        = EXCLUDED.precio_lista,
            factor_empaque      = EXCLUDED.factor_empaque,
            precio_referencia   = EXCLUDED.precio_referencia,
            unidad_venta        = EXCLUDED.unidad_venta,
            id_familia          = CASE WHEN benchmark_productos.familia_manual
                                       THEN benchmark_productos.id_familia
                                       ELSE EXCLUDED.id_familia END,
            fecha_actualizacion = CURRENT_TIMESTAMP
    ");
    $pdo->beginTransaction();
    try {
        benchmark_auditoria_contexto($pdo, $origen);
        foreach ($vigentes as $codigo => $f) {
            $upsert->execute([
                $codigo, $f['nombre_producto'], $f['subcategoria'], $f['clasificacion2'],
                $f['precio_lista'], $f['factor_empaque'], $f['precio_referencia'], $f['unidad_venta'], $f['id_familia'],
            ]);
            $resumen['actualizados']++;
            $f['id_familia'] === null ? $resumen['sin_familia']++ : $resumen['con_familia']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $resumen;
}


/** Segundos que se consideran "al día" los precios sincronizados (la pantalla pide la actualización en segundo plano al vencer). */
const BENCHMARK_SYNC_TTL = 3600;

/** Ruta de un archivo de control de la sincronización (carpeta fija cache/ del proyecto, fuera de git). */
function benchmark_sync_ruta(string $nombre): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . $nombre;
}

/** Resultado de la última sincronización registrada (o null si nunca se ha hecho). */
function benchmark_sync_leer_cache(): ?array
{
    $archivo = benchmark_sync_ruta('benchmark_sync_cache.json');
    if (!is_readable($archivo)) {
        return null;
    }
    $c = json_decode((string) @file_get_contents($archivo), true);
    if (!is_array($c) || !isset($c['ts'])) {
        return null;
    }
    return [
        'actualizados' => (int)($c['actualizados'] ?? 0),
        'excluidos'    => (array)($c['excluidos'] ?? []),
        'duplicados'   => (array)($c['duplicados'] ?? []),
        'reubicados'   => (array)($c['reubicados'] ?? []),
        'sin_alcance'  => $c['sin_alcance'] ?? null,
        'desde_cache'  => true,
        'ts'           => (int)$c['ts'],
    ];
}

/**
 * Sincroniza con Softland solo si la última sincronización tiene más de $ttl segundos
 * (o si $forzar es true). Evita consultar SQL Server y reescribir el catálogo en cada carga de pantalla.
 * Un candado evita que dos personas sincronicen al mismo tiempo. El resultado se guarda en
 * cache/benchmark_sync_cache.json, de donde lo leen las pantallas.
 *
 * @return array{actualizados:int, excluidos:array, duplicados:array, reubicados:array, sin_alcance:mixed, desde_cache:bool, ts:int}
 */
function benchmark_sincronizar_si_toca(PDO $pdo, string $origen = 'sync_softland', int $ttl = BENCHMARK_SYNC_TTL, bool $forzar = false): array
{
    $c = benchmark_sync_leer_cache();
    if (!$forzar && $c !== null && (time() - $c['ts']) < $ttl) {
        return $c;
    }

    $lock = @fopen(benchmark_sync_ruta('benchmark_sync.lock'), 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        // Otra persona ya está sincronizando: se usa lo último guardado; si no hay nada, se espera a que termine
        if ($c !== null) {
            fclose($lock);
            return $c;
        }
        flock($lock, LOCK_EX);
        $c = benchmark_sync_leer_cache();
        if ($c !== null) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return $c;
        }
    }

    try {
        $r = benchmark_sincronizar_softland($pdo, $origen);
        $r['desde_cache'] = false;
        $r['ts'] = time();
        @file_put_contents(benchmark_sync_ruta('benchmark_sync_cache.json'), json_encode($r), LOCK_EX);
        return $r;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
