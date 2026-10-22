-- PostgreSQL: aplicar solo si faltan estos cambios en la base existente.
-- Un contador por anio; PostgreSQL serializa los guardados del mismo anio.
CREATE TABLE IF NOT EXISTS depositos_correlativos (
    ANIO SMALLINT  NOT NULL,
    ULTIMO INT  NOT NULL DEFAULT 0,
    PRIMARY KEY (ANIO)
);

ALTER TABLE depositos ADD COLUMN IF NOT EXISTS CODIGO VARCHAR(20) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_depositos_codigo ON depositos (CODIGO);

-- Si ya existen codigos, continuar desde el mayor sin renumerarlos.
INSERT INTO depositos_correlativos (ANIO, ULTIMO)
SELECT CAST(SUBSTRING(CODIGO, 9, 4) AS INTEGER),
       MAX(CAST(RIGHT(CODIGO, 7) AS INTEGER))
FROM depositos
WHERE CODIGO ~ '^BNP-VTA-[0-9]{4}-[0-9]{7}$'
GROUP BY SUBSTRING(CODIGO, 9, 4)
ON CONFLICT (ANIO) DO UPDATE SET ULTIMO = GREATEST(depositos_correlativos.ULTIMO, EXCLUDED.ULTIMO);
