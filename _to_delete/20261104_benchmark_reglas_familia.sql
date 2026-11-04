-- =====================================================================
-- Benchmark: reglas de clasificación por familia + estado "por revisar" (04/11/2026)
-- Idempotente. No toca usuarios ni roles.
-- Requiere: 20261104_benchmark_familias.sql y las funciones de auditoría.
--
-- Cómo funciona: la sincronización con Softland (solo SELECT) busca artículos
-- y los asigna a una familia con estas reglas. Un patrón usa % como comodín y se
-- compara contra el nombre del artículo en MAYÚSCULAS. Un artículo entra a la
-- familia si cumple algún patrón «incluir» y ningún patrón «excluir». Los
-- artículos nuevos entran con en_revision = true y NO se muestran en la captura
-- hasta que se aprueben (en_revision = false).
-- =====================================================================

DO $$
BEGIN
    IF to_regclass('benchmark.benchmark_familias') IS NULL THEN
        RAISE EXCEPTION 'Falta benchmark.benchmark_familias: correr antes 20261104_benchmark_familias.sql';
    END IF;
    IF to_regprocedure('benchmark.fn_auditoria_columnas()') IS NULL
       OR to_regprocedure('benchmark.fn_auditoria_bitacora()') IS NULL THEN
        RAISE EXCEPTION 'Faltan las funciones de auditoría de Benchmark';
    END IF;
END $$;

-- 1) Producto aprobado o por revisar -------------------------------------
-- Los productos que ya existen quedan aprobados (false).
ALTER TABLE benchmark.benchmark_productos
    ADD COLUMN IF NOT EXISTS en_revision boolean NOT NULL DEFAULT false;

-- 2) Reglas por familia ---------------------------------------------------
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

-- 3) Reglas iniciales (salen de la revisión de los datos reales de Softland) ----
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
    ('Embutidos',      'incluir', '%EXTREMEÑO%')
) AS r (familia, tipo, patron)
JOIN benchmark.benchmark_familias f ON lower(f.nombre) = lower(r.familia)
ON CONFLICT (id_familia, tipo, upper(patron)) DO NOTHING;

-- Verificación: reglas por familia
SELECT f.nombre AS familia,
       count(*) FILTER (WHERE r.tipo = 'incluir') AS incluir,
       count(*) FILTER (WHERE r.tipo = 'excluir') AS excluir
FROM benchmark.benchmark_familias f
LEFT JOIN benchmark.benchmark_familia_reglas r ON r.id_familia = f.id AND r.estado = 1
GROUP BY f.nombre, f.orden ORDER BY f.orden;
