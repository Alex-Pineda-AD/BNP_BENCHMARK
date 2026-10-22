<?php
session_start();
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}

require_once __DIR__ . '/../../connection/database_sqlserver.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include __DIR__ . '/../../includes/head.php'; ?>

    <link rel="stylesheet" href="../../assets/vendors/simple-datatables/style.css">
</head>
<body>
<div id="app">
    <div id="sidebar" class="active"><?php include __DIR__ . '/../../includes/menu_sidebar.php'; ?></div>
    <main id="main">
            <header class="mb-3">
                <a href="#" class="burger-btn d-block d-xl-none">
                    <i class="bi bi-justify fs-3"></i>
                </a>
            </header>

            <div class="page-heading">
                <div class="page-title">
                    <div class="row">
                        <div class="col-12 col-md-6 order-md-1 order-last">
                            <h3>Lista de articulos</h3>
                            <!-- <p class="text-subtitle text-muted">For user to check they list</p> -->
                        </div>
                      
                    </div>
                </div>
                <section class="section">
                    <div class="card">
                        <div class="card-body">
                            <table class="table table-striped " id="table1">
                                <thead>
                                    <tr>
                                        <th>ARTICULO</th>
                                        <th>DESCRIPCION</th>
                                        <th>CLASIFICACION_1</th>
                                        <th>CLASIFICACION_2</th>
                                        <th>CLASIFICACION_3</th>
                                        <th>UNIDAD_ALMACEN</th>
                                        <th>UNIDAD_EMPAQUE</th>
                                        <th>UNIDAD_VENTA</th>
                                        <th>ACCIONES</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $articulos = [];

                                     try {
                                                $articulos = database_sqlserver()->query('SELECT TOP 100
                                                    ARTICULO, DESCRIPCION, CLASIFICACION_1, CLASIFICACION_2, CLASIFICACION_3,
                                                    UNIDAD_ALMACEN, UNIDAD_EMPAQUE, UNIDAD_VENTA
                                                    FROM BANASUPRO.ARTICULO 
                                                    ORDER BY ARTICULO DESC')->fetchAll();
                                            } catch (PDOException $e) {
                                                $errorConsulta = 'No se pudieron consultar articulos.';
                                            }
                                            foreach ($articulos as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['ARTICULO']) ?></td>
                                            <td><?= htmlspecialchars($row['DESCRIPCION']) ?></td>
                                            <td><?= number_format($row['CLASIFICACION_1']) ?></td>
                                            <td><?= number_format($row['CLASIFICACION_2']) ?></td>
                                            <td><?= number_format($row['CLASIFICACION_3'], 2) ?></td>
                                            <td><?= htmlspecialchars($row['UNIDAD_ALMACEN']) ?></td>
                                            <td><?= htmlspecialchars($row['UNIDAD_EMPAQUE']) ?></td>
                                            <td><?= htmlspecialchars($row['UNIDAD_VENTA']) ?></td>
                                            <td><a href="" class="btn btn-info btn-sm"><i class="fas fa-upload"></i> cargar</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                  
                                </tbody>
                            </table>
                        </div>
                    </div>

                </section>
            </div>
                <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
        </div>
</main>
    </div>
    <script src="../../assets/vendors/perfect-scrollbar/perfect-scrollbar.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>

    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script>
        // Simple Datatable
        let table1 = document.querySelector('#table1');
        let dataTable = new simpleDatatables.DataTable(table1);
    </script>
    <script src="../../assets/js/main.js"></script>
</body>
</html>