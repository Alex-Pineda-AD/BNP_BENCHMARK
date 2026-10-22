<?php
// A supervised correction starts a new review cycle; older approvals remain in history.
function depositoAprobacionVigenteSql(string $id): string {
    return "EXISTS(SELECT 1 FROM depositos_bitacora H WHERE H.ID_DEPOSITO = $id AND H.ESTADO_NUEVO IN (5,8)
        AND H.ID > COALESCE((SELECT MAX(R.ID) FROM depositos_bitacora R WHERE R.ID_DEPOSITO = $id
        AND R.ACCION = 'SOLICITUD_CORRECCION' AND R.ESTADO_ANTERIOR IN (5,8) AND R.ESTADO_NUEVO = 7),0))";
}
function depositoTieneAprobacionVigente(PDO $pdo,int $id): bool {
    $q=$pdo->prepare('SELECT '.depositoAprobacionVigenteSql('?'));
    $q->execute([$id,$id]); return (bool)$q->fetchColumn();
}
