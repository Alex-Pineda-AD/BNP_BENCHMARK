-- DATOS DE PRUEBA para ver las gráficas del Análisis de Precios.
-- Crea 5 semanas de levantamientos (hacia atrás desde hoy, sin incluir hoy) para cada competidor activo,
-- con hasta 10 productos de benchmark_productos. Todo queda marcado con observaciones = 'PRUEBA-ANALISIS'.
-- Antes: abrí una vez "Levantamiento de Precios" para que se sincronicen los artículos de Softland.
-- Solo LEE la tabla usuarios (toma el id más bajo).

BEGIN;
SELECT setseed(0.42);

INSERT INTO benchmark_levantamientos (id_usuario, id_competidor, fecha_levantamiento, observaciones)
SELECT u.id, c.id, (CURRENT_DATE - 7 * k)::date, 'PRUEBA-ANALISIS'
FROM (SELECT MIN(id) AS id FROM usuarios) u
CROSS JOIN (SELECT id FROM benchmark_competidores WHERE estado = 1) c
CROSS JOIN generate_series(1, 5) k
ON CONFLICT (id_usuario, id_competidor, fecha_levantamiento) DO NOTHING;

INSERT INTO benchmark_precios_detalle
    (id_levantamiento, id_producto, precio_banasupro_referencia, precio_competencia, observaciones)
SELECT l.id, p.id,
       -- tu precio sube un poco con el tiempo
       round((p.precio_referencia * (1 - 0.01 * ((CURRENT_DATE - l.fecha_levantamiento) / 7)))::numeric, 2),
       -- la competencia varía entre -8% y +20% respecto al precio de referencia
       round((p.precio_referencia * (0.92 + random() * 0.28))::numeric, 2),
       'PRUEBA-ANALISIS'
FROM benchmark_levantamientos l
CROSS JOIN (SELECT id, precio_referencia FROM benchmark_productos
            WHERE estado = 1 AND precio_referencia > 0 ORDER BY id LIMIT 10) p
WHERE l.observaciones = 'PRUEBA-ANALISIS'
ON CONFLICT (id_levantamiento, id_producto) DO NOTHING;

COMMIT;

-- Para BORRAR los datos de prueba (el detalle se elimina en cascada):
-- DELETE FROM benchmark_levantamientos WHERE observaciones = 'PRUEBA-ANALISIS';
