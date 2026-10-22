-- PostgreSQL: aplicar solo si faltan estos cambios en la base existente.
-- PostgreSQL. Run once; the added columns are idempotent.
ALTER TABLE depositos
    ALTER COLUMN MONTO TYPE DECIMAL(12,2),
    ALTER COLUMN MONTO SET NOT NULL,
    ADD COLUMN IF NOT EXISTS REMITENTE VARCHAR(150) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS BENEFICIARIO VARCHAR(150) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS MONEDA CHAR(3) NOT NULL DEFAULT 'HNL';
-- Prevent duplicate bank references even under concurrent requests.
CREATE UNIQUE INDEX IF NOT EXISTS uq_deposito_banco_referencia ON depositos (ID_BANCO, NUMERO_REFERENCIA);
