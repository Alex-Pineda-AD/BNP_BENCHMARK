<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../connection/database.php';

// Este controlador solo consulta datos. Conserva el acceso por sesión de la vista.
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['mensaje' => 'La sesión terminó. Inicia sesión nuevamente.']);
    exit;
}
session_write_close();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['mensaje' => 'Método no permitido.']);
    exit;
}

try {
    $carga = filter_var($_GET['carga_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($carga === false || $carga < 0) throw new InvalidArgumentException('La carga no es válida.');
    $banco = $_GET['banco_id'] ?? '';
    $desde = $_GET['desde'] ?? '';
    $hasta = $_GET['hasta'] ?? '';
    $buscar = $_GET['buscar'] ?? '';
    if (!is_string($buscar) || mb_strlen($buscar) > 150) throw new InvalidArgumentException('La búsqueda admite hasta 150 caracteres.');
    $buscar = trim($buscar);
    $pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
    if (!$pagina || $pagina < 1) {
        throw new InvalidArgumentException('La página no es válida.');
    }
    if (!is_string($banco) || !is_string($desde) || !is_string($hasta)) {
        throw new InvalidArgumentException('Los filtros no son válidos.');
    }
    if ($banco !== '' && (!ctype_digit($banco) || (int) $banco < 1)) {
        throw new InvalidArgumentException('Selecciona un banco válido.');
    }
    foreach ([$desde, $hasta] as $valor) {
        if ($valor !== '') {
            $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
            if (!$fecha || $fecha->format('Y-m-d') !== $valor) {
                throw new InvalidArgumentException('Introduce fechas válidas.');
            }
        }
    }
    if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
        throw new InvalidArgumentException('Desde no puede ser posterior a Hasta.');
    }

    // Agregamos únicamente los filtros completados. Los valores nunca se concatenan al SQL.
    $condiciones = ['b.ESTADO != 0'];
    $parametros = [];
    if ($carga > 0) {
        $condiciones[] = 'e.ID = :carga';
        $parametros['carga'] = $carga;
    }
    if ($banco !== '') {
        $condiciones[] = 'e.ID_BANCO = :banco';
        $parametros['banco'] = $banco;
    }
    if ($desde !== '') {
        $condiciones[] = 't.FECHA >= :desde';
        $parametros['desde'] = $desde;
    }
    if ($hasta !== '') {
        $condiciones[] = 't.FECHA < (CAST(:hasta AS date) + INTERVAL \'1 day\')';
        $parametros['hasta'] = $hasta;
    }
    // El resumen respeta la carga, las fechas y el banco; no depende de la pagina ni del texto.
    $sqlResumen = "SELECT R.MES, R.BANCO, R.MONEDA,
        TO_CHAR(SUM(R.DEBITO), 'FM999,999,999,999,999,990.00') DEBITO, TO_CHAR(SUM(R.CREDITO), 'FM999,999,999,999,999,990.00') CREDITO,
        TO_CHAR(SUM(CASE WHEN R.ORDEN = 1 THEN R.BALANCE ELSE 0 END), 'FM999,999,999,999,999,990.00') BALANCE
        FROM (
            SELECT e.ID_BANCO, b.DESCRIPCION BANCO, e.MONEDA, TO_CHAR(t.FECHA, 'YYYY-MM') MES,
                t.DEBITO, t.CREDITO, t.BALANCE,
                ROW_NUMBER() OVER (
                    PARTITION BY e.ID_BANCO, e.MONEDA, e.NUMERO_CUENTA, TO_CHAR(t.FECHA, 'YYYY-MM')
                    ORDER BY t.FECHA DESC, t.HORA DESC, t.ID DESC
                ) ORDEN
            FROM transacciones_estado_cuenta t
            INNER JOIN estados_cuenta e ON e.ID = t.ID_ESTADO_CIENTA
            INNER JOIN bancos b ON b.ID = e.ID_BANCO
            WHERE " . implode(' AND ', $condiciones) . '
        ) R GROUP BY R.ID_BANCO, R.BANCO, R.MES, R.MONEDA
        ORDER BY R.MES DESC, R.BANCO, R.MONEDA';
    $consultaResumen = database()->prepare($sqlResumen);
    $consultaResumen->execute($parametros);
    $resumenHtml = '';
    foreach ($consultaResumen as $filaResumen) {
        $resumenHtml .= '<tr>';
        foreach ($filaResumen as $valor) {
            $resumenHtml .= '<td>' . htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        }
        $resumenHtml .= '</tr>';
    }
    if ($resumenHtml === '') $resumenHtml = '<tr><td colspan="6">No hay movimientos para este período.</td></tr>';
    if ($buscar !== '') {
        // Buscar en todas las paginas, incluyendo montos con o sin separador de miles.
        $campos = ['b.DESCRIPCION', 't.REFERENCIA', 't.CODIGO', 't.DESCRIPCION',
            'CAST(t.FECHA AS TEXT)', "TO_CHAR(t.FECHA, 'DD/MM/YYYY')",
            'CAST(t.DEBITO AS TEXT)', 'CAST(t.CREDITO AS TEXT)', 'CAST(t.BALANCE AS TEXT)',
            "CONCAT('L. ', TO_CHAR(t.DEBITO, 'FM999,999,999,999,999,990.00'))", "CONCAT('L. ', TO_CHAR(t.CREDITO, 'FM999,999,999,999,999,990.00'))", "CONCAT('L. ', TO_CHAR(t.BALANCE, 'FM999,999,999,999,999,990.00'))"];
        $coincidencias = [];
        foreach ($campos as $indice => $campo) {
            $coincidencias[] = $campo . ' ILIKE :buscar' . $indice . " ESCAPE '!'";
            $parametros['buscar' . $indice] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $buscar) . '%';
        }
        $condiciones[] = '(' . implode(' OR ', $coincidencias) . ')';
    }
    $desdeSql = ' FROM transacciones_estado_cuenta t
        INNER JOIN estados_cuenta e ON e.ID = t.ID_ESTADO_CIENTA
        LEFT JOIN bancos b ON b.ID = e.ID_BANCO';
    if ($condiciones) {
        $desdeSql .= ' WHERE ' . implode(' AND ', $condiciones);
    }
    // 1. Contar todos los resultados del filtro para saber cuántas páginas existen.
    $consulta = database()->prepare('SELECT COUNT(*)' . $desdeSql);
    $consulta->execute($parametros);
    $total = (int) $consulta->fetchColumn();
    $paginas = max(1, (int) ceil($total / 50));
    $pagina = min($pagina, $paginas);
    $inicio = ($pagina - 1) * 50;

    // 2. Traer solo los 50 registros de la página solicitada.
    $sql = 'SELECT b.DESCRIPCION AS banco, t.FECHA AS fecha,
        t.REFERENCIA AS referencia, t.CODIGO AS tipo, t.DESCRIPCION AS descripcion,
        CONCAT(\'L. \',TO_CHAR(t.DEBITO, \'FM999,999,999,999,999,990.00\')) AS debito, CONCAT(\'L. \',TO_CHAR(t.CREDITO, \'FM999,999,999,999,999,990.00\')) AS credito,
        CONCAT(\'L. \',TO_CHAR(t.BALANCE, \'FM999,999,999,999,999,990.00\')) AS saldo' . $desdeSql;
    // $inicio es un entero calculado aquí, nunca texto recibido del usuario.
    $sql .= ' ORDER BY t.ID DESC LIMIT 50 OFFSET ' . $inicio;
    $consulta = database()->prepare($sql);
    $consulta->execute($parametros);
    $filas = $consulta->fetchAll();
    // 3. Dibujar las filas con un foreach de PHP. JavaScript solo reemplaza la tabla.
    $html = '';
    foreach ($filas as $fila) {
        $html .= '<tr>';
        foreach ($fila as $valor) {
            $html .= '<td>' . htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    if (!$filas) {
        $html = '<tr><td colspan="8">No hay movimientos para estos filtros.</td></tr>';
    }
    echo json_encode(['html' => $html, 'pagina' => $pagina, 'paginas' => $paginas,
        'total' => $total, 'resumen' => $resumenHtml], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['mensaje' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Filtro de movimientos: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['mensaje' => 'No se pudieron consultar los movimientos. Intenta nuevamente.']);
}
