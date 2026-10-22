<?php

// Todos los bancos devuelven estas mismas tres secciones.
// null significa que el documento NO proporciona el dato; no equivale a cero.
function nuevoEstadoBancario(): array
{
    $campos = ['NUMERO_CLIENTE', 'NOMBRE', 'PRODUCTO', 'MONEDA', 'SALDO_INICIAL',
        'SALDO_LIBROS', 'RETENIDOS_DIFERIDOS', 'SALDO_DISPONIBLE', 'FECHA',
        'STBGAV', 'STBUNC', 'MENSAJE_1', 'MENSAJE_2', 'MENSAJE_3', 'MENSAJE_4',
        'MENSAJE_5', 'MENSAJE_6', 'NUMERO_CUENTA', 'IBAN', 'TIPO_PRODUCTO',
        'PERIODO_DESDE', 'PERIODO_HASTA', 'SALDO_FINAL'];
    return ['cuenta' => array_fill_keys($campos, null),
        'transacciones' => [], 'resumen' => [], 'advertencias' => []];
}

function textoBancarioUtf8(string $texto): string
{
    if (!preg_match('//u', $texto)) {
        $texto = iconv('Windows-1252', 'UTF-8', $texto);
        if ($texto === false) {
            throw new InvalidArgumentException('No se pudo convertir el TXT a UTF-8.');
        }
    }
    return preg_replace('/^\xEF\xBB\xBF/', '', $texto);
}

function fechaBancaria(string $valor, string $formato): string
{
    $fecha = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
    if (!$fecha || $fecha->format($formato) !== $valor) {
        throw new InvalidArgumentException("Fecha inválida: $valor.");
    }
    return $fecha->format('Y-m-d');
}

function importeBancario(string $valor): string
{
    if (!preg_match('/^-?(?:\d+|\d{1,3}(?:,\d{3})+)\.\d{2}$/', $valor)) {
        throw new InvalidArgumentException('Importe inválido en el documento.');
    }
    $valor = str_replace(',', '', $valor);
    if (strlen(ltrim(explode('.', $valor)[0], '-0')) > 13) {
        throw new InvalidArgumentException('El importe supera la capacidad de DECIMAL(15,2).');
    }
    return $valor; // Texto decimal: no usar float para guardar dinero.
}

function monedaBancaria(string $valor): string
{
    $valor = strtoupper(trim($valor));
    if (in_array($valor, ['LPS', 'LEMPIRAS', 'HNL'], true)) {
        return 'HNL';
    }
    if (in_array($valor, ['USD', 'DOLARES', 'DÓLARES'], true)) {
        return 'USD';
    }
    throw new InvalidArgumentException('Moneda no reconocida en el documento.');
}

function revisarColumnas(array $fila, int $cantidad, int $linea): void
{
    if (count($fila) !== $cantidad) {
        throw new InvalidArgumentException("Cantidad de columnas incorrecta en la línea $linea.");
    }
}

function movimientoBancario(string $fecha, string $referencia, ?string $codigo,
    string $descripcion, string $debito, string $credito, string $saldo): array
{
    return ['FECHA' => $fecha, 'REFERENCIA' => $referencia, 'CODIGO' => $codigo,
        'DESCRIPCION' => $descripcion, 'DEBITO' => importeBancario($debito),
        'CREDITO' => importeBancario($credito), 'BALANCE' => importeBancario($saldo),
        'HORA' => null, 'AGENCIA' => null, 'CAJERO' => null, 'NUMERO_CHEQUE' => null];
}

function resumenBancario(): array
{
    return array_fill_keys(['CODIGO', 'CANTIDAD_DEBITOS', 'MONTO_DEBITOS',
        'CANTIDAD_CREDITOS', 'MONTO_CREDITOS', 'CONCEPTO', 'CANTIDAD', 'MONTO'], null);
}

// Validaciones compartidas después de leer, antes de insertar una sola fila.
function revisarEstadoBancario(array $datos): array
{
    if (empty($datos['cuenta']['NUMERO_CUENTA']) || empty($datos['cuenta']['NOMBRE'])
        || empty($datos['cuenta']['MONEDA'])) {
        throw new InvalidArgumentException('Faltan los datos principales de la cuenta.');
    }
    $desde = $datos['cuenta']['PERIODO_DESDE'];
    $hasta = $datos['cuenta']['PERIODO_HASTA'];
    if ($desde && $hasta && $desde > $hasta) {
        throw new InvalidArgumentException('El período del estado de cuenta es inválido.');
    }
    $vistos = [];
    foreach ($datos['transacciones'] as $fila) {
        // Una referencia puede repetirse legítimamente. Comparamos la fila completa.
        $huella = hash('sha256', json_encode($fila));
        if (isset($vistos[$huella])) {
            $datos['advertencias'][] = 'Hay movimientos idénticos; se conservaron sin eliminarlos.';
        }
        $vistos[$huella] = true;
    }
    foreach ($datos['resumen'] as $fila) {
        // Atlántida informa montos totales, pero no cantidades de movimientos.
        if ($fila['CODIGO'] === 'Total' && $fila['CANTIDAD_DEBITOS'] !== null
            && $fila['CANTIDAD_CREDITOS'] !== null) {
            $cantidad = (int) $fila['CANTIDAD_DEBITOS'] + (int) $fila['CANTIDAD_CREDITOS'];
            if ($cantidad !== count($datos['transacciones'])) {
                $datos['advertencias'][] = 'El total de movimientos del resumen no coincide con el detalle cargado.';
            }
        }
    }
    $datos['advertencias'] = array_values(array_unique($datos['advertencias']));
    return $datos;
}
