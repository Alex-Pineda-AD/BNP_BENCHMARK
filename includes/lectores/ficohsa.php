<?php
require_once __DIR__ . '/comun.php';

function leerFicohsa(string $texto): array
{
    $texto = textoBancarioUtf8($texto);
    if (strpos($texto, 'Titular,Cuenta #,Moneda,Fecha del reporte,') !== 0) {
        throw new InvalidArgumentException('El archivo no tiene el encabezado esperado de Ficohsa.');
    }
    $datos = nuevoEstadoBancario();
    // fgetcsv respeta las comas entre comillas y las descripciones multilínea.
    $archivo = fopen('php://temp', 'r+');
    fwrite($archivo, $texto);
    rewind($archivo);
    $registro = 0;
    $detalle = false;
    try {
        while (($c = fgetcsv($archivo, 0, ',', '"', '')) !== false) {
            $registro++;
            $c = array_map('trim', array_map('strval', $c));
            if (count($c) === 1 && $c[0] === '') {
                continue;
            }
            if ($registro === 1) {
                continue; // Los títulos fueron comprobados al inicio.
            }
            if ($registro === 2) {
                revisarColumnas($c, 5, $registro);
                $datos['cuenta']['NOMBRE'] = $c[0];
                $datos['cuenta']['NUMERO_CUENTA'] = $c[1];
                $datos['cuenta']['MONEDA'] = monedaBancaria($c[2]);
                $datos['cuenta']['FECHA'] = fechaBancaria($c[3], 'd/m/Y');
                continue;
            }
            if (!$detalle && $c[0] === 'Período') {
                continue;
            }
            if (!$detalle && $c[0] === 'Desde:') {
                revisarColumnas($c, 4, $registro);
                if ($c[2] !== 'Hasta:') {
                    throw new InvalidArgumentException('Período inválido en Ficohsa.');
                }
                $datos['cuenta']['PERIODO_DESDE'] = fechaBancaria($c[1], 'd/m/Y');
                $datos['cuenta']['PERIODO_HASTA'] = fechaBancaria($c[3], 'd/m/Y');
                continue;
            }
            if (!$detalle && array_slice($c, 0, 7) === ['Fecha', 'Descripción', 'Número de referencia', 'N° Cheque', 'Débito', 'Crédito', 'Balance']) {
                $detalle = true;
                continue;
            }
            if (!$detalle) {
                throw new InvalidArgumentException("Encabezado desconocido en el registro $registro de Ficohsa.");
            }
            // El exportador puede agregar una columna vacía al final.
            if (count($c) === 8 && $c[7] === '') {
                array_pop($c);
            }
            revisarColumnas($c, 7, $registro);
            // En este formato, débito/crédito vacío significa que no hubo ese movimiento.
            $fila = movimientoBancario(fechaBancaria($c[0], 'd/m/Y'), $c[2], null,
                $c[1], $c[4] === '' ? '0.00' : $c[4], $c[5] === '' ? '0.00' : $c[5], $c[6]);
            $fila['NUMERO_CHEQUE'] = $c[3] === '' ? null : $c[3];
            $datos['transacciones'][] = $fila;
        }
    } finally {
        fclose($archivo);
    }
    if (!$detalle || !$datos['cuenta']['PERIODO_DESDE'] || !$datos['cuenta']['PERIODO_HASTA']) {
        throw new InvalidArgumentException('Falta el detalle o el período de Ficohsa.');
    }
    return revisarEstadoBancario($datos);
}
