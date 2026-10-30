<?php
// Auditoría de Benchmark: le dice a PostgreSQL qué usuario está escribiendo.
// Los triggers del esquema benchmark leen estas variables de sesión para llenar
// creado_por / modificado_por y la tabla benchmark.benchmark_auditoria.
function benchmark_auditoria_contexto(PDO $pdo, string $origen = 'web'): void
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT set_config('benchmark.id_usuario', ?, false),
                                  set_config('benchmark.origen', ?, false),
                                  set_config('benchmark.ip', ?, false)");
    $stmt->execute([$uid > 0 ? (string)$uid : '', $origen, $_SERVER['REMOTE_ADDR'] ?? '']);
    $stmt->closeCursor();
}
