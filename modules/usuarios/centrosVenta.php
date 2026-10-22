<?php
session_start();
header('Cache-Control: no-store');

require_once __DIR__ . '/../../connection/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}

if (!in_array((int) ($_SESSION['id_rol'] ?? 0), [4, 5], true)) {
    http_response_code(403);
    exit('No tienes permiso para administrar usuarios.');
}
if (empty($_SESSION['usuarios_token'])) {
    $_SESSION['usuarios_token'] = bin2hex(random_bytes(32));
}
$pdo = database();

$consulta = $pdo->prepare('
    SELECT A.ID, CONCAT(A.ID,\' - \',A.CENTRO_VENTA) CENTRO_VENTA, UPPER(A.TIPO) TIPO, A.ID_CATEGORIA, 
    CASE 
        WHEN NULLIF(C.DIRECCION, \'\') IS NULL THEN \'badge bg-secondary text-white\'
        WHEN NULLIF(D.DESCRIPCION, \'\') IS NULL THEN \'badge bg-secondary text-white\'
        WHEN NULLIF(E.DESCRIPCION, \'\') IS NULL THEN \'badge bg-secondary text-white\'
        ELSE \'\'
    END BG, 

        COALESCE(NULLIF(C.DIRECCION, \'\'), \'..::: NO DEFINIDO :::..\') DIRECCION,
        COALESCE(NULLIF(D.DESCRIPCION, \'\'), \'..::: NO DEFINIDO :::..\') REGION,
        COALESCE(NULLIF(E.DESCRIPCION, \'\'), \'..::: NO DEFINIDO :::..\') DEPARTAMENTO,

    CASE 
        WHEN A.TIPO = \'Venta\' THEN \'\'
        WHEN A.TIPO = \'Consumo\' THEN \'\'
    END AS C_TIPO,
    CASE 
        WHEN B.DESCRIPCION = \'1 - COMERCIAL\' THEN \'\'
        WHEN B.DESCRIPCION = \'2 - PROVEEDURIA\' THEN \'\'
        ELSE \'badge bg-secondary\'
    END AS TIPO_DESC,
    CASE 
        WHEN B.DESCRIPCION = \'1 - COMERCIAL\' THEN \'COMERCIAL\'
        WHEN B.DESCRIPCION = \'2 - PROVEEDURIA\' THEN \'PROVEEDURIA\'
        WHEN B.DESCRIPCION = \'3 - AGROSUPRO\' THEN \'AGROSUPRO\'
        ELSE \'DESCONONCIDO\'
    END AS DESCRIPCION
    FROM centro_de_venta A
        INNER JOIN categoria B
            ON A.ID_CATEGORIA = B.ID
        LEFT JOIN ubicacion C
            ON A.ID_UBICACION = C.ID
        LEFT JOIN region D
             ON C.ID_REGION = D.ID
        LEFT JOIN departamentos E
            ON C.ID_DEPARTAMENTO = E.ID
        ORDER BY A.ID;
');
$consulta->execute();
$bancos = $consulta->fetchAll(PDO::FETCH_ASSOC);


// Escapar cualquier dato que se vaya a mostrar como HTML.
function escapar($valor)
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <?php include __DIR__ . '/../../includes/head.php'; ?>

     <!-- Include Choices CSS -->
    <link rel="stylesheet" href="../../assets/vendors/choices.js/choices.min.css" />

    <link rel="stylesheet" href="../../assets/vendors/simple-datatables/style.css">
</head>
<body>
<?php include __DIR__ . '/../../modals/modals_usuarios/modal_bancos_agregar.php';
      include __DIR__ . '/../../modals/modals_usuarios/modal_bancos_editar.php'; ?>
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
                            <h3>Usuarios</h3>
                            <!-- <p class="text-subtitle text-muted">For user to check they list</p> -->
                        </div>
                      
                    </div>
                </div>
                <section class="section">
                    <div class="card">
                        <div class="card-body">
                            <?php if (isset($_SESSION['usuarios_mensaje'])): ?>
                                <div class="alert alert-<?= escapar($_SESSION['usuarios_tipo']) ?>" role="alert">
                                    <?= escapar($_SESSION['usuarios_mensaje']) ?>
                                </div>
                                <?php unset($_SESSION['usuarios_mensaje'], $_SESSION['usuarios_tipo']); ?>
                            <?php endif; ?>
                            <button type="button" class="btn btn-primary btn-s" data-bs-toggle="modal" data-bs-target="#addUser" data-bs-whatever="@mdo" > <i class="fas fa-university"></i>&nbsp; Banco</button>
                            <table class="table table-striped table-xs table-hover" id="table1">
                                <thead>
                                    <tr>
                                        <th>CENTRO VENTA</th>
                                        <th>DEPTO</th>
                                        <th>REGION</th>
                                        <th>DIRECCION</th>
                                        <!-- <th>TIPO</th>
                                        <th>CATEGORIA</th> -->
                                        <th>ACCIONES</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $vr_correlativo=0; 
                                    foreach ($bancos as $row): 
                                        $vr_correlativo++; ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['CENTRO_VENTA']) ?></td>
                                            <td><span class="<?=  $row['BG'] ?>"><?= htmlspecialchars($row['DEPARTAMENTO']) ?></span></td>
                                            <td><span class="<?=  $row['BG'] ?>"><?= htmlspecialchars($row['REGION']) ?></span></td>
                                            <td><span class="<?=  $row['BG'] ?>"><?= htmlspecialchars($row['DIRECCION']) ?></span></td>
                                            <!-- <td><span class="<? //=  $row['C_TIPO'] ?>"><? //= escapar($row['TIPO']) ?></span></td>
                                            <td><span class="<? //=  $row['TIPO_DESC'] ?>"><? //= htmlspecialchars($row['DESCRIPCION']) ?></span></td> -->
                                            
                                            <td>
                                                <button type="button" class="btn btn-warning btn-sm text-dark" data-bs-toggle="modal" data-bs-target="#editUser" data-banco="<?= escapar(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>" > <i class="fas fa-edit"></i> Editar</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                  
                                </tbody>
                            </table>
                        </div>
                    </div>

                </section>
            </div>
                <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
    </main>
    </div>
    <!-- <script src="../../assets/vendors/perfect-scrollbar/perfect-scrollbar.min.js"></script> -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>

    <script src="../../assets/vendors/simple-datatables/simple-datatables.js"></script>
    <script>
        // Simple Datatable
        let table1 = document.querySelector('#table1');
        let dataTable = new simpleDatatables.DataTable(table1);
    </script>
    <script src="../../assets/vendors/choices.js/choices.min.js"></script>
    
    <script src="../../assets/js/main.js"></script>

    <script src="../../js/bancos.js?v=<?= filemtime(__DIR__ . '/../../js/bancos.js') ?>"></script>
</body>
</html>
