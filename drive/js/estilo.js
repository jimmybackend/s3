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
      const visionClasses = ['vision-normal','vision-myopia','vision-presbyopia','vision-protanopia','vision-deuteranopia','vision-tritanopia'];

      const defaultState = {
        theme: 'theme-neon-green',
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

        if (state.ascii) body.classList.add('ascii-on');
        else body.classList.remove('ascii-on');
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
          applyState(defaultState);
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

      function installVisionAccessibility() {
        if (!document.querySelector('link[data-drive-vision-accessibility]')) {
          const link = document.createElement('link');
          link.rel = 'stylesheet';
          link.href = 'css/vision-accessibility.css?v=20260910-1';
          link.dataset.driveVisionAccessibility = '1';
          document.head.appendChild(link);
        }

        const myopiaButton = document.querySelector('.js-set-vision[data-vision="vision-myopia"]');
        if (!myopiaButton) return;

        myopiaButton.textContent = 'Miopía · lectura clara';
        myopiaButton.title = 'Prioriza nitidez y contraste; no simula desenfoque';
        myopiaButton.setAttribute('aria-label', 'Miopía, lectura clara');

        if (!document.querySelector('.js-set-vision[data-vision="vision-presbyopia"]')) {
          const presbyopiaButton = document.createElement('button');
          presbyopiaButton.type = 'button';
          presbyopiaButton.className = 'dropdown-item js-set-vision';
          presbyopiaButton.dataset.vision = 'vision-presbyopia';
          presbyopiaButton.textContent = 'Vista cansada';
          presbyopiaButton.title = 'Aumenta tamaño, espaciado, contraste y áreas táctiles';
          presbyopiaButton.setAttribute('aria-label', 'Vista cansada, lectura ampliada');
          myopiaButton.insertAdjacentElement('afterend', presbyopiaButton);
        }
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

                <div class="alert alert-info mb-3">
                  <div class="py-1">
                    <h6 class="mb-2"><i class="fas fa-diagram-project mr-1"></i> Ecosistema jimmybackend</h6>
                    <p class="mb-2">
                      ArcadeCloud Drive está diseñado para convivir e integrarse con otros proyectos
                      del mismo ecosistema. Cada aplicación puede operar por separado y, cuando se
                      configura así, MiChat y ArcadeCloud Drive pueden compartir la misma base MySQL.
                    </p>
                    <ul class="mb-0 pl-4">
                      <li><a href="https://github.com/jimmybackend/michat" target="_blank" rel="noopener noreferrer" style="color:#111 !important;text-decoration:underline;">MiChat</a> — chat e integración con Amazon Bedrock.</li>
                      <li><a href="https://github.com/jimmybackend/MCMA-OpenMemory" target="_blank" rel="noopener noreferrer" style="color:#111 !important;text-decoration:underline;">MCMA-OpenMemory</a> — memoria artificial y recuperación de conocimiento.</li>
                      <li><a href="https://github.com/jimmybackend/s3" target="_blank" rel="noopener noreferrer" style="color:#111 !important;text-decoration:underline;">ArcadeCloud Drive</a> — navegación MySQL y almacenamiento físico en Amazon S3.</li>
                    </ul>
                  </div>
                </div>

                <p class="mb-3">
                  Si este software te resulta útil, conserva el crédito de
                  <strong>jimmybackend</strong> y, cuando sea posible, comparte tus mejoras con la
                  comunidad. El proyecto nació para aportar una herramienta práctica y reutilizable,
                  y agradeceremos que su origen no se pierda con el tiempo.
                </p>

                <div class="alert alert-info mb-0">
                  <div class="py-1">
                    <div><strong>Proyecto / autor:</strong> jimmybackend</div>
                    <div><strong>Contacto:</strong> <a href="mailto:jimmybackend@gmail.com" style="color:#111 !important;text-decoration:underline;">jimmybackend@gmail.com</a></div>
                    <div><strong>Soporte:</strong> <a href="mailto:soporte@esforzados.com" style="color:#111 !important;text-decoration:underline;">soporte@esforzados.com</a></div>
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

      function installUsefulLinks() {
        const modal = document.getElementById('modalEnlacesUtiles');
        const list = modal ? modal.querySelector('ul.list-group') : null;
        if (!list) return;

        list.innerHTML = `
          <li class="list-group-item">
            <a href="https://web.airdroid.com/?from=usercenter&lang=es-es" target="_blank" rel="noopener noreferrer">
              <i class="fab fa-android text-success mr-2"></i> AirDroid Web
            </a>
          </li>
          <li class="list-group-item">
            <a href="up.php" target="_blank">
              <i class="fas fa-upload text-primary mr-2"></i> Subir +1GB
            </a>
          </li>
          <li class="list-group-item">
            <a href="aws.php" target="_blank">
              <i class="fas fa-qrcode text-primary mr-2"></i> Generador OTP
            </a>
          </li>
          <li class="list-group-item">
            <a href="ec2.php" target="_blank">
              <i class="fas fa-server text-info mr-2"></i> EC2
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://github.com/jimmybackend/michat" target="_blank" rel="noopener noreferrer">
              <i class="fab fa-github mr-2"></i> GitHub · MiChat
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://github.com/jimmybackend/MCMA-OpenMemory" target="_blank" rel="noopener noreferrer">
              <i class="fab fa-github mr-2"></i> GitHub · MCMA-OpenMemory
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://github.com/jimmybackend/s3" target="_blank" rel="noopener noreferrer">
              <i class="fab fa-github mr-2"></i> GitHub · ArcadeCloud Drive
            </a>
          </li>`;
      }

      function loadAiSearchModule() {
        if (document.querySelector('script[data-drive-ai-search]')) return;
        const script = document.createElement('script');
        script.src = 'js/ai-search.js?v=20260908-1';
        script.async = false;
        script.dataset.driveAiSearch = '1';
        document.body.appendChild(script);
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

      installVisionAccessibility();
      installAboutDialog();
      installUsefulLinks();
      loadAiSearchModule();
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