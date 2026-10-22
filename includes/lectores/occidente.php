<?php
require_once __DIR__ . '/comun.php';

function leerOccidente(string $texto): array
{
    $texto = textoBancarioUtf8($texto);
    if (strpos($texto, 'Cuenta|') !== 0 || strpos($texto, 'Fecha|Hora|Agen|Cajero|Trx|Ref|') === false) {
        throw new InvalidArgumentException('El archivo no tiene el encabezado esperado de Occidente.');
    }
    $datos = nuevoEstadoBancario();
    $seccion = 'cuenta';
    foreach (preg_split('/\r\n|\n|\r/', $texto) as $indice => $linea) {
        $linea = trim($linea);
        if ($linea === '') {
            continue;
        }
        $c = array_map('trim', explode('|', $linea));
        if ($linea === 'Fecha|Hora|Agen|Cajero|Trx|Ref|Descripción|Débito|Crédito|Saldo') {
            $seccion = 'detalle';
        } elseif ($linea === 'Resumen de Movimientos del Mes') {
            $seccion = 'resumen';
        } elseif ($seccion === 'cuenta' && $c[0] === 'Cuenta') {
            revisarColumnas($c, 3, $indice + 1);
            $datos['cuenta']['NUMERO_CUENTA'] = $c[1];
            $datos['cuenta']['NOMBRE'] = $c[2];
        } elseif ($seccion === 'cuenta' && $c[0] === 'Producto') {
            revisarColumnas($c, 6, $indice + 1);
            $datos['cuenta']['TIPO_PRODUCTO'] = $c[1];
            $datos['cuenta']['PERIODO_DESDE'] = fechaBancaria($c[3], 'd-m-Y');
            $datos['cuenta']['PERIODO_HASTA'] = fechaBancaria($c[5], 'd-m-Y');
        } elseif ($seccion === 'cuenta' && $c[0] === 'Moneda') {
            revisarColumnas($c, 6, $indice + 1);
            $datos['cuenta']['MONEDA'] = monedaBancaria($c[1]);
            $datos['cuenta']['SALDO_INICIAL'] = importeBancario($c[3]);
            $datos['cuenta']['SALDO_FINAL'] = importeBancario($c[5]);
        } elseif ($seccion === 'detalle') {
            revisarColumnas($c, 10, $indice + 1);
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $c[1])) {
                throw new InvalidArgumentException('Hora inválida en Occidente.');
            }
            $fila = movimientoBancario(fechaBancaria($c[0], 'd-m-Y'), $c[5], $c[4], $c[6], $c[7], $c[8], $c[9]);
            $fila['HORA'] = $c[1];
            $fila['AGENCIA'] = $c[2];
            $fila['CAJERO'] = $c[3];
            $datos['transacciones'][] = $fila;
        } elseif ($seccion === 'resumen') {
            if ($linea === 'Descripción|N° Trans.|Monto') {
                continue;
            }
            revisarColumnas($c, 3, $indice + 1);
            if (!ctype_digit($c[1]) || !in_array($c[0], ['Débitos', 'Créditos', 'Cheques Pagados'], true)) {
                throw new InvalidArgumentException('Resumen desconocido en Occidente.');
            }
            $fila = resumenBancario();
            $fila['CONCEPTO'] = $c[0];
            $fila['CANTIDAD'] = $c[1];
            $fila['MONTO'] = importeBancario($c[2]);
            $datos['resumen'][] = $fila;
        } else {
            throw new InvalidArgumentException('Línea no reconocida en Occidente: ' . ($indice + 1));
        }
    }
    if (count($datos['resumen']) !== 3 || !$datos['cuenta']['PERIODO_DESDE'] || $datos['cuenta']['SALDO_INICIAL'] === null) {
        throw new InvalidArgumentException('Falta el encabezado o el resumen de Occidente.');
    }
    $cantidad = 0;
    foreach ($datos['resumen'] as $fila) {
        // Los cheques pueden estar incluidos en los débitos: no sumarlos otra vez.
        if (in_array($fila['CONCEPTO'], ['Débitos', 'Créditos'], true)) {
            $cantidad += (int) $fila['CANTIDAD'];
        }
    }
    if ($cantidad !== count($datos['transacciones'])) {
        $datos['advertencias'][] = 'El resumen de Occidente declara ' . $cantidad
            . ' movimientos, pero el archivo contiene ' . count($datos['transacciones']) . '. Puede ser un extracto parcial.';
    }
    return revisarEstadoBancario($datos);
}
