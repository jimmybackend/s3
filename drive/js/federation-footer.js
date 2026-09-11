class FederationFooterModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.nodeTarget = null;
    this.countTarget = null;
    this.requestsButton = null;
    this.requestsCount = null;
    this.identityButton = null;
    this.identityModal = null;
    this.saveIdentityButton = null;
    this.identityConfigured = true;
    this.csrf = '';
  }

  init() {
    this.nodeTarget = this.document.getElementById('footerFederationNode');
    this.countTarget = this.document.getElementById('footerFederationPeers');
    if (!this.nodeTarget || !this.countTarget) return this;

    this.refresh(this.nodeTarget, this.countTarget);

    this.identityButton = this.document.getElementById('btnFederationNodeIdentity');
    this.identityModal = this.document.getElementById('modalFederationNodeIdentity');
    this.saveIdentityButton = this.document.getElementById('btnSaveFederationNodeIdentity');
    if (this.identityButton && this.identityModal && this.saveIdentityButton) {
      this.csrf = String(this.identityButton.dataset.csrf || '');
      if (this.identityModal.parentElement !== this.document.body) this.document.body.appendChild(this.identityModal);
      if (this.window.jQuery) {
        this.window.jQuery(this.identityModal).on('shown.bs.modal', () => this.loadIdentity());
      } else {
        this.identityButton.addEventListener('click', () => this.loadIdentity());
      }
      this.saveIdentityButton.addEventListener('click', () => this.saveIdentity());
    }

    this.requestsButton = this.document.getElementById('btnFederationProviderRequests');
    this.requestsCount = this.document.getElementById('footerFederationRequests');
    if (this.requestsButton && this.requestsCount) {
      this.csrf = this.csrf || String(this.requestsButton.dataset.csrf || '');
      this.loadAdmin(false);
      const modal = this.document.getElementById('modalFederationProviderRequests');
      if (modal) {
        if (modal.parentElement !== this.document.body) this.document.body.appendChild(modal);
        if (this.window.jQuery) {
          this.window.jQuery(modal).on('shown.bs.modal', () => this.loadAdmin(true));
        } else {
          this.requestsButton.addEventListener('click', () => this.loadAdmin(true));
        }
      } else {
        this.requestsButton.addEventListener('click', () => this.loadAdmin(true));
      }
    }
    return this;
  }

  async refresh(nodeTarget = this.nodeTarget, countTarget = this.countTarget) {
    if (!nodeTarget || !countTarget) return;
    try {
      const response = await fetch('federationcloud/nodes.php', {
        credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
      });
      const data = await response.json();
      if (!response.ok || !data.ok || !data.local_node || !data.local_node.node_id) throw new Error(data.error || `HTTP ${response.status}`);

      const nodeId = String(data.local_node.node_id);
      const nodeName = data.local_node.node_name ? String(data.local_node.node_name) : '';
      nodeTarget.textContent = nodeName || this.shortNodeId(nodeId);
      nodeTarget.title = nodeName ? `${nodeName} · ${nodeId}` : nodeId;
      const connected = Math.max(1, Number.parseInt(data.connected_nodes, 10) || 1);
      countTarget.textContent = String(connected);
      countTarget.title = `${connected} nodo${connected === 1 ? '' : 's'} activo${connected === 1 ? '' : 's'} en los últimos ${Number(data.active_window_minutes) || 15} minutos`;
      if (data.degraded) countTarget.title += ' · seed temporalmente no disponible';
    } catch (error) {
      nodeTarget.textContent = 'no disponible';
      countTarget.textContent = '—';
      console.error('[federation-footer] No se pudo consultar FederationCloud:', error);
    }
  }

  async loadIdentity() {
    this.showIdentityMessage('Cargando identidad del nodo…', 'info');
    try {
      const response = await fetch('federationcloud/node-admin.php', {
        credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);

      this.identityConfigured = data.configured === true;
      if (this.identityConfigured && data.node) {
        this.fillIdentity(data.node);
        this.saveIdentityButton.dataset.action = 'rename';
        this.saveIdentityButton.innerHTML = '<i class="fas fa-save mr-1"></i>Guardar nombre';
        this.showIdentityMessage('', '');
        return;
      }

      const name = this.document.getElementById('federationNodeNameInput');
      const nodeId = this.document.getElementById('federationNodeIdReadonly');
      const publicUrl = this.document.getElementById('federationNodePublicUrlReadonly');
      const federationUrl = this.document.getElementById('federationNodeFederationUrlReadonly');
      if (name) name.value = '';
      if (nodeId) nodeId.value = 'Se generará al crear el nodo';
      if (publicUrl) publicUrl.value = String(data.public_url || '');
      if (federationUrl) federationUrl.value = String(data.federation_url || '');
      this.saveIdentityButton.dataset.action = 'create';
      this.saveIdentityButton.innerHTML = '<i class="fas fa-plus mr-1"></i>Crear nodo';

      const d = data.diagnostics || {};
      let message = data.error || `No existe todavía la identidad en ${data.identity_path || 'la ruta configurada'}. Escribe un nombre único y pulsa Crear nodo.`;
      if (!d.privileged_helper && !d.parent_writable) {
        message += ` El servidor necesita preparación una sola vez. Usuario PHP-FPM: ${d.runtime_user || 'desconocido'}. Ejecuta: ${d.install_helper_command || 'instala el helper administrativo'}`;
      }
      this.showIdentityMessage(message, data.error ? 'danger' : 'warning');
    } catch (error) {
      this.showIdentityMessage(error.message || 'No se pudo cargar la identidad del nodo.', 'danger');
    }
  }

  fillIdentity(node) {
    const name = this.document.getElementById('federationNodeNameInput');
    const nodeId = this.document.getElementById('federationNodeIdReadonly');
    const publicUrl = this.document.getElementById('federationNodePublicUrlReadonly');
    const federationUrl = this.document.getElementById('federationNodeFederationUrlReadonly');
    if (name) name.value = String(node.node_name || '');
    if (nodeId) nodeId.value = String(node.node_id || '');
    if (publicUrl) publicUrl.value = String(node.public_url || '');
    if (federationUrl) federationUrl.value = String(node.federation_url || '');
  }

  async saveIdentity() {
    const input = this.document.getElementById('federationNodeNameInput');
    const button = this.saveIdentityButton;
    const nodeName = String(input?.value || '').trim().toLowerCase();
    if (!button || !this.csrf) return;
    if (!/^[a-z0-9][a-z0-9._-]{1,62}[a-z0-9]$/.test(nodeName)) {
      this.showIdentityMessage('Usa 3-64 caracteres: a-z, 0-9, punto, guion o guion bajo.', 'warning');
      return;
    }

    const action = String(button.dataset.action || (this.identityConfigured ? 'rename' : 'create'));
    button.disabled = true;
    button.textContent = action === 'create' ? 'Creando…' : 'Guardando…';
    this.showIdentityMessage(action === 'create' ? 'Verificando nombre global y creando identidad criptográfica…' : 'Verificando nombre global y actualizando identidad firmada…', 'info');

    try {
      const body = new URLSearchParams();
      body.set('action', action);
      body.set('node_name', nodeName);
      const response = await fetch('federationcloud/node-admin.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {
          'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
          'X-Federation-CSRF': this.csrf,
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString()
      });
      const data = await response.json();
      if (!response.ok || !data.ok || !data.node) throw new Error(data.error || `HTTP ${response.status}`);

      this.identityConfigured = true;
      this.fillIdentity(data.node);
      button.dataset.action = 'rename';
      await this.refresh();
      this.showIdentityMessage(data.message || 'Identidad del nodo actualizada.', 'success');
    } catch (error) {
      this.showIdentityMessage(error.message || 'No se pudo administrar el nodo.', 'danger');
    } finally {
      button.disabled = false;
      button.innerHTML = this.identityConfigured
        ? '<i class="fas fa-save mr-1"></i>Guardar nombre'
        : '<i class="fas fa-plus mr-1"></i>Crear nodo';
    }
  }

  showIdentityMessage(message, type) {
    const alert = this.document.getElementById('federationNodeIdentityAlert');
    if (!alert) return;
    if (!message) {
      alert.className = 'alert d-none';
      alert.textContent = '';
      return;
    }
    alert.className = `alert alert-${type || 'info'}`;
    alert.textContent = message;
  }

  async loadAdmin(renderLists) {
    if (!this.requestsCount) return;
    try {
      const response = await fetch('federationcloud/provider-admin.php', {
        credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
      const pending = Array.isArray(data.pending) ? data.pending : [];
      const active = Array.isArray(data.active) ? data.active : [];
      this.requestsCount.textContent = String(pending.length);
      this.requestsButton.title = pending.length === 1 ? '1 solicitud de nodo proveedor pendiente' : `${pending.length} solicitudes de nodos proveedores pendientes`;
      if (renderLists) {
        this.renderAdminLists(pending, active);
        this.showAdminMessage('', '');
      }
    } catch (error) {
      this.requestsCount.textContent = '—';
      if (renderLists) this.showAdminMessage(error.message || 'No se pudo consultar las solicitudes.', 'danger');
      console.error('[federation-footer] No se pudo consultar autorizaciones:', error);
    }
  }

  renderAdminLists(pending, active) {
    const pendingList = this.document.getElementById('federationProviderPendingList');
    const activeList = this.document.getElementById('federationProviderActiveList');
    const pendingBadge = this.document.getElementById('federationProviderPendingBadge');
    const activeBadge = this.document.getElementById('federationProviderActiveBadge');
    if (!pendingList || !activeList || !pendingBadge || !activeBadge) return;
    pendingBadge.textContent = String(pending.length);
    activeBadge.textContent = String(active.length);
    pendingList.replaceChildren();
    activeList.replaceChildren();
    if (pending.length === 0) pendingList.appendChild(this.emptyMessage('No hay solicitudes pendientes.'));
    else pending.forEach((row) => pendingList.appendChild(this.providerCard(row, 'pending')));
    if (active.length === 0) activeList.appendChild(this.emptyMessage('No hay proveedores autorizados todavía.'));
    else active.forEach((row) => activeList.appendChild(this.providerCard(row, 'active')));
  }

  providerCard(row, state) {
    const card = this.document.createElement('div');
    card.className = 'border border-secondary rounded p-3 mb-2';
    const head = this.document.createElement('div');
    head.className = 'd-flex flex-wrap justify-content-between align-items-start';
    const identity = this.document.createElement('div');
    const name = this.document.createElement('strong');
    name.className = 'text-info';
    name.textContent = row.provider_node_name || this.shortNodeId(String(row.provider_node_id || ''));
    name.title = String(row.provider_node_id || '');
    identity.appendChild(name);
    const nodeId = this.document.createElement('div');
    nodeId.className = 'small text-muted text-break';
    nodeId.textContent = String(row.provider_node_id || '');
    identity.appendChild(nodeId);
    const url = this.document.createElement('div');
    url.className = 'small text-muted text-break';
    url.textContent = String(row.public_url || row.federation_url || '');
    identity.appendChild(url);
    head.appendChild(identity);
    const badge = this.document.createElement('span');
    badge.className = state === 'active' ? 'badge badge-success' : 'badge badge-warning';
    badge.textContent = state === 'active' ? 'Autorizado' : 'Pendiente';
    head.appendChild(badge);
    card.appendChild(head);
    const meta = this.document.createElement('div');
    meta.className = 'small mt-2';
    meta.textContent = `Rol: ${row.role || 'provider'} · Alcance: ${row.scope || 'all_allowed_resources'} · Último contacto: ${row.last_seen || '—'}`;
    card.appendChild(meta);
    const actions = this.document.createElement('div');
    actions.className = 'mt-3 d-flex flex-wrap';
    if (state === 'pending') {
      actions.appendChild(this.actionButton('Aprobar', 'approve', row.provider_node_id, 'btn-outline-success'));
      actions.appendChild(this.actionButton('Rechazar', 'reject', row.provider_node_id, 'btn-outline-danger'));
    } else {
      actions.appendChild(this.actionButton('Revocar autorización', 'revoke', row.provider_node_id, 'btn-outline-danger'));
    }
    card.appendChild(actions);
    return card;
  }

  actionButton(label, decision, providerNodeId, className) {
    const button = this.document.createElement('button');
    button.type = 'button';
    button.className = `btn btn-sm ${className} mr-2 mb-1`;
    button.textContent = label;
    button.addEventListener('click', () => this.decideProvider(String(providerNodeId || ''), decision, button));
    return button;
  }

  async decideProvider(providerNodeId, decision, button) {
    if (!providerNodeId || !this.csrf) return;
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Procesando…';
    try {
      const body = new URLSearchParams();
      body.set('provider_node_id', providerNodeId);
      body.set('decision', decision);
      const response = await fetch('federationcloud/provider-admin.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {
          'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
          'X-Federation-CSRF': this.csrf,
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        }, body: body.toString()
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
      const label = decision === 'approve' ? 'Proveedor autorizado.' : decision === 'revoke' ? 'Autorización revocada.' : 'Solicitud rechazada.';
      this.showAdminMessage(label, 'success');
      await this.loadAdmin(true);
    } catch (error) {
      this.showAdminMessage(error.message || 'No se pudo actualizar la autorización.', 'danger');
      button.disabled = false;
      button.textContent = originalText;
    }
  }

  showAdminMessage(message, type) {
    const alert = this.document.getElementById('federationProviderAdminAlert');
    if (!alert) return;
    if (!message) { alert.className = 'alert d-none'; alert.textContent = ''; return; }
    alert.className = `alert alert-${type || 'info'}`;
    alert.textContent = message;
  }

  emptyMessage(text) {
    const element = this.document.createElement('div');
    element.className = 'text-muted small border border-secondary rounded p-3';
    element.textContent = text;
    return element;
  }

  shortNodeId(nodeId) {
    if (nodeId.length <= 26) return nodeId;
    return `${nodeId.slice(0, 14)}…${nodeId.slice(-8)}`;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || {modules: {}};
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new FederationFooterModule(win, doc).init();
    win.ArcadeCloudDrive.modules['federation-footer'] = instance;
    return instance;
  }
}

FederationFooterModule.boot();
