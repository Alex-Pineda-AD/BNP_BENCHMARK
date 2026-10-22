# PostgreSQL local

La aplicación utiliza PDO PostgreSQL y la base `banasupro`. No importa datos ni modifica tablas al conectarse.

1. Habilitar `extension=pdo_pgsql` en el `php.ini` que usa Apache en WAMP y reiniciar Apache. Para ejecutar pruebas, habilitarlo también en PHP CLI o pasar `-d extension=pdo_pgsql`.
2. Copiar `connection/database.local.example.php` a `connection/database.local.php` y completar usuario, contraseña, puerto y esquema. El archivo local está excluido de Git. También se admiten `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` y `DB_SCHEMA`; el archivo local tiene prioridad.
3. Ejecutar `php tools/check_postgresql.php`. Solo consulta metadatos. Se esperan identificadores PostgreSQL sin comillas (minúsculas en el catálogo). PDO devuelve las claves en mayúsculas para mantener las vistas existentes.
4. Verificar que las columnas de ID tengan identidad o secuencia y que las secuencias continúen después del mayor ID importado. Los registros nuevos obtienen su ID con `RETURNING`.
5. Probar inicio de sesión, usuarios y bancos, registro/edición/revisión de depósitos, alertas, carga de estados y filtros de movimientos.

Los SQL históricos en la raíz y en `migrations/` pertenecen a MySQL/MariaDB. Las versiones de PostgreSQL están en `migrations/postgresql/`. Si la base ya tiene las mismas tablas y columnas, no es necesario ejecutarlas. No se aplican automáticamente. La conversión histórica de IDs de MySQL no se necesita en una base PostgreSQL ya migrada.

Pruebas: `php tests/postgresql-estado-cuenta.php` valida inserciones, bytes originales, duplicados y rollback en tablas temporales. `php tests/estado_cuenta_multibanco.php "carpeta de TXT"` valida los lectores sin conexión y necesita los cuatro archivos de ejemplo. Agregar `--postgres` para las inserciones temporales. Las pruebas `depositos-codigo.php`, `deposito-editar.php`, `deposito-revision.php`, `deposito-service.php` y `alertas-depositos.php` necesitan conexión y usan tablas temporales.

El catálogo de artículos en `modules/ventas/listado_articulos.php` consulta la base externa SOFTLAND mediante SQL Server. Esa conexión es independiente de `banasupro` y requiere definir su equivalente si también se desea trasladar ese catálogo.
