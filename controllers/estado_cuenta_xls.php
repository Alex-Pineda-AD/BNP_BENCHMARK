<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

function volver(string $tipo, string $mensaje): void
{
    $_SESSION['estado_cuenta_resultado'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
    header('Location: ../modules/conciliacion/estado_cuentas.php');
    exit;
}

function leerPlantilla(string $ruta): array
{
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo Excel.');
    }
    try {
        $cadenas = [];
        $xmlCadenas = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlCadenas !== false) {
            $documento = new DOMDocument();
            if (!@$documento->loadXML($xmlCadenas, LIBXML_NONET)) {
                throw new RuntimeException('Las cadenas del Excel no son válidas.');
            }
            foreach ($documento->getElementsByTagNameNS('*', 'si') as $elemento) {
                $texto = '';
                foreach ($elemento->getElementsByTagNameNS('*', 't') as $fragmento) {
                    $texto .= $fragmento->textContent;
                }
                $cadenas[] = $texto;
            }
        }
        $xmlHoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($xmlHoja === false) {
            throw new RuntimeException('No se encontró la primera hoja del Excel.');
        }
        $documento = new DOMDocument();
        if (!@$documento->loadXML($xmlHoja, LIBXML_NONET)) {
            throw new RuntimeException('La hoja del Excel no es válida.');
        }
        $filas = [];
        foreach ($documento->getElementsByTagNameNS('*', 'row') as $fila) {
            $datos = [];
            foreach ($fila->getElementsByTagNameNS('*', 'c') as $celda) {
                preg_match('/^[A-Z]+/', $celda->getAttribute('r'), $coincidencia);
                $columna = $coincidencia[0] ?? '';
                $valor = $celda->getElementsByTagNameNS('*', 'v')->item(0);
                $texto = $valor ? $valor->textContent : '';
                if ($celda->getAttribute('t') === 's') {
                    $texto = $cadenas[(int) $texto] ?? '';
                } elseif ($celda->getAttribute('t') === 'inlineStr') {
                    $texto = $celda->textContent;
                }
                $datos[$columna] = $texto;
            }
            $filas[(int) $fila->getAttribute('r')] = $datos;
        }
        return $filas;
    } finally {
        $zip->close();
    }
}

function encabezado(string $texto): string
{
    $texto = strtr(mb_strtolower(trim($texto), 'UTF-8'),
        ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', '°' => '', 'º' => '']);
    return preg_replace('/[^a-z]/', '', $texto);
}

function columnasDocumento(array $filas): array
{
    $nombres = [
        'fecha' => ['fecha'], 'referencia' => ['referencia', 'numerodereferencia'],
        'codigo' => ['codigo', 'tipo'], 'descripcion' => ['descripcion'],
        'cheque' => ['cheque', 'ncheque', 'numerodecheque'],
        'debito' => ['debito', 'debitos'], 'credito' => ['credito', 'creditos'],
        'balance' => ['balance', 'saldo'],
    ];
    foreach ($filas as $numero => $fila) {
        if ($numero > 25) {
            break;
        }
        $mapa = [];
        foreach ($fila as $columna => $valor) {
            $normalizado = encabezado((string) $valor);
            foreach ($nombres as $campo => $opciones) {
                if (in_array($normalizado, $opciones, true)) {
                    $mapa[$campo] = $columna;
                }
            }
        }
        if (isset($mapa['fecha'], $mapa['referencia'], $mapa['descripcion'],
            $mapa['debito'], $mapa['credito'], $mapa['balance'])) {
            return [$numero, $mapa];
        }
    }
    // La plantilla proporcionada comienza directamente con los movimientos.
    $primera = reset($filas);
    if ($primera && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', trim((string) ($primera['A'] ?? '')))) {
        return [0, ['fecha' => 'A', 'referencia' => 'B', 'codigo' => 'D',
            'descripcion' => 'E', 'debito' => 'H', 'credito' => 'I', 'balance' => 'J']];
    }
    throw new RuntimeException('No se reconocieron las columnas del estado de cuenta.');
}

function montoExcel($valor, int $fila, string $campo): string
{
    $texto = str_replace([',', ' ', "\xc2\xa0"], '', trim((string) $valor));
    if ($texto === '' || !is_numeric($texto)) {
        throw new RuntimeException("$campo inválido en la fila $fila.");
    }
    return $texto;
}

function fechaExcel($valor, int $fila): string
{
    $texto = trim((string) $valor);
    if (is_numeric($texto)) {
        $fecha = (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) floor((float) $texto) . ' days');
        return $fecha->format('Y-m-d');
    }
    $fecha = DateTimeImmutable::createFromFormat('!d/m/Y', $texto);
    if (!$fecha || $fecha->format('d/m/Y') !== $texto) {
        throw new RuntimeException("Fecha inválida en la fila $fila.");
    }
    return $fecha->format('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    volver('danger', 'Seleccione un archivo Excel para importar.');
}
if (empty($_SESSION['user_id'])) {
    volver('danger', 'Debe iniciar sesión para importar estados de cuenta.');
}
if (empty($_SESSION['estado_cuenta_csrf']) || !hash_equals($_SESSION['estado_cuenta_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    volver('danger', 'La sesión expiró. Recargue la página.');
}
$archivo = $_FILES['estado_cuenta'] ?? null;
if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
    volver('danger', 'No se recibió el archivo. Compruebe el tamaño permitido e inténtelo de nuevo.');
}
if (strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
    volver('danger', 'El archivo debe ser .xlsx.');
}

$pdo = null;
try {
    $pdo = database();
    $idBanco = filter_input(INPUT_POST, 'id_banco', FILTER_VALIDATE_INT);
    $consultaBanco = $pdo->prepare('SELECT ID FROM bancos WHERE ID = ? AND ESTADO = 1');
    $consultaBanco->execute([$idBanco ?: 0]);
    if (!$consultaBanco->fetch()) {
        throw new RuntimeException('Seleccione un banco activo.');
    }
    $filas = leerPlantilla($archivo['tmp_name']);
    [$filaEncabezado, $columnas] = columnasDocumento($filas);
    $pdo->beginTransaction();
    $original = file_get_contents($archivo['tmp_name']);
    if ($original === false) throw new RuntimeException('No se pudo leer el archivo original.');
    $encabezado = $pdo->prepare('INSERT INTO estados_cuenta
        (ID_USUARIO, ID_BANCO, NOMBRE_ARCHIVO, HASH_ARCHIVO, ARCHIVO_ORIGINAL, NOMBRE, MONEDA)
        VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING ID');
    $encabezado->bindValue(1, (int) $_SESSION['user_id'], PDO::PARAM_INT);
    $encabezado->bindValue(2, $idBanco, PDO::PARAM_INT);
    $encabezado->bindValue(3, basename($archivo['name']));
    $encabezado->bindValue(4, hash('sha256', $original));
    $encabezado->bindValue(5, $original, PDO::PARAM_LOB);
    $encabezado->bindValue(6, basename($archivo['name']));
    // La plantilla existente expresa los montos en lempiras.
    $encabezado->bindValue(7, 'HNL');
    $encabezado->execute();
    $idEstado = $encabezado->fetchColumn();
    $insertar = $pdo->prepare('INSERT INTO transacciones_estado_cuenta
        (REFERENCIA, CODIGO, DESCRIPCION, FECHA, DEBITO, CREDITO, BALANCE,
         NUMERO_CHEQUE, ID_ESTADO_CIENTA)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $cantidad = 0;
    foreach ($filas as $numero => $fila) {
        if ($numero <= $filaEncabezado) {
            continue;
        }
        $dato = static function (string $campo) use ($fila, $columnas): string {
            return trim((string) ($fila[$columnas[$campo] ?? ''] ?? ''));
        };
        $fechaOriginal = $dato('fecha');
        $referencia = $dato('referencia');
        $tipo = $dato('codigo');
        $descripcion = $dato('descripcion');
        $cheque = $dato('cheque');
        if ($fechaOriginal === '' && $referencia === '' && $descripcion === '') {
            continue;
        }
        if (encabezado($descripcion) === 'saldoinicial') {
            continue;
        }
        $fecha = fechaExcel($fechaOriginal, $numero);
        $debito = montoExcel($dato('debito'), $numero, 'Débito');
        $credito = montoExcel($dato('credito'), $numero, 'Crédito');
        $saldo = montoExcel($dato('balance'), $numero, 'Saldo');
        if (strlen($referencia) > 50 || strlen($cheque) > 20 || strlen($tipo) > 10 || strlen($descripcion) > 100) {
            throw new RuntimeException("Texto demasiado largo en la fila $numero.");
        }
        $insertar->execute([$referencia, $tipo, $descripcion, $fecha,
            $debito, $credito, $saldo, $cheque, $idEstado]);
        $cantidad++;
    }
    if ($cantidad === 0) {
        throw new RuntimeException('El archivo no contiene movimientos.');
    }
    $pdo->commit();
    volver('success', "Se importaron $cantidad movimientos.");
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Importación de estado de cuenta: ' . $e->getMessage());
    volver('danger', 'No se pudo importar: ' . $e->getMessage());
}
