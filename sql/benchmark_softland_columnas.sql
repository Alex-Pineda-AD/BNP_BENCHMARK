-- Columnas que necesita la sincronización con Softland (benchmark_levantamientos.php)
-- y el guardado por fila (benchmark_guardar_fila_controller.php).
-- Idempotente: se puede ejecutar más de una vez.

ALTER TABLE benchmark_productos
    ADD COLUMN IF NOT EXISTS categoria varchar(100),
    ADD COLUMN IF NOT EXISTS gramaje varchar(100),
    ADD COLUMN IF NOT EXISTS fecha_actualizacion timestamp(6);

-- Requerido por INSERT ... ON CONFLICT (codigo)
CREATE UNIQUE INDEX IF NOT EXISTS benchmark_productos_codigo_key
    ON benchmark_productos (codigo);

-- Precio propio al momento del levantamiento (snapshot)
ALTER TABLE benchmark_precios_detalle
    ADD COLUMN IF NOT EXISTS precio_banasupro_referencia numeric(10,2);
