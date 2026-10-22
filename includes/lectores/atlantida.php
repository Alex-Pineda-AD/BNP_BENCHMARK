<?php
require_once __DIR__ . '/comun.php';

function leerAtlantida(string $texto): array
{
    $texto = textoBancarioUtf8($texto);
    if (strpos($texto, 'CUENTA|') !== 0 || strpos($texto, '|CUENTA IBAN|') === false) {
        throw new InvalidArgumentException('El archivo no tiene el encabezado esperado de Atlántida.');
    }
    $datos = nuevoEstadoBancario();
    $detalle = false;
    foreach (preg_split('/\r\n|\n|\r/', $texto) as $indice => $linea) {
        $linea = trim($linea);
        if ($linea === '' || strpos($linea, 'BANCO ATLANTIDA ©') === 0) {
            continue;
        }
        $c = array_map('trim', explode('|', $linea));
        if ($c[0] === 'CUENTA' && !$detalle) {
            revisarColumnas($c, 5, $indice + 1);
            $datos['cuenta']['NUMERO_CUENTA'] = $c[1];
            $datos['cuenta']['TIPO_PRODUCTO'] = $c[2];
            $datos['cuenta']['IBAN'] = $c[4];
        } elseif ($c[0] === 'CLIENTE' && !$detalle) {
            revisarColumnas($c, 2, $indice + 1);
            $datos['cuenta']['NOMBRE'] = $c[1];
        } elseif ($linea === 'NUMERO|FECHA|TIPO|DESCRIPCION|REFERENCIA|MONEDA|DEBITOS|CREDITOS|SALDO') {
            $detalle = true;
        } else {
            if (!$detalle) {
                throw new InvalidArgumentException('No se reconoce el encabezado de Atlántida.');
            }
            revisarColumnas($c, 9, $indice + 1);
            // El total no tiene fecha ni referencia: es un resumen, no un movimiento.
            // Algunos archivos cortos no incluyen esta fila; por eso es opcional.
            if ($c[0] === '' && $c[1] === '' && $c[2] === ''
                && $c[3] === 'TOTAL MOVIMIENTOS' && $c[4] === '' && $c[5] === '') {
                if ($datos['resumen']) {
                    throw new InvalidArgumentException('El archivo de Atlántida tiene más de una fila de totales.');
                }
                $resumen = resumenBancario();
                $resumen['CODIGO'] = 'Total';
                $resumen['CONCEPTO'] = 'TOTAL MOVIMIENTOS';
                $resumen['MONTO_DEBITOS'] = importeBancario($c[6]);
                $resumen['MONTO_CREDITOS'] = importeBancario($c[7]);
                // El banco no informa cantidades en esta fila: permanecen NULL.
                $datos['resumen'][] = $resumen;
                $datos['cuenta']['SALDO_FINAL'] = importeBancario($c[8]);
                continue;
            }
            if ($datos['resumen']) {
                throw new InvalidArgumentException('Hay filas inesperadas después del total de Atlántida.');
            }
            if ($c[1] === '') {
                throw new InvalidArgumentException('Falta la fecha del movimiento en la línea ' . ($indice + 1) . '.');
            }
            $fecha = fechaBancaria($c[1], 'd/m/y');
            $moneda = monedaBancaria($c[5]);
            if ($datos['cuenta']['MONEDA'] !== null && $datos['cuenta']['MONEDA'] !== $moneda) {
                throw new InvalidArgumentException('El archivo mezcla monedas en una misma cuenta.');
            }
            $datos['cuenta']['MONEDA'] = $moneda;
            // Esta fila informa el saldo de apertura: no es un depósito.
            if ($c[0] === '' && $c[3] === 'Saldo Inicial') {
                $datos['cuenta']['SALDO_INICIAL'] = importeBancario($c[8]);
                continue;
            }
            if (!ctype_digit($c[0])) {
                throw new InvalidArgumentException('Número de movimiento inválido en Atlántida.');
            }
            $datos['transacciones'][] = movimientoBancario($fecha, $c[4], $c[2], $c[3], $c[6], $c[7], $c[8]);
        }
    }
    if (!$detalle || $datos['cuenta']['SALDO_INICIAL'] === null) {
        throw new InvalidArgumentException('Falta el detalle o el saldo inicial de Atlántida.');
    }
    // Si no hay período o resumen en el documento, no los inventamos.
    return revisarEstadoBancario($datos);
}
