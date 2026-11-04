<?php
// Sincronización programable de Benchmark con Softland (solo línea de comandos).
// Uso:  php benchmark_sincronizar_cli.php
// Se puede programar (Programador de tareas de Windows o cron). Softland solo recibe SELECT.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo línea de comandos.');
}

require_once __DIR__ . '/../../connection/database.php';
require_once __DIR__ . '/../../includes/benchmark_sync_softland.php';

try {
    // Forzado y con registro en cache/: así las pantallas saben que ya está al día
    $r = benchmark_sincronizar_si_toca(database(), 'sync_cli', 0, true);
    if ($r['sin_alcance']) {
        echo date('Y-m-d H:i:s') . ' Sin alcance configurado (benchmark_alcance_categorias).' . PHP_EOL;
        exit(0);
    }
    echo date('Y-m-d H:i:s') . " OK · actualizados: {$r['actualizados']} (con familia: {$r['con_familia']}, sin familia: {$r['sin_familia']}) · sin precio válido en Softland: "
        . count($r['excluidos']) . ' · duplicados ocultos: ' . count($r['duplicados'])
        . ' · reubicados desde categorías comodín: ' . count($r['reubicados']) . PHP_EOL;
    if (!empty($r['excluidos'])) {
        echo 'Códigos sin precio válido: ' . implode(', ', $r['excluidos']) . PHP_EOL;
    }
} catch (Throwable $e) {
    error_log('[benchmark_sincronizar_cli] ' . $e->getMessage());
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
