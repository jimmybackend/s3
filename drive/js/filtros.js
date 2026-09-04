class FiltrosModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    const FiltroUI = {
      init: function () {
        const formFiltros = document.getElementById('formFiltros');
        const formLimite  = document.getElementById('formLimite');
        const btnQuitar   = document.getElementById('btnQuitarFiltros');

        if (formFiltros) {
          formFiltros.addEventListener('submit', this.aplicarFiltros);
        }
        if (formLimite) {
          const select = formLimite.querySelector('select[name="limite"]');
          if (select) select.addEventListener('change', this.aplicarDesdeSelect);
        }
        if (btnQuitar) {
          btnQuitar.addEventListener('click', this.quitarFiltros);
        }

        this._toggleBtnQuitar();
      },

      _toggleBtnQuitar: function() {
        const form = document.getElementById('formFiltros');
        const btn  = document.getElementById('btnQuitarFiltros');
        if (!form || !btn) return;

        const has = (form.buscar?.value || form.fecha_inicio?.value ||
                     form.fecha_fin?.value || form.tipo?.value);
        btn.style.display = has ? 'inline-block' : 'none';
      },

      aplicarFiltros: function (e) {
        e.preventDefault();
        const f = e.target;
        const L = document.getElementById('formLimite');
        const filtros = {
          buscar:       f.buscar?.value ?? '',
          fecha_inicio: f.fecha_inicio?.value ?? '',
          fecha_fin:    f.fecha_fin?.value ?? '',
          tipo:         f.tipo?.value ?? '',
          limite:       L?.querySelector('select[name="limite"]')?.value ?? 50,
          pagina:       1
        };
        actualizarBloqueArchivos(null, filtros);
      },

      aplicarDesdeSelect: function (e) {
        const v = parseInt(e.target.value, 10);
        const f = document.getElementById('formFiltros');
        const filtros = {
          limite:       isNaN(v) ? 50 : v,
          buscar:       f?.buscar?.value ?? '',
          fecha_inicio: f?.fecha_inicio?.value ?? '',
          fecha_fin:    f?.fecha_fin?.value ?? '',
          tipo:         f?.tipo?.value ?? '',
          pagina:       1
        };
        actualizarBloqueArchivos(null, filtros);
      },

      quitarFiltros: function () {
        actualizarBloqueArchivos(null, {
          buscar:      '',
          fecha_inicio:'',
          fecha_fin:   '',
          tipo:        '',
          pagina:      1
        });
      }
    };

    document.addEventListener('DOMContentLoaded', () => FiltroUI.init());
    window.FiltroUI = FiltroUI;

    if (typeof FiltroUI !== 'undefined' && typeof window.FiltroUI === 'undefined') window.FiltroUI = FiltroUI;
    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new FiltrosModule(win, doc).init();
    win.ArcadeCloudDrive.modules['filtros'] = instance;
    return instance;
  }
}

FiltrosModule.boot();
