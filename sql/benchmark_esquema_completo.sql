-- ESQUEMA COMPLETO DE BENCHMARK (PostgreSQL, base banasupro2, esquema public).
-- Crea solo las 4 tablas benchmark_* con su estado final. No crea ni modifica usuarios ni roles:
-- benchmark_levantamientos solo referencia usuarios(id), que ya debe existir.
-- Idempotente: si una tabla ya existe, no la toca (para actualizar una BD vieja use migracion_completa.sql).

BEGIN;

-- 1. Competidores
CREATE TABLE IF NOT EXISTS benchmark_competidores (
    id             serial PRIMARY KEY,
    nombre         varchar(100)  NOT NULL,
    descripcion    text,                                   -- la UI la llama "ubicación/referencia"
    estado         integer       NOT NULL DEFAULT 1,
    fecha_creacion timestamp(6)  NOT NULL DEFAULT now(),
    CONSTRAINT benchmark_competidores_estado_check CHECK (estado IN (0, 1))
);

-- 2. Productos (espejo de Softland; codigo = ARTICULO)
CREATE TABLE IF NOT EXISTS benchmark_productos (
    id                  serial PRIMARY KEY,
    codigo              varchar(50)   NOT NULL,
    nombre_producto     varchar(150)  NOT NULL,
    precio_referencia   numeric(10,2) NOT NULL,
    categoria           varchar(100),
    estado              integer       NOT NULL DEFAULT 1,
    fecha_creacion      timestamp(6)  NOT NULL DEFAULT now(),
    fecha_actualizacion timestamp(6),
    CONSTRAINT benchmark_productos_estado_check CHECK (estado IN (0, 1)),
    CONSTRAINT benchmark_productos_precio_check CHECK (precio_referencia >= 0)
);
-- Requerido por INSERT ... ON CONFLICT (codigo)
CREATE UNIQUE INDEX IF NOT EXISTS benchmark_productos_codigo_key
    ON benchmark_productos (codigo);

-- 3. Levantamientos: una visita por usuario, competidor y día
CREATE TABLE IF NOT EXISTS benchmark_levantamientos (
    id                  serial PRIMARY KEY,
    id_usuario          bigint  NOT NULL,
    id_competidor       integer NOT NULL,
    fecha_levantamiento date    NOT NULL,
    observaciones       text,
    CONSTRAINT benchmark_levantamientos_id_usuario_fkey
        FOREIGN KEY (id_usuario) REFERENCES usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT benchmark_levantamientos_id_competidor_fkey
        FOREIGN KEY (id_competidor) REFERENCES benchmark_competidores (id) ON DELETE RESTRICT,
    CONSTRAINT benchmark_levantamientos_visita_key
        UNIQUE (id_usuario, id_competidor, fecha_levantamiento)
);
-- Índices para las consultas del módulo Análisis de Precios
CREATE INDEX IF NOT EXISTS benchmark_levantamientos_fecha_idx
    ON benchmark_levantamientos (fecha_levantamiento);
CREATE INDEX IF NOT EXISTS benchmark_levantamientos_competidor_idx
    ON benchmark_levantamientos (id_competidor, fecha_levantamiento);

-- 4. Detalle de precios por levantamiento
CREATE TABLE IF NOT EXISTS benchmark_precios_detalle (
    id                          serial PRIMARY KEY,
    id_levantamiento            integer       NOT NULL,
    id_producto                 integer       NOT NULL,
    precio_banasupro_referencia numeric(10,2) NOT NULL,    -- snapshot del precio propio al capturar
    precio_competencia          numeric(10,2) NOT NULL,
    observaciones               text,
    CONSTRAINT benchmark_precios_detalle_id_levantamiento_fkey
        FOREIGN KEY (id_levantamiento) REFERENCES benchmark_levantamientos (id) ON DELETE CASCADE,
    CONSTRAINT benchmark_precios_detalle_id_producto_fkey
        FOREIGN KEY (id_producto) REFERENCES benchmark_productos (id),
    CONSTRAINT benchmark_precios_detalle_precio_competencia_check CHECK (precio_competencia > 0),
    CONSTRAINT benchmark_precios_detalle_precio_banasupro_check CHECK (precio_banasupro_referencia >= 0),
    CONSTRAINT benchmark_precios_detalle_producto_key UNIQUE (id_levantamiento, id_producto)
);
CREATE INDEX IF NOT EXISTS benchmark_precios_detalle_id_producto_idx
    ON benchmark_precios_detalle (id_producto);

COMMIT;

-- Verificación: debe listar las 4 tablas con sus columnas finales
SELECT table_name, column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name LIKE 'benchmark\_%'
ORDER BY table_name, ordinal_position;
