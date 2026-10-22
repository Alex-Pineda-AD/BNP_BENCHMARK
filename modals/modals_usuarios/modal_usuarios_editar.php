<!--  <div class="modal fade text-left" id="editUser" tabindex="-1" 
    role="dialog" aria-labelledby="myModalLabel33" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">

 -->
<div class="modal fade text-left" id="editUser" tabindex="-1" role="dialog" aria-labelledby="myModalLabel33" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h4 class="modal-title text-dark" id="myModalLabel33"><i class="fas fa-user-plus px-1"></i> &nbsp; Editar Usuario</h4>
                <button type="button" class="close" data-bs-dismiss="modal"
                    aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <form action="../../controllers/mantenimiento_usuarios.php" method="POST">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['usuarios_token']) ?>">
<div class="modal-body">
                    <h6>Datos Generales</h6>
                    <div class="row">
                        <div class="col-6">
                            <label>Nombre Completo</label>
                            <div class="form-group">
                            <input type="text" placeholder="Juan Perez" maxlength="60" name="nombreCompleto" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="col-6">
                            <label>DNI: </label>
                            <div class="form-group">
                                <input type="text" placeholder="0801198428401" maxlength="20" name="dni" class="form-control" required>
                            </div>
                        </div>

                        <div class="col-6">
                            <label>Telefono: </label>
                            <div class="form-group">
                                <input type="text" placeholder="99990000" maxlength="15" name="telefono" class="form-control">
                            </div>
                        </div>
                        <div class="col-6">
                            <label>Correo: </label>
                            <div class="form-group">
                                <input type="email" placeholder="mail@banasupro.hn" maxlength="50" name="correo" class="form-control">
                            </div>
                        </div>
                    </div>
                        <hr>
                        <div class="col-12">
                            <h6>Datos de Usuario</h6> 
                            <div class="row">
                                <div class="col-4">
                                    <label>Usuario: </label>
                                    <div class="form-group">
                                        <input type="text" placeholder="jperez" name="usuario" class="form-control" required maxlength="20">
                                    </div>
                                </div>
                                <div class="col-4">
                                    <label>Contraseña: </label>
                                    <div class="input-group mb-3">
                                        <input type="password" id="passwordEditar" placeholder="Dejar vacía para conservar" name="pass" class="form-control">
                                        <button class="btn btn-light-secondary" type="button" onclick="mostrarContrasena(this)"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <label>Rol: </label>
                                    <div class="form-group">
                                        <select class="form-control" name="idRol" style="text-transform: uppercase;" required >
                                            <option value="" hidden> --</option>
                                        <?php 
                                        $centrosVenta=$pdo->prepare('
                                        SELECT ID, ROL
                                        FROM ROLES 
                                        WHERE ESTADO!=0 ORDER BY ROL ASC');

                                        $centrosVenta->execute();
                                        foreach ($centrosVenta as $cv ) {
                                            echo '<option value="'.$cv['ID'].'">'.escapar($cv['ROL']).'</option>';                                                                                 
                                        } ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        
                        <hr>
                        <div class="col-12">
                            <h6>Centros de venta</h6> 

                            <div class="row">
                                <div class="col-12">
                                    <select class="form-select optgroup text-dark" multiple="multiple" name="idCentroventas[]">
                                        <option value="" disabled hidden>SELECCIONE LOS CENTROS DE VENTA</option>
                                        <?php 
                                        $centrosVenta=$pdo->prepare('
                                        SELECT ID, CENTRO_VENTA, ID_CATEGORIA 
                                        FROM CENTRO_DE_VENTA ORDER BY ID ASC');
                                        $centrosVenta->execute();
                                        foreach ($centrosVenta as $cv ) {
                                            echo '<option value="'.$cv['ID'].'">'.$cv['ID'].' - '.escapar($cv['CENTRO_VENTA']).'</option>';                                                                                 
                                        } ?>
                                    </select>
                                </div>
                            </div>   
                        </div>
                </div>
                <input type="hidden" name="idUsuario">
<input type="text" name="bande" value="2" hidden readonly>


                <div class="modal-footer">
                    <button type="reset" class="btn btn-light-secondary"
                        data-bs-dismiss="modal">
                        <i class="bx bx-x d-block d-sm-none"></i>
                        <span class="d-none d-sm-block">Cancelar</span>
                    </button>
                    <button type="submit" class="btn btn-primary ml-1"
                        >
                        <i class="bx bx-check d-block d-sm-none"></i>
                        <span class="d-none d-sm-block"><i class="fas fa-save"></i> Guardar</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
