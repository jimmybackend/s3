class FederationOsAdminModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.body = doc.body;
    this.isSuperAdmin = this.body?.dataset?.superadmin === '1';
    this.providerCsrf = String(this.body?.dataset?.providerCsrf || '');
  }

  init() {
    this.document.getElementById('btnFederationOsRefresh')?.addEventListener('click', () => this.refresh());
    this.document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-provider-decision]');
      if (!button) return;
      event.preventDefault();
      this.decideProvider(
        String(button.dataset.providerNodeId || ''),
        String(button.dataset.providerDecision || ''),
        button
      );
    });

    this.refresh();

    if (this.isSuperAdmin) {
      const serverModule = this.window.ArcadeCloudDrive
        && this.window.ArcadeCloudDrive.modules
        && this.window.ArcadeCloudDrive.modules['server-admin'];
      if (serverModule && typeof serverModule.load === 'function') {
        serverModule.load();
      } else {
        this.document.getElementById('btnServerAdmin')?.click();
      }
    }

    return this;
  }

  async refresh() {
    this.showAlert('', '');
    const jobs = [this.loadNodes()];
    if (this.isSuperAdmin) {
      jobs.push(this.loadProviders(), this.loadModeration());
    }
    await Promise.allSettled(jobs);
  }

  async loadNodes() {
    try {
      const data = await this.fetchJson('nodes.php');
      const local = data.local_node && typeof data.local_node === 'object' ? data.local_node : {};
      const nodes = Array.isArray(data.nodes) ? data.nodes : [];
      const connected = Math.max(1, Number.parseInt(data.connected_nodes || '1', 10) || 1);
      const activeWindow = Math.max(1, Number.parseInt(data.active_window_minutes || '15', 10) || 15);

      this.text('federationAboutNodeName', local.node_name || this.shortNodeId(String(local.node_id || '')));
      this.text('federationAboutNodeId', local.node_id || '—');
      this.text('federationAboutConnected', connected);
      this.text('federationAboutWindow', activeWindow + ' min');
      this.text('federationAboutPublicUrl', local.public_url || '—');
      this.text('federationAboutFederationUrl', local.federation_url || '—');
      this.text('federationAboutSeed', data.seed_node_id || '—');
      this.text('federationAboutPeerCount', nodes.length);

      const health = this.document.getElementById('federationAboutHealth');
      if (health) {
        health.textContent = data.degraded ? 'degradado' : 'activo';
        health.className = 'badge ' + (data.degraded ? 'badge-warning' : 'badge-success');
      }

      this.renderPeers(nodes, String(local.node_id || ''));
    } catch (error) {
      this.showAlert(error.message || 'No se pudo consultar el directorio FederationCloud.', 'danger');
      const health = this.document.getElementById('federationAboutHealth');
      if (health) {
        health.textContent = 'no disponible';
        health.className = 'badge badge-danger';
      }
    }
  }

  renderPeers(nodes, localNodeId) {
    const target = this.document.getElementById('federationAboutPeerList');
    if (!target) return;
    target.replaceChildren();

    if (!nodes.length) {
      target.appendChild(this.empty('No hay nodos activos conocidos.'));
      return;
    }

    nodes.forEach((row) => {
      const card = this.document.createElement('article');
      card.className = 'federation-os-peer-card';

      const top = this.document.createElement('div');
      top.className = 'federation-os-peer-head';

      const name = this.document.createElement('strong');
      name.textContent = String(row.node_name || this.shortNodeId(String(row.node_id || 'Nodo')));
      top.appendChild(name);

      const badge = this.document.createElement('span');
      const isLocal = String(row.node_id || '') === localNodeId;
      badge.className = 'badge ' + (isLocal ? 'badge-info' : 'badge-success');
      badge.textContent = isLocal ? 'este nodo' : 'conectado';
      top.appendChild(badge);

      const id = this.document.createElement('div');
      id.className = 'small text-muted text-break';
      id.textContent = String(row.node_id || '');

      const url = this.document.createElement('div');
      url.className = 'small text-muted text-break mt-1';
      url.textContent = String(row.public_url || row.federation_url || 'sin URL publicada');

      card.appendChild(top);
      card.appendChild(id);
      card.appendChild(url);
      target.appendChild(card);
    });
  }

  async loadProviders() {
    try {
      const data = await this.fetchJson('provider-admin.php');
      const pending = Array.isArray(data.pending) ? data.pending : [];
      const active = Array.isArray(data.active) ? data.active : [];
      this.text('federationProviderPendingBadge', pending.length);
      this.text('federationProviderActiveBadge', active.length);
      this.renderProviderList('federationProviderPendingList', pending, 'pending');
      this.renderProviderList('federationProviderActiveList', active, 'active');
    } catch (error) {
      this.showAlert(error.message || 'No se pudieron consultar solicitudes de proveedores.', 'danger');
    }
  }

  renderProviderList(id, rows, state) {
    const target = this.document.getElementById(id);
    if (!target) return;
    target.replaceChildren();

    if (!rows.length) {
      target.appendChild(this.empty(state === 'pending'
        ? 'No hay solicitudes pendientes.'
        : 'No hay proveedores autorizados.'));
      return;
    }

    rows.forEach((row) => {
      const card = this.document.createElement('article');
      card.className = 'federation-os-provider-card';

      const head = this.document.createElement('div');
      head.className = 'federation-os-provider-head';

      const identity = this.document.createElement('div');
      const title = this.document.createElement('strong');
      title.textContent = String(row.provider_node_name || this.shortNodeId(String(row.provider_node_id || '')));
      identity.appendChild(title);

      const nodeId = this.document.createElement('div');
      nodeId.className = 'small text-muted text-break';
      nodeId.textContent = String(row.provider_node_id || '');
      identity.appendChild(nodeId);

      const endpoint = this.document.createElement('div');
      endpoint.className = 'small text-muted text-break';
      endpoint.textContent = String(row.public_url || row.federation_url || '');
      identity.appendChild(endpoint);

      head.appendChild(identity);

      const badge = this.document.createElement('span');
      badge.className = 'badge ' + (state === 'pending' ? 'badge-warning' : 'badge-success');
      badge.textContent = state === 'pending' ? 'pendiente' : 'autorizado';
      head.appendChild(badge);

      const meta = this.document.createElement('div');
      meta.className = 'small mt-2 text-muted';
      meta.textContent = 'Rol: ' + String(row.role || 'provider')
        + ' · Alcance: ' + String(row.scope || 'all_allowed_resources')
        + ' · Último contacto: ' + String(row.last_seen || '—');

      const actions = this.document.createElement('div');
      actions.className = 'federation-os-provider-actions';

      if (state === 'pending') {
        actions.appendChild(this.providerButton('Aprobar', 'approve', row.provider_node_id, 'is-success'));
        actions.appendChild(this.providerButton('Rechazar', 'reject', row.provider_node_id, 'is-danger'));
      } else {
        actions.appendChild(this.providerButton('Revocar autorización', 'revoke', row.provider_node_id, 'is-danger'));
      }

      card.appendChild(head);
      card.appendChild(meta);
      card.appendChild(actions);
      target.appendChild(card);
    });
  }

  providerButton(label, decision, providerNodeId, className) {
    const button = this.document.createElement('button');
    button.type = 'button';
    button.className = 'federation-os-action ' + className;
    button.dataset.providerDecision = decision;
    button.dataset.providerNodeId = String(providerNodeId || '');
    button.textContent = label;
    return button;
  }

  async decideProvider(providerNodeId, decision, button) {
    if (!providerNodeId || !['approve', 'reject', 'revoke'].includes(decision) || !this.providerCsrf) return;

    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'Procesando…';

    try {
      const body = new URLSearchParams();
      body.set('provider_node_id', providerNodeId);
      body.set('decision', decision);

      const data = await this.fetchJson('provider-admin.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Federation-CSRF': this.providerCsrf
        },
        body: body.toString()
      });

      const message = decision === 'approve'
        ? 'Proveedor autorizado.'
        : decision === 'revoke'
          ? 'Autorización revocada.'
          : 'Solicitud rechazada.';
      this.showAlert(String(data.message || message), 'success');
      await this.loadProviders();
    } catch (error) {
      this.showAlert(error.message || 'No se pudo actualizar la solicitud.', 'danger');
    } finally {
      button.disabled = false;
      button.textContent = original;
    }
  }

  async loadModeration() {
    try {
      const data = await this.fetchJson('moderation-api.php');
      const reports = Array.isArray(data.reports) ? data.reports : [];
      const blocks = Array.isArray(data.active_blocks) ? data.active_blocks : [];
      this.text('federationModerationPendingBadge', reports.length);
      this.text('federationModerationReports', reports.length);
      this.text('federationModerationBlocks', blocks.length);
    } catch (error) {
      this.text('federationModerationPendingBadge', '—');
      this.text('federationModerationReports', '—');
      this.text('federationModerationBlocks', '—');
      this.showAlert(error.message || 'No se pudo consultar moderación.', 'warning');
    }
  }

  async fetchJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {})
      },
      ...options
    });

    const raw = await response.text();
    let data = null;
    try {
      data = JSON.parse(raw);
    } catch (_) {
      data = null;
    }

    if (!response.ok || !data || data.ok !== true) {
      throw new Error((data && data.error) || ('Solicitud fallida (HTTP ' + response.status + ').'));
    }
    return data;
  }

  text(id, value) {
    const node = this.document.getElementById(id);
    if (node) node.textContent = String(value ?? '');
  }

  empty(message) {
    const node = this.document.createElement('div');
    node.className = 'federation-os-empty';
    node.textContent = message;
    return node;
  }

  shortNodeId(value) {
    const nodeId = String(value || '');
    if (nodeId.length <= 26) return nodeId;
    return nodeId.slice(0, 14) + '…' + nodeId.slice(-8);
  }

  showAlert(message, type) {
    const box = this.document.getElementById('federationOsAdminAlert');
    if (!box) return;
    if (!message) {
      box.className = 'alert d-none';
      box.textContent = '';
      return;
    }
    box.className = 'alert alert-' + String(type || 'info');
    box.textContent = String(message);
  }
}

window.FederationOsAdmin = new FederationOsAdminModule(window, document).init();
