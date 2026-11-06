-- =====================================================================
-- Benchmark: base de comparacion por fila (unidad o paquete) - 06/11/2026
-- Idempotente (se puede correr mas de una vez). No toca usuarios ni roles.
--
--   'unidad'  = el competidor captura el precio de UNA unidad; se compara contra
--               PRECIO LISTA / FACTOR_EMPAQUE (precio_referencia), como hasta hoy.
--   'paquete' = el competidor captura el precio del MISMO paquete que Banasupro
--               (PRECIO LISTA de Softland, sin dividir); se compara contra precio_lista.
-- precio_banasupro_referencia siempre queda en la misma base que precio_competencia.
-- Todo lo capturado antes de este cambio era por unidad: queda como 'unidad'.
-- =====================================================================
BEGIN;

ALTER TABLE benchmark.benchmark_precios_detalle
    ADD COLUMN IF NOT EXISTS base_comparacion varchar(10) NOT NULL DEFAULT 'unidad';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'ck_benchmark_detalle_base_comparacion'
          AND conrelid = 'benchmark.benchmark_precios_detalle'::regclass
    ) THEN
        ALTER TABLE benchmark.benchmark_precios_detalle
            ADD CONSTRAINT ck_benchmark_detalle_base_comparacion
            CHECK (base_comparacion IN ('unidad', 'paquete'));
    END IF;
END $$;

COMMIT;

-- Verificacion (solo lectura)
SELECT base_comparacion, count(*) AS filas
FROM benchmark.benchmark_precios_detalle
GROUP BY base_comparacion;
