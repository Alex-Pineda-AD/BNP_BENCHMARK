# Cruce, prevalidación y cierre

Abrir **Cruce y prevalidación** en el menú de conciliación. La migración PostgreSQL
`migrations/postgresql/20261014_conciliacion.sql` agrega tres tablas sin alterar los
depósitos ni movimientos originales. Puede aplicarse con `tools/migrate_conciliacion.php`.

## Prueba manual

1. Ingresar con un usuario activo de rol 2, 4 o 5. Elegir un estado importado.
2. Revisar la tabla de depósitos aprobados, con fechas y horas del depósito y
   del banco en columnas contiguas. Pulsar **Revisar** para abrir el detalle.
   La búsqueda compara `depositos.FECHA_DEPOSITO` con
   `transacciones_estado_cuenta.FECHA` y `HORA`. Prioriza fecha y hora iguales;
   también muestra candidatos del mismo día, por referencia o por monto, indicando
   las diferencias. Usar el buscador de depósitos si hay varios. Revisar su
   comprobante, referencia, cuenta, moneda, monto y fecha/hora frente al crédito.
3. Revisar el comparativo lado a lado, confirmar la revisión del comprobante
   y pulsar **Enviar a prevalidación**. Las diferencias
   de referencia, fecha u hora deben documentarse. Cuenta, banco, moneda y monto
   deben coincidir; el movimiento debe ser un crédito sin débito. Si todo coincide,
   la observación es opcional. Datos faltantes o diferencias requieren explicación.
   Una cuenta distinta se muestra como bloqueo y no se acepta por coincidencia de sufijo.
4. Ingresar con OTRO usuario de rol 4 o 5. En **Revisión del supervisor**, aprobar el
   vínculo o devolverlo con motivo. Una devolución libera los registros para
   otro cruce y mantiene el registro anterior en el historial.
5. En **Movimientos bancarios**, usar el buscador y las páginas de 25 registros
   para consultar todos los movimientos. Justificar los que no correspondan a depósitos, indicando por
   ejemplo la comisión bancaria. Esta acción está reservada a roles 4 y 5.
6. En **Cierre**, pulsar **Verificar balances y cerrar**. Se exige cuenta, período,
   saldos inicial y final, movimientos resueltos, depósitos del período sin
   pendientes y balances consecutivos consistentes. El cierre guarda una
   instantánea de movimientos, vínculos y justificaciones.

## Alcance de esta versión

- Cruce de un depósito contra un movimiento de los estados importados en
  `estados_cuenta` / `transacciones_estado_cuenta`.
- Comparación por crédito, nunca por balance. Hora ausente se presenta sin inventarla.
- No hay aprobación automática, conciliación de depósitos agrupados, reapertura
  ni ajuste automático de diferencias monetarias.
- Cierre por estado importado. No sustituye una conciliación contra un libro
  contable externo ni un cierre consolidado de varias cuentas.
- Las partidas sin depósito son justificadas por el supervisor y quedan
  diferenciadas de los vínculos aprobados.

## Verificación

`tests/conciliacion.php` usa un esquema aislado con datos ficticios y lo elimina
al finalizar. Prueba permisos, doble revisión, duplicados, cambios de datos,
devolución, justificación y cierre. Requiere PostgreSQL y `pdo_pgsql`.

`tests/conciliacion_pantalla.php` verifica el renderizado PHP y las consultas con
datos locales, sin cambiar depósitos, movimientos ni aprobaciones. No es una
prueba visual de navegador.

## Cierre y consulta de períodos

### Mes abierto y permisos excepcionales

La migración `20261019_periodos_control.sql` agrega un control mensual y asigna
el mantenimiento al primer usuario activo de rol 5. En esta instalación el
propietario es **admin**. Otros usuarios, incluso del mismo rol, no pueden acceder
al mantenimiento ni otorgar permisos. No se cambian roles existentes.

En **Control de períodos**, el propietario habilita un solo mes. Cambiarlo bloquea
el anterior y revoca las delegaciones. No reabre ni cierra estados bancarios.
El cierre administrativo del mes exige que los estados de ese mes estén cerrados.
Los estados históricos se pueden consultar y exportar; sus operaciones quedan
bloqueadas tanto en pantalla como en el servidor. Los estados deben tener fechas
de inicio y fin dentro del mes habilitado. Si un archivo incluye varios meses,
se debe preparar el estado mensual correspondiente, sin cerrar parcialmente el
archivo importado. Los depósitos y movimientos también deben pertenecer al mes.

El formulario de ajuste y cierre excepcional solo se muestra al propietario o
a un usuario expresamente autorizado para ese estado y mes. La autorización
requiere motivo, vence en un máximo de 30 días y puede revocarse. No concede acceso
al mantenimiento ni permite aprobar los propios vínculos.

Los ajustes llevan fecha, importe con signo, documento de respaldo, justificación,
usuario y fecha de registro. Se guardan aparte: no fabrican depósitos, no alteran
movimientos bancarios ni borran inconsistencias. Para revertir un ajuste se crea
una contrapartida identificada. El cierre excepcional exige que los ajustes sumen
exactamente la diferencia original de saldo; cualquier balance consecutivo
inconsistente se conserva en el reporte. No omite la aprobación de vínculos ni la
resolución de movimientos y depósitos pendientes.

El historial marca **EXCEPCIONAL** y el Excel muestra diferencia original, ajustes,
motivo y cantidad de balances inconsistentes. Los ajustes se identifican como
registros de conciliación y no como movimientos bancarios. Todo cambio de mes,
permiso, ajuste o cierre queda en la bitácora de control.

### Si un depósito no coincide

- Dato mal capturado: devolver para corrección, verificar comprobante y aprobar nuevamente.
- Depósito aún no reflejado por el banco: mantener pendiente y obtener el estado correspondiente.
- Comisión, reversión u otro movimiento sin depósito: justificar su naturaleza y respaldo.
- Diferencia real documentada: evaluar un ajuste identificado con autorización limitada.
- Movimiento o comprobante faltante: obtener el original e importarlo/registrarlo mediante el flujo normal.

No se debe cambiar el monto de un comprobante ni crear un movimiento bancario
inexistente para hacer coincidir los totales.

### Correcciones después de aprobar un depósito

En **Vincular depósitos → Revisar**, un Supervisor o Administrador puede usar
**Devolver depósito para corrección**, con motivo obligatorio. Está disponible
incluso si el monto no coincide con el crédito. En la revisión del supervisor,
la misma acción está dentro de **El depósito aprobado tiene datos incorrectos**.

El depósito pasa a **Por corregir** (7); sus vínculos activos se devuelven y liberan
el movimiento. La solicitud se guarda en bitácora y aparece en la revisión de
depósitos y en los depósitos del usuario que lo registró. El analista puede
corregirlo desde **Validar depósitos**; el registrador también puede reenviarlo
desde **Mis depósitos**. Se exige una nueva aprobación documental y otro cruce.
Los registros aprobados de ciclos anteriores se conservan, pero no bloquean la
corrección autorizada. Los depósitos incluidos en cierres no se pueden devolver.

Un Supervisor o Administrador selecciona el estado de cuenta y abre **3. Cierre**.
Antes de cerrar, todos los vínculos deben estar aprobados por otro usuario y los
movimientos sin depósito deben estar justificados. También se verifican depósitos
del período pendientes, saldo inicial + créditos − débitos y balances consecutivos.
El botón **Verificar balances y cerrar** guarda el cierre y bloquea el flujo para
ese estado. No existe reapertura en esta versión.

En el menú **Períodos cerrados** se pueden filtrar cierres por banco y por períodos
que se cruzan con el rango indicado. Cada fila representa un estado de cuenta,
cuenta y moneda; no es un cierre consolidado de todos los bancos.

**Ver detalle** muestra la instantánea conservada. **Exportar Excel** descarga un
`.xlsx` con resumen, movimientos, depósitos, usuarios que prevalidaron y aprobaron,
observaciones y partidas justificadas. Los importes son celdas numéricas; las cuentas
y referencias se exportan como texto. La exportación requiere sesión activa y rol
Analista Contable, Supervisor o Administrador. No requiere cambiar el rol del analista.

Los cierres nuevos guardan también los depósitos, banco y nombres de los usuarios.
Los cierres anteriores que carezcan de esos campos mantienen sus movimientos y
los identificadores disponibles; no se reconstruyen los importes con datos actuales.

La exportación de la aplicación usa OOXML y ZipArchive de PHP 7.4, sin requerir una
versión de PhpSpreadsheet que no sea compatible con el servidor actual.
