<?php

// Esta función es la única que inserta datos, sin importar el banco.
// El controlador ya comprobó el usuario, el banco y el archivo.
function guardarEstadoCuenta(PDO $pdo, int $usuarioId, int $bancoId,
    string $nombreArchivo, string $original, array $datos): string
{
    $pdo->beginTransaction();
    try {
        // 1. Guardar la cuenta y el TXT original, sin modificar sus bytes.
        $consulta = $pdo->prepare('INSERT INTO estados_cuenta (
            ID_USUARIO, ID_BANCO, NOMBRE_ARCHIVO, HASH_ARCHIVO, ARCHIVO_ORIGINAL,
            NUMERO_CLIENTE, NOMBRE, PRODUCTO, MONEDA, SALDO_INICIAL, SALDO_LIBROS,
            RETENIDOS_DIFERIDOS, SALDO_DISPONIBLE, FECHA, STBGAV, STBUNC,
            MENSAJE_1, MENSAJE_2, MENSAJE_3, MENSAJE_4, MENSAJE_5, MENSAJE_6,
            NUMERO_CUENTA, IBAN, TIPO_PRODUCTO, PERIODO_DESDE, PERIODO_HASTA, SALDO_FINAL
        ) VALUES (
            :ID_USUARIO, :ID_BANCO, :NOMBRE_ARCHIVO, :HASH_ARCHIVO, :ARCHIVO_ORIGINAL,
            :NUMERO_CLIENTE, :NOMBRE, :PRODUCTO, :MONEDA, :SALDO_INICIAL, :SALDO_LIBROS,
            :RETENIDOS_DIFERIDOS, :SALDO_DISPONIBLE, :FECHA, :STBGAV, :STBUNC,
            :MENSAJE_1, :MENSAJE_2, :MENSAJE_3, :MENSAJE_4, :MENSAJE_5, :MENSAJE_6,
            :NUMERO_CUENTA, :IBAN, :TIPO_PRODUCTO, :PERIODO_DESDE, :PERIODO_HASTA, :SALDO_FINAL
        ) RETURNING ID');
        $cuenta = $datos['cuenta'];
        $cuenta['ID_USUARIO'] = $usuarioId;
        $cuenta['ID_BANCO'] = $bancoId;
        $cuenta['NOMBRE_ARCHIVO'] = $nombreArchivo;
        $cuenta['HASH_ARCHIVO'] = hash('sha256', $original);
        $cuenta['ARCHIVO_ORIGINAL'] = $original;
        foreach ($cuenta as $campo => $valor) {
            $tipo = $campo === 'ARCHIVO_ORIGINAL' ? PDO::PARAM_LOB
                : ($valor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $consulta->bindValue(':' . $campo, $valor, $tipo);
        }
        $consulta->execute();
        $id = (string) $consulta->fetchColumn();

        // 2. Cada movimiento queda relacionado con el ID recién generado.
        // CI EN TA es el nombre existente en la tabla, no un campo nuevo.
        $consulta = $pdo->prepare('INSERT INTO transacciones_estado_cuenta (
            ID_ESTADO_CIENTA, FECHA, REFERENCIA, CODIGO, DESCRIPCION,
            DEBITO, CREDITO, BALANCE, HORA, AGENCIA, CAJERO, NUMERO_CHEQUE
        ) VALUES (
            :ID_ESTADO_CIENTA, :FECHA, :REFERENCIA, :CODIGO, :DESCRIPCION,
            :DEBITO, :CREDITO, :BALANCE, :HORA, :AGENCIA, :CAJERO, :NUMERO_CHEQUE
        )');
        foreach ($datos['transacciones'] as $fila) {
            $fila['ID_ESTADO_CIENTA'] = $id;
            $consulta->execute($fila);
        }

        // 3. Guardar el resumen declarado por el banco; no recalcularlo aquí.
        $consulta = $pdo->prepare('INSERT INTO resumen_estado_cuenta (
            ID_ESTADO_CUENTA, CODIGO, CANTIDAD_DEBITOS, MONTO_DEBITOS,
            CANTIDAD_CREDITOS, MONTO_CREDITOS, CONCEPTO, CANTIDAD, MONTO
        ) VALUES (
            :ID_ESTADO_CUENTA, :CODIGO, :CANTIDAD_DEBITOS, :MONTO_DEBITOS,
            :CANTIDAD_CREDITOS, :MONTO_CREDITOS, :CONCEPTO, :CANTIDAD, :MONTO
        )');
        foreach ($datos['resumen'] as $fila) {
            $fila['ID_ESTADO_CUENTA'] = $id;
            $consulta->execute($fila);
        }
        $pdo->commit(); // Confirmar solo cuando las tres partes estén guardadas.
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack(); // Nunca dejar una cuenta guardada a medias.
        }
        throw $e; // El controlador se encarga del mensaje al usuario.
    }
}
