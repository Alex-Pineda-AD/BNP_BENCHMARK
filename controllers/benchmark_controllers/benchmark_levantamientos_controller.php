<?php
session_start();
require_once __DIR__ . '/../../connection/connection.php';

if (!isset($pdo) && function_exists('database')) {
    $pdo = database();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_competidor = trim($_POST['id_competidor'] ?? '');
    $fecha_levantamiento = trim($_POST['fecha_levantamiento'] ?? '');
    $observaciones_general = trim($_POST['observaciones'] ?? '');
    $id_usuario = $_SESSION['user_id'];

    // Capturar los arreglos dinámicos enviados por las filas del modal
    $productosSeleccionados = $_POST['id_producto'] ?? [];
    $nombresComp = $_POST['nombre_competencia'] ?? [];
    $preciosComp = $_POST['precio_competencia'] ?? [];
    $obsItems = $_POST['observaciones_item'] ?? [];

    if (empty($id_competidor) || empty($fecha_levantamiento)) {
        $_SESSION['levantamiento_resultado'] = [
            'tipo' => 'danger',
            'mensaje' => 'Debe seleccionar un competidor y una fecha de inspección válida.'
        ];
        header('Location: ../../modules/benchmark/benchmark_levantamientos.php');
        exit;
    }

    if (empty($productosSeleccionados)) {
        $_SESSION['levantamiento_resultado'] = [
            'tipo' => 'danger',
            'mensaje' => 'Debe agregar al menos un producto a la comparativa.'
        ];
        header('Location: ../../modules/benchmark/benchmark_levantamientos.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Insertar la Cabecera del Levantamiento
        $stmtL = $pdo->prepare('
            INSERT INTO benchmark_levantamientos (id_usuario, id_competidor, fecha_levantamiento, observaciones) 
            VALUES (?, ?, ?, ?)
        ');
        $stmtL->execute([$id_usuario, $id_competidor, $fecha_levantamiento, $observaciones_general]);
        
        $id_levantamiento = $pdo->lastInsertId();

        // 2. Preparar la consulta para buscar el precio de referencia oficial y guardar el detalle
        $stmtPrecioRef = $pdo->prepare('SELECT precio_referencia FROM benchmark_productos WHERE id = ?');
        
        $stmtD = $pdo->prepare('
            INSERT INTO benchmark_precios_detalle 
            (id_levantamiento, id_producto, precio_base_referencia, nombre_producto_competencia, precio_competencia, observaciones) 
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        $registrados = 0;
        foreach ($productosSeleccionados as $index => $id_producto) {
            $idProdLimpio = trim($id_producto);
            $precioLimpio = trim($preciosComp[$index] ?? '');
            
            // Validar que se haya seleccionado un producto y escrito un precio válido
            if ($idProdLimpio !== '' && $precioLimpio !== '' && is_numeric($precioLimpio)) {
                
                // Consultar el precio de referencia actual del producto base Softland
                $stmtPrecioRef->execute([(int)$idProdLimpio]);
                $prodData = $stmtPrecioRef->fetch(PDO::FETCH_ASSOC);
                $precioBaseRef = (float)($prodData['precio_referencia'] ?? 0.00);

                $nombreComp = trim($nombresComp[$index] ?? '');
                $obsItem = trim($obsItems[$index] ?? '');

                $stmtD->execute([
                    $id_levantamiento,
                    (int)$idProdLimpio,
                    $precioBaseRef,
                    $nombreComp,
                    (float)$precioLimpio,
                    $obsItem
                ]);
                $registrados++;
            }
        }

        if ($registrados === 0) {
            $pdo->rollBack();
            $_SESSION['levantamiento_resultado'] = [
                'tipo' => 'danger',
                'mensaje' => 'Debe completar al menos un producto con su respectivo precio de competencia.'
            ];
            header('Location: ../../modules/benchmark/benchmark_levantamientos.php');
            exit;
        }

        $pdo->commit();

        $_SESSION['levantamiento_resultado'] = [
            'tipo' => 'success',
            'mensaje' => "¡Comparativo registrado correctamente! Se capturaron {$registrados} productos de la competencia."
        ];

    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['levantamiento_resultado'] = [
            'tipo' => 'danger',
            'mensaje' => 'Error en la base de datos: ' . $e->getMessage()
        ];
    }

    header('Location: ../../modules/benchmark/benchmark_levantamientos.php');
    exit;
}