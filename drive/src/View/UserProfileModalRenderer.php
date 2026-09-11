<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class UserProfileModalRenderer
{
    public static function render(string $csrf): string
    {
        $e = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $csrfEscaped = $e($csrf);

        return <<<HTML
<div class="modal fade" id="modalUserProfile" tabindex="-1" role="dialog" aria-labelledby="modalUserProfileTitle" aria-hidden="true" data-csrf="{$csrfEscaped}">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="modalUserProfileTitle"><i class="fas fa-user-circle mr-2"></i>Mi perfil</h5>
          <small class="text-muted">Tus datos personales, imagen y seguridad de acceso.</small>
        </div>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>

      <div class="modal-body">
        <div id="profileAlert" class="alert d-none" role="alert"></div>

        <div class="row">
          <div class="col-lg-4 mb-4">
            <div class="card h-100">
              <div class="card-body text-center">
                <div id="profileAvatarPreview" class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle border" style="width:128px;height:128px;font-size:2rem;font-weight:700;overflow:hidden;">U</div>
                <div id="profileAliasPreview" class="font-weight-bold mb-3">@usuario</div>

                <input id="profilePictureInput" type="file" class="form-control-file mb-2" accept="image/jpeg,image/png,image/webp">
                <small class="form-text text-muted mb-3">JPG, PNG o WebP. Máximo 5 MB y 4096 × 4096.</small>

                <button id="btnUploadProfilePicture" type="button" class="btn btn-primary btn-sm mr-1">
                  <i class="fas fa-camera mr-1"></i>Cambiar imagen
                </button>
                <button id="btnRemoveProfilePicture" type="button" class="btn btn-outline-secondary btn-sm">
                  Usar iniciales
                </button>
              </div>
            </div>
          </div>

          <div class="col-lg-8 mb-4">
            <div class="card h-100">
              <div class="card-header font-weight-bold">Datos personales</div>
              <div class="card-body">
                <form id="profilePersonalForm" autocomplete="off">
                  <div class="form-row">
                    <div class="form-group col-md-6"><label for="profileFirstname">Nombre</label><input id="profileFirstname" name="firstname" class="form-control" maxlength="255"></div>
                    <div class="form-group col-md-6"><label for="profileLastname">Apellidos</label><input id="profileLastname" name="lastname" class="form-control" maxlength="255"></div>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-md-6"><label for="profileCurp">CURP</label><input id="profileCurp" name="curp" class="form-control text-uppercase" maxlength="18"></div>
                    <div class="form-group col-md-3"><label for="profileGender">Género</label><select id="profileGender" name="gender" class="form-control"><option value="Masculino">Masculino</option><option value="Femenino">Femenino</option><option value="Otro">Otro</option></select></div>
                    <div class="form-group col-md-3"><label for="profileBirthdate">Nacimiento</label><input id="profileBirthdate" name="birthdate" type="date" class="form-control"></div>
                  </div>
                  <div class="form-group"><label for="profileAddress">Dirección</label><input id="profileAddress" name="address" class="form-control" maxlength="255"></div>
                  <div class="form-row">
                    <div class="form-group col-md-5"><label for="profileNeighborhood">Colonia</label><input id="profileNeighborhood" name="neighborhood" class="form-control" maxlength="255"></div>
                    <div class="form-group col-md-3"><label for="profilePostalcode">Código postal</label><input id="profilePostalcode" name="postalcode" class="form-control" maxlength="10"></div>
                    <div class="form-group col-md-4"><label for="profileState">Estado</label><input id="profileState" name="state" class="form-control" maxlength="255"></div>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-md-4"><label for="profileCountry">País</label><input id="profileCountry" name="country" class="form-control" maxlength="255"></div>
                    <div class="form-group col-md-4"><label for="profileHomephone">Teléfono de casa</label><input id="profileHomephone" name="homephone" class="form-control" maxlength="15"></div>
                    <div class="form-group col-md-4"><label for="profileMobilephone">Teléfono móvil</label><input id="profileMobilephone" name="mobilephone" class="form-control" maxlength="15"></div>
                  </div>
                  <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Guardar datos</button>
                </form>
              </div>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-lg-6 mb-3">
            <div class="card h-100">
              <div class="card-header font-weight-bold">Cuenta</div>
              <div class="card-body">
                <div class="form-group"><label>Alias de cuenta</label><input id="profileEmail" class="form-control" readonly></div>
                <div class="form-row">
                  <div class="form-group col-md-6"><label>Rol</label><input id="profileRole" class="form-control" readonly></div>
                  <div class="form-group col-md-6"><label>Estado</label><input id="profileUserstatus" class="form-control" readonly></div>
                </div>
                <div class="form-group mb-0"><label>Registrado</label><input id="profileRegistrationdate" class="form-control" readonly></div>
                <small class="form-text text-muted mt-2">El correo real permanece oculto. Los permisos, roles y estado de cuenta no se modifican desde este formulario.</small>
              </div>
            </div>
          </div>

          <div class="col-lg-6 mb-3">
            <div class="card h-100">
              <div class="card-header font-weight-bold">Cambiar contraseña</div>
              <div class="card-body">
                <p class="small text-muted">Primero enviaremos un código de seis dígitos al correo registrado.</p>
                <button id="btnRequestPasswordCode" type="button" class="btn btn-outline-primary btn-sm mb-3"><i class="fas fa-envelope mr-1"></i>Enviar código</button>
                <div id="passwordCodeDestination" class="small text-muted mb-3"></div>
                <div class="form-group"><label for="profileVerificationCode">Código</label><input id="profileVerificationCode" class="form-control" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></div>
                <div class="form-group"><label for="profileNewPassword">Nueva contraseña</label><input id="profileNewPassword" type="password" class="form-control" maxlength="128" autocomplete="new-password"></div>
                <div class="form-group"><label for="profileConfirmPassword">Confirmar contraseña</label><input id="profileConfirmPassword" type="password" class="form-control" maxlength="128" autocomplete="new-password"></div>
                <small class="form-text text-muted mb-3">Mínimo 12 caracteres.</small>
                <button id="btnChangeProfilePassword" type="button" class="btn btn-danger"><i class="fas fa-key mr-1"></i>Cambiar contraseña</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button></div>
    </div>
  </div>
</div>
HTML;
    }
}
