class ArcadeCloudOsFederationApp {
  constructor(win, doc) {
    this.window = win; this.document = doc;
    this.host = doc.getElementById('federationApp');
    this.toolbar = doc.getElementById('federationToolbar');
    this.loading = doc.getElementById('federationFrameLoading');
    this.status = doc.getElementById('federationWindowStatus');
    this.currentView = 'search'; this.loaded = false;
    this.labels = { search: 'Catálogo global', requests: 'Solicitudes', shares: 'Compartidos', replicas: 'Réplicas', about: 'Acerca de FederationCloud', moderation: 'Moderación' };
    this.panes = { search: 'federationSearchPane', requests: 'federationRequestsPane', shares: 'federationSharesPane', replicas: 'federationReplicasPane' };
    this.adminViews = { about: 'federationcloud/os-admin.php?embed=1', moderation: 'federationcloud/moderation.php?embed=1' };
  }

  init() {
    if (!this.host || !this.toolbar) return this;
    this.toolbar.addEventListener('click', event => {
      const button = event.target.closest('[data-federation-view]');
      if (!button) return;
      event.preventDefault(); this.openView(String(button.dataset.federationView || 'search'));
    });
    this.document.querySelectorAll('[data-open-federation]').forEach(button => button.addEventListener('click', () => this.ensureLoaded()));
    this.syncButtons(); return this;
  }

  async ensureLoaded() {
    if (this.loaded) return;
    this.setLoading(true);
    try {
      const response = await this.window.fetch(this.host.dataset.source, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-ArcadeCloud-Embed': '1', 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) throw new Error('No se pudo cargar FederationCloud.');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const main = page.querySelector('main.federation-portal-shell');
      if (!main) throw new Error('FederationCloud devolvió una vista no integrable.');
      ['federationNodeId', 'federationDropUrl', 'federationDropSource'].forEach(key => { if (page.body.dataset[key]) this.document.body.dataset[key] = page.body.dataset[key]; });
      this.document.body.classList.add('federation-portal-embedded');
      this.host.replaceChildren(this.document.importNode(main, true));
      this.loaded = true;
      this.window.FederationPortalModule?.boot(this.window, this.document);
      this.window.FederationShareDriveModule?.boot(this.window, this.document);
      this.showPane(this.currentView);
    } catch (error) { this.host.textContent = error?.message || 'No se pudo cargar FederationCloud.'; }
    finally { this.setLoading(false); }
  }

  async openView(view) {
    if (!this.labels[view]) return false;
    this.currentView = view; this.syncButtons(); this.setStatus(this.labels[view]);
    if (this.adminViews[view]) await this.loadStandalone(this.adminViews[view]);
    else { await this.ensureLoaded(); this.showPane(view); }
    return true;
  }

  async loadStandalone(url) {
    this.setLoading(true);
    try {
      const response = await this.window.fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-ArcadeCloud-Embed': '1', 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) throw new Error('No se pudo cargar esta vista de FederationCloud.');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const main = page.querySelector('main') || page.querySelector('[role="main"]');
      if (!main) throw new Error('FederationCloud devolvió una vista no integrable.');
      this.host.replaceChildren(this.document.importNode(main, true));
      this.loaded = false;
      page.querySelectorAll('script').forEach(source => {
        if (/theme-state-bridge\.js/.test(source.src || '')) return;
        const script = this.document.createElement('script');
        if (source.getAttribute('src')) script.src = new URL(source.getAttribute('src'), new URL(url, this.window.location.href)).toString();
        else script.textContent = source.textContent;
        this.host.append(script);
      });
    } catch (error) { this.host.textContent = error?.message || 'No se pudo cargar FederationCloud.'; }
    finally { this.setLoading(false); }
  }

  showPane(view) {
    const target = this.panes[view]; if (!target) return;
    this.host.querySelectorAll('.tab-pane').forEach(pane => {
      const active = pane.id === target; pane.classList.toggle('show', active); pane.classList.toggle('active', active);
    });
  }

  syncButtons() { this.toolbar?.querySelectorAll('[data-federation-view]').forEach(button => { const active = button.dataset.federationView === this.currentView; button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', active ? 'true' : 'false'); }); }
  setLoading(value) { if (this.loading) this.loading.hidden = !value; this.host?.classList.toggle('is-loading', value); }
  setStatus(value) { if (this.status) this.status.textContent = value; }
}

window.ArcadeCloudOsFederation = new ArcadeCloudOsFederationApp(window, document).init();
