(() => {
  'use strict';

  class UserProfileModule {
    constructor() {
      this.modal = document.getElementById('modalUserProfile');
      if (!this.modal) return;

      this.csrf = String(this.modal.dataset.csrf || '');
      this.alert = document.getElementById('profileAlert');
      this.form = document.getElementById('profilePersonalForm');
      this.pictureInput = document.getElementById('profilePictureInput');
      this.avatarPreview = document.getElementById('profileAvatarPreview');
      this.aliasPreview = document.getElementById('profileAliasPreview');
      this.bind();
    }

    bind() {
      $('#modalUserProfile').on('show.bs.modal', () => this.load());

      this.form?.addEventListener('submit', (event) => {
        event.preventDefault();
        this.savePersonal();
      });

      document.getElementById('btnUploadProfilePicture')?.addEventListener('click', () => this.uploadAvatar());
      document.getElementById('btnRemoveProfilePicture')?.addEventListener('click', () => this.removeAvatar());
      document.getElementById('btnRequestPasswordCode')?.addEventListener('click', () => this.requestPasswordCode());
      document.getElementById('btnChangeProfilePassword')?.addEventListener('click', () => this.changePassword());
    }

    async load() {
      try {
        this.message('Cargando perfil…', 'info');
        const response = await fetch('profile.php', { credentials: 'same-origin' });
        const data = await this.readJson(response);
        if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo cargar el perfil.');
        this.fill(data.profile || {});
        this.hideMessage();
      } catch (error) {
        this.message(error.message || 'No se pudo cargar el perfil.', 'danger');
      }
    }

    fill(profile) {
      const map = {
        profileFirstname: 'firstname',
        profileLastname: 'lastname',
        profileCurp: 'curp',
        profileGender: 'gender',
        profileBirthdate: 'birthdate',
        profileAddress: 'address',
        profileNeighborhood: 'neighborhood',
        profilePostalcode: 'postalcode',
        profileState: 'state',
        profileCountry: 'country',
        profileHomephone: 'homephone',
        profileMobilephone: 'mobilephone',
        profileEmail: 'alias',
        profileRole: 'role',
        profileUserstatus: 'userstatus',
        profileRegistrationdate: 'registrationdate'
      };

      Object.entries(map).forEach(([id, key]) => {
        const element = document.getElementById(id);
        if (element) element.value = profile[key] == null ? '' : String(profile[key]);
      });

      this.aliasPreview.textContent = String(profile.alias || '@usuario');
      this.renderAvatar(profile);
      this.renderNavbar(profile);
    }

    renderAvatar(profile) {
      const url = String(profile.avatar_url || '');
      const initials = String(profile.initials || 'U');
      if (!this.avatarPreview) return;

      this.avatarPreview.replaceChildren();
      if (url) {
        const image = document.createElement('img');
        image.src = url;
        image.alt = 'Imagen de perfil';
        image.style.width = '100%';
        image.style.height = '100%';
        image.style.objectFit = 'cover';
        this.avatarPreview.appendChild(image);
      } else {
        this.avatarPreview.textContent = initials;
      }
    }

    renderNavbar(profile) {
      const menu = document.getElementById('usuarioMenu');
      if (!menu) return;

      const label = menu.querySelector('.drive-user-label');
      if (label) label.textContent = String(profile.alias || '@usuario');

      const current = menu.querySelector('.drive-user-avatar');
      if (!current) return;

      const url = String(profile.avatar_url || '');
      const initials = String(profile.initials || 'U');
      let replacement;

      if (url) {
        replacement = document.createElement('img');
        replacement.src = url;
        replacement.alt = 'Perfil';
        replacement.className = 'drive-user-avatar rounded-circle mr-2';
        replacement.width = 30;
        replacement.height = 30;
        replacement.style.objectFit = 'cover';
      } else {
        replacement = document.createElement('span');
        replacement.className = 'drive-user-avatar rounded-circle mr-2 d-inline-flex align-items-center justify-content-center';
        replacement.setAttribute('aria-hidden', 'true');
        replacement.style.cssText = 'width:30px;height:30px;min-width:30px;font-size:.75rem;font-weight:700;border:2px solid rgba(255,255,255,.85);background:rgba(255,255,255,.15);letter-spacing:.02em;';
        replacement.textContent = initials;
      }

      current.replaceWith(replacement);
    }

    async savePersonal() {
      try {
        const body = new FormData(this.form);
        body.set('action', 'update_personal');
        const data = await this.post(body);
        this.fill(data.profile || {});
        this.message(data.message || 'Perfil actualizado.', 'success');
      } catch (error) {
        this.message(error.message || 'No se pudo actualizar el perfil.', 'danger');
      }
    }

    async uploadAvatar() {
      const file = this.pictureInput?.files?.[0];
      if (!file) {
        this.message('Selecciona una imagen primero.', 'warning');
        return;
      }

      try {
        const body = new FormData();
        body.set('action', 'upload_avatar');
        body.set('profilepicture', file);
        const data = await this.post(body);
        this.pictureInput.value = '';
        this.fill(data.profile || {});
        this.message(data.message || 'Imagen actualizada.', 'success');
      } catch (error) {
        this.message(error.message || 'No se pudo cambiar la imagen.', 'danger');
      }
    }

    async removeAvatar() {
      try {
        const body = new FormData();
        body.set('action', 'remove_avatar');
        const data = await this.post(body);
        this.fill(data.profile || {});
        this.message(data.message || 'Imagen eliminada.', 'success');
      } catch (error) {
        this.message(error.message || 'No se pudo eliminar la imagen.', 'danger');
      }
    }

    async requestPasswordCode() {
      try {
        const body = new FormData();
        body.set('action', 'request_password_code');
        const data = await this.post(body);
        const target = document.getElementById('passwordCodeDestination');
        if (target) target.textContent = `Código enviado a ${data.email || 'tu correo registrado'}.`;
        this.message(data.message || 'Código enviado.', 'success');
      } catch (error) {
        this.message(error.message || 'No se pudo enviar el código.', 'danger');
      }
    }

    async changePassword() {
      const code = document.getElementById('profileVerificationCode')?.value || '';
      const password = document.getElementById('profileNewPassword')?.value || '';
      const confirmation = document.getElementById('profileConfirmPassword')?.value || '';

      try {
        const body = new FormData();
        body.set('action', 'change_password');
        body.set('verification_code', code);
        body.set('new_password', password);
        body.set('confirm_password', confirmation);
        const data = await this.post(body);

        document.getElementById('profileVerificationCode').value = '';
        document.getElementById('profileNewPassword').value = '';
        document.getElementById('profileConfirmPassword').value = '';
        document.getElementById('passwordCodeDestination').textContent = '';
        this.message(data.message || 'Contraseña actualizada.', 'success');
      } catch (error) {
        this.message(error.message || 'No se pudo cambiar la contraseña.', 'danger');
      }
    }

    async post(body) {
      const response = await fetch('profile.php', {
        method: 'POST',
        headers: { 'X-Profile-CSRF': this.csrf },
        body,
        credentials: 'same-origin'
      });
      const data = await this.readJson(response);
      if (!response.ok || !data.ok) throw new Error(data.error || 'La operación no pudo completarse.');
      return data;
    }

    async readJson(response) {
      try {
        return await response.json();
      } catch (_) {
        return { ok: false, error: 'El servidor devolvió una respuesta inválida.' };
      }
    }

    message(text, type) {
      if (!this.alert) return;
      this.alert.className = `alert alert-${type}`;
      this.alert.textContent = text;
    }

    hideMessage() {
      if (!this.alert) return;
      this.alert.className = 'alert d-none';
      this.alert.textContent = '';
    }
  }

  window.addEventListener('DOMContentLoaded', () => new UserProfileModule());
})();
