-- =====================================================================
-- Benchmark: alcance por categoría/subcategoría de Softland (04/11/2026)
-- Idempotente. No toca usuarios ni roles. No borra nada.
-- Requiere las funciones de auditoría de Benchmark ya creadas en la base.
--
-- La categoría (Clasificacion2) y la subcategoría (Clasificacion3) vienen
-- literales de Softland. Esta tabla solo define QUÉ categorías entran al
-- levantamiento: una fila con clasificacion3 NULL significa "toda la categoría".
-- =====================================================================

DO $$
BEGIN
    IF to_regprocedure('benchmark.fn_auditoria_columnas()') IS NULL
       OR to_regprocedure('benchmark.fn_auditoria_bitacora()') IS NULL THEN
        RAISE EXCEPTION 'Faltan las funciones de auditoría de Benchmark (fn_auditoria_columnas / fn_auditoria_bitacora)';
    END IF;
END $$;

-- 1) Datos de Softland que se guardan por producto ------------------------
--    precio_referencia = precio_lista ÷ factor_empaque (precio por unidad de venta)
ALTER TABLE benchmark.benchmark_productos
    ADD COLUMN IF NOT EXISTS clasificacion2 varchar(100),
    ADD COLUMN IF NOT EXISTS precio_lista   numeric(12,2),
    ADD COLUMN IF NOT EXISTS factor_empaque numeric(12,4);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_benchmark_productos_factor_empaque') THEN
        ALTER TABLE benchmark.benchmark_productos
            ADD CONSTRAINT ck_benchmark_productos_factor_empaque
            CHECK (factor_empaque IS NULL OR factor_empaque > 0);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_benchmark_productos_clasif
    ON benchmark.benchmark_productos (clasificacion2, categoria);

-- 2) Alcance: qué categorías/subcategorías de Softland entran ----------------
CREATE TABLE IF NOT EXISTS benchmark.benchmark_alcance_categorias (
    id                  serial PRIMARY KEY,
    clasificacion2      varchar(100) NOT NULL,
    clasificacion3      varchar(100),
    estado              int NOT NULL DEFAULT 1 CHECK (estado IN (0, 1)),
    creado_por          bigint,
    fecha_creacion      timestamp(6),
    modificado_por      bigint,
    fecha_modificacion  timestamp(6)
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_benchmark_alcance_categorias
    ON benchmark.benchmark_alcance_categorias (upper(clasificacion2), upper(coalesce(clasificacion3, '')));

DROP TRIGGER IF EXISTS trg_aud_columnas ON benchmark.benchmark_alcance_categorias;
CREATE TRIGGER trg_aud_columnas
    BEFORE INSERT OR UPDATE ON benchmark.benchmark_alcance_categorias
    FOR EACH ROW EXECUTE FUNCTION benchmark.fn_auditoria_columnas();

DROP TRIGGER IF EXISTS trg_aud_bitacora ON benchmark.benchmark_alcance_categorias;
CREATE TRIGGER trg_aud_bitacora
    AFTER INSERT OR UPDATE OR DELETE ON benchmark.benchmark_alcance_categorias
    FOR EACH ROW EXECUTE FUNCTION benchmark.fn_auditoria_bitacora();

-- 3) Alcance inicial: las categorías donde viven las 11 familias de abastecimiento.
--    Quedan fuera a propósito: Bebidas y jugos / Leches líquidas, Bebés y niños,
--    Abarrotes / Enlatados y empaquetados. Para sumarlas, insertar otra fila aquí.
INSERT INTO benchmark.benchmark_alcance_categorias (clasificacion2, clasificacion3) VALUES
    ('LACTEOS Y DERIVADOS', NULL),
    ('EMBUTIDOS',           NULL),
    ('CARNES Y AVES',       NULL),
    ('ABARROTES', 'GRANOS'),
    ('ABARROTES', 'HARINAS'),
    ('ABARROTES', 'PASTAS TAMALES Y PURE DE PAPAS'),
    ('ABARROTES', 'PASTAS,TAMALES Y PURE DE PAPAS'),
    ('ABARROTES', 'ENDULZANTES'),
    ('ABARROTES', 'HUEVOS'),
    ('ABARROTES', 'LECHES EN POLVO')
ON CONFLICT (upper(clasificacion2), upper(coalesce(clasificacion3, ''))) DO NOTHING;

-- Verificación: alcance cargado (debe devolver 10 filas)
SELECT clasificacion2 AS categoria, coalesce(clasificacion3, '(toda la categoría)') AS subcategoria, estado
FROM benchmark.benchmark_alcance_categorias
ORDER BY clasificacion2, clasificacion3 NULLS FIRST;
