-- Solicitudes de creación de usuarios (replicado del sistema de conciliación).
--
-- IMPORTANTE: este script modifica la tabla compartida `usuarios` (agrega la columna id_areas),
-- igual que ya lo hizo el script original de tu amigo en el servidor.
-- Es idempotente: se puede correr más de una vez sin daño.
-- Correrlo primero en la base LOCAL. En el servidor, comprobar antes si ya está aplicado.

-- 1) Tabla de áreas (no hace nada si ya existe; solo siembra si está vacía)
CREATE TABLE IF NOT EXISTS areas (
    id BIGSERIAL PRIMARY KEY,
    descripcion VARCHAR(30) NOT NULL,   -- igual que en la base del servidor
    estado INT NOT NULL DEFAULT 1
);
-- Mismas áreas, IDs y nombres que la base del servidor (solo se siembran si la tabla está vacía)
INSERT INTO areas (id, descripcion, estado)
SELECT v.id, v.descripcion, 1
FROM (VALUES (1, 'INFORMATICA'), (2, 'CONTABILIDAD'), (3, 'VENTAS')) AS v(id, descripcion)
WHERE NOT EXISTS (SELECT 1 FROM areas);
-- Alinea la secuencia del id con los valores sembrados
SELECT setval(pg_get_serial_sequence('areas', 'id'), (SELECT COALESCE(MAX(id), 1) FROM areas));

-- 2) Solicitudes
CREATE TABLE IF NOT EXISTS solicitudes_creacion_usuarios (
    id BIGSERIAL PRIMARY KEY,
    nombre_completo VARCHAR(60) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    telefono VARCHAR(15),
    correo VARCHAR(50),
    id_area BIGINT NOT NULL REFERENCES areas(id),
    id_rol_solicitado BIGINT NOT NULL REFERENCES roles(id),
    motivo VARCHAR(500) NOT NULL,
    usuario_solicita BIGINT NOT NULL REFERENCES usuarios(id),
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE'
        CHECK (estado IN ('PENDIENTE', 'ATENDIDA')),
    fecha_creo TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atendida_por BIGINT REFERENCES usuarios(id),
    fecha_atendida TIMESTAMP,
    usuario_creado_id BIGINT REFERENCES usuarios(id),
    CHECK ((estado = 'PENDIENTE' AND atendida_por IS NULL AND fecha_atendida IS NULL AND usuario_creado_id IS NULL)
        OR (estado = 'ATENDIDA' AND atendida_por IS NOT NULL AND fecha_atendida IS NOT NULL AND usuario_creado_id IS NOT NULL))
);

-- 3) Área en usuarios
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS id_areas BIGINT REFERENCES areas(id);

-- NOT NULL solo si todos los usuarios ya tienen área (si no, avisa y no falla)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM usuarios WHERE id_areas IS NULL) THEN
        RAISE NOTICE 'Hay usuarios sin área: asígnales una y vuelve a correr este bloque para dejar id_areas como NOT NULL.';
    ELSE
        ALTER TABLE usuarios ALTER COLUMN id_areas SET NOT NULL;
    END IF;
END $$;

-- 4) Centros de venta de cada solicitud
CREATE TABLE IF NOT EXISTS solicitudes_creacion_usuarios_centros (
    id_solicitud BIGINT NOT NULL REFERENCES solicitudes_creacion_usuarios(id) ON DELETE CASCADE,
    id_centro_venta BIGINT NOT NULL REFERENCES centro_de_venta(id),
    PRIMARY KEY (id_solicitud, id_centro_venta)
);

CREATE INDEX IF NOT EXISTS ix_solicitudes_creacion_usuarios_estado_fecha
    ON solicitudes_creacion_usuarios (estado, fecha_creo DESC);
CREATE INDEX IF NOT EXISTS ix_solicitudes_creacion_usuarios_solicitante_fecha
    ON solicitudes_creacion_usuarios (usuario_solicita, fecha_creo DESC);

-- 5) Índice que usa la consulta de centros asignados al supervisor (existe en la base del servidor)
CREATE INDEX IF NOT EXISTS ix_supervision_centros_activos
    ON usuarios_centros_venta (id_usuario, id_centro_venta) WHERE (estado = 1);

-- Comprobación: usuarios que aún no tienen área
-- SELECT id, usuario, nombre_completo FROM usuarios WHERE id_areas IS NULL ORDER BY id;
