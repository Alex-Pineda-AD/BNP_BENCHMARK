<?php
require_once __DIR__ . '/deposito_service.php';
require_once __DIR__ . '/deposito_ciclo.php';

// El controlador abre y confirma la transaccion junto con el archivo de la foto.
function editarDeposito(PDO $pdo, int $id, array $datos, int $usuario, ?string $foto, ?string $url): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Se requiere una transacción.');
    validarCentroVentaUsuario($pdo, $usuario, $datos['ID_CENTRO_VENTA']);
    // Mismo bloqueo que el registro: evita referencias duplicadas entre solicitudes.
    $banco = $pdo->prepare('SELECT ID FROM bancos WHERE ID = ? AND ESTADO = 1 FOR UPDATE');
    $banco->execute([$datos['ID_BANCO']]);
    if (!$banco->fetchColumn()) throw new InvalidArgumentException('El banco no está disponible.');
    $consulta = $pdo->prepare('SELECT * FROM depositos WHERE ID = ? AND USUARIO_CREO = ? FOR UPDATE');
    $consulta->execute([$id, $usuario]);
    $anterior = $consulta->fetch();
    if (!$anterior) throw new InvalidArgumentException('El depósito no existe o no te pertenece.');
    if (!in_array((int)$anterior['ESTADO'],[2,4,7],true) || depositoTieneAprobacionVigente($pdo,$id)) {
        throw new InvalidArgumentException('El depósito ya fue aprobado o cerrado y no se puede editar.');
    }
    $referencia = strtoupper(trim($datos['NUMERO_REFERENCIA'] ?? ''));
    $datos['NUMERO_REFERENCIA'] = $referencia === '' ? null : $referencia;
    // Un duplicado antiguo no impide corregir la foto o los demas datos.
    // Solo comprobamos duplicados si cambia el banco o la referencia.
    $cambioReferencia = $referencia !== strtoupper(trim($anterior['NUMERO_REFERENCIA'] ?? ''));
    $cambioBanco = (int) $datos['ID_BANCO'] !== (int) $anterior['ID_BANCO'];
    if ($datos['NUMERO_REFERENCIA'] !== null && ($cambioReferencia || $cambioBanco)) {
        $duplicado = $pdo->prepare('SELECT ID FROM depositos WHERE ID_BANCO = ?
            AND UPPER(TRIM(NUMERO_REFERENCIA)) = ? AND ID != ? LIMIT 1 FOR UPDATE');
        $duplicado->execute([$datos['ID_BANCO'], $datos['NUMERO_REFERENCIA'], $id]);
        if ($duplicado->fetchColumn()) throw new InvalidArgumentException('La referencia o número de documento ya está registrado en otro depósito de este banco.');
    }
    $fecha = $datos['FECHA_CREO'];
    $cambios = [];
    foreach (['NUMERO_CUENTA','NUMERO_REFERENCIA','NUMERO_AUTORIZACION','ID_BANCO','ID_CENTRO_VENTA','MONTO','COMENTARIO','REMITENTE','BENEFICIARIO','MONEDA'] as $campo) {
        if ((string) $anterior[$campo] !== (string) $datos[$campo]) $cambios[] = $campo;
    }
    $detalle = $pdo->prepare('SELECT ID_DEPOSITO_DETALLE FROM depositos_detalle WHERE ID_DEPOSITO = ? ORDER BY ID_DEPOSITO_DETALLE DESC LIMIT 1');
    $detalle->execute([$id]);
    $idDetalle = $detalle->fetchColumn() ?: null;
    if ($foto !== null) {
        // La foto anterior no se reemplaza ni se elimina: queda en el historial.
        $detalle = $pdo->prepare('INSERT INTO depositos_detalle
            (ID_DEPOSITO, FOTO_DEPOSITO, URL, TIPO_CAPTURA, OBSERVACION, USUARIO_CREO, FECHA_CREO)
            VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING ID_DEPOSITO_DETALLE');
        $detalle->execute([$id, $foto, $url, 'manual', 'Foto corregida por el usuario.', $usuario, $fecha]);
        $idDetalle = (string) $detalle->fetchColumn();
        $cambios[] = 'FOTO';
    }
    if (!$idDetalle) throw new InvalidArgumentException('Cargá una foto para este depósito.');
    $actualizar = $pdo->prepare('UPDATE depositos SET NUMERO_CUENTA = ?, NUMERO_REFERENCIA = ?,
        NUMERO_AUTORIZACION = ?, ID_BANCO = ?, ID_CENTRO_VENTA = ?, MONTO = ?, COMENTARIO = ?,
        REMITENTE = ?, BENEFICIARIO = ?, MONEDA = ?, ESTADO = 2, USUARIO_MODIFICO = ?, FECHA_MODIFICO = ?
        WHERE ID = ?');
    $actualizar->execute([$datos['NUMERO_CUENTA'], $datos['NUMERO_REFERENCIA'], $datos['NUMERO_AUTORIZACION'],
        $datos['ID_BANCO'], $datos['ID_CENTRO_VENTA'], $datos['MONTO'], $datos['COMENTARIO'],
        $datos['REMITENTE'], $datos['BENEFICIARIO'], $datos['MONEDA'], $usuario, $fecha, $id]);
    $bitacora = $pdo->prepare('INSERT INTO depositos_bitacora
        (ID_DEPOSITO, ID_DEPOSITO_DETALLE, ACCION, ESTADO_ANTERIOR, ESTADO_NUEVO, OBSERVACION, USUARIO_CREO, FECHA_CREO)
        VALUES (?, ?, ?, ?, 2, ?, ?, ?)');
    $bitacora->execute([$id, $idDetalle, (int) $anterior['ESTADO'] === 7 ? 'REENVIO' : 'EDICION',
        $anterior['ESTADO'], $cambios ? 'Campos modificados: ' . implode(', ', $cambios) : 'Datos confirmados sin cambios.', $usuario, $fecha]);
}
