class ArcadeCloudOsFederationApp {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.frame = doc.getElementById('federationFrame');
    this.toolbar = doc.getElementById('federationToolbar');
    this.loading = doc.getElementById('federationFrameLoading');
    this.status = doc.getElementById('federationWindowStatus');
    this.currentView = 'search';
    this.views = {
      search: {
        url: 'federationcloud/portal.php?embed=1&view=search',
        label: 'Catálogo global'
      },
      requests: {
        url: 'federationcloud/portal.php?embed=1&view=requests',
        label: 'Solicitudes'
      },
      shares: {
        url: 'federationcloud/portal.php?embed=1&view=shares',
        label: 'Compartidos'
      },
      replicas: {
        url: 'federationcloud/portal.php?embed=1&view=replicas',
        label: 'Réplicas'
      },
      about: {
        url: 'federationcloud/os-admin.php?embed=1',
        label: 'Acerca de FederationCloud'
      },
      moderation: {
        url: 'federationcloud/moderation.php?embed=1',
        label: 'Moderación'
      }
    };
  }

  init() {
    if (!this.frame || !this.toolbar) return this;

    this.toolbar.addEventListener('click', (event) => {
      const button = event.target.closest('[data-federation-view]');
      if (!button || button.disabled) return;
      event.preventDefault();
      this.openView(String(button.dataset.federationView || 'search'));
    });

    this.frame.addEventListener('load', () => {
      this.setLoading(false);
      this.syncFromFrame();
    });

    this.document.querySelectorAll('[data-open-federation]').forEach((button) => {
      button.addEventListener('click', () => {
        this.setStatus(this.views[this.currentView]?.label || 'FederationCloud');
      });
    });

    this.syncButtons();
    return this;
  }

  openView(view) {
    const config = this.views[view];
    if (!config || !this.frame) return false;

    this.currentView = view;
    this.syncButtons();
    this.setStatus(config.label);
    this.setLoading(true);

    const target = new URL(config.url, this.window.location.href).toString();
    if (this.frame.src !== target) {
      this.frame.src = target;
    } else {
      try {
        this.frame.contentWindow.location.reload();
      } catch (_) {
        this.frame.src = target;
      }
    }

    return true;
  }

  syncButtons() {
    if (!this.toolbar) return;
    this.toolbar.querySelectorAll('[data-federation-view]').forEach((button) => {
      const active = String(button.dataset.federationView || '') === this.currentView;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  syncFromFrame() {
    if (!this.frame) return;

    try {
      const url = new URL(this.frame.contentWindow.location.href);
      const path = url.pathname;

      if (path.endsWith('/federationcloud/os-admin.php')) {
        this.currentView = 'about';
      } else if (path.endsWith('/federationcloud/moderation.php')) {
        this.currentView = 'moderation';
      } else if (path.endsWith('/federationcloud/portal.php')) {
        const view = String(url.searchParams.get('view') || 'search');
        this.currentView = Object.prototype.hasOwnProperty.call(this.views, view) ? view : 'search';
      }

      this.syncButtons();
      this.setStatus(this.views[this.currentView]?.label || 'FederationCloud');
    } catch (_) {
      this.setStatus('FederationCloud');
    }
  }

  setLoading(loading) {
    if (this.loading) this.loading.hidden = !loading;
    if (this.frame) this.frame.classList.toggle('is-loading', loading);
  }

  setStatus(text) {
    if (this.status) this.status.textContent = String(text || 'FederationCloud');
  }
}

window.ArcadeCloudOsFederation = new ArcadeCloudOsFederationApp(window, document).init();
