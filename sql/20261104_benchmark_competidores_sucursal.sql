-- =====================================================================
-- Benchmark: competidores con sucursal (04/11/2026)
-- Una misma cadena (La Colonia, Walmart...) puede tener varias tiendas:
-- nombre = cadena, sucursal = punto de venta. Se muestra como "Cadena – Sucursal".
-- Idempotente. No toca usuarios ni roles. No borra ni cambia datos existentes.
-- =====================================================================

ALTER TABLE benchmark.benchmark_competidores
    ADD COLUMN IF NOT EXISTS sucursal varchar(100);

-- Evita registrar dos veces la misma cadena con la misma sucursal
CREATE UNIQUE INDEX IF NOT EXISTS ux_benchmark_competidores_cadena_sucursal
    ON benchmark.benchmark_competidores (lower(nombre), lower(COALESCE(TRIM(sucursal), '')));

-- Revisión: debe mostrar los competidores como se verán en pantalla
SELECT id, nombre, sucursal,
       nombre || COALESCE(' – ' || NULLIF(TRIM(sucursal), ''), '') AS nombre_completo, estado
FROM benchmark.benchmark_competidores
ORDER BY nombre, sucursal;
