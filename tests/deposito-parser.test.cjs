const assert = require('node:assert/strict');
const { parseReceipt, assessReceipt } = require('../js/deposito-parser.js');
const text = `BAC
Notificación de transferencia
Hola,
Le informamos que DAVID FERNANDO
IZAGUIRRE MEJIA realizó una transferencia a
la cuenta bancaria N° 754231111 a nombre de
KARLA YOLANY RIVERA RAMOS.
Fecha 20 septiembre 2026
Hora 6:10 PM
Monto L200.00
Detalle Jabón
Referencia 412415174`;
const parsed = parseReceipt(text);
assert.deepEqual(parsed, { BANCO: 'BAC', NUMERO_CUENTA: '754231111', REMITENTE: 'DAVID FERNANDO IZAGUIRRE MEJIA', BENEFICIARIO: 'KARLA YOLANY RIVERA RAMOS', FECHA: '2026-09-20', HORA: '18:10', MONTO: '200.00', MONEDA: 'HNL', NUMERO_REFERENCIA: '412415174', COMENTARIO: 'Jabón' });
assert.equal(assessReceipt(text, 90).readable, true);
assert.equal(assessReceipt(text, 20).readable, false);
assert.equal(assessReceipt('Imagen sin datos legibles', 95).readable, false);
assert.equal(assessReceipt(text.replace('Referencia 412415174', ''), 90).readable, true);
assert.equal(parseReceipt(text.replace('20 septiembre', '31 septiembre')).FECHA, '');
assert.equal(parseReceipt(text.replace('6:10 PM', '12:05 AM')).HORA, '00:05');
assert.equal(parseReceipt(text.replace('6:10 PM', '12:05 PM')).HORA, '12:05');
assert.equal(parseReceipt(text.replace('L200.00', 'L1,234.56')).MONTO, '1234.56');
assert.equal(parseReceipt(text.replace('L200.00', 'L1.234,56')).MONTO, '1234.56');
assert.equal(parseReceipt(text.replace('L200.00', 'USD 200.50')).MONEDA, 'USD');
assert.equal(parseReceipt(text.replace('L200.00', 'L0.00')).MONTO, '');
console.log('OK: extracción, centavos, medianoche, moneda, fechas inválidas y rechazo de lecturas incompletas.');

const ficohsa = `Ficohsa
DEPOSITO DE EFECTIVO
TT26194C1KZK
FECHA DEPOSITO 11-07-2026 11:56AM
NO. DE CUENTA 4421949/010102347700
NOMBRE BANASUPRO MONEDA HNL
MONTO DEPOSITADO ******7,000.00
DEPOSITANTE JOSE CELSO DIAZ DIAZ
IDENTIFICACION 1234`;
const f = parseReceipt(ficohsa);
assert.equal(f.BANCO, 'FICOHSA');
assert.equal(f.NUMERO_CUENTA, '4421949/010102347700');
assert.equal(f.NUMERO_REFERENCIA, 'TT26194C1KZK');
assert.equal(f.MONTO, '7000.00');
assert.equal(f.BENEFICIARIO, 'BANASUPRO');
assert.equal(f.HORA, '11:56');
assert.equal(f.REMITENTE, 'JOSE CELSO DIAZ DIAZ');
assert.equal(f.COMENTARIO, '');
assert.equal(assessReceipt(ficohsa, 85).readable, true);
const occidente = `Banco de Occidente, S.A.
DEPOSITO CUENTA CHEQUES M/N
FECHA PROCESO 09/07/2026 FECHA ACTUAL 09/07/2026
Hora 15:29:39
N.Documento 54570519 N.Autorizacion 209484
N.Cuenta 11-411-000006-0
Cuentahabiente SUPLIDORA NACIONAL DE PRODUCTOS BAS
EFECTIVO L5,300.00 TOTAL DEPOSITO L5,300.00
DEPOSITANTE KARLA MENDEZ`;
const o = parseReceipt(occidente);
assert.equal(o.BANCO, 'OCCIDENTE');
assert.equal(o.NUMERO_REFERENCIA, '54570519');
assert.equal(o.NUMERO_CUENTA, '11-411-000006-0');
assert.equal(o.MONTO, '5300.00');
assert.equal(o.HORA, '15:29');
assert.equal(o.FECHA, '2026-07-09');
assert.equal(assessReceipt(occidente, 85).readable, true);
assert.equal(assessReceipt(occidente, 42).readable, false);
assert.equal(parseReceipt(occidente.replace('L5,300.00\n', 'L5,300.000\n')).MONTO, '');
console.log('OK: formatos Ficohsa y Occidente, sin inventar detalle ni aceptar montos ambiguos.');

assert.equal(assessReceipt(text.replace('20 septiembre 2026', '').replace('6:10 PM', ''), 90).readable, true);

const partial = ficohsa.replace('TT26194C1KZK', 'TT26194C1K ZK x').replace('******7,000.00', '¥s%4¥%xx7 000.00');
assert.equal(assessReceipt(partial, 60).readable, true);
assert.equal(parseReceipt(partial).NUMERO_REFERENCIA, '');
assert.equal(parseReceipt(partial).MONTO, '');
assert.equal(assessReceipt(partial, 42).readable, false);
assert.equal(assessReceipt('FICOHSA ' + 'texto sin campos '.repeat(10), 90).readable, false);
