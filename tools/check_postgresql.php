<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/database.php';
try {
    $pdo = database();
    $tables = ['usuarios', 'roles', 'bancos', 'estados', 'centro_de_venta',
        'categoria', 'ubicacion', 'region', 'departamentos', 'usuarios_centros_venta',
        'depositos', 'depositos_detalle', 'depositos_correlativos', 'depositos_bitacora',
        'alertas_configuracion', 'estados_cuenta', 'transacciones_estado_cuenta', 'resumen_estado_cuenta'];
    $missing = [];
    $query = $pdo->prepare('SELECT to_regclass(?)');
    foreach ($tables as $table) {
        $query->execute([$table]);
        if (!$query->fetchColumn()) $missing[] = $table;
    }
    $columns = $pdo->query('SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = current_schema()')->fetchAll();
    $quoted = array_filter($columns, fn($row) => $row['COLUMN_NAME'] !== strtolower($row['COLUMN_NAME']));
    echo "Conexión PostgreSQL correcta.\n";
    if ($missing) echo 'Tablas faltantes: ' . implode(', ', $missing) . "\n";
    if ($quoted) echo "Hay columnas con mayúsculas entre comillas; requieren ajustar los identificadores SQL antes de usar la aplicación.\n";
    exit($missing || $quoted ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo verificar PostgreSQL: ' . $e->getMessage() . "\n");
    exit(1);
}
