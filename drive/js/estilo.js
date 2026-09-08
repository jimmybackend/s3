class EstiloModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    document.addEventListener('DOMContentLoaded', function () {
      const body = document.body;

      const themeClasses = ['theme-neon-green','theme-neon-blue','theme-neon-red','theme-neon-yellow'];
      const modeClasses = ['theme-dark','theme-light'];
      const visionClasses = ['vision-normal','vision-myopia','vision-protanopia','vision-deuteranopia','vision-tritanopia'];

      const defaultState = {
        theme: 'theme-neon-green',   // oficial por default
        mode: 'theme-dark',
        vision: 'vision-normal',
        ascii: true
      };

      function removeClasses(list) {
        list.forEach(c => body.classList.remove(c));
      }

      function applyState(state) {
        body.classList.add('ui-theme');

        removeClasses(themeClasses);
        removeClasses(modeClasses);
        removeClasses(visionClasses);

        const nextTheme = state.theme || defaultState.theme;
        const nextMode = state.mode || defaultState.mode;
        const nextVision = state.vision || defaultState.vision;

        body.classList.add(nextTheme);
        body.classList.add(nextMode);
        body.classList.add(nextVision);

        document.querySelectorAll('.js-set-theme').forEach(btn => {
          const active = btn.dataset.theme === nextTheme;
          btn.classList.toggle('active', active);
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        document.querySelectorAll('.js-set-mode').forEach(btn => {
          const active = btn.dataset.mode === nextMode;
          btn.classList.toggle('active', active);
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        document.querySelectorAll('.js-set-vision').forEach(btn => {
          const active = btn.dataset.vision === nextVision;
          btn.classList.toggle('active', active);
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        if (state.ascii) {
          body.classList.add('ascii-on');
        } else {
          body.classList.remove('ascii-on');
        }
      }

      function getStateFromBody() {
        return {
          theme: themeClasses.find(c => body.classList.contains(c)) || defaultState.theme,
          mode: modeClasses.find(c => body.classList.contains(c)) || defaultState.mode,
          vision: visionClasses.find(c => body.classList.contains(c)) || defaultState.vision,
          ascii: body.classList.contains('ascii-on')
        };
      }

      function savePrefs() {
        localStorage.setItem('ui-theme-state', JSON.stringify(getStateFromBody()));
      }

      function loadPrefs() {
      const saved = localStorage.getItem('ui-theme-state');

      if (!saved) {
        applyState(defaultState);   // usa verde neon
        return;
      }

      try {
        const state = JSON.parse(saved);
        applyState({ ...defaultState, ...state });
      } catch(e) {
        applyState(defaultState);
      }
    }

      function setTheme(theme) {
        applyState({ ...getStateFromBody(), theme });
        savePrefs();
      }

      function setMode(mode) {
        applyState({ ...getStateFromBody(), mode });
        savePrefs();
      }

      function setVision(vision) {
        applyState({ ...getStateFromBody(), vision });
        savePrefs();
      }

      function toggleAscii() {
        applyState({ ...getStateFromBody(), ascii: !body.classList.contains('ascii-on') });
        savePrefs();
      }

      function installAboutDialog() {
        const userMenu = document.getElementById('usuarioMenu');
        const dropdown = userMenu ? userMenu.closest('.dropdown') : null;
        const menu = dropdown ? dropdown.querySelector('.dropdown-menu') : null;

        if (menu && !document.getElementById('btnAcercaArcadeCloud')) {
          const aboutButton = document.createElement('button');
          aboutButton.type = 'button';
          aboutButton.id = 'btnAcercaArcadeCloud';
          aboutButton.className = 'dropdown-item';
          aboutButton.setAttribute('data-toggle', 'modal');
          aboutButton.setAttribute('data-target', '#modalAcercaArcadeCloud');
          aboutButton.innerHTML = '<i class="fas fa-circle-info"></i> Acerca de';

          const divider = menu.querySelector('.dropdown-divider');
          if (divider) menu.insertBefore(aboutButton, divider);
          else menu.appendChild(aboutButton);
        }

        if (document.getElementById('modalAcercaArcadeCloud')) return;

        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'modalAcercaArcadeCloud';
        modal.tabIndex = -1;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-labelledby', 'modalAcercaArcadeCloudLabel');
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
          <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
            <div class="modal-content">
              <div class="modal-header">
                <div>
                  <h5 class="modal-title" id="modalAcercaArcadeCloudLabel">
                    <i class="fas fa-cloud mr-2"></i>ArcadeCloud Drive
                  </h5>
                  <small class="text-muted">Drive web para Amazon S3</small>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                  <span aria-hidden="true">&times;</span>
                </button>
              </div>

              <div class="modal-body">
                <p>
                  <strong>ArcadeCloud Drive</strong> es un gestor web para Amazon S3 diseñado para
                  mantener la navegación y la organización lógica en MySQL, mientras Amazon S3 se
                  utiliza como almacenamiento físico de los archivos.
                </p>

                <div class="alert alert-info">
                  <strong>Software libre.</strong>
                  Este proyecto se distribuye bajo la
                  <strong>GNU General Public License v3.0 (GPLv3)</strong>.
                  Puedes usarlo, estudiarlo, modificarlo y redistribuirlo respetando los términos
                  de esa licencia y conservando los avisos legales que correspondan.
                </div>

                <p>
                  ArcadeCloud Drive no vende ni incluye una “licencia de S3”. Para utilizarlo
                  necesitas tu propia cuenta de AWS, un bucket de Amazon S3 y la configuración de
                  acceso correspondiente. Los cargos generados por AWS son responsabilidad del
                  titular de esa cuenta.
                </p>

                <p class="mb-3">
                  Si este software te resulta útil, conserva el crédito de
                  <strong>jimmybackend</strong> y, cuando sea posible, comparte tus mejoras con la
                  comunidad. El proyecto nació para aportar una herramienta práctica y reutilizable,
                  y agradeceremos que su origen no se pierda con el tiempo.
                </p>

                <div class="card">
                  <div class="card-body py-3">
                    <div><strong>Proyecto / autor:</strong> jimmybackend</div>
                    <div><strong>Contacto:</strong> <a href="mailto:jimmybacked@gmail.com">jimmybacked@gmail.com</a></div>
                    <div><strong>Soporte:</strong> <a href="mailto:soporte@esforzados.com">soporte@esforzados.com</a></div>
                    <div>
                      <strong>Repositorio:</strong>
                      <a href="https://github.com/jimmybackend/s3" target="_blank" rel="noopener noreferrer">
                        github.com/jimmybackend/s3
                      </a>
                    </div>
                    <div><strong>Licencia:</strong> GNU GPL v3.0</div>
                  </div>
                </div>

                <small class="d-block text-muted mt-3">
                  Software distribuido sin garantía, en los términos de la GPLv3.
                  Amazon Web Services y Amazon S3 son servicios de Amazon Web Services, Inc.
                </small>
              </div>

              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
              </div>
            </div>
          </div>`;

        document.body.appendChild(modal);
      }

      document.addEventListener('click', function(e) {
        const btnTheme = e.target.closest('.js-set-theme');
        if (btnTheme) {
          e.preventDefault();
          setTheme(btnTheme.dataset.theme);
          return;
        }

        const btnMode = e.target.closest('.js-set-mode');
        if (btnMode) {
          e.preventDefault();
          setMode(btnMode.dataset.mode);
          return;
        }

        const btnVision = e.target.closest('.js-set-vision');
        if (btnVision) {
          e.preventDefault();
          setVision(btnVision.dataset.vision);
          return;
        }

        const btnAscii = e.target.closest('#btnToggleAscii');
        if (btnAscii) {
          e.preventDefault();
          toggleAscii();
        }
      });

      installAboutDialog();
      loadPrefs();
    });

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new EstiloModule(win, doc).init();
    win.ArcadeCloudDrive.modules['estilo'] = instance;
    return instance;
  }
}

EstiloModule.boot();
