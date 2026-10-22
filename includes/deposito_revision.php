<?php
require_once __DIR__ . '/deposito_service.php';
require_once __DIR__ . '/deposito_ciclo.php';
// Permite detectar si el deposito cambio mientras el revisor tenia abierto el modal.
function versionDepositoRevision(array $deposito): string
{
    $valores = [];
    foreach (['ID','ESTADO','ID_DEPOSITO_DETALLE','FECHA_MODIFICO','ID_BANCO','ID_CENTRO_VENTA','NUMERO_CUENTA','NUMERO_REFERENCIA','NUMERO_AUTORIZACION','MONTO','MONEDA','REMITENTE','BENEFICIARIO','COMENTARIO'] as $campo) {
        $valores[] = (string) ($deposito[$campo] ?? '');
    }
    return hash('sha256', json_encode($valores));
}

function revisarDeposito(PDO $pdo, int $id, int $estado, string $observacion, int $usuario, string $version, ?array $datos = null): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Se requiere una transacción.');
    if (!in_array($estado, [5, 7, 8], true)) throw new InvalidArgumentException('Seleccioná Aprobado o Por corregir.');
    $observacion = trim($observacion);
    if (mb_strlen($observacion) > 500) throw new InvalidArgumentException('La observación admite hasta 500 caracteres.');
    if ($estado === 7 && $observacion === '') throw new InvalidArgumentException('Indicá qué debe corregirse.');
    $permiso = $pdo->prepare('SELECT ID FROM usuarios WHERE ID = ? AND ESTADO = 1 AND ID_ROL IN (2,4,5)');
    $permiso->execute([$usuario]);
    if (!$permiso->fetchColumn()) throw new InvalidArgumentException('No tenés permiso para revisar depósitos.');
    if ($datos !== null) {
        $datos = validarDeposito($datos);
        // Usar el mismo bloqueo del registro para evitar referencias duplicadas.
        $banco = $pdo->prepare('SELECT ID FROM bancos WHERE ID = ? AND ESTADO = 1 FOR UPDATE');
        $banco->execute([$datos['ID_BANCO']]);
        if (!$banco->fetchColumn()) throw new InvalidArgumentException('El banco no está disponible.');
        $centro = $pdo->prepare('SELECT ID FROM centro_de_venta WHERE ID = ?');
        $centro->execute([$datos['ID_CENTRO_VENTA']]);
        if (!$centro->fetchColumn()) throw new InvalidArgumentException('El centro de venta no existe.');
    }
    $consulta = $pdo->prepare('SELECT * FROM depositos WHERE ID = ? FOR UPDATE');
    $consulta->execute([$id]);
    $deposito = $consulta->fetch();
    if (!$deposito) throw new InvalidArgumentException('El depósito no existe.');
    if (!in_array((int) $deposito['ESTADO'], [2,4,7], true) || depositoTieneAprobacionVigente($pdo,$id)) {
        throw new InvalidArgumentException('El depósito ya no admite revisión. Recargá la pantalla.');
    }
    $detalle = $pdo->prepare('SELECT ID_DEPOSITO_DETALLE FROM depositos_detalle WHERE ID_DEPOSITO = ? ORDER BY ID_DEPOSITO_DETALLE DESC LIMIT 1 FOR UPDATE');
    $detalle->execute([$id]);
    $deposito['ID_DEPOSITO_DETALLE'] = $detalle->fetchColumn();
    if (!$deposito['ID_DEPOSITO_DETALLE']) throw new InvalidArgumentException('El depósito no tiene comprobante para revisar.');
    if (!hash_equals(versionDepositoRevision($deposito), $version)) {
        throw new InvalidArgumentException('El depósito cambió mientras lo revisabas. Recargá y compará nuevamente la foto y los datos.');
    }
    $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Tegucigalpa')))->format('Y-m-d H:i:s');
    $cambios = [];
    if ($datos !== null) {
        $referencia = strtoupper(trim($datos['NUMERO_REFERENCIA'] ?? ''));
        $datos['NUMERO_REFERENCIA'] = $referencia === '' ? null : $referencia;
        if ($referencia !== '' && ($referencia !== strtoupper(trim($deposito['NUMERO_REFERENCIA'] ?? '')) || (int) $datos['ID_BANCO'] !== (int) $deposito['ID_BANCO'])) {
            $duplicado = $pdo->prepare('SELECT ID FROM depositos WHERE ID_BANCO = ? AND UPPER(TRIM(NUMERO_REFERENCIA)) = ? AND ID != ? LIMIT 1 FOR UPDATE');
            $duplicado->execute([$datos['ID_BANCO'], $referencia, $id]);
            if ($duplicado->fetchColumn()) throw new InvalidArgumentException('La referencia o número de documento ya está registrado en otro depósito de este banco.');
        }
        foreach (['NUMERO_CUENTA','NUMERO_REFERENCIA','NUMERO_AUTORIZACION','ID_BANCO','ID_CENTRO_VENTA','MONTO','COMENTARIO','REMITENTE','BENEFICIARIO','MONEDA'] as $campo) {
            if ((string) $deposito[$campo] !== (string) $datos[$campo]) $cambios[] = $campo;
        }
        $editar = $pdo->prepare('UPDATE depositos SET NUMERO_CUENTA = ?, NUMERO_REFERENCIA = ?, NUMERO_AUTORIZACION = ?, ID_BANCO = ?, ID_CENTRO_VENTA = ?, MONTO = ?, COMENTARIO = ?, REMITENTE = ?, BENEFICIARIO = ?, MONEDA = ? WHERE ID = ?');
        $editar->execute([$datos['NUMERO_CUENTA'], $datos['NUMERO_REFERENCIA'], $datos['NUMERO_AUTORIZACION'], $datos['ID_BANCO'], $datos['ID_CENTRO_VENTA'], $datos['MONTO'], $datos['COMENTARIO'], $datos['REMITENTE'], $datos['BENEFICIARIO'], $datos['MONEDA'], $id]);
    }
    $actualizar = $pdo->prepare('UPDATE depositos SET ESTADO = ?, USUARIO_MODIFICO = ?, FECHA_MODIFICO = ? WHERE ID = ?');
    $actualizar->execute([$estado, $usuario, $fecha, $id]);
    $bitacora = $pdo->prepare('INSERT INTO depositos_bitacora
        (ID_DEPOSITO, ID_DEPOSITO_DETALLE, ACCION, ESTADO_ANTERIOR, ESTADO_NUEVO, OBSERVACION, USUARIO_CREO, FECHA_CREO)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if ($cambios) {
        $bitacora->execute([$id, $deposito['ID_DEPOSITO_DETALLE'], 'EDICION', $deposito['ESTADO'], $deposito['ESTADO'], 'Campos modificados por el revisor: ' . implode(', ', $cambios), $usuario, $fecha]);
    }
    $bitacora->execute([$id, $deposito['ID_DEPOSITO_DETALLE'], $estado === 7 ? 'SOLICITUD_CORRECCION' : 'APROBACION',
        $deposito['ESTADO'], $estado, $observacion === '' ? 'Foto y datos verificados por el revisor.' : $observacion, $usuario, $fecha]);
}
