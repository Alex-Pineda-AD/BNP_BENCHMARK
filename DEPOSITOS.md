# Registro de depósitos con cámara

Pantalla: `http://localhost/conciliacion_bancaria/BNB/BNP-ConciliacionBancaria/modules/conciliacion/depositos.php`.

## Mantenimiento de la pantalla

Se utilizan las clases de Bootstrap/Mazer de la plantilla. No hay un archivo CSS propio para depósitos: los estilos de Select2 y su ajuste de posición están al final de `assets/css/app.css`, con comentarios. La plantilla tiene `zoom: .8`; por eso la lista se ancla al contenedor del campo y se posiciona debajo con `top: 100%`, evitando cálculos de coordenadas adicionales.

- `modules/conciliacion/depositos.php`: formulario y campos visibles.
- `js/centros-venta.js`: búsqueda por nombre, filtro por ID y selección.
- `js/depositos.js`: foto, lectura y envío del formulario.
- `connection/depositos_config.php`: habilitar o deshabilitar selección de archivos.

Ingresar por `index.html`. El login abre directamente el registro de depósitos.
La pantalla requiere una sesión de usuario activo antes de permitir cargar imágenes. Si falta la sesión, redirige al login con un mensaje explicativo; así no se llega a la captura con el selector vacío por falta de autenticación.

## Flujo

1. Seleccionar tipo de comprobante: impreso (OCR) o manuscrito (manual).
2. Tomar o cargar una foto JPG, PNG o WebP. La foto es obligatoria en ambos modos. No se admiten PDF; fotografiar cada voucher por separado.
3. Para manuscritos, no se ejecuta OCR: se habilitan los campos vacíos. El usuario debe completar banco, centro, cuenta, remitente, beneficiario, moneda, monto, referencia y detalle, además de confirmar la revisión.
4. Para impresos se reconocen las etiquetas de BAC, Ficohsa y Occidente. Se exige confianza OCR mínima de 50, banco reconocido y al menos tres datos identificados; una lectura parcial habilita la corrección obligatoria; el detalle puede completarse manualmente cuando no esté impreso. En Occidente se utiliza N. Documento como referencia; en Ficohsa, el código TT. No se corrigen ni inventan caracteres dudosos.
5. El botón Guardar se habilita solo con foto, campos válidos y confirmación. Editar cualquier dato desmarca la confirmación. El servidor vuelve a exigir campos completos, imagen válida, banco activo y centro autorizado.
6. Cambiar de tipo limpia los datos y exige otra foto. Al guardar se muestra SweetAlert2 y se limpian foto, campos y centro, conservando el tipo seleccionado.

Ambos modos guardan encabezado y detalle mediante una transacción, con estado pendiente de revisión. El tipo de captura controla la validación y no agrega columnas a la base de datos. La lectura OCR ocurre en el navegador; el servidor no vuelve a ejecutar OCR. En manuscritos, la legibilidad la comprueba el usuario: si no puede leer un dato debe tomar otra foto, sin inventar valores.

Los campos de cuenta admiten números separados por guiones o barras; la referencia admite letras, números y guiones. Esto permite las cuentas y referencias de los vouchers proporcionados. Cada lectura automática requiere comparación con la foto y no garantiza exactitud ni autenticidad.

## Cámara y procesamiento

Para pruebas se habilitó el selector de archivos JPG, PNG y WebP (8 MB máximo), que utiliza la misma lectura y validación que la cámara y conserva el archivo original. Para volver a mostrar solo la cámara, cambiar `DEPOSITOS_PERMITIR_ARCHIVOS` a `false` en `connection/depositos_config.php` y recargar la página. Con `true` aparecen ambas opciones. Este ajuste controla la interfaz; no certifica el origen de una imagen enviada al servidor.

La cámara requiere un contexto seguro: localhost en la computadora o HTTPS desde un teléfono. Una IP local por HTTP no permite `getUserMedia` en navegadores modernos. Si la cámara está ocupada, denegada o no existe, se muestra un mensaje para corregirlo. La cámara se libera al capturar, cerrar, ocultar la página o salir.

Se conserva la fotografía original. Una copia reduce subrayados rojos y amplía imágenes pequeñas para mejorar el reconocimiento. Si la primera lectura no es completa, se prueba la imagen sin preparar.

Se usa [Tesseract.js 6.0.1](https://github.com/naptha/tesseract.js/tree/v6.0.1). Motor, worker y modelos español/inglés están en `assets/vendors/tesseract`; la fotografía no se envía a servicios OCR externos. La primera lectura puede tardar más por la carga de modelos locales. La plantilla conserva su fuente web original.

## Persistencia

El centro de venta se selecciona con Select2 4.0.13 (archivos locales), con búsqueda por nombre o ID. El campo de ID filtra por prefijo y selecciona automáticamente una coincidencia exacta. La consulta une `usuarios`, `usuarios_centros_venta` y `centro_de_venta` (nombre real de la tabla local); exige usuario y asignación activos, sin excepciones para admin. Una lista vacía bloquea el guardado. La asignación se vuelve a validar en el servidor al insertar. Cambiar el centro exige reconfirmar los datos, y repetir una captura conserva el centro mientras el depósito aún no se haya guardado.

La migración `20260921_deposito_centro_venta.sql` añade `depositos.ID_CENTRO_VENTA`, con índice y NULL para depósitos históricos; los nuevos registros requieren un centro autorizado. El detalle queda asociado a ese centro mediante ID_DEPOSITO.

- Conexión: `connection/database.php`, MariaDB en 127.0.0.1:3307, base conciliacion.
- Migración aplicada: `migrations/20260921_depositos_ocr.sql` (InnoDB, DECIMAL(12,2), nombres, moneda, referencia única).
- Fotos con nombres aleatorios: `files/depositos/`. `.htaccess` deniega el acceso directo y la ejecución; no se incluye una galería pública.
- Campos FOTO_DEPOSITO y URL en ambas tablas guardan nombre generado y ruta relativa. El detalle enlaza mediante ID_DEPOSITO.
- Tamaño máximo: 8 MB, 25 megapíxeles; formatos JPEG, PNG, WebP. El navegador captura JPEG.
- `tools/create_admin.php` es solo CLI: crea admin con rol 5 si no existe y muestra una contraseña aleatoria una sola vez. No cambia usuarios existentes. No se guardan contraseñas en texto plano en el código.

## Verificaciones

```text
node tests/deposito-parser.test.cjs
C:/wamp64/bin/php/php8.3.0/php.exe tests/deposito-service.php
```

Las pruebas de base de datos usan tablas temporales que ocultan las tablas reales durante esa conexión. Comprueban centavos, relación de encabezado/detalle, referencia duplicada, validaciones y reversión si falla el detalle, sin crear depósitos reales.

También se verificaron en navegador el inicio de sesión, lectura completa de la captura proporcionada, rechazo de una imagen blanca y el flujo de captura con cámara simulada, edición y confirmación. La cámara física debe probarse en el dispositivo que usará el usuario.

## Fecha y hora del sistema

No se muestran campos de fecha ni hora. `includes/deposito_service.php` obtiene la fecha y hora actual del servidor en `America/Tegucigalpa` al procesar el guardado y utiliza el mismo valor para `FECHA_HORA` del depósito y su detalle. Los valores enviados por el navegador se ignoran. La fecha almacenada corresponde al registro en el sistema, no a la fecha impresa del voucher. El OCR no exige estos dos datos para habilitar el formulario.

## OCR opcional y registro manual de impresos

Una foto válida habilita el ingreso manual aunque el OCR no reconozca el formato, tenga baja confianza o falle el motor. Las lecturas insuficientes no rellenan campos con datos dudosos. El servidor exige la foto, los campos completos, la confirmación y el centro autorizado, pero no exige texto ni confianza OCR. Esto aplica también al modo impreso. Los archivos inválidos siguen bloqueados.
