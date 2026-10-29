-- gramaje traía Clasificacion3 de Softland (subcategoría), no unidades de medida.
-- Requiere haber ejecutado antes benchmark_normalizacion.sql.

ALTER TABLE benchmark_productos DROP COLUMN IF EXISTS gramaje;
