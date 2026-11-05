-- =====================================================================
-- Benchmark: leche fuera de LACTEOS, unidad de venta y baja de articulo ajeno (05/11/2026)
-- Idempotente (se puede correr mas de una vez). No toca usuarios ni roles.
-- =====================================================================
BEGIN;

-- 1) Unidad de venta de Softland (UND, LBS, ARB, ONZ...) para saber de que unidad sale cada precio.
ALTER TABLE benchmark.benchmark_productos
    ADD COLUMN IF NOT EXISTS unidad_venta varchar(10);

-- 2) Alcance: solo entran los articulos que coincidan con una familia (requiere_familia = true).
--    Leche evaporada/de coco (ENLATADOS Y EMPAQUETADOS) y leche en polvo de bebe (BEBES Y NIÑOS).
INSERT INTO benchmark.benchmark_alcance_categorias (clasificacion2, clasificacion3, estado, requiere_familia)
SELECT v.c2, v.c3, 1, true
FROM (VALUES
        ('ABARROTES',     'ENLATADOS Y EMPAQUETADOS'),
        ('BEBES Y NIÑOS', 'ALIMENTACION BEBE Y NIÑOS')
     ) AS v(c2, c3)
WHERE NOT EXISTS (
    SELECT 1 FROM benchmark.benchmark_alcance_categorias a
    WHERE upper(a.clasificacion2) = upper(v.c2)
      AND upper(COALESCE(a.clasificacion3, '')) = upper(v.c3)
);

-- 3) La escoba (101717) no pertenece a ninguna familia: se da de baja en Benchmark (no se toca Softland).
UPDATE benchmark.benchmark_productos
   SET estado = 0
 WHERE codigo = '101717' AND estado = 1;

-- 4) Limpieza del alcance: filas que Softland no tiene (nunca traen articulos).
DELETE FROM benchmark.benchmark_alcance_categorias
 WHERE (upper(clasificacion2) = 'ABARROTES' AND upper(COALESCE(clasificacion3,'')) = 'PASTAS,TAMALES Y PURE DE PAPAS')
    OR (upper(clasificacion2) = 'ABARROTES' AND upper(COALESCE(clasificacion3,'')) = 'LECHES EN POLVO');

COMMIT;

-- Verificacion (solo lectura)
SELECT 'alcance' AS tipo, clasificacion2 || ' / ' || COALESCE(clasificacion3, '(todas)') AS detalle,
       'estado=' || estado || ' requiere_familia=' || requiere_familia AS valor
FROM benchmark.benchmark_alcance_categorias
WHERE upper(clasificacion2) IN ('ABARROTES', 'BEBES Y NIÑOS')
UNION ALL
SELECT 'escoba', codigo || ' ' || nombre_producto, 'estado=' || estado
FROM benchmark.benchmark_productos WHERE codigo = '101717'
UNION ALL
SELECT 'columna', column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'benchmark' AND table_name = 'benchmark_productos' AND column_name = 'unidad_venta'
ORDER BY 1, 2;
