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
  }

  async refreshQuote() {
    const file = this.file?.files?.[0];
    const days = Number(this.days?.value || 0);
    const downloads = Number(this.downloads?.value || 0);
    if (!file || !days || !downloads) {
      if (this.quote) this.quote.textContent = '—';
      return;
    }
    try {
      const body = new URLSearchParams({
        size_bytes: String(file.size),
        days: String(days),
        downloads: String(downloads)
      });
      const data = await this.request('api.php?action=quote', { method: 'POST', body });
      this.quote.textContent = this.money(data.quote.amount_cents, data.quote.currency);
    } catch (error) {
      if (this.quote) this.quote.textContent = 'No disponible';
    }
  }

  async create(event) {
    event.preventDefault();
    const file = this.file?.files?.[0];
    if (!file) return this.showError('Selecciona un archivo.');
    const maxBytes = Number(this.root.dataset.maxBytes || 0);
    if (maxBytes > 0 && file.size > maxBytes) return this.showError('El archivo excede el máximo permitido.');

    this.setBusy(true);
    this.showError('');
    try {
      this.setProgress('Creando orden y preparando almacenamiento privado…');
      const body = new URLSearchParams({
        email: String(this.email?.value || ''),
        filename: file.name,
        size_bytes: String(file.size),
        mime_type: file.type || 'application/octet-stream',
        days: String(this.days?.value || ''),
        downloads: String(this.downloads?.value || ''),
        source_domain: String(this.root.dataset.source || '')
      });
      const order = await this.request('api.php?action=create', { method: 'POST', body });
      this.currentDropId = order.drop_id;
      this.ownerToken = order.owner_token;
      sessionStorage.setItem(`federationdrop.owner.${order.drop_id}`, order.owner_token);

      this.setProgress('Subiendo directamente al almacenamiento del nodo…');
      const uploadHeaders = new Headers(order.upload.headers || {});
      const response = await fetch(order.upload.url, {
        method: 'PUT',
        headers: uploadHeaders,
        body: file
      });
      if (!response.ok) throw new Error('S3 rechazó la subida del archivo.');

      this.setProgress('Verificando objeto y cerrando la subida…');
      const completeBody = new URLSearchParams({
        drop_id: order.drop_id,
        owner_token: order.owner_token
      });
      const status = await this.request('api.php?action=upload-complete', {
        method: 'POST',
        body: completeBody
      });

      this.showManage();
      this.renderStatus(status);
      if (status.payment_status !== 'paid' && order.checkout_url) {
        this.setProgress('Archivo bajo custodia. Abriendo el pago…');
        window.location.assign(order.checkout_url);
        return;
      }
      this.setProgress('FederationDrop activo.');
    } catch (error) {
      this.showError(error?.message || 'No se pudo crear FederationDrop.');
      this.setProgress('');
    } finally {
      this.setBusy(false);
    }
  }

  async refreshStatus() {
    if (!this.currentDropId || !this.ownerToken) return;
    try {
      const params = new URLSearchParams({
        action: 'status',
        drop_id: this.currentDropId,
        owner_token: this.ownerToken
      });
      const status = await this.request(`api.php?${params.toString()}`, { method: 'GET' });
      this.renderStatus(status);
    } catch (error) {
      this.manageStatus.textContent = error?.message || 'No se pudo consultar FederationDrop.';
    }
  }

  renderStatus(status) {
    this.showManage();
    const label = {
      pending_upload: 'Pendiente de subida',
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
      parts.push(`<div class="mb-2"><strong>Descarga pública</strong><div class="drop-link"><a href="${this.attr(status.share_url)}">${this.escape(status.share_url)}</a></div></div>`);
      parts.push(`<div class="mb-2"><strong>ArcadeLink</strong><div class="drop-link"><a href="${this.attr(status.arcadelink_url)}">${this.escape(status.arcadelink_url)}</a></div></div>`);
      parts.push(`<div class="drop-muted small mb-3">Vence: ${this.escape(status.expires_at || '')}</div>`);
    } else if (status.payment_status !== 'paid' && status.checkout_url) {
      parts.push(`<p><a class="btn btn-info" href="${this.attr(status.checkout_url)}">Continuar al pago</a></p>`);
    }

    if (!['deleted', 'expired'].includes(status.status)) {
      parts.push('<button id="dropDeleteButton" type="button" class="btn btn-outline-danger btn-sm">Eliminar ahora</button>');
    }

    this.manageBody.innerHTML = parts.join('');
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
      this.manageStatus.textContent = result.status === 'deleted' ? 'Eliminado' : String(result.status || '');
      this.manageBody.innerHTML = '<p>El objeto fue retirado del almacenamiento.</p>';
    } catch (error) {
      this.manageStatus.textContent = error?.message || 'No se pudo eliminar FederationDrop.';
    }
  }

  async request(url, options) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        ...(options?.body instanceof URLSearchParams ? {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'} : {})
      },
      ...options
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
