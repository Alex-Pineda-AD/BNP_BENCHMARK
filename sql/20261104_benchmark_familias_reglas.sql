-- =====================================================================
-- Benchmark: asignación automática de familias (04/11/2026)
-- Idempotente. No toca usuarios ni roles. No borra nada.
-- Requiere: 20261104_benchmark_familias.sql y 20261104_benchmark_alcance_categorias.sql
--
-- Cómo funciona:
--  * El levantamiento se captura por categoría/subcategoría de Softland (alcance).
--  * Cada producto además recibe una de las 11 familias con reglas de nombre
--    (incluir/excluir). La comparativa y el análisis se agrupan por familia.
--  * Un producto sin familia se captura igual, pero no entra a la comparativa por familia.
--  * Categorías "mezcladas" (ej. PROYECTOS) se marcan requiere_familia = true:
--    solo entran los artículos que coinciden con alguna familia.
--  * familia_manual = true protege una asignación hecha a mano (la sincronización no la pisa).
-- =====================================================================

DO $$
BEGIN
    IF to_regclass('benchmark.benchmark_familias') IS NULL THEN
        RAISE EXCEPTION 'Falta benchmark.benchmark_familias: correr antes 20261104_benchmark_familias.sql';
    END IF;
    IF to_regclass('benchmark.benchmark_alcance_categorias') IS NULL THEN
        RAISE EXCEPTION 'Falta benchmark.benchmark_alcance_categorias: correr antes 20261104_benchmark_alcance_categorias.sql';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'benchmark' AND table_name = 'benchmark_productos' AND column_name = 'id_familia') THEN
        RAISE EXCEPTION 'Falta benchmark_productos.id_familia: correr antes 20261104_benchmark_familias.sql';
    END IF;
END $$;

-- 1) Marcas nuevas ---------------------------------------------------------
ALTER TABLE benchmark.benchmark_alcance_categorias
    ADD COLUMN IF NOT EXISTS requiere_familia boolean NOT NULL DEFAULT false;

ALTER TABLE benchmark.benchmark_productos
    ADD COLUMN IF NOT EXISTS familia_manual boolean NOT NULL DEFAULT false;

-- 2) Reglas por familia -----------------------------------------------------
CREATE TABLE IF NOT EXISTS benchmark.benchmark_familia_reglas (
    id                  serial PRIMARY KEY,
    id_familia          int         NOT NULL
                        REFERENCES benchmark.benchmark_familias (id) ON DELETE RESTRICT,
    tipo                varchar(8)  NOT NULL CHECK (tipo IN ('incluir', 'excluir')),
    patron              varchar(80) NOT NULL,
    estado              int         NOT NULL DEFAULT 1 CHECK (estado IN (0, 1)),
    creado_por          bigint,
    fecha_creacion      timestamp(6),
    modificado_por      bigint,
    fecha_modificacion  timestamp(6)
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_benchmark_familia_reglas
    ON benchmark.benchmark_familia_reglas (id_familia, tipo, upper(patron));

DROP TRIGGER IF EXISTS trg_aud_columnas ON benchmark.benchmark_familia_reglas;
CREATE TRIGGER trg_aud_columnas
    BEFORE INSERT OR UPDATE ON benchmark.benchmark_familia_reglas
    FOR EACH ROW EXECUTE FUNCTION benchmark.fn_auditoria_columnas();

DROP TRIGGER IF EXISTS trg_aud_bitacora ON benchmark.benchmark_familia_reglas;
CREATE TRIGGER trg_aud_bitacora
    AFTER INSERT OR UPDATE OR DELETE ON benchmark.benchmark_familia_reglas
    FOR EACH ROW EXECUTE FUNCTION benchmark.fn_auditoria_bitacora();

-- 3) Reglas iniciales (patrón con % como comodín, sin acentos, contra el nombre) ----
INSERT INTO benchmark.benchmark_familia_reglas (id_familia, tipo, patron)
SELECT f.id, r.tipo, r.patron
FROM (VALUES
    ('Arroz blanco',   'incluir', '%ARROZ%'),
    ('Arroz blanco',   'excluir', '%PRECO%'),
    ('Arroz blanco',   'excluir', '%ESCALD%'),
    ('Arroz blanco',   'excluir', '%NUTRIBON%'),
    ('Arroz blanco',   'excluir', '%CEREAL%'),
    ('Arroz blanco',   'excluir', '%INFANTIL%'),
    ('Arroz blanco',   'excluir', '%HORCHATA%'),
    ('Arroz precocido','incluir', '%ARROZ%PRECO%'),
    ('Arroz precocido','incluir', '%ARROZ%ESCALD%'),
    ('Azúcar',         'incluir', '%AZUCAR%'),
    ('Azúcar',         'excluir', '%AZUCARON%'),
    ('Frijol',         'incluir', '%FRIJOL%'),
    ('Frijol',         'excluir', '%FERTI%'),
    ('Frijol',         'excluir', '%SEMILLA%'),
    ('Frijol',         'excluir', '%QUESO%'),
    ('Frijol',         'excluir', '%VERDE%'),
    ('Frijol',         'excluir', '%HABICHUELA%'),
    ('Frijol',         'excluir', '%VOLTEADO%'),
    ('Huevo',          'incluir', '%HUEVO%'),
    ('Pollo',          'incluir', '%POLLO%'),
    ('Pollo',          'excluir', '%REPOLLO%'),
    ('Pollo',          'excluir', '%CUBITO%'),
    ('Pollo',          'excluir', '%CONSOME%'),
    ('Pollo',          'excluir', '%SOPA%'),
    ('Pollo',          'excluir', '%SABOR%'),
    ('Pollo',          'excluir', '%MAGGI%'),
    ('Pollo',          'excluir', '%MAGUI%'),
    ('Pollo',          'excluir', '%SALCHICHA%'),
    ('Pollo',          'excluir', '%CHORIZO%'),
    ('Pollo',          'excluir', '%JAMON%'),
    ('Pollo',          'excluir', '%MORTADELA%'),
    ('Pasta larga',    'incluir', '%ESPAGUET%'),
    ('Pasta larga',    'incluir', '%TALLARIN%'),
    ('Pasta larga',    'incluir', '%FIDEO%'),
    ('Pasta corta',    'incluir', '%PASTA CORTA%'),
    ('Pasta corta',    'incluir', '%CODITO%'),
    ('Pasta corta',    'incluir', '%CARACOL%'),
    ('Pasta corta',    'incluir', '%PASTA ALIMENTICIA%'),
    ('Harina',         'incluir', '%HARINA%'),
    ('Leche',          'incluir', '%LECHE%'),
    ('Leche',          'excluir', '%MALTEADA%'),
    ('Leche',          'excluir', '%DULCE DE LECHE%'),
    ('Embutidos',      'incluir', '%CHORIZO%'),
    ('Embutidos',      'incluir', '%SALCHICHA%'),
    ('Embutidos',      'incluir', '%JAMON%'),
    ('Embutidos',      'incluir', '%MORTADELA%'),
    ('Embutidos',      'incluir', '%LONGANIZA%'),
    ('Embutidos',      'incluir', '%HOT DOG%'),
    ('Embutidos',      'incluir', '%HT DG%'),
    ('Embutidos',      'incluir', '%EXTREMENO%')
) AS r (familia, tipo, patron)
JOIN benchmark.benchmark_familias f ON lower(f.nombre) = lower(r.familia)
ON CONFLICT (id_familia, tipo, upper(patron)) DO NOTHING;

-- 4) PROYECTOS: categoría mezclada de Softland; solo entran los artículos con familia ------
INSERT INTO benchmark.benchmark_alcance_categorias (clasificacion2, clasificacion3, requiere_familia)
VALUES ('PROYECTOS', NULL, true)
ON CONFLICT (upper(clasificacion2), upper(coalesce(clasificacion3, ''))) DO NOTHING;

-- Verificación 1: reglas por familia (11 filas)
SELECT f.nombre AS familia,
       count(*) FILTER (WHERE r.tipo = 'incluir') AS incluir,
       count(*) FILTER (WHERE r.tipo = 'excluir') AS excluir
FROM benchmark.benchmark_familias f
LEFT JOIN benchmark.benchmark_familia_reglas r ON r.id_familia = f.id AND r.estado = 1
GROUP BY f.nombre, f.orden ORDER BY f.orden;

-- Verificación 2: alcance (11 filas; PROYECTOS con requiere_familia = true)
SELECT clasificacion2 AS categoria, coalesce(clasificacion3, '(toda la categoría)') AS subcategoria,
       requiere_familia, estado
FROM benchmark.benchmark_alcance_categorias
ORDER BY clasificacion2, clasificacion3 NULLS FIRST;
