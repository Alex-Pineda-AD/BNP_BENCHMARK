-- Índices para acelerar la tabla comparativa, el análisis y los exportes.
-- Idempotente (IF NOT EXISTS). Solo crea índices; no cambia datos.
CREATE INDEX IF NOT EXISTS idx_bm_detalle_producto     ON benchmark.benchmark_precios_detalle (id_producto);
CREATE INDEX IF NOT EXISTS idx_bm_detalle_levantamiento ON benchmark.benchmark_precios_detalle (id_levantamiento);
CREATE INDEX IF NOT EXISTS idx_bm_lev_competidor_fecha  ON benchmark.benchmark_levantamientos (id_competidor, fecha_levantamiento DESC);
CREATE INDEX IF NOT EXISTS idx_bm_prod_familia          ON benchmark.benchmark_productos (id_familia) WHERE estado = 1;
ANALYZE benchmark.benchmark_precios_detalle;
ANALYZE benchmark.benchmark_levantamientos;
ANALYZE benchmark.benchmark_productos;
