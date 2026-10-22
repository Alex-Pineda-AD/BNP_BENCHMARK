-- PostgreSQL: aplicar solo si faltan estos cambios en la base existente.
-- NULL preserves historical deposits whose center is unknown.
-- New submissions must supply an authorized center (validated by the server).
ALTER TABLE depositos ADD COLUMN IF NOT EXISTS ID_CENTRO_VENTA DOUBLE PRECISION NULL;
CREATE INDEX IF NOT EXISTS idx_depositos_centro_venta ON depositos (ID_CENTRO_VENTA);
