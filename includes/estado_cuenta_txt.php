<?php

// El lector solo transforma el texto en arreglos. No guarda nada en la base.
function leerEstadoCuentaTxt(string $contenido): array
{
    if (!preg_match('//u', $contenido)) {
        $contenido = iconv('Windows-1252', 'UTF-8', $contenido);
        if ($contenido === false) {
            throw new InvalidArgumentException('No se pudo leer la codificación del TXT.');
        }
    }
    $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
    $datos = ['cuenta' => [], 'transacciones' => [], 'resumen' => []];
    $seccion = 'cuenta';
    $camposCuenta = [
        'numero_cliente', 'nombre', 'producto', 'moneda', 'saldo_inicial',
        'saldo_libros', 'retenidos_diferidos', 'saldo_disponible', 'fecha',
        'stbgav', 'stbunc', 'mensaje1', 'mensaje2', 'mensaje3',
        'mensaje4', 'mensaje5', 'mensaje6',
    ];

    foreach (preg_split('/\r\n|\n|\r/', $contenido) as $indice => $linea) {
        $linea = trim($linea);
        if ($linea === 'Detalle de Estado Bancario') {
            $seccion = 'transacciones';
            continue;
        }
        if ($linea === 'Resumen de Estado Bancario') {
            $seccion = 'resumen';
            continue;
        }
        // Saltar únicamente títulos, líneas vacías y separadores conocidos.
        if ($linea === '' || preg_match('/^[=\-]+$/', $linea)
            || preg_match('/^(Número de Clientes|Fecha de Transacción|Código Transacción Totales)\s*\|/u', $linea)) {
            continue;
        }

        $columnas = array_map('trim', explode('|', $linea));
        $numeroLinea = $indice + 1;

        if ($seccion === 'cuenta') {
            if (count($columnas) !== 17 || !ctype_digit($columnas[0]) || $datos['cuenta']) {
                throw new InvalidArgumentException("Encabezado inválido en la línea $numeroLinea.");
            }
            $fila = array_combine($camposCuenta, $columnas);
            $fila['fecha'] = fechaEstadoTxt($fila['fecha']);
            foreach (['saldo_inicial', 'saldo_libros', 'retenidos_diferidos', 'saldo_disponible', 'stbgav', 'stbunc'] as $campo) {
                $fila[$campo] = importeEstadoTxt($fila[$campo]);
            }
            $datos['cuenta'] = $fila;
        } elseif ($seccion === 'transacciones') {
            if (count($columnas) !== 7) {
                throw new InvalidArgumentException("Movimiento inválido en la línea $numeroLinea.");
            }
            $fila = array_combine(['fecha', 'referencia', 'codigo', 'descripcion', 'debito', 'credito', 'balance'], $columnas);
            $fila['fecha'] = fechaEstadoTxt($fila['fecha']);
            foreach (['debito', 'credito', 'balance'] as $campo) {
                $fila[$campo] = importeEstadoTxt($fila[$campo]);
            }
            $datos['transacciones'][] = $fila;
        } else {
            if (count($columnas) !== 5 || !ctype_digit($columnas[1]) || !ctype_digit($columnas[3])) {
                throw new InvalidArgumentException("Resumen inválido en la línea $numeroLinea.");
            }
            $fila = array_combine(['codigo', 'cantidad_debitos', 'monto_debitos', 'cantidad_creditos', 'monto_creditos'], $columnas);
            $fila['monto_debitos'] = importeEstadoTxt($fila['monto_debitos']);
            $fila['monto_creditos'] = importeEstadoTxt($fila['monto_creditos']);
            $datos['resumen'][] = $fila;
        }
    }
    if (!$datos['cuenta'] || !$datos['resumen'] || !in_array('Total', array_column($datos['resumen'], 'codigo'), true)) {
        throw new InvalidArgumentException('El TXT no contiene el encabezado y el resumen completo.');
    }
    return $datos;
}

function fechaEstadoTxt(string $valor): string
{
    $fecha = DateTimeImmutable::createFromFormat('!d/m/Y', $valor);
    if (!$fecha || $fecha->format('d/m/Y') !== $valor) {
        throw new InvalidArgumentException('El archivo contiene una fecha inválida.');
    }
    return $fecha->format('Y-m-d');
}

function importeEstadoTxt(string $valor): string
{
    if (!preg_match('/^-?(?:\d+|\d{1,3}(?:,\d{3})+)\.\d{2}$/', $valor)) {
        throw new InvalidArgumentException('El archivo contiene un importe inválido.');
    }
    // Conservar los decimales como texto evita redondeos de float.
    return str_replace(',', '', $valor);
}
