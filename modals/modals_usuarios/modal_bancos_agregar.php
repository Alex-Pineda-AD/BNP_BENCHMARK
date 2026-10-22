<!--  <div class="modal fade text-left" id="addUser" tabindex="-1" 
    role="dialog" aria-labelledby="myModalLabel33" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
 -->

    <div class="modal fade" id="addUser" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h4 class="modal-title text-white" id="myModalLabel33"><i class="fas fa-user-plus"></i> &nbsp; Nuevo Banco</h4>
                <button type="button" class="close" data-bs-dismiss="modal"
                    aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <form action="../../controllers/mantenimiento_bancos.php" method="POST">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['usuarios_token']) ?>">
                <div class="modal-body">
                    <h6>Datos Generales</h6>
                    <div class="row">
                        <div class="col-6">
                            <label>Nombre del banco</label>
                            <div class="form-group">
                            <input type="text" maxlength="60" name="descripcion" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="col-6">
                            <label>Numero de cuenta: </label>
                            <div class="form-group">
                                <input type="text"  maxlength="20" name="nuemroCuenta" class="form-control" required>
                            </div>
                        </div>

                    </div>
                        
                        
                </div>
                <input type="text" name="bande" value="1" hidden readonly>


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
