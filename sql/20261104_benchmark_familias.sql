-- =====================================================================
-- Benchmark: familias de productos (04/11/2026)
-- Idempotente: se puede correr más de una vez sin romper nada.
-- No toca las tablas usuarios ni roles.
-- Requiere las funciones de auditoría de Benchmark ya creadas en la base
-- (benchmark_esquema_auditoria_completo.sql).
-- =====================================================================

DO $$
BEGIN
    IF to_regprocedure('benchmark.fn_auditoria_columnas()') IS NULL
       OR to_regprocedure('benchmark.fn_auditoria_bitacora()') IS NULL THEN
        RAISE EXCEPTION 'Faltan las funciones de auditoría de Benchmark (fn_auditoria_columnas / fn_auditoria_bitacora). Correr antes benchmark_esquema_auditoria_completo.sql';
    END IF;
END $$;

-- 1) Tabla de familias ------------------------------------------------
CREATE TABLE IF NOT EXISTS benchmark.benchmark_familias (
    id                  serial PRIMARY KEY,
    nombre              varchar(60) NOT NULL,
    orden               smallint    NOT NULL DEFAULT 0,
    estado              int         NOT NULL DEFAULT 1 CHECK (estado IN (0, 1)),
    creado_por          bigint,
    fecha_creacion      timestamp(6),
    modificado_por      bigint,
    fecha_modificacion  timestamp(6)
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_benchmark_familias_nombre
    ON benchmark.benchmark_familias (lower(nombre));

-- 2) Familia de cada producto del catálogo ----------------------------
-- Un producto con id_familia y estado = 1 es un producto "incluido" en el
-- levantamiento. La categoría de Softland no se usa para agrupar.
ALTER TABLE benchmark.benchmark_productos
    ADD COLUMN IF NOT EXISTS id_familia int
        REFERENCES benchmark.benchmark_familias (id) ON DELETE RESTRICT;

CREATE INDEX IF NOT EXISTS ix_benchmark_productos_familia
    ON benchmark.benchmark_productos (id_familia);

-- 3) Auditoría de la tabla nueva (mismas funciones y nombres que las otras 4)
DROP TRIGGER IF EXISTS trg_aud_columnas ON benchmark.benchmark_familias;
CREATE TRIGGER trg_aud_columnas
    BEFORE INSERT OR UPDATE ON benchmark.benchmark_familias
    FOR EACH ROW EXECUTE FUNCTION benchmark.fn_auditoria_columnas();

DROP TRIGGER IF EXISTS trg_aud_bitacora ON benchmark.benchmark_familias;
CREATE TRIGGER trg_aud_bitacora
    AFTER INSERT OR UPDATE OR DELETE ON benchmark.benchmark_familias
    FOR EACH ROW EXECUTE FUNCTION benchmark.fn_auditoria_bitacora();

-- 4) Familias del dashboard de abastecimiento (30/10/2026) ------------
-- Ajustar cuando se confirme cuál de las 11 no entra (estado = 0).
INSERT INTO benchmark.benchmark_familias (nombre, orden) VALUES
    ('Arroz blanco',   1),
    ('Arroz precocido',2),
    ('Azúcar',         3),
    ('Frijol',         4),
    ('Huevo',          5),
    ('Pollo',          6),
    ('Pasta larga',    7),
    ('Pasta corta',    8),
    ('Harina',         9),
    ('Leche',         10),
    ('Embutidos',     11)
ON CONFLICT (lower(nombre)) DO NOTHING;
