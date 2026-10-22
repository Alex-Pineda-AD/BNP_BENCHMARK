<?php
require_once __DIR__ . '/centros_venta.php';
// Obligatoria al registrar un deposito; no sustituye la fecha de creacion.
function validarFechaDeposito($valor): string
{
    if (is_string($valor)) {
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $formato) {
            $fecha = DateTimeImmutable::createFromFormat('!' . $formato, $valor, new DateTimeZone('America/Tegucigalpa'));
            if ($fecha && $fecha->format($formato) === $valor && (int) $fecha->format('Y') >= 1000) {
                return $fecha->format('Y-m-d H:i:s');
            }
        }
    }
    throw new InvalidArgumentException('Ingresá una fecha y hora válidas del comprobante.');
}

function validarDeposito(array $input): array
{
    validarTipoCaptura($input);
    $lengths = ['NUMERO_CUENTA' => 50, 'NUMERO_REFERENCIA' => 100, 'NUMERO_AUTORIZACION' => 100, 'REMITENTE' => 150, 'BENEFICIARIO' => 150, 'COMENTARIO' => 500];
    $data = [];
    foreach ($lengths as $field => $max) {
        $value = $input[$field] ?? '';
        if (in_array($field, ['NUMERO_REFERENCIA', 'NUMERO_AUTORIZACION'], true) && is_string($value) && trim($value) === '') { $data[$field] = null; continue; }
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            throw new InvalidArgumentException("Revisá el campo $field (máximo $max caracteres).");
        }
        $data[$field] = trim($value);
    }
    if (!preg_match('/^[0-9]+(?:[-\/][0-9]+)*$/D', $data['NUMERO_CUENTA'])) {
        throw new InvalidArgumentException('La cuenta debe contener números, separados por guiones o barras cuando corresponda.');
    }
    foreach (['NUMERO_REFERENCIA', 'NUMERO_AUTORIZACION'] as $field) {
        if ($data[$field] !== null && !preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/D', $data[$field])) throw new InvalidArgumentException('La referencia y autorización admiten letras, números o guiones.');
    }
    $amount = $input['MONTO'] ?? '';
    if (!is_string($amount) || !preg_match('/^\d{1,16}(\.\d{1,2})?$/D', $amount) || !preg_match('/[1-9]/', $amount)) {
        throw new InvalidArgumentException('Ingresá un monto mayor a cero, con un máximo de dos decimales.');
    }
    $data['MONTO'] = $amount; // Keep the decimal string; do not round through floating point.
    $data['MONEDA'] = $input['MONEDA'] ?? '';
    if (!in_array($data['MONEDA'], ['HNL', 'USD'], true)) {
        throw new InvalidArgumentException('Seleccioná una moneda válida.');
    }
    $bank = filter_var($input['ID_BANCO'] ?? null, FILTER_VALIDATE_INT);
    if (!$bank || $bank < 1) throw new InvalidArgumentException('Seleccioná el banco.');
    $data['ID_BANCO'] = $bank;
    $center = filter_var($input['ID_CENTRO_VENTA'] ?? null, FILTER_VALIDATE_INT);
    if (!$center || $center < 1) throw new InvalidArgumentException('Seleccioná un centro de venta asignado a tu usuario.');
    $data['ID_CENTRO_VENTA'] = $center;
    // Fecha y hora del servidor al registrar, en horario de Honduras.
    // No se aceptan fechas enviadas por el navegador ni obtenidas del OCR.
    $data['FECHA_CREO'] = (new DateTimeImmutable('now', new DateTimeZone('America/Tegucigalpa')))->format('Y-m-d H:i:s');
    if (($input['CONFIRMADO'] ?? '') !== '1') throw new InvalidArgumentException('Confirmá que revisaste los datos de la imagen.');
    return $data;
}

function validarTipoCaptura(array $input): string
{
    $type = $input['TIPO_CAPTURA'] ?? 'ocr';
    if (!in_array($type, ['ocr', 'manual'], true)) throw new InvalidArgumentException('Seleccioná un tipo de comprobante válido.');
    return $type;
}

// El contador se guarda en la misma transaccion que el deposito y su foto.
function generarCodigoDeposito(PDO $pdo, string $fecha): string
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('El correlativo requiere una transacción activa.');
    }
    $anio = (int) substr($fecha, 0, 4);
    // Si dos usuarios guardan a la vez, el segundo espera a que termine el primero.
    $contador = $pdo->prepare('INSERT INTO depositos_correlativos (ANIO, ULTIMO)
        VALUES (?, 1) ON CONFLICT (ANIO) DO UPDATE SET ULTIMO = depositos_correlativos.ULTIMO + 1');
    $contador->execute([$anio]);
    $consulta = $pdo->prepare('SELECT ULTIMO FROM depositos_correlativos WHERE ANIO = ? FOR UPDATE');
    $consulta->execute([$anio]);
    $numero = (int) $consulta->fetchColumn();
    if ($numero > 9999999) {
        throw new RuntimeException('Se agotó el correlativo de depósitos para este año.');
    }
    return 'BNP-VTA-' . $anio . '-' . str_pad((string) $numero, 7, '0', STR_PAD_LEFT);
}

// Caller owns the transaction so both inserts and file persistence are coordinated.
function insertarDeposito(PDO $pdo, array $data, int $userId, string $filename, string $url, string $tipoCaptura = 'manual', ?string $textoOcr = null): string
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('El depósito requiere una transacción activa.');
    }
    $data['FECHA_DEPOSITO'] = validarFechaDeposito($data['FECHA_DEPOSITO'] ?? null);
    validarCentroVentaUsuario($pdo, $userId, $data['ID_CENTRO_VENTA']);
    // Referencia y numero de documento son el mismo campo en el formulario.
    $data['NUMERO_REFERENCIA'] = trim($data['NUMERO_REFERENCIA'] ?? '');
    if ($data['NUMERO_REFERENCIA'] !== '') {
        $data['NUMERO_REFERENCIA'] = strtoupper($data['NUMERO_REFERENCIA']);
        // Bloqueamos el banco hasta terminar el guardado: otra solicitud del mismo
        // banco espera y luego comprueba los depositos que ya fueron confirmados.
        $banco = $pdo->prepare('SELECT ID FROM bancos WHERE ID = ? FOR UPDATE');
        $banco->execute([$data['ID_BANCO']]);
        if (!$banco->fetchColumn()) {
            throw new InvalidArgumentException('El banco seleccionado no existe.');
        }
        $duplicado = $pdo->prepare('SELECT ID, CODIGO FROM depositos
            WHERE ID_BANCO = ? AND UPPER(TRIM(NUMERO_REFERENCIA)) = ?
            ORDER BY ID LIMIT 1 FOR UPDATE');
        $duplicado->execute([$data['ID_BANCO'], $data['NUMERO_REFERENCIA']]);
        $registrado = $duplicado->fetch();
        if ($registrado) {
            $identificador = $registrado['CODIGO'] ?: '#' . $registrado['ID'];
            throw new InvalidArgumentException('La referencia o número de documento '
                . $data['NUMERO_REFERENCIA'] . ' ya está registrado en este banco en el depósito '
                . $identificador . '. No se guardó otro depósito.');
        }
    } else {
        $data['NUMERO_REFERENCIA'] = null;
    }
    $data['CODIGO'] = generarCodigoDeposito($pdo, $data['FECHA_CREO']);
    $sql = 'INSERT INTO depositos (CODIGO, NUMERO_CUENTA, NUMERO_REFERENCIA, NUMERO_AUTORIZACION, ID_BANCO, ID_CENTRO_VENTA, MONTO, COMENTARIO, REMITENTE, BENEFICIARIO, MONEDA, ESTADO, FECHA_DEPOSITO, FECHA_CREO, USUARIO_CREO) VALUES (:CODIGO, :NUMERO_CUENTA, :NUMERO_REFERENCIA, :NUMERO_AUTORIZACION, :ID_BANCO, :ID_CENTRO_VENTA, :MONTO, :COMENTARIO, :REMITENTE, :BENEFICIARIO, :MONEDA, 2, :FECHA_DEPOSITO, :FECHA_CREO, :usuario) RETURNING ID';
    $insertar = $pdo->prepare($sql);
    $insertar->execute($data + ['usuario' => $userId]);
    $id = (string) $insertar->fetchColumn();
    $pdo->prepare('INSERT INTO depositos_detalle (ID_DEPOSITO, FOTO_DEPOSITO, URL, TIPO_CAPTURA, TEXTO_OCR, FECHA_CREO, USUARIO_CREO) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $filename, $url, $tipoCaptura, $textoOcr, $data['FECHA_CREO'], $userId]);
    return $id;
}
