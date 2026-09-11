class FederationPortalModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.csrf = '';
    this.state = { incoming: [], outgoing: [], shares: [] };
  }

  init() {
    this.bind();
    this.loadAccess();
    return this;
  }

  bind() {
    const form = this.document.getElementById('federationSearchForm');
    if (form) {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        this.search();
      });
    }

    this.document.addEventListener('click', (event) => {
      const requestButton = event.target?.closest?.('[data-federation-request-resource]');
      if (requestButton) {
        event.preventDefault();
        this.requestAccess(String(requestButton.dataset.federationRequestResource || ''), requestButton);
        return;
      }

      const decisionButton = event.target?.closest?.('[data-federation-decision]');
      if (decisionButton) {
        event.preventDefault();
        this.decide(
          String(decisionButton.dataset.requestId || ''),
          String(decisionButton.dataset.federationDecision || ''),
          decisionButton
        );
      }
    });
  }

  async loadAccess() {
    try {
      const data = await this.fetchJson('access.php', { credentials: 'same-origin', cache: 'no-store' });
      this.csrf = String(data.csrf || '');
      this.state.incoming = Array.isArray(data.incoming) ? data.incoming : [];
      this.state.outgoing = Array.isArray(data.outgoing) ? data.outgoing : [];
      this.state.shares = Array.isArray(data.shares) ? data.shares : [];
      this.renderAccess();
    } catch (error) {
      this.alert(error.message || 'No se pudieron cargar solicitudes y Shares.', 'danger');
    }
  }

  async search() {
    const input = this.document.getElementById('federationSearchInput');
    const query = String(input?.value || '').trim();
    if (query.length < 2) {
      this.alert('La búsqueda debe tener al menos 2 caracteres.', 'warning');
      return;
    }

    const target = this.document.getElementById('federationSearchResults');
    const summary = this.document.getElementById('federationSearchSummary');
    if (target) target.innerHTML = '<div class="text-muted small">Buscando en la réplica local…</div>';

    try {
      const data = await this.fetchJson(`search.php?q=${encodeURIComponent(query)}&limit=50`, {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const results = Array.isArray(data.results) ? data.results : [];
      if (summary) summary.textContent = `${results.length} resultado${results.length === 1 ? '' : 's'} · consulta local`;
      this.renderSearchResults(results);
    } catch (error) {
      if (target) target.innerHTML = '';
      this.alert(error.message || 'No se pudo completar la búsqueda global.', 'danger');
    }
  }

  renderSearchResults(results) {
    const target = this.document.getElementById('federationSearchResults');
    if (!target) return;
    target.innerHTML = '';
    if (!results.length) {
      target.innerHTML = '<div class="text-muted small">No hay coincidencias en el catálogo global conocido por este nodo.</div>';
      return;
    }

    results.forEach((row) => {
      const card = this.document.createElement('article');
      card.className = 'federation-result-card';
      const policy = String(row.discovery_policy || row.DiscoveryPolicy || '');
      const resourceId = String(row.resource_id || row.ResourceId || '');
      const title = String(row.title || row.Title || 'Recurso FederationCloud');
      const mediaType = String(row.media_type || row.MediaType || 'application/octet-stream');
      const visibility = String(row.visibility || row.Visibility || '');
      const originNodeId = String(row.origin_node_id || row.OriginNodeId || '');
      const locations = Array.isArray(row.locations) ? row.locations : [];

      const actions = this.document.createElement('div');
      actions.className = 'federation-result-actions';
      if (policy === 'requestable_metadata' && resourceId) {
        const button = this.document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-warning';
        button.dataset.federationRequestResource = resourceId;
        button.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>Solicitar acceso';
        actions.appendChild(button);
      } else if (policy === 'public_metadata') {
        const badge = this.document.createElement('span');
        badge.className = 'badge badge-success';
        badge.textContent = 'metadata pública';
        actions.appendChild(badge);
      }

      const body = this.document.createElement('div');
      body.className = 'federation-result-main';
      body.innerHTML = `
        <div class="d-flex align-items-center flex-wrap mb-1">
          <strong class="mr-2">${this.escape(title)}</strong>
          <span class="badge badge-secondary mr-1">${this.escape(mediaType)}</span>
          <span class="badge badge-info mr-1">${this.escape(visibility)}</span>
          <span class="badge badge-dark">${this.escape(policy)}</span>
        </div>
        <div class="small text-muted text-break">${this.escape(resourceId)}</div>
        <div class="small text-muted mt-1">Origen: ${this.escape(originNodeId || 'desconocido')} · ubicaciones conocidas: ${locations.length}</div>`;

      card.appendChild(body);
      card.appendChild(actions);
      target.appendChild(card);
    });
  }

  async requestAccess(resourceId, button) {
    if (!resourceId || !this.csrf) return;
    const old = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Solicitando…';
    try {
      const body = new URLSearchParams();
      body.set('action', 'request');
      body.set('resource_id', resourceId);
      const data = await this.fetchJson('access.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Federation-Access-CSRF': this.csrf
        },
        body: body.toString()
      });
      this.alert(String(data.message || 'Solicitud registrada.'), data.status === 'queued' ? 'warning' : 'success');
      await this.loadAccess();
    } catch (error) {
      this.alert(error.message || 'No se pudo solicitar acceso.', 'danger');
    } finally {
      button.disabled = false;
      button.innerHTML = old;
    }
  }

  async decide(requestId, decision, button) {
    if (!requestId || !['approve', 'reject'].includes(decision) || !this.csrf) return;
    const daysInput = this.document.querySelector(`[data-request-days="${CSS.escape(requestId)}"]`);
    const days = Math.max(1, Math.min(30, Number.parseInt(daysInput?.value || '7', 10) || 7));
    const old = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Guardando…';
    try {
      const body = new URLSearchParams();
      body.set('action', 'decision');
      body.set('request_id', requestId);
      body.set('decision', decision);
      body.set('days', String(days));
      const data = await this.fetchJson('access.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Federation-Access-CSRF': this.csrf
        },
        body: body.toString()
      });
      this.alert(data.status === 'approved' ? 'Acceso aprobado y grant temporal creado.' : 'Solicitud rechazada.', 'success');
      await this.loadAccess();
    } catch (error) {
      this.alert(error.message || 'No se pudo guardar la decisión.', 'danger');
    } finally {
      button.disabled = false;
      button.innerHTML = old;
    }
  }

  renderAccess() {
    const incoming = this.state.incoming;
    const outgoing = this.state.outgoing;
    const shares = this.state.shares;
    const received = shares.filter((row) => String(row.direction) === 'received');
    const sent = shares.filter((row) => String(row.direction) === 'sent');

    this.text('federationIncomingCount', incoming.length);
    this.text('federationOutgoingCount', outgoing.length);
    this.text('federationIncomingBadge', incoming.filter((row) => String(row.status) === 'pending').length);
    this.text('federationSharesBadge', shares.length);
    this.text('federationReceivedCount', received.length);
    this.text('federationSentCount', sent.length);

    this.renderIncoming(incoming);
    this.renderOutgoing(outgoing);
    this.renderShares('federationReceivedShares', received);
    this.renderShares('federationSentShares', sent);
  }

  renderIncoming(rows) {
    const target = this.document.getElementById('federationIncomingList');
    if (!target) return;
    target.innerHTML = '';
    if (!rows.length) {
      target.innerHTML = '<div class="small text-muted">No hay solicitudes recibidas.</div>';
      return;
    }
    rows.forEach((row) => {
      const status = String(row.status || 'pending');
      const card = this.document.createElement('div');
      card.className = 'federation-mini-card';
      card.innerHTML = `
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <strong>${this.escape(String(row.resource_id || ''))}</strong>
            <div class="small text-muted">Nodo: ${this.escape(String(row.remote_node_id || ''))}</div>
          </div>
          <span class="badge ${this.statusClass(status)}">${this.escape(status)}</span>
        </div>
        <div class="small text-muted mt-2">Solicitada: ${this.escape(String(row.requested_at || ''))}</div>`;
      if (status === 'pending') {
        const actions = this.document.createElement('div');
        actions.className = 'd-flex align-items-center flex-wrap mt-2';
        actions.innerHTML = `
          <input type="number" min="1" max="30" value="7" class="form-control form-control-sm mr-2 federation-days-input" data-request-days="${this.escapeAttr(String(row.request_id || ''))}" aria-label="Días de acceso">
          <button class="btn btn-success btn-sm mr-2" data-federation-decision="approve" data-request-id="${this.escapeAttr(String(row.request_id || ''))}"><i class="fas fa-check mr-1"></i>Aprobar</button>
          <button class="btn btn-outline-danger btn-sm" data-federation-decision="reject" data-request-id="${this.escapeAttr(String(row.request_id || ''))}"><i class="fas fa-xmark mr-1"></i>Rechazar</button>`;
        card.appendChild(actions);
      }
      target.appendChild(card);
    });
  }

  renderOutgoing(rows) {
    const target = this.document.getElementById('federationOutgoingList');
    if (!target) return;
    target.innerHTML = '';
    if (!rows.length) {
      target.innerHTML = '<div class="small text-muted">No has enviado solicitudes.</div>';
      return;
    }
    rows.forEach((row) => {
      const status = String(row.status || 'queued');
      const card = this.document.createElement('div');
      card.className = 'federation-mini-card';
      card.innerHTML = `
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <strong>${this.escape(String(row.resource_id || ''))}</strong>
            <div class="small text-muted">Nodo: ${this.escape(String(row.remote_node_id || ''))}</div>
          </div>
          <span class="badge ${this.statusClass(status)}">${this.escape(status)}</span>
        </div>
        <div class="small text-muted mt-2">Actualizada: ${this.escape(String(row.updated_at || ''))}</div>
        ${row.last_error ? `<div class="small text-warning mt-1">${this.escape(String(row.last_error))}</div>` : ''}`;
      target.appendChild(card);
    });
  }

  renderShares(targetId, rows) {
    const target = this.document.getElementById(targetId);
    if (!target) return;
    target.innerHTML = '';
    if (!rows.length) {
      target.innerHTML = '<div class="small text-muted">Sin elementos.</div>';
      return;
    }
    rows.forEach((row) => {
      const status = String(row.status || 'active');
      const card = this.document.createElement('div');
      card.className = 'federation-mini-card';
      const accessUrl = typeof row.access_url === 'string' ? row.access_url : '';
      card.innerHTML = `
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <strong>${this.escape(String(row.title || 'Recurso FederationCloud'))}</strong>
            <div class="small text-muted">${this.escape(String(row.media_type || 'application/octet-stream'))}</div>
          </div>
          <span class="badge ${this.statusClass(status)}">${this.escape(status)}</span>
        </div>
        <div class="small text-muted text-break mt-1">${this.escape(String(row.resource_id || ''))}</div>
        <div class="small text-muted mt-1">Expira: ${this.escape(String(row.expires_at || '—'))}</div>`;
      if (accessUrl && status === 'active') {
        const link = this.document.createElement('a');
        link.className = 'btn btn-info btn-sm mt-2';
        link.href = accessUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.innerHTML = '<i class="fas fa-arrow-up-right-from-square mr-1"></i>Abrir acceso';
        card.appendChild(link);
      }
      target.appendChild(card);
    });
  }

  async fetchJson(url, options = {}) {
    const response = await fetch(url, { headers: { Accept: 'application/json', ...(options.headers || {}) }, ...options });
    const text = await response.text();
    let data = null;
    try { data = text ? JSON.parse(text) : {}; } catch (_) { data = null; }
    if (!response.ok || !data || data.ok === false) {
      throw new Error(String((data && data.error) || `HTTP ${response.status}`));
    }
    return data;
  }

  alert(message, type = 'info') {
    const target = this.document.getElementById('federationPortalAlert');
    if (!target) return;
    target.className = `alert alert-${type}`;
    target.textContent = message;
    target.classList.remove('d-none');
    this.window.setTimeout(() => target.classList.add('d-none'), 7000);
  }

  statusClass(status) {
    if (['approved', 'active', 'completed'].includes(status)) return 'badge-success';
    if (['rejected', 'expired', 'failed'].includes(status)) return 'badge-danger';
    if (['queued', 'pending'].includes(status)) return 'badge-warning';
    return 'badge-secondary';
  }

  text(id, value) {
    const target = this.document.getElementById(id);
    if (target) target.textContent = String(value);
  }

  escape(value) {
    const div = this.document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
  }

  escapeAttr(value) {
    return this.escape(value).replace(/`/g, '&#96;');
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new FederationPortalModule(win, doc).init();
    win.ArcadeCloudDrive.modules['federation-portal'] = instance;
    return instance;
  }
}

FederationPortalModule.boot();
