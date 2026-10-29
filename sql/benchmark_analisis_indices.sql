-- Índices para las consultas del módulo Análisis de Precios (filtro por fecha y por competidor).
-- Idempotente: se puede ejecutar más de una vez.
CREATE INDEX IF NOT EXISTS benchmark_levantamientos_fecha_idx
    ON benchmark_levantamientos (fecha_levantamiento);
CREATE INDEX IF NOT EXISTS benchmark_levantamientos_competidor_idx
    ON benchmark_levantamientos (id_competidor, fecha_levantamiento);
