class FederationDropApp {
  constructor(root) {
    this.root = root;
    this.form = document.getElementById('dropCreateForm');
    this.file = document.getElementById('dropFile');
    this.email = document.getElementById('dropEmail');
    this.days = document.getElementById('dropDays');
    this.downloads = document.getElementById('dropDownloads');
    this.quote = document.getElementById('dropQuote');
    this.progress = document.getElementById('dropProgress');
    this.error = document.getElementById('dropError');
    this.button = document.getElementById('dropCreateButton');
    this.manageCard = document.getElementById('dropManageCard');
    this.manageStatus = document.getElementById('dropManageStatus');
    this.manageBody = document.getElementById('dropManageBody');
    this.refreshButton = document.getElementById('dropRefreshButton');
    this.currentDropId = '';
    this.ownerToken = '';
    this.pendingFile = null;
    this.resourceId = String(root?.dataset?.resourceId || '');
    this.resourceSize = Number(root?.dataset?.resourceSize || 0);
    this.pollTimer = null;
  }

  init() {
    if (!this.root) return;
    this.form?.addEventListener('submit', (event) => this.create(event));
    [this.file, this.days, this.downloads].forEach((element) => {
      element?.addEventListener('change', () => this.refreshQuote());
      element?.addEventListener('input', () => this.refreshQuote());
    });
    this.refreshButton?.addEventListener('click', () => this.refreshStatus());

    const manage = String(this.root.dataset.manage || this.root.dataset.paymentReturn || '');
    let ownerToken = String(this.root.dataset.ownerToken || '');
    if (!ownerToken && manage) {
      ownerToken = sessionStorage.getItem(`federationdrop.owner.${manage}`) || '';
    }
    if (manage && ownerToken) {
      this.currentDropId = manage;
      this.ownerToken = ownerToken;
      this.showManage();
      this.refreshStatus();
    }
    if (this.resourceId) this.refreshQuote();
  }

  async refreshQuote() {
    const file = this.file?.files?.[0];
    const sizeBytes = this.resourceId ? this.resourceSize : Number(file?.size || 0);
    const days = Number(this.days?.value || 0);
    const downloads = Number(this.downloads?.value || 0);
    if (!sizeBytes || !days || !downloads) {
      if (this.quote) this.quote.textContent = '—';
      return;
    }
    try {
      const body = new URLSearchParams({
        size_bytes: String(sizeBytes),
        days: String(days),
        downloads: String(downloads)
      });
      const data = await this.request('api.php?action=quote', { method: 'POST', body });
      this.quote.textContent = this.money(data.quote.amount_cents, data.quote.currency);
    } catch (_) {
      if (this.quote) this.quote.textContent = 'No disponible';
    }
  }

  async create(event) {
    event.preventDefault();
    const file = this.file?.files?.[0];
    if (!this.resourceId && !file) return this.showError('Selecciona un archivo.');
    const sizeBytes = this.resourceId ? this.resourceSize : Number(file?.size || 0);
    const maxBytes = Number(this.root.dataset.maxBytes || 0);
    if (maxBytes > 0 && sizeBytes > maxBytes) return this.showError('El archivo excede el máximo permitido.');

    this.setBusy(true);
    this.showError('');
    try {
      this.setProgress('Creando orden segura…');
      const body = new URLSearchParams({
        email: String(this.email?.value || ''),
        days: String(this.days?.value || ''),
        downloads: String(this.downloads?.value || ''),
        source_domain: String(this.root.dataset.source || '')
      });
      let action = 'create';
      if (this.resourceId) {
        action = 'create-public-resource';
        body.set('resource_id', this.resourceId);
      } else {
        body.set('filename', file.name);
        body.set('size_bytes', String(file.size));
        body.set('mime_type', file.type || 'application/octet-stream');
      }
      const order = await this.request(`api.php?action=${action}`, { method: 'POST', body });
      this.currentDropId = order.drop_id;
      this.ownerToken = order.owner_token;
      this.pendingFile = this.resourceId ? null : file;
      sessionStorage.setItem(`federationdrop.owner.${order.drop_id}`, order.owner_token);

      this.showManage();
      await this.refreshStatus();
      this.setProgress(this.resourceId
        ? 'Completa el pago. Después FederationCloud traerá el recurso directamente entre nubes; no tendrás que volver a seleccionarlo.'
        : 'Completa el pago en la ventana que se abrirá. El archivo todavía NO se ha subido.');

      const paymentWindow = window.open(order.checkout_url, '_blank', 'noopener,noreferrer');
      if (!paymentWindow) {
        this.setProgress('El navegador bloqueó la ventana de pago. Usa “Continuar al pago” abajo y no cierres esta página.');
      }
      this.startPaymentPolling();
    } catch (error) {
      this.showError(error?.message || 'No se pudo crear FederationDrop.');
      this.setProgress('');
    } finally {
      this.setBusy(false);
    }
  }

  startPaymentPolling() {
    this.stopPaymentPolling();
    let attempts = 0;
    this.pollTimer = window.setInterval(async () => {
      attempts += 1;
      try {
        const status = await this.refreshStatus();
        if (status?.can_upload && this.pendingFile) {
          this.stopPaymentPolling();
          await this.uploadPaidFile(this.pendingFile);
          return;
        }
        if (['active', 'deleted', 'expired', 'blocked'].includes(String(status?.status || ''))) {
          this.stopPaymentPolling();
        }
      } catch (_) {}
      if (attempts >= 200) this.stopPaymentPolling();
    }, 3000);
  }

  stopPaymentPolling() {
    if (this.pollTimer) window.clearInterval(this.pollTimer);
    this.pollTimer = null;
  }

  async uploadPaidFile(file) {
    if (!file || !this.currentDropId || !this.ownerToken) return;
    this.showError('');

    try {
      this.setProgress('Pago confirmado. Buscando un nodo de ingreso cercano…');
      const nearby = await this.tryNearbyIngress(file);
      if (nearby) {
        this.pendingFile = null;
        this.setProgress('Subida recibida por el nodo cercano. FederationCloud la está migrando a la custodia de drive.esforzados.com.');
        this.startPaymentPolling();
        await this.refreshStatus();
        return;
      }
    } catch (error) {
      console.warn('FederationDrop nearby ingress fallback:', error);
      this.setProgress('El nodo cercano no pudo completar la subida; continuando directamente con drive.esforzados.com…');
    }

    await this.uploadDirect(file);
  }

  async tryNearbyIngress(file) {
    const authBody = new URLSearchParams({
      drop_id: this.currentDropId,
      owner_token: this.ownerToken
    });
    const discovery = await this.request('api.php?action=ingress-candidates', {
      method: 'POST',
      body: authBody
    });
    const candidates = Array.isArray(discovery.candidates) ? discovery.candidates : [];
    if (!candidates.length) return false;

    const measured = await Promise.all(candidates.map(async (candidate) => {
      const latency = await this.measureIngressLatency(String(candidate.probe_url || ''));
      return Number.isFinite(latency) ? { candidate, latency } : null;
    }));
    const ranked = measured
      .filter(Boolean)
      .sort((a, b) => a.latency - b.latency);
    if (!ranked.length) return false;

    const selected = ranked[0].candidate;
    this.setProgress(`Nodo cercano: ${String(selected.domain || selected.node_id || 'FederationCloud')} · autorizando subida temporal…`);

    const authorizeBody = new URLSearchParams({
      drop_id: this.currentDropId,
      owner_token: this.ownerToken,
      ingress_node_id: String(selected.node_id || '')
    });
    const ingress = await this.request('api.php?action=ingress-authorize', {
      method: 'POST',
      body: authorizeBody
    });

    let remoteAuthorized = false;
    try {
      const remote = await this.remoteJson(`${ingress.endpoint}?action=authorize`, {
        action: 'authorize',
        grant: ingress.grant
      });
      remoteAuthorized = true;

      this.setProgress('Subiendo al nodo de ingreso más rápido…');
      const headers = new Headers(remote.upload?.headers || {});
      const uploadResponse = await fetch(String(remote.upload?.url || ''), {
        method: 'PUT',
        headers,
        body: file
      });
      if (!uploadResponse.ok) throw new Error('El almacenamiento del nodo cercano rechazó la subida.');

      this.setProgress('Verificando la subida en el nodo cercano…');
      const completed = await this.remoteJson(`${ingress.endpoint}?action=complete`, {
        action: 'complete',
        grant: ingress.grant
      });
      if (String(completed.ingress_id || '') !== String(ingress.ingress_id || '')) {
        throw new Error('El nodo cercano devolvió un identificador ingress inesperado.');
      }

      const registerBody = new URLSearchParams({
        drop_id: this.currentDropId,
        owner_token: this.ownerToken,
        ingress_id: String(ingress.ingress_id || '')
      });
      await this.request('api.php?action=ingress-register', {
        method: 'POST',
        body: registerBody
      });
      return true;
    } catch (error) {
      if (remoteAuthorized) {
        try {
          await this.remoteJson(`${ingress.endpoint}?action=delete`, {
            action: 'delete',
            grant: ingress.grant
          });
        } catch (_) {}
      }
      throw error;
    }
  }

  async measureIngressLatency(url) {
    if (!url) return Number.POSITIVE_INFINITY;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 1800);
    const started = performance.now();
    try {
      const response = await fetch(url, {
        method: 'GET',
        mode: 'cors',
        credentials: 'omit',
        cache: 'no-store',
        signal: controller.signal
      });
      if (!response.ok) return Number.POSITIVE_INFINITY;
      const data = await response.json().catch(() => null);
      if (!data || data.ok !== true) return Number.POSITIVE_INFINITY;
      return performance.now() - started;
    } catch (_) {
      return Number.POSITIVE_INFINITY;
    } finally {
      window.clearTimeout(timeout);
    }
  }

  async remoteJson(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      mode: 'cors',
      credentials: 'omit',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload || {})
    });
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || `Nodo ingress HTTP ${response.status}`);
    }
    return data;
  }

  async uploadDirect(file) {
    this.setProgress('Solicitando autorización temporal de subida central…');
    try {
      const authBody = new URLSearchParams({
        drop_id: this.currentDropId,
        owner_token: this.ownerToken
      });
      const auth = await this.request('api.php?action=upload-authorize', {
        method: 'POST',
        body: authBody
      });

      this.setProgress('Subiendo directamente al almacenamiento privado de drive.esforzados.com…');
      const uploadHeaders = new Headers(auth.upload.headers || {});
      const response = await fetch(auth.upload.url, {
        method: 'PUT',
        headers: uploadHeaders,
        body: file
      });
      if (!response.ok) throw new Error('S3 rechazó la subida del archivo.');

      this.setProgress('Verificando el objeto pagado…');
      const completeBody = new URLSearchParams({
        drop_id: this.currentDropId,
        owner_token: this.ownerToken
      });
      const status = await this.request('api.php?action=upload-complete', {
        method: 'POST',
        body: completeBody
      });
      this.pendingFile = null;
      this.renderStatus(status);
      this.setProgress('FederationDrop activo. Ya puedes compartir el enlace o su ArcadeLink.');
    } catch (error) {
      this.showError(error?.message || 'No se pudo subir el archivo pagado.');
      this.setProgress('La orden sigue disponible; puedes volver a seleccionar el archivo desde Administración.');
      await this.refreshStatus();
    }
  }

  async refreshStatus() {
    if (!this.currentDropId || !this.ownerToken) return null;
    try {
      const params = new URLSearchParams({
        action: 'status',
        drop_id: this.currentDropId,
        owner_token: this.ownerToken
      });
      const status = await this.request(`api.php?${params.toString()}`, { method: 'GET' });
      this.renderStatus(status);
      return status;
    } catch (error) {
      this.manageStatus.textContent = error?.message || 'No se pudo consultar FederationDrop.';
      throw error;
    }
  }

  renderStatus(status) {
    this.showManage();
    const label = {
      pending_upload: status.materializing ? 'Pagado · trayendo recurso entre nubes' : 'Pagado · pendiente de subir archivo',
      pending_payment: 'Pendiente de pago',
      active: 'Activo',
      expired: 'Vencido',
      deleted: 'Eliminado',
      blocked: 'Bloqueado'
    }[status.status] || status.status;

    this.manageStatus.textContent = `${label} · ${status.download_count}/${status.max_downloads} descargas`;
    const parts = [];
    parts.push(`<p><strong>${this.escape(status.filename)}</strong><br><span class="drop-muted">${this.escape(this.money(status.amount_cents, status.currency))}</span></p>`);

    if (status.status === 'active') {
      parts.push(`<div class="mb-2"><strong>Descarga pública</strong><div class="drop-link"><a rel="noopener noreferrer" href="${this.attr(status.share_url)}">${this.escape(status.share_url)}</a></div></div>`);
      parts.push(`<div class="mb-2"><strong>ArcadeLink</strong><div class="drop-link"><a rel="noopener noreferrer" href="${this.attr(status.arcadelink_url)}">${this.escape(status.arcadelink_url)}</a></div></div>`);
      parts.push(`<div class="drop-muted small mb-3">Vence: ${this.escape(status.expires_at || '')}</div>`);
    } else if (status.payment_status !== 'paid' && status.checkout_url) {
      parts.push(`<p><a class="btn btn-info" target="_blank" rel="noopener noreferrer" href="${this.attr(status.checkout_url)}">Continuar al pago</a></p>`);
      parts.push('<p class="drop-muted small">El archivo no se subirá hasta que el pago sea confirmado.</p>');
    } else if (status.materializing) {
      parts.push('<div class="alert alert-info mb-3">Pago confirmado. FederationCloud está trayendo el recurso público directamente desde las copias disponibles y verificará el SHA-256 antes de activarlo.</div>');
      if (status.source_resource_id) {
        parts.push(`<div class="drop-muted small mb-3">Fuente: ${this.escape(status.source_resource_id)}</div>`);
      }
    } else if (status.can_upload) {
      parts.push('<div class="form-group"><label for="dropPaidFile">Pago confirmado. Selecciona el archivo de la orden para subirlo.</label><input id="dropPaidFile" class="form-control-file" type="file"></div>');
      parts.push('<button id="dropPaidUploadButton" type="button" class="btn btn-info btn-sm">Subir archivo pagado</button>');
    }

    if (!['deleted', 'expired'].includes(status.status)) {
      parts.push('<button id="dropDeleteButton" type="button" class="btn btn-outline-danger btn-sm ml-2">Eliminar ahora</button>');
    }

    this.manageBody.innerHTML = parts.join('');
    const paidFile = document.getElementById('dropPaidFile');
    document.getElementById('dropPaidUploadButton')?.addEventListener('click', () => {
      const selected = paidFile?.files?.[0];
      if (!selected) return this.showError('Selecciona el archivo que pagaste.');
      if (status.size_bytes && Number(selected.size) !== Number(status.size_bytes)) {
        return this.showError('El tamaño del archivo no coincide con la orden pagada.');
      }
      this.uploadPaidFile(selected);
    });
    document.getElementById('dropDeleteButton')?.addEventListener('click', () => this.deleteCurrent());
  }

  async deleteCurrent() {
    if (!this.currentDropId || !this.ownerToken) return;
    if (!window.confirm('¿Eliminar este FederationDrop y su objeto almacenado?')) return;
    try {
      const body = new URLSearchParams({
        drop_id: this.currentDropId,
        owner_token: this.ownerToken
      });
      const result = await this.request('api.php?action=delete', { method: 'POST', body });
      this.stopPaymentPolling();
      this.manageStatus.textContent = result.status === 'deleted' ? 'Eliminado' : String(result.status || '');
      this.manageBody.innerHTML = '<p>El FederationDrop fue retirado.</p>';
    } catch (error) {
      this.manageStatus.textContent = error?.message || 'No se pudo eliminar FederationDrop.';
    }
  }

  async request(url, options) {
    const headers = {
      'Accept': 'application/json',
      ...(options?.body instanceof URLSearchParams ? {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'} : {})
    };
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...options,
      headers: {...headers, ...(options?.headers || {})}
    });
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'FederationDrop no pudo completar la operación.');
    }
    return data;
  }

  money(cents, currency) {
    const amount = Number(cents || 0) / 100;
    try {
      return new Intl.NumberFormat('es-MX', { style: 'currency', currency: currency || 'MXN' }).format(amount);
    } catch (_) {
      return `${amount.toFixed(2)} ${currency || 'MXN'}`;
    }
  }

  showManage() {
    this.manageCard?.classList.remove('d-none');
  }

  setBusy(value) {
    if (this.button) this.button.disabled = value || this.root.dataset.ready !== '1';
  }

  setProgress(message) {
    if (this.progress) this.progress.textContent = message;
  }

  showError(message) {
    if (!this.error) return;
    this.error.textContent = message;
    this.error.classList.toggle('d-none', !message);
  }

  escape(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
  }

  attr(value) {
    return this.escape(value).replace(/"/g, '&quot;');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('federationDropApp');
  if (root) new FederationDropApp(root).init();
});
