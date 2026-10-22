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
    SELECT A.ID, A.DNI, A.NUM_TELEFONO, A.ID_ROL, A.USUARIO, A.NOMBRE_COMPLETO,
           COALESCE(A.CORREO, \'-\') CORREO,
           CASE 
                WHEN A.ESTADO = 1 THEN \'Activo\'
                WHEN A.ESTADO = 0 THEN \'Inactivo\'
                ELSE \'Desconocido\'
            END AS ESTADO,
                   B.ROL
    FROM usuarios A
    LEFT JOIN roles B 
    ON A.ID_ROL = B.ID ORDER BY A.ID
');
$consulta->execute();
$usuarios = $consulta->fetchAll(PDO::FETCH_ASSOC);
$centrosPorUsuario = [];
$consultaCentros = $pdo->query('SELECT ID_USUARIO, ID_CENTRO_VENTA FROM usuarios_centros_venta WHERE ESTADO = 1');
foreach ($consultaCentros as $centro) {
    $centrosPorUsuario[$centro['ID_USUARIO']][] = (string) $centro['ID_CENTRO_VENTA'];
}

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
<?php include __DIR__ . '/../../modals/modals_usuarios/modal_usuarios_agregar.php';
      include __DIR__ . '/../../modals/modals_usuarios/modal_usuarios_editar.php'; ?>
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
                            <button type="button" class="btn btn-primary btn-s" data-bs-toggle="modal" data-bs-target="#addUser" data-bs-whatever="@mdo" > <i class="fas fa-user-plus"></i>&nbsp; Usuario</button>
                            <table class="table table-striped table-xs" id="table1">
                                <thead>
                                    <tr>
                                        <th>USUARIO</th>
                                        <th>NOMBRE_COMPLETO</th>
                                        <th>CORREO</th>
                                        <th>ESTADO</th>
                                        <th>ROL</th>
                                        <th>ACCIONES</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usuarios as $row): 

                                        $disabled=$row['ID_ROL']==5 ? 'disabled' : 'enabled'; ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['USUARIO']) ?></td>
                                            <td><?= htmlspecialchars($row['NOMBRE_COMPLETO']) ?></td>
                                            <td><?= escapar($row['CORREO']) ?></td>
                                            <td><?= htmlspecialchars($row['ESTADO']) ?></td>
                                            <td><?= escapar($row['ROL']) ?></td>
                                            <td><button type="button" class="btn btn-warning btn-sm text-dark " <?php echo $disabled ?> data-bs-toggle="modal" data-bs-target="#editUser" data-usuario="<?= escapar(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>" data-centros="<?= escapar(json_encode($centrosPorUsuario[$row['ID']] ?? [])) ?>"> <i class="fas fa-user-edit"></i> Editar</button></td>
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

    <script src="../../js/usuarios.js?v=<?= filemtime(__DIR__ . '/../../js/usuarios.js') ?>"></script>
</body>
</html>
