# BNP-BenchMark — contexto para Claude

Última actualización: 2026-10-28. Rama de trabajo: `benchmarkv3` (último commit de la sesión: `1c17701`).

## Reglas del usuario (obligatorias)
- **Preguntar antes de cada cambio** de archivos, base de datos, commit o push: describir qué se va a hacer y esperar un "sí". Leer código y hacer consultas de solo lectura no requiere permiso.
- **Nunca modificar las tablas `usuarios` y `roles`** de Postgres: son compartidas con proyectos de amigos del usuario (y con conciliación). Solo leerlas o referenciarlas.
- **Ignorar todo lo de conciliación bancaria** (`modules/conciliacion`, `includes/conciliacion_*`, depósitos, estados de cuenta, lectores de bancos). El proyecto del usuario es solo **Benchmark**.
- Responder en español.

## Qué es Benchmark
Comparación de precios de Banasupro contra la competencia.
- `modules/benchmark/benchmark_competidores.php` — CRUD de competidores (modal en `modals/modals_benchmark/`, controlador `controllers/benchmark_controllers/benchmark_competidores_controller.php`, JS `js/benchmark_js/benchmark_competidores.js`).
- `modules/benchmark/benchmark_levantamientos.php` — matriz de captura: lee 30 artículos de Softland (SQL Server), los sincroniza en `benchmark_productos` (upsert por `codigo`) y permite guardar precio de competencia por fila vía `fetch` a `benchmark_guardar_fila_controller.php` (JS `js/benchmark_js/benchmark_levantamientos.js`). Si Softland falla, usa los productos ya guardados en Postgres y registra el error en `C:\xampp\apache\logs\error.log`.
- `modules/benchmark/benchmark_tabla_comparativa.php` — tabla + KPIs + gráfico (ApexCharts) de precio propio vs competencia.
- Menú en `includes/menu_sidebar.php` (sección Benchmark para roles 5 y 6).

## Conexiones
- **PostgreSQL** (principal): `connection/database.php` → `database()`. Config real en `connection/database.local.php` (ignorado en git; plantilla `database.local.example.php`). Base `banasupro2`, esquema `public`. Usa `PDO::ATTR_CASE => CASE_UPPER`: las claves de los resultados vienen en MAYÚSCULAS (el código hace `$row['id'] ?? $row['ID']` o `array_change_key_case`).
- **SQL Server / Softland**: `connection/database_sqlserver.php` → `database_sqlserver()`. Config local en `connection/database_sqlserver.local.php` (ignorado en git; plantilla `database_sqlserver.local.example.php`). Autenticación de Windows (`user`/`password` en `null`). Base `SOFTLAND`, vista `BANASUPRO.SoftlandBI_FA_listaPrecios`. En la PC de casa el servidor es `DESKTOP-R7SPSOM\MSSQLSERVER1`; el default del código es `IT-SVALLE\MSSQLSERVER22`.
- `connection/connection_sqlserver.php` es un archivo viejo que **no se usa**.

## Preparar otra computadora
1. PHP de XAMPP es 8.2 ZTS x64 → copiar `php_pdo_sqlsrv_82_ts_x64.dll` (Microsoft Drivers for PHP for SQL Server 5.11/5.12) a `C:\xampp\php\ext\` y agregar `extension=pdo_sqlsrv_82_ts_x64` en `php.ini`. Requiere ODBC Driver 17 o 18. Reiniciar Apache. Verificar con `C:\xampp\php\php.exe -m` (debe salir `pdo_sqlsrv`).
2. Crear `connection/database.local.php` (Postgres, con la contraseña) y `connection/database_sqlserver.local.php` (servidor SQL de esa PC) a partir de las plantillas.
3. Si esa PC tiene su propia base Postgres, ejecutar en orden los scripts de `sql/`:
   1. `benchmark_softland_columnas.sql`
   2. `benchmark_normalizacion.sql` (borra productos de prueba `PROD-%` sin precios)
   3. `benchmark_quitar_gramaje.sql`
4. Si esa PC tenía la rama `benchmarkv3` vieja (con el commit `14850f3` de bitácora), traer la nueva con `git fetch` + `git reset --hard origin/benchmarkv3` (revisar antes cambios locales sin subir).
5. `php.ini` de XAMPP trae `date.timezone=Europe/Berlin`; el controlador de guardado fija `America/Tegucigalpa` por su cuenta.

## Base de datos Benchmark (estado final, Postgres `banasupro2`)
```
benchmark_competidores
  id serial PK | nombre varchar(100) NOT NULL | descripcion text (UI la llama "ubicación/referencia")
  estado int NOT NULL default 1 CHECK (0,1) | fecha_creacion timestamp NOT NULL default now

benchmark_productos            -- espejo de Softland
  id serial PK | codigo varchar(50) NOT NULL UNIQUE (= ARTICULO de Softland)
  nombre_producto varchar(150) NOT NULL | precio_referencia numeric(10,2) NOT NULL >= 0
  categoria varchar(100) (Clasificacion2/1 de Softland) | estado int NOT NULL CHECK (0,1)
  fecha_creacion timestamp NOT NULL | fecha_actualizacion timestamp (la pone el upsert)

benchmark_levantamientos       -- una visita por usuario + competidor + día
  id serial PK | id_usuario bigint NOT NULL FK usuarios(id) RESTRICT
  id_competidor int NOT NULL FK benchmark_competidores(id) RESTRICT
  fecha_levantamiento date NOT NULL | observaciones text
  UNIQUE (id_usuario, id_competidor, fecha_levantamiento)

benchmark_precios_detalle
  id serial PK | id_levantamiento int NOT NULL FK levantamientos(id) CASCADE
  id_producto int NOT NULL FK benchmark_productos(id) (índice)
  precio_banasupro_referencia numeric(10,2) NOT NULL >= 0  -- snapshot del precio propio al capturar
  precio_competencia numeric(10,2) NOT NULL > 0 | observaciones text
  UNIQUE (id_levantamiento, id_producto)
```
Columnas eliminadas a propósito: `benchmark_precios_detalle.nombre_producto_competencia` (copia redundante del nombre del producto) y `benchmark_productos.gramaje` (traía `Clasificacion3`, una subcategoría, no unidades como Lb/litros).

## Seguridad (convenciones a mantener)
- `includes/benchmark_seguridad.php`: `benchmark_requerir_pagina()` (sin sesión → login; rol distinto de 5/6 → 403), `benchmark_tiene_acceso()`, `benchmark_csrf_token()`, `benchmark_csrf_valido()`. Todo archivo nuevo de benchmark debe usarlos.
- Formularios POST llevan `<input type="hidden" name="csrf_token">`; los `fetch` mandan cabecera `X-CSRF-Token` (`BENCHMARK_CSRF` definido en la página) y `Content-Type: application/json`.
- El precio propio se toma siempre de `benchmark_productos` en el servidor, nunca del navegador.
- Errores de BD: `error_log(...)` + mensaje genérico al usuario. Datos a `<script>`: `json_encode` con `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`. HTML: `escapar()` / `htmlspecialchars`.
- SweetAlert2 local: `assets/vendors/sweetalert2/sweetalert2.all.min.js` (v10.13.0), no CDN.

## Pendientes / ideas
- **Cambiar la contraseña de Postgres**: la anterior quedó en el historial de git (commit `78845f4`). Coordinar con los amigos que comparten la base; luego actualizar `database.local.php`.
- La consulta de Softland trae solo `TOP 30` (filtro ABARROTES / ARTICULOS COMERCIALES). Hay ~10,000 artículos válidos; quizá se necesite búsqueda o paginación.
- Módulo de **bitácora de precios** descartado de la rama; está en la rama **local** `respaldo-bitacora-14850f3` de la PC de casa (no está en GitHub). Si se retoma, adaptarlo a la base normalizada y a `benchmark_seguridad.php`.
- Nada probado todavía en el navegador después de las correcciones de seguridad (sí probado por HTTP con curl).
