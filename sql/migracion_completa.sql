-- Migración completa de Benchmark (scripts 1 + 2 + 3) en UNA transacción.
-- Ejecutar en pgAdmin/DBeaver conectado a la base banasupro2.
-- Si algo falla, no se aplica nada (ROLLBACK automático) y el mensaje dice por qué.
-- No toca las tablas usuarios ni roles (solo las referencia con una FK).

BEGIN;

-- ===== 1. benchmark_softland_columnas.sql =====
ALTER TABLE benchmark_productos
    ADD COLUMN IF NOT EXISTS categoria varchar(100),
    ADD COLUMN IF NOT EXISTS gramaje varchar(100),
    ADD COLUMN IF NOT EXISTS fecha_actualizacion timestamp(6);

CREATE UNIQUE INDEX IF NOT EXISTS benchmark_productos_codigo_key
    ON benchmark_productos (codigo);

ALTER TABLE benchmark_precios_detalle
    ADD COLUMN IF NOT EXISTS precio_banasupro_referencia numeric(10,2);

-- ===== Verificación previa: datos viejos que romperían la normalización =====
DO $$
DECLARE n integer;
BEGIN
    SELECT count(*) INTO n FROM benchmark_precios_detalle WHERE precio_banasupro_referencia IS NULL;
    IF n > 0 THEN RAISE EXCEPTION 'Hay % fila(s) en benchmark_precios_detalle con precio_banasupro_referencia NULL', n; END IF;

    SELECT count(*) INTO n FROM benchmark_precios_detalle WHERE precio_competencia IS NULL OR precio_competencia <= 0;
    IF n > 0 THEN RAISE EXCEPTION 'Hay % fila(s) con precio_competencia nulo o <= 0', n; END IF;

    SELECT count(*) INTO n FROM (SELECT 1 FROM benchmark_precios_detalle
        GROUP BY id_levantamiento, id_producto HAVING count(*) > 1) t;
    IF n > 0 THEN RAISE EXCEPTION 'Hay % producto(s) repetido(s) dentro de un mismo levantamiento', n; END IF;

    SELECT count(*) INTO n FROM (SELECT 1 FROM benchmark_levantamientos
        GROUP BY id_usuario, id_competidor, fecha_levantamiento::date HAVING count(*) > 1) t;
    IF n > 0 THEN RAISE EXCEPTION 'Hay % visita(s) duplicada(s) (usuario + competidor + día)', n; END IF;

    SELECT count(*) INTO n FROM benchmark_levantamientos l
        WHERE NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.id = l.id_usuario);
    IF n > 0 THEN RAISE EXCEPTION 'Hay % levantamiento(s) con id_usuario que no existe en usuarios', n; END IF;

    SELECT count(*) INTO n FROM benchmark_productos
        WHERE codigo IS NULL OR estado IS NULL OR fecha_creacion IS NULL
           OR estado NOT IN (0,1) OR precio_referencia < 0;
    IF n > 0 THEN RAISE EXCEPTION 'Hay % producto(s) con codigo/estado/fecha_creacion nulos, estado fuera de 0/1 o precio negativo', n; END IF;

    SELECT count(*) INTO n FROM benchmark_competidores
        WHERE estado IS NULL OR fecha_creacion IS NULL OR estado NOT IN (0,1);
    IF n > 0 THEN RAISE EXCEPTION 'Hay % competidor(es) con estado/fecha_creacion nulos o estado fuera de 0/1', n; END IF;
END $$;

-- ===== 2. benchmark_normalizacion.sql =====
DELETE FROM benchmark_productos p
WHERE p.codigo LIKE 'PROD-%'
  AND NOT EXISTS (SELECT 1 FROM benchmark_precios_detalle d WHERE d.id_producto = p.id);

ALTER TABLE benchmark_competidores
    ALTER COLUMN estado SET NOT NULL,
    ALTER COLUMN fecha_creacion SET NOT NULL,
    ADD CONSTRAINT benchmark_competidores_estado_check CHECK (estado IN (0, 1));

ALTER TABLE benchmark_productos
    ALTER COLUMN codigo SET NOT NULL,
    ALTER COLUMN precio_referencia TYPE numeric(10,2),
    ALTER COLUMN estado SET NOT NULL,
    ALTER COLUMN fecha_creacion SET NOT NULL,
    ADD CONSTRAINT benchmark_productos_estado_check CHECK (estado IN (0, 1)),
    ADD CONSTRAINT benchmark_productos_precio_check CHECK (precio_referencia >= 0);

ALTER TABLE benchmark_levantamientos
    ALTER COLUMN id_usuario TYPE bigint,
    ALTER COLUMN fecha_levantamiento TYPE date,
    ADD CONSTRAINT benchmark_levantamientos_id_usuario_fkey
        FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE RESTRICT,
    ADD CONSTRAINT benchmark_levantamientos_visita_key
        UNIQUE (id_usuario, id_competidor, fecha_levantamiento);

ALTER TABLE benchmark_precios_detalle
    DROP COLUMN nombre_producto_competencia,
    ALTER COLUMN precio_competencia TYPE numeric(10,2),
    ALTER COLUMN precio_banasupro_referencia TYPE numeric(10,2),
    ALTER COLUMN precio_banasupro_referencia SET NOT NULL,
    ADD CONSTRAINT benchmark_precios_detalle_precio_competencia_check CHECK (precio_competencia > 0),
    ADD CONSTRAINT benchmark_precios_detalle_precio_banasupro_check CHECK (precio_banasupro_referencia >= 0),
    ADD CONSTRAINT benchmark_precios_detalle_producto_key UNIQUE (id_levantamiento, id_producto);

CREATE INDEX IF NOT EXISTS benchmark_precios_detalle_id_producto_idx
    ON benchmark_precios_detalle (id_producto);

-- ===== 3. benchmark_quitar_gramaje.sql =====
ALTER TABLE benchmark_productos DROP COLUMN IF EXISTS gramaje;

-- ===== Opcional: columnas viejas que no están en el esquema final del CLAUDE.md =====
-- Quita el comentario (--) solo si confirmas que no las usas:
-- ALTER TABLE benchmark_productos       DROP COLUMN IF EXISTS descripcion;
-- ALTER TABLE benchmark_precios_detalle DROP COLUMN IF EXISTS fecha_registro;

COMMIT;

-- ===== Verificación final: compara con el esquema del CLAUDE.md =====
SELECT table_name, column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name LIKE 'benchmark\_%'
ORDER BY table_name, ordinal_position;
