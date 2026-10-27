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

    // Capturar los arreglos dinámicos enviados por las filas del modal (sin precio base)
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

        // 1. Insertar la Cabecera del Levantamiento (PostgreSQL RETURNING id)
        $stmtL = $pdo->prepare('
            INSERT INTO benchmark_levantamientos (id_usuario, id_competidor, fecha_levantamiento, observaciones) 
            VALUES (?, ?, ?, ?) RETURNING id
        ');
        $stmtL->execute([$id_usuario, $id_competidor, $fecha_levantamiento, $observaciones_general]);
        
        $id_levantamiento = $stmtL->fetchColumn();
        if (!$id_levantamiento) {
            $id_levantamiento = $pdo->lastInsertId();
        }

        $preciosBanasupro = $_POST['precio_banasupro_ref'] ?? [];
        $registrados = 0;

        // Validar si la columna precio_banasupro_referencia está disponible en la base de datos
        $tieneColumnaPrecioRef = false;
        try {
            $pdo->query("SELECT precio_banasupro_referencia FROM benchmark_precios_detalle LIMIT 1");
            $tieneColumnaPrecioRef = true;
        } catch (Throwable $e) {
            $tieneColumnaPrecioRef = false;
        }

        if ($tieneColumnaPrecioRef) {
            $stmtD = $pdo->prepare('
                INSERT INTO benchmark_precios_detalle 
                (id_levantamiento, id_producto, precio_banasupro_referencia, nombre_producto_competencia, precio_competencia, observaciones) 
                VALUES (?, ?, ?, ?, ?, ?)
            ');
        } else {
            $stmtD = $pdo->prepare('
                INSERT INTO benchmark_precios_detalle 
                (id_levantamiento, id_producto, nombre_producto_competencia, precio_competencia, observaciones) 
                VALUES (?, ?, ?, ?, ?)
            ');
        }

        foreach ($productosSeleccionados as $index => $id_producto) {
            $idProdLimpio = trim($id_producto);
            $precioLimpio = trim($preciosComp[$index] ?? '');
            
            // Validar que se haya seleccionado un producto y escrito un precio válido
            if ($idProdLimpio !== '' && $precioLimpio !== '' && is_numeric($precioLimpio)) {
                $obsItem = trim($obsItems[$index] ?? '');
                $precioBana = (float)($preciosBanasupro[$index] ?? 0);

                // Buscar el producto en la base de datos para asegurar FK válida y nombre automático
                $idFinal = (int)$idProdLimpio;
                $stmtBusq = $pdo->prepare("SELECT id, nombre_producto FROM benchmark_productos WHERE id = ? OR codigo = ? LIMIT 1");
                $stmtBusq->execute([$idFinal, $idProdLimpio]);
                $prodRow = $stmtBusq->fetch(PDO::FETCH_ASSOC);

                if ($prodRow) {
                    $idFinal = (int)($prodRow['ID'] ?? $prodRow['id']);
                    $nombreAuto = $prodRow['NOMBRE_PRODUCTO'] ?? $prodRow['nombre_producto'];
                } else {
                    $nombreAuto = 'Producto General';
                }

                // El nombre de la competencia es el mismo producto automáticamente si no se especifica
                $nombreComp = trim($nombresComp[$index] ?? '');
                if ($nombreComp === '') {
                    $nombreComp = $nombreAuto;
                }

                if ($tieneColumnaPrecioRef) {
                    $stmtD->execute([
                        $id_levantamiento,
                        $idFinal,
                        $precioBana,
                        $nombreComp,
                        (float)$precioLimpio,
                        $obsItem
                    ]);
                } else {
                    $stmtD->execute([
                        $id_levantamiento,
                        $idFinal,
                        $nombreComp,
                        (float)$precioLimpio,
                        $obsItem
                    ]);
                }
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