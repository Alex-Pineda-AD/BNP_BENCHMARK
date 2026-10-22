<?php
function administrarAlertas(PDO $pdo, int $usuario): bool
{
    $consulta = $pdo->prepare('SELECT ID FROM usuarios WHERE ID = ? AND ESTADO = 1 AND ID_ROL IN (4,5)');
    $consulta->execute([$usuario]);
    return (bool) $consulta->fetchColumn();
}

// Editar una regla no cambia los estados ni las fechas de los depositos.
function guardarAlerta(PDO $pdo, array $datos, int $usuario): void
{
    if (!administrarAlertas($pdo, $usuario)) throw new InvalidArgumentException('No tenés permiso para configurar alertas.');
    $pantalla = $datos['pantalla'] ?? '';
    $estado = filter_var($datos['idEstado'] ?? null, FILTER_VALIDATE_INT);
    $dias = filter_var($datos['plazoDias'] ?? null, FILTER_VALIDATE_INT);
    $activo = $datos['activo'] ?? '0';
    $mensaje = $datos['mensaje'] ?? '';
    if (!in_array($pantalla, ['DEPOSITOS','VALIDACION'], true) || !$estado) throw new InvalidArgumentException('Seleccioná pantalla y estado.');
    if (!$dias || $dias < 1 || $dias > 3650) throw new InvalidArgumentException('Ingresá un plazo entre 1 y 3650 días hábiles.');
    if (!in_array($activo, ['0','1'], true) || !is_string($mensaje) || trim($mensaje) === '' || mb_strlen($mensaje) > 250) throw new InvalidArgumentException('Revisá el mensaje y la activación de la alerta.');
    $consulta = $pdo->prepare('SELECT ID FROM estados WHERE ID = ?');
    $consulta->execute([$estado]);
    if (!$consulta->fetchColumn()) throw new InvalidArgumentException('El estado no existe.');
    $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Tegucigalpa')))->format('Y-m-d H:i:s');
    $guardar = $pdo->prepare('INSERT INTO alertas_configuracion
        (PANTALLA, ID_ESTADO, PLAZO_DIAS, MENSAJE, ESTADO, USUARIO_MODIFICO, FECHA_MODIFICO)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (PANTALLA, ID_ESTADO) DO UPDATE SET PLAZO_DIAS=EXCLUDED.PLAZO_DIAS, MENSAJE=EXCLUDED.MENSAJE,
        ESTADO=EXCLUDED.ESTADO, USUARIO_MODIFICO=EXCLUDED.USUARIO_MODIFICO, FECHA_MODIFICO=EXCLUDED.FECHA_MODIFICO');
    $guardar->execute([$pantalla, $estado, $dias, trim($mensaje), $activo, $usuario, $fecha]);
}

function vencimientoAlertaDeposito(string $fecha, int $dias): DateTimeImmutable
{
    $limite = new DateTimeImmutable($fecha, new DateTimeZone('America/Tegucigalpa'));
    // El dia de entrada no consume un dia de plazo; se conserva la hora original.
    while ($dias > 0) {
        $limite = $limite->modify('+1 day');
        if ((int) $limite->format('N') <= 5) $dias--;
    }
    return $limite;
}

function rolAlertasDepositos(PDO $pdo, string $pantalla, int $usuario): int
{
    $consulta = $pdo->prepare('SELECT ID_ROL FROM usuarios WHERE ID = ? AND ESTADO = 1');
    $consulta->execute([$usuario]);
    $rol = (int) $consulta->fetchColumn();
    $permitidos = $pantalla === 'DEPOSITOS' ? [2,3,4,5] : ($pantalla === 'VALIDACION' ? [2,4,5] : []);
    return in_array($rol, $permitidos, true) ? $rol : 0;
}

function consultarAlertasDepositos(PDO $pdo, string $pantalla, int $usuario, ?DateTimeImmutable $ahora = null): array
{
    $rol = rolAlertasDepositos($pdo, $pantalla, $usuario);
    if (!$rol) return [];
    // Solo una ENTRADA a otro estado reinicia el plazo. Una observacion o edicion
    // dentro del mismo estado no debe ocultar un atraso.
    $sql = 'SELECT D.ID, D.CODIGO, E.DESCRIPCION ESTADO_NOMBRE,
        C.CENTRO_VENTA, R.MENSAJE, R.PLAZO_DIAS,
        CASE WHEN H.ESTADO_NUEVO = D.ESTADO THEN H.FECHA_CREO
             WHEN H.ID IS NULL AND D.ESTADO = 2 THEN D.FECHA_CREO
             ELSE NULL END AS INICIO_ETAPA
        FROM depositos D
        INNER JOIN alertas_configuracion R ON R.ID_ESTADO = D.ESTADO AND R.ESTADO = 1 AND R.PANTALLA = ?
        LEFT JOIN estados E ON E.ID = D.ESTADO
        LEFT JOIN centro_de_venta C ON C.ID = D.ID_CENTRO_VENTA
        LEFT JOIN depositos_bitacora H ON H.ID = (
            SELECT MAX(B.ID) FROM depositos_bitacora B WHERE B.ID_DEPOSITO = D.ID
            AND (B.ESTADO_ANTERIOR IS NULL OR B.ESTADO_ANTERIOR <> B.ESTADO_NUEVO))
        WHERE 1 = 1';
    $parametros = [$pantalla];
    // En Mis depositos, ventas/contabilidad ven solo sus propios registros.
    if ($pantalla === 'DEPOSITOS' && !in_array($rol, [4,5], true)) {
        $sql .= ' AND D.USUARIO_CREO = ?';
        $parametros[] = $usuario;
    }
    $consulta = $pdo->prepare($sql . ' ORDER BY D.ID DESC');
    $consulta->execute($parametros);
    $ahora = $ahora ?? new DateTimeImmutable('now', new DateTimeZone('America/Tegucigalpa'));
    $alertas = [];
    foreach ($consulta->fetchAll() as $fila) {
        if (!$fila['INICIO_ETAPA']) continue; // No inventar fechas de etapas sin historial.
        $limite = vencimientoAlertaDeposito($fila['INICIO_ETAPA'], (int) $fila['PLAZO_DIAS']);
        if ($ahora >= $limite) {
            $fila['VENCIMIENTO'] = $limite->format('Y-m-d H:i:s');
            $alertas[] = $fila;
        }
    }
    return $alertas;
}
