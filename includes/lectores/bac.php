<?php
require_once __DIR__ . '/comun.php';
require_once __DIR__ . '/../estado_cuenta_txt.php';

function leerBac(string $texto): array
{
    $texto = textoBancarioUtf8($texto);
    if (strpos($texto, 'Número de Clientes') !== 0) {
        throw new InvalidArgumentException('El archivo no tiene el encabezado esperado de BAC.');
    }
    // Reutilizamos el lector BAC anterior, que ya valida las tres secciones.
    $bac = leerEstadoCuentaTxt($texto);
    $datos = nuevoEstadoBancario();
    foreach ($bac['cuenta'] as $campo => $valor) {
        $nombre = strtoupper($campo);
        if (strpos($campo, 'mensaje') === 0) {
            $nombre = 'MENSAJE_' . substr($campo, -1);
        }
        $datos['cuenta'][$nombre] = $valor;
    }
    // En este formato, Producto contiene el número de la cuenta.
    $datos['cuenta']['NUMERO_CUENTA'] = $bac['cuenta']['producto'];
    $datos['cuenta']['MONEDA'] = monedaBancaria($bac['cuenta']['moneda']);
    foreach ($bac['transacciones'] as $fila) {
        $datos['transacciones'][] = movimientoBancario($fila['fecha'], $fila['referencia'],
            $fila['codigo'], $fila['descripcion'], $fila['debito'], $fila['credito'], $fila['balance']);
    }
    foreach ($bac['resumen'] as $fila) {
        $resumen = resumenBancario();
        foreach ($fila as $campo => $valor) {
            $resumen[strtoupper($campo)] = $valor;
        }
        $datos['resumen'][] = $resumen;
    }
    return revisarEstadoBancario($datos);
}
