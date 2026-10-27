<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$sidebarUsuarioNombre=(string)($_SESSION['user_nombre']??'Usuario');
$sidebarUsuarioLogin=$sidebarUsuarioNombre;
$sidebarUsuarioRol='Rol no disponible';
$sidebarPuedeControl=false;
if(!empty($_SESSION['user_id'])) {
    try {
        require_once __DIR__.'/../connection/database.php';
        $sidebarUsuarioConsulta=database()->prepare('SELECT U.NOMBRE_COMPLETO,U.USUARIO,R.ROL FROM usuarios U LEFT JOIN roles R ON R.ID=U.ID_ROL WHERE U.ID=? AND U.ESTADO=1');
        $sidebarUsuarioConsulta->execute([(int)$_SESSION['user_id']]);
        $sidebarUsuarioDatos=$sidebarUsuarioConsulta->fetch();
        if($sidebarUsuarioDatos) {
            $sidebarUsuarioLogin=(string)$sidebarUsuarioDatos['USUARIO'];
            $sidebarUsuarioNombre=trim((string)$sidebarUsuarioDatos['NOMBRE_COMPLETO'])?:$sidebarUsuarioLogin;
            $sidebarUsuarioRol=(string)($sidebarUsuarioDatos['ROL']??'Rol no disponible');
            require_once __DIR__.'/conciliacion_periodos.php';
            $sidebarPuedeControl=conciliacionPropietario(database(),(int)$_SESSION['user_id']);
        }
    } catch(Throwable $sidebarUsuarioError) { error_log('No se pudo consultar el usuario del sidebar: '.$sidebarUsuarioError->getMessage()); }
}
?>
<style>
.sidebar-wrapper.sidebar-session-layout{display:flex;flex-direction:column;top:0;bottom:0;height:var(--layout-height,100vh);max-height:none;overflow:hidden}
.sidebar-session-layout>.sidebar-header{flex:0 0 auto}
.sidebar-session-layout>.sidebar-menu{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain}
.sidebar-session-footer{flex:0 0 auto;padding:16px 22px 18px;border-top:1px solid #e9ecef;background:#fff}
.sidebar-session-profile{display:flex;align-items:center;gap:12px;padding:12px;background:#f7f8fa;border:1px solid #eceef1;border-radius:10px}
.sidebar-session-avatar{display:flex;align-items:center;justify-content:center;flex:0 0 38px;height:38px;border-radius:50%;background:#e9ecf1;color:#505b6b;font-size:20px}
.sidebar-session-info{min-width:0;line-height:1.4;overflow-wrap:anywhere}
.sidebar-session-name{font-size:13px;font-weight:700;color:#303947}
.sidebar-session-login{font-size:11px;color:#77808b;margin-top:2px}
.sidebar-session-role{font-size:12px;color:#596574;margin-top:4px}
.sidebar-session-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin-top:10px;padding:8px 12px;border:0;border-radius:6px;background:transparent;color:#626b77;font-size:12px;cursor:pointer}
.sidebar-session-logout:hover{background:#f3f4f6;color:#303947}
.sidebar-session-logout:focus-visible{outline:2px solid #6b7280;outline-offset:2px}
</style>
<div class="sidebar-wrapper active sidebar-session-layout">
                <div class="sidebar-header">
                    <div class="d-flex justify-content-between">
                        <div class="logo">
                            <!-- <a href="index.html"><img src="../../assets/images/logo/logo.png" alt="Logo" srcset=""></a> -->
                            
                            <a ><h3>...::: Banasupro :::...</h3></a>
                        </div>
                        <div class="toggler">
                            <a href="#" class="sidebar-hide d-xl-none d-block"><i class="bi bi-x bi-middle"></i></a>
                        </div>
                    </div>
                </div>
                <div class="sidebar-menu">
                    <ul class="menu">
                        <?php 
                        // Validamos si la URL actual pertenece al módulo de benchmark
                        $esBenchmark = (strpos($_SERVER['SCRIPT_NAME'], 'benchmark') !== false); 
                        ?>
                        
                        <?php #    ...::::: SUPER  :::::...
                        if ($_SESSION['id_rol']==5 || $_SESSION['id_rol']==4) {?>
                        <li hidden class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'depositos.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/depositos.php" class="sidebar-link">
                                <i class="bi bi-camera"></i><span>Registrar depósito</span>
                            </a>
                        </li>
                        <li hidden class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'mis_depositos.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/mis_depositos.php" class="sidebar-link">
                                <i class="fas fa-receipt"></i><span>Mis depositos</span>
                            </a>
                        </li>

                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'depositos_revision.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/depositos_revision.php" class="sidebar-link">
                                <i class="fas fa-clipboard-check"></i><span>Validar depositos</span>
                            </a>
                        </li>

                        <li class="sidebar-item  has-sub">
                            <a href="#" class='sidebar-link'>
                                <i class="fas fa-sort-amount-up"></i>
                                <span>Estados de cuenta</span> 
                            </a>
                            <ul class="submenu ">
                                <li class="submenu-item ">
                                    <a class='sidebar-link' href="../conciliacion/transacciones_bancarias.php"><i class="fas fa-file-invoice-dollar"></i> Listado de Estados de cuenta</a>
                                </li>
                                <li class="submenu-item ">
                                    <a class='sidebar-link' href="../conciliacion/cargar_estado_cuentas_txt.php" ><i class="fas fa-file-upload"></i> Importar estado de cuenta</a>
                                </li>
                            </ul>
                        </li>

                        
                        
                        <?php if (in_array((int)($_SESSION['id_rol'] ?? 0), [2,4,5], true)): ?>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'cruce_depositos.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/cruce_depositos.php" class="sidebar-link"><i class="fas fa-link"></i><span>Cruce y prevalidación</span></a>
                        </li>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'cierres_conciliacion.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/cierres_conciliacion.php" class="sidebar-link"><i class="fas fa-archive"></i><span>Períodos cerrados</span></a>
                        </li>
                        <?php endif; ?>

                        <li class="sidebar-title"><i class="fas fa-sliders-h me-1"></i> Configuraciones</li>

                        <?php if($sidebarPuedeControl): ?>
                        <li class="sidebar-item <?=basename($_SERVER['SCRIPT_NAME'])==='control_periodos.php'?'active':''?>">
                            <a href="../conciliacion/control_periodos.php" class="sidebar-link">
                                <i class="fas fa-calendar-alt"></i><span>Control de períodos</span>
                            </a>
                        </li>
                        <?php endif; ?>

                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'configuracion_alertas.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/configuracion_alertas.php" class="sidebar-link">
                                <i class="bi bi-bell"></i><span>Alertas de seguimiento</span>
                            </a>
                        </li>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'bancos.php' ? 'active' : '' ?>">
                            <a  href="../usuarios/bancos.php" class="sidebar-link">
                                <i class="fas fa-university"></i><span>Bancos</span>
                            </a>
                        </li>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'centrosVenta.php' ? 'active' : '' ?>">
                            <a  href="../usuarios/centrosVenta.php" class="sidebar-link">
                                <i class="fas fa-store"></i><span>Centros de venta</span>
                            </a>
                        </li>

                        <?php if (in_array((int)($_SESSION['id_rol'] ?? 0), [5], true)): ?>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'usuarios.php' ? 'active' : '' ?>">
                            <a href="../usuarios/usuarios.php" class="sidebar-link">
                                <i class="fas fa-users-cog"></i><span>Usuarios</span>
                            </a>
                        </li>
                        <?php endif ?>
                            
                        <?php }  #    ...::::: FIN SUPER :::::...





                             #    ...::::: VENTAS:::::...
                        if ($_SESSION['id_rol']==3) {?>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'depositos.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/depositos.php" class="sidebar-link">
                                <i class="bi bi-camera"></i><span>Registrar depósito</span>
                            </a>
                        </li>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'index.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/mis_depositos.php" class="sidebar-link">
                                <i class="fas fa-receipt"></i><span>Mis depositos</span>
                            </a>
                        </li>

                        <li hidden class="sidebar-title">Configuraciones</li>
                        <li hidden class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'index.php' ? 'active' : '' ?>">
                            <a href="../usuarios/usuarios.php" class="sidebar-link">
                                <i class="fas fa-users-cog"></i><span>Usuarios</span>
                            </a>
                        </li>
                        <?php }  #    ...::::: FIN VENTAS :::::...


                             #    ...::::: CONTABLE :::::...
                         if ($_SESSION['id_rol']==2) {?>


                         <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'depositos_revision.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/depositos_revision.php" class="sidebar-link">
                                <i class="fas fa-clipboard-check"></i><span>Validar depositos</span>
                            </a>
                        </li>

                        <li  class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'cargar_estado_cuentas_txt.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/cargar_estado_cuentas_txt.php" class="sidebar-link">
                                <i class="fa fa-file-upload"></i><span>Cargar estado de cuenta</span>
                            </a>
                        </li>

                        <li  class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'transacciones_bancarias.php' ? 'active' : '' ?>">
                            <a href="../conciliacion/transacciones_bancarias.php" class="sidebar-link">
                               <i class="fa fa-file-invoice-dollar"></i><span>Estados de cuenta</span>
                            </a>
                        </li>

                        <?php }  #    ...::::: FIN CONTABLE :::::...?>
                        
                        <!-- ...::::: BENCHMARK :::::... -->
                        <?php if (in_array((int)($_SESSION['id_rol'] ?? 0), [5, 6], true)): ?>
                        <li class="sidebar-title"><i class="fas fa-chart-line me-1"></i> Benchmark</li>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'benchmark_competidores.php' ? 'active' : '' ?>">
                            <a href="../benchmark/benchmark_competidores.php" class="sidebar-link">
                                <i class="fas fa-building"></i>
                                <span>Competidores</span>
                            </a>
                        </li>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'benchmark_levantamientos.php' ? 'active' : '' ?>">
                            <a href="../benchmark/benchmark_levantamientos.php" class="sidebar-link">
                                <i class="fas fa-clipboard-list"></i>
                                <span>Levantamiento de Precios</span>
                            </a>
                        </li>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'benchmark_tabla_comparativa.php' ? 'active' : '' ?>">
                            <a href="../benchmark/benchmark_tabla_comparativa.php" class="sidebar-link">
                                <i class="fas fa-chart-bar"></i>
                                <span>Tabla Comparativa</span>
                            </a>
                        </li>
                        <?php endif; ?>

                        <!-- ...::::: MANTENIMIENTO (ROL 6) :::::... -->
                        <?php if (in_array((int)($_SESSION['id_rol'] ?? 0), [6], true)): ?>
                        <li class="sidebar-title"><i class="fas fa-cogs me-1"></i> Mantenimiento</li>
                        <li class="sidebar-item <?= basename($_SERVER['SCRIPT_NAME']) === 'usuarios.php' ? 'active' : '' ?>">
                            <a href="../usuarios/usuarios.php" class="sidebar-link">
                                <i class="fas fa-users-cog"></i>
                                <span>Usuarios</span>
                            </a>
                        </li>
                        <?php endif; ?>


                    </ul>
                </div>
                <div class="sidebar-session-footer" aria-label="Usuario de la sesión">
                    <div class="sidebar-session-profile">
                        <div class="sidebar-session-avatar" aria-hidden="true"><i class="bi bi-person"></i></div>
                        <div class="sidebar-session-info">
                            <div class="sidebar-session-name"><?=htmlspecialchars($sidebarUsuarioNombre,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div>
                            <?php if($sidebarUsuarioLogin!==$sidebarUsuarioNombre): ?><div class="sidebar-session-login">@<?=htmlspecialchars($sidebarUsuarioLogin,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div><?php endif; ?>
                            <div class="sidebar-session-role"><?=htmlspecialchars($sidebarUsuarioRol,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div>
                        </div>
                    </div>
                    <?php $_SESSION['cerrar_sesion_token'] = $_SESSION['cerrar_sesion_token'] ?? bin2hex(random_bytes(32)); ?>
                    <form action="../../controllers/cerrar_sesion.php" method="POST">
                        <input type="hidden" name="token" value="<?=htmlspecialchars($_SESSION['cerrar_sesion_token'],ENT_QUOTES,'UTF-8')?>">
                        <button type="submit" class="sidebar-session-logout"><i class="fas fa-sign-out-alt" aria-hidden="true"></i><span>Cerrar sesión</span></button>
                    </form>
                </div>
                <button class="sidebar-toggler btn x"><i data-feather="x"></i></button>
            </div>