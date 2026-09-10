class FederationFooterModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const nodeTarget = this.document.getElementById('footerFederationNode');
    const countTarget = this.document.getElementById('footerFederationPeers');
    if (!nodeTarget || !countTarget) return this;

    this.refresh(nodeTarget, countTarget);
    return this;
  }

  async refresh(nodeTarget, countTarget) {
    try {
      const response = await fetch('federationcloud/nodes.php', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await response.json();
      if (!response.ok || !data.ok || !data.local_node || !data.local_node.node_id) {
        throw new Error(data.error || `HTTP ${response.status}`);
      }

      const nodeId = String(data.local_node.node_id);
      nodeTarget.textContent = this.shortNodeId(nodeId);
      nodeTarget.title = nodeId;

      const connected = Math.max(1, Number.parseInt(data.connected_nodes, 10) || 1);
      countTarget.textContent = String(connected);
      countTarget.title = `${connected} nodo${connected === 1 ? '' : 's'} activo${connected === 1 ? '' : 's'} en los últimos ${Number(data.active_window_minutes) || 15} minutos`;

      if (data.degraded) {
        countTarget.title += ' · seed temporalmente no disponible';
      }
    } catch (error) {
      nodeTarget.textContent = 'no disponible';
      countTarget.textContent = '—';
      console.error('[federation-footer] No se pudo consultar FederationCloud:', error);
    }
  }

  shortNodeId(nodeId) {
    if (nodeId.length <= 26) return nodeId;
    return `${nodeId.slice(0, 14)}…${nodeId.slice(-8)}`;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new FederationFooterModule(win, doc).init();
    win.ArcadeCloudDrive.modules['federation-footer'] = instance;
    return instance;
  }
}

FederationFooterModule.boot();
