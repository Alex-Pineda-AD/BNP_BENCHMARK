# Carga de estados de cuenta TXT

## Puesta en marcha

1. Aplicar **una sola vez** `migrations/20260923_estados_cuenta_multibanco.sql` a las tablas `estados_cuenta`, `transacciones_estado_cuenta` y `resumen_estado_cuenta` existentes. Este ajuste no crea las antiguas tablas con sufijo `_txt` ni migra datos desde ellas.
2. Verificar en el catálogo `bancos` que estén activos BAC, ATLANTIDA, FICOHSA y OCCIDENTE. Si falta uno, agregarlo mediante el mantenimiento de bancos. No se fijan IDs en el código.
3. Iniciar sesión y abrir `modules/conciliacion/cargar_estado_txt.php`.
4. Seleccionar banco y archivo. El formulario envía `banco_id`, `archivo` y el token de sesión `csrf` por POST al controlador `controllers/estado_cuenta_txt.php`.
5. El controlador responde JSON con el ID guardado, cantidades y advertencias. Un error de formato no guarda datos. Una advertencia de extracto parcial permite guardar el documento tal como llegó.

La migración se entrega para aplicar: no se ejecuta automáticamente al subir archivos. Se mantiene el nombre existente `ID_ESTADO_CIENTA` en movimientos. Los registros anteriores conservan su información; su banco no se asigna automáticamente.

## Cómo seguir el código

- **Controlador:** comprueba sesión, archivo y banco; selecciona el lector con `switch` y llama al guardado común.
- **`includes/lectores/`:** una función por banco; recibe el contenido y devuelve `cuenta`, `transacciones`, `resumen` y `advertencias`. No ejecuta SQL. BAC reutiliza además `includes/estado_cuenta_txt.php`.
- **`includes/lectores/comun.php`:** fechas, importes, moneda y estructura compartida. No contiene reglas de posición de columnas por banco.
- **`includes/guardar_estado_cuenta.php`:** tres INSERT preparados. La cuenta se guarda primero para obtener su ID y relacionar movimientos y resumen. `commit()` confirma; `rollBack()` revierte todo si falla cualquier inserción.

Para corregir un formato, modificar su lector. Para agregar un banco, crear su lector, registrarlo en `lectores/bancos.php`, agregar un `case` al controlador y probarlo con un archivo real.

## Reglas de datos

- Los campos ausentes son `NULL`, no cero. LPS y Lempiras se normalizan a HNL. Los importes se mantienen como texto decimal hasta insertarlos en DECIMAL; no se convierten a float.
- Referencias y cuentas se guardan como texto para conservar ceros iniciales y referencias alfanuméricas.
- `FECHA` conserva la fecha de encabezado de BAC y la fecha de reporte de Ficohsa. No se deduce a partir de la primera transacción. Atlántida usa años de dos cifras: PHP interpreta 00–69 como 2000–2069 y 70–99 como 1970–1999.
- BAC: `PRODUCTO` también se copia a `NUMERO_CUENTA`; no se interpreta STBGAV/STBUNC ni se confunde saldo disponible con saldo final.
- Atlántida: `Saldo Inicial` no se inserta como movimiento. La fila opcional `TOTAL MOVIMIENTOS` no tiene fecha: se guarda como resumen de montos y su saldo se conserva en `SALDO_FINAL`. Sus cantidades quedan NULL porque el archivo no las declara. Se aceptan también documentos sin esta fila; no se fabrica un resumen ni un período.
- Ficohsa: `fgetcsv()` maneja comas entre comillas. Débito/crédito vacío se interpreta como cero, según este formato; código ausente queda NULL.
- Occidente: se conservan hora, agencia, cajero y conceptos del resumen, incluidos cheques. El resumen puede corresponder al mes completo aunque el detalle sea parcial; se informa la diferencia de cantidades.
- Se conserva el archivo original completo en MEDIUMBLOB. El hash SHA-256 único impide repetir exactamente el mismo archivo. No detecta solapamientos entre archivos diferentes; tampoco se eliminan movimientos por referencia repetida.
- No se calculan resúmenes bancarios inexistentes ni se comprueba todavía la conciliación monetaria entre cada saldo y movimiento. Las advertencias de cantidad no certifican que un documento esté completo.

## Pruebas

```text
php tests/estado_cuenta_multibanco.php "carpeta con los cuatro archivos TXT" --mysql
```

Las cantidades esperadas corresponden a los cuatro ejemplos proporcionados. La prueba MySQL crea tablas **temporales** con los nombres reales dentro de su conexión, aplica el ajuste e inserta allí. No cambia tablas persistentes. Comprueba que una falla al guardar el resumen revierta también la cuenta y sus movimientos.
