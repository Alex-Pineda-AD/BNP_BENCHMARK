<?php
session_start();

require_once __DIR__ . '/../../connection/database.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Debes iniciar sesión.');
}

# RECIBOR POR URL EL ID DEL BANCO
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('El ID del estado de cuenta no es válido.');
}

$pdo = database();

$consulta = $pdo->prepare('
    SELECT
        A.ID,
        A.NUMERO_CUENTA,
        A.NOMBRE,
        A.MONEDA,
        A.FECHA,
        A.PERIODO_DESDE,
        A.PERIODO_HASTA,
        A.SALDO_INICIAL,
        A.SALDO_FINAL,
        B.DESCRIPCION AS BANCO
    FROM estados_cuenta AS A
    LEFT JOIN bancos AS B ON B.ID = A.ID_BANCO
    WHERE A.ID = :id
');

$consulta->execute(['id' => $id]);
$estado = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$estado) {
    http_response_code(404);
    exit('No se encontró el estado de cuenta.');
}

// 3. Consultar los movimientos relacionados con ese estado.
// ID_ESTADO_CIENTA es el nombre actual de tu columna.
$consulta = $pdo->prepare('
    SELECT
        FECHA,
        REFERENCIA,
        CODIGO,
        DESCRIPCION,
        TO_CHAR(DEBITO, \'FM999,999,999,999,999,990.00\') DEBITO,
        TO_CHAR(CREDITO, \'FM999,999,999,999,999,990.00\') CREDITO,
        TO_CHAR(BALANCE, \'FM999,999,999,999,999,990.00\') BALANCE
    FROM transacciones_estado_cuenta
    WHERE ID_ESTADO_CIENTA = :id
    ORDER BY ID ASC
');

$consulta->execute(['id' => $id]);

// Escapar cualquier dato que se vaya a mostrar como HTML.
function escapar($valor)
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
?>

<h1>Estado de cuenta #<?= escapar($estado['ID']) ?></h1>

<p><strong>Banco:</strong> <?= escapar($estado['BANCO']) ?></p>
<p><strong>Cuenta:</strong> <?= escapar($estado['NUMERO_CUENTA']) ?></p>
<p><strong>Titular:</strong> <?= escapar($estado['NOMBRE']) ?></p>
<p><strong>Moneda:</strong> <?= escapar($estado['MONEDA']) ?></p>

<p>
    <strong>Saldo inicial:</strong>
    <?= escapar($estado['SALDO_INICIAL'] ?? 'No informado') ?>
</p>

<p>
    <strong>Saldo final:</strong>
    <?= escapar($estado['SALDO_FINAL'] ?? 'No informado') ?>
</p>

<table border="1" cellpadding="8">
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Referencia</th>
            <th>Código</th>
            <th>Descripción</th>
            <th>Débito</th>
            <th>Crédito</th>
            <th>Saldo</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($consulta as $movimiento): ?>
            <tr>
                <td><?= escapar($movimiento['FECHA']) ?></td>
                <td><?= escapar($movimiento['REFERENCIA']) ?></td>
                <td><?= escapar($movimiento['CODIGO']) ?></td>
                <td><?= escapar($movimiento['DESCRIPCION']) ?></td>
                <td><?= escapar($movimiento['DEBITO']) ?></td>
                <td><?= escapar($movimiento['CREDITO']) ?></td>
                <td><?= escapar($movimiento['BALANCE']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>