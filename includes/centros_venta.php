<?php
function centrosVentaUsuario(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT DISTINCT cv.ID, cv.CENTRO_VENTA
        FROM usuarios u
        INNER JOIN usuarios_centros_venta ucv ON ucv.ID_USUARIO = u.ID AND ucv.ESTADO = 1
        INNER JOIN centro_de_venta cv ON cv.ID = ucv.ID_CENTRO_VENTA
        WHERE u.ID = ? AND u.ESTADO = 1 ORDER BY cv.CENTRO_VENTA, cv.ID');
    $query->execute([$userId]);
    return $query->fetchAll();
}

function validarCentroVentaUsuario(PDO $pdo, int $userId, int $centerId): void
{
    $query = $pdo->prepare('SELECT 1 FROM usuarios u
        INNER JOIN usuarios_centros_venta ucv ON ucv.ID_USUARIO = u.ID AND ucv.ESTADO = 1
        INNER JOIN centro_de_venta cv ON cv.ID = ucv.ID_CENTRO_VENTA
        WHERE u.ID = ? AND u.ESTADO = 1 AND cv.ID = ? LIMIT 1');
    $query->execute([$userId, $centerId]);
    if (!$query->fetchColumn()) throw new InvalidArgumentException('El centro de venta no está asignado a tu usuario o la asignación está inactiva. Recargá la página y seleccioná un centro autorizado.');
}
