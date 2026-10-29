-- Normalización de las tablas benchmark_*.
-- Requiere haber ejecutado antes benchmark_softland_columnas.sql.

-- Productos de prueba que no vienen de Softland
DELETE FROM benchmark_productos p
WHERE p.codigo LIKE 'PROD-%'
  AND NOT EXISTS (SELECT 1 FROM benchmark_precios_detalle d WHERE d.id_producto = p.id);

-- benchmark_competidores
ALTER TABLE benchmark_competidores
    ALTER COLUMN estado SET NOT NULL,
    ALTER COLUMN fecha_creacion SET NOT NULL,
    ADD CONSTRAINT benchmark_competidores_estado_check CHECK (estado IN (0, 1));

-- benchmark_productos (espejo de Softland; codigo = ARTICULO)
ALTER TABLE benchmark_productos
    ALTER COLUMN codigo SET NOT NULL,
    ALTER COLUMN precio_referencia TYPE numeric(10,2),
    ALTER COLUMN estado SET NOT NULL,
    ALTER COLUMN fecha_creacion SET NOT NULL,
    ADD CONSTRAINT benchmark_productos_estado_check CHECK (estado IN (0, 1)),
    ADD CONSTRAINT benchmark_productos_precio_check CHECK (precio_referencia >= 0);

-- benchmark_levantamientos: una visita por usuario, competidor y día
ALTER TABLE benchmark_levantamientos
    ALTER COLUMN id_usuario TYPE bigint,
    ALTER COLUMN fecha_levantamiento TYPE date,
    ADD CONSTRAINT benchmark_levantamientos_id_usuario_fkey
        FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE RESTRICT,
    ADD CONSTRAINT benchmark_levantamientos_visita_key
        UNIQUE (id_usuario, id_competidor, fecha_levantamiento);

-- benchmark_precios_detalle: el nombre del producto se obtiene de benchmark_productos
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
