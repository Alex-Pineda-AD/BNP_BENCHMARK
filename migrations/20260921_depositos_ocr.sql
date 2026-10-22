-- MariaDB 11.2. Run once; the added columns are idempotent.
ALTER TABLE depositos ENGINE=InnoDB;
ALTER TABLE depositos_detalle ENGINE=InnoDB;
ALTER TABLE depositos
    MODIFY MONTO DECIMAL(12,2) NOT NULL,
    ADD COLUMN IF NOT EXISTS REMITENTE VARCHAR(150) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS BENEFICIARIO VARCHAR(150) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS MONEDA CHAR(3) NOT NULL DEFAULT 'HNL';
-- Prevent duplicate bank references even under concurrent requests.
CREATE UNIQUE INDEX IF NOT EXISTS uq_deposito_banco_referencia ON depositos (ID_BANCO, NUMERO_REFERENCIA);
