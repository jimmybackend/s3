from pathlib import Path


def replace_once(path: str, old: str, new: str, marker: str) -> None:
    p = Path(path)
    text = p.read_text()
    if new in text:
        print(f"{marker}_ALREADY_OK")
        return
    if old not in text:
        raise SystemExit(f"{marker}_OLD_NOT_FOUND")
    p.write_text(text.replace(old, new, 1))
    print(f"{marker}_OK")


# s3.php: versionar estilo, identificar panel Diseño y marcar Dropzone principal.
replace_once(
    "drive/s3.php",
    '<div class="dropdown-menu dropdown-menu-right" aria-labelledby="temaMenu" style="min-width:280px;">',
    '<div class="dropdown-menu dropdown-menu-right" id="temaMenuPanel" aria-labelledby="temaMenu" style="min-width:280px;">',
    "THEME_PANEL",
)
replace_once(
    "drive/s3.php",
    '<form action="api/upload.php?mode=local_put&action=init" class="dropzone mb-4" id="dropzonePublico">',
    '<form action="api/upload.php?mode=local_put&action=init" class="dropzone dropzone-drive mb-4" id="dropzonePublico">',
    "DROPZONE_CLASS",
)
replace_once(
    "drive/s3.php",
    '<script src="js/estilo.js"></script>',
    '<script src="js/estilo.js?v=<?= (int) filemtime(__DIR__ . \'/js/estilo.js\') ?>"></script>',
    "ESTILO_VERSION",
)

# Tooltip de metadatos al costado derecho en vez de encima del centro.
replace_once(
    "drive/bloque_archivos.php",
    'class="text-muted file-meta-line"\n                   data-toggle="tooltip"\n                   data-placement="top"',
    'class="text-muted file-meta-line"\n                   data-toggle="tooltip"\n                   data-placement="right"',
    "META_TOOLTIP_RIGHT",
)

# Estado visual real de Diseño/Visión.
p = Path("drive/js/estilo.js")
text = p.read_text()
old = """        body.classList.add(state.theme || defaultState.theme);\n        body.classList.add(state.mode || defaultState.mode);\n        body.classList.add(state.vision || defaultState.vision);\n\n        if (state.ascii) {"""
new = """        const nextTheme = state.theme || defaultState.theme;\n        const nextMode = state.mode || defaultState.mode;\n        const nextVision = state.vision || defaultState.vision;\n\n        body.classList.add(nextTheme);\n        body.classList.add(nextMode);\n        body.classList.add(nextVision);\n\n        document.querySelectorAll('.js-set-theme').forEach(btn => {\n          const active = btn.dataset.theme === nextTheme;\n          btn.classList.toggle('active', active);\n          btn.setAttribute('aria-pressed', active ? 'true' : 'false');\n        });\n        document.querySelectorAll('.js-set-mode').forEach(btn => {\n          const active = btn.dataset.mode === nextMode;\n          btn.classList.toggle('active', active);\n          btn.setAttribute('aria-pressed', active ? 'true' : 'false');\n        });\n        document.querySelectorAll('.js-set-vision').forEach(btn => {\n          const active = btn.dataset.vision === nextVision;\n          btn.classList.toggle('active', active);\n          btn.setAttribute('aria-pressed', active ? 'true' : 'false');\n        });\n\n        if (state.ascii) {"""
if new not in text:
    if old not in text:
        raise SystemExit("ESTILO_ACTIVE_OLD_NOT_FOUND")
    p.write_text(text.replace(old, new, 1))
print("ESTILO_ACTIVE_OK")

# Credenciales separadas para panel EC2/RDS, con fallback a las credenciales normales.
p = Path("Config-s3.php")
text = p.read_text()
anchor = """    public static function getS3(): S3Client\n    {\n"""
method = """    /**\n     * Configuración AWS para herramientas de control (EC2/RDS).\n     *\n     * Si AWS_CONTROL_ACCESS_KEY_ID / AWS_CONTROL_SECRET_ACCESS_KEY están\n     * definidos, se usan exclusivamente para el plano de control. Si no,\n     * se conserva compatibilidad usando las credenciales AWS generales.\n     */\n    public static function getAwsControlClientConfig(array $overrides = []): array\n    {\n        self::bootAwsEnv();\n\n        $key = self::env('AWS_CONTROL_ACCESS_KEY_ID');\n        $secret = self::env('AWS_CONTROL_SECRET_ACCESS_KEY');\n\n        if ($key === '' && $secret === '') {\n            return self::getAwsClientConfig($overrides);\n        }\n\n        if ($key === '' || $secret === '') {\n            throw new RuntimeException(\n                'AWS_CONTROL_ACCESS_KEY_ID y AWS_CONTROL_SECRET_ACCESS_KEY deben configurarse juntos.'\n            );\n        }\n\n        $credentials = [\n            'key' => $key,\n            'secret' => $secret,\n        ];\n\n        $token = self::env('AWS_CONTROL_SESSION_TOKEN');\n        if ($token !== '') {\n            $credentials['token'] = $token;\n        }\n\n        $config = [\n            'region' => self::getRegion(),\n            'version' => 'latest',\n            'credentials' => $credentials,\n        ];\n\n        return array_replace_recursive($config, $overrides);\n    }\n\n"""
if method not in text:
    if anchor not in text:
        raise SystemExit("CONTROL_CONFIG_ANCHOR_NOT_FOUND")
    p.write_text(text.replace(anchor, method + anchor, 1))
print("CONTROL_CONFIG_OK")

for gateway in ["drive/src/Aws/Ec2Gateway.php", "drive/src/Aws/RdsGateway.php"]:
    p = Path(gateway)
    text = p.read_text()
    new_text = text.replace("\\Config::getAwsClientConfig(", "\\Config::getAwsControlClientConfig(", 1)
    if new_text == text and "getAwsControlClientConfig" not in text:
        raise SystemExit(f"CONTROL_GATEWAY_NOT_FOUND:{gateway}")
    p.write_text(new_text)
print("CONTROL_GATEWAYS_OK")

# EC2: la instancia de producción actual y fallback explícito si el listado filtrado viene vacío.
p = Path("drive/ec2.php")
text = p.read_text()
old_ids = """const PROTECTED_INSTANCE_IDS = [\n    'i-091f5ddb0e2b42656',\n    'i-0978e1ba7e04a9d69',\n];"""
new_ids = """const PROTECTED_INSTANCE_IDS = [\n    'i-097146ee51c7f7026', // mailit-click\n];"""
if new_ids not in text:
    if old_ids not in text:
        raise SystemExit("EC2_IDS_OLD_NOT_FOUND")
    text = text.replace(old_ids, new_ids, 1)

old_list = """    $panel = $app->ec2Gateway($region);\n    $list = $panel->listInstances($state);"""
new_list = """    $panel = $app->ec2Gateway($region);\n    $list = $panel->listInstances($state);\n\n    // Fallback defensivo: si AWS devuelve una lista vacía con filtro,\n    // intenta localizar las instancias personales configuradas por ID.\n    if ($list === []) {\n        foreach (PROTECTED_INSTANCE_IDS as $configuredId) {\n            $instance = $panel->getInstance($configuredId);\n            if (!$instance) {\n                continue;\n            }\n\n            $instanceState = (string)($instance['State']['Name'] ?? 'unknown');\n            if ($state === 'all' || $state === '' || $state === $instanceState) {\n                $list[] = $instance;\n            }\n        }\n    }"""
if new_list not in text:
    if old_list not in text:
        raise SystemExit("EC2_LIST_OLD_NOT_FOUND")
    text = text.replace(old_list, new_list, 1)
p.write_text(text)
print("EC2_TARGET_OK")

# CSS de Dropzone + Visión perceptible + estados activos.
p = Path("drive/css/styles.css")
text = p.read_text()
marker = "/* ===== UX ROUND 2: DROPZONE + VISION ===== */"
css = r'''

/* ===== UX ROUND 2: DROPZONE + VISION ===== */
#dropzonePublico.dropzone-drive {
  display: flex !important;
  flex-wrap: wrap !important;
  justify-content: center !important;
  align-items: flex-start !important;
  gap: 12px !important;
  width: 100% !important;
  max-width: 100% !important;
  box-sizing: border-box !important;
  overflow: hidden !important;
}

#dropzonePublico.dropzone-drive .dz-message {
  flex: 0 0 100% !important;
  width: 100% !important;
  text-align: center !important;
  margin: 1rem 0 !important;
}

#dropzonePublico.dropzone-drive .dz-preview {
  margin: 6px !important;
  vertical-align: top !important;
  max-width: 150px !important;
}

body.ui-theme.vision-normal { filter: none !important; }
body.ui-theme.vision-myopia { filter: blur(.65px) contrast(.92) saturate(.82) !important; }
body.ui-theme.vision-protanopia { filter: hue-rotate(-28deg) saturate(.55) contrast(1.04) !important; }
body.ui-theme.vision-deuteranopia { filter: hue-rotate(24deg) saturate(.58) contrast(1.04) !important; }
body.ui-theme.vision-tritanopia { filter: hue-rotate(58deg) saturate(.62) contrast(1.04) !important; }

#temaMenuPanel .js-set-theme.active,
#temaMenuPanel .js-set-mode.active,
#temaMenuPanel .js-set-vision.active {
  font-weight: 700 !important;
  border-color: currentColor !important;
}

#temaMenuPanel .js-set-theme.active::after,
#temaMenuPanel .js-set-mode.active::after,
#temaMenuPanel .js-set-vision.active::after {
  content: "✓";
  float: right;
  margin-left: .75rem;
}
'''
if marker not in text:
    p.write_text(text.rstrip() + css + "\n")
print("STYLES_ROUND2_OK")

# Responsive: Dropzone 2 columnas, navbar/brand y Diseño pegado al lado derecho.
p = Path("drive/css/responsive.css")
text = p.read_text()
marker = "/* ===== UX ROUND 2: NAVBAR + DROPZONE MOVIL ===== */"
css = r'''

/* ===== UX ROUND 2: NAVBAR + DROPZONE MOVIL ===== */
.drive-navbar {
  min-height: 66px !important;
  align-items: center !important;
}

.drive-navbar .navbar-brand {
  min-width: 160px;
  display: inline-flex !important;
  align-items: center !important;
  white-space: nowrap !important;
}

@media (max-width: 991.98px), (pointer: coarse) {
  .drive-navbar {
    min-height: 62px !important;
  }

  .drive-navbar .navbar-brand {
    min-width: 58px !important;
    width: 58px !important;
    flex: 0 0 58px !important;
    overflow: hidden !important;
    font-size: 0 !important;
    margin-right: .35rem !important;
  }

  .drive-navbar .navbar-brand img.drive-brand-logo {
    width: 50px !important;
    height: 40px !important;
    max-width: 50px !important;
    object-fit: contain !important;
    margin-right: 0 !important;
  }

  #temaMenuPanel {
    position: fixed !important;
    top: 64px !important;
    right: 8px !important;
    left: auto !important;
    width: min(310px, calc(100vw - 16px)) !important;
    min-width: 0 !important;
    max-height: calc(100dvh - 76px) !important;
    overflow-y: auto !important;
    transform: none !important;
    z-index: 1205 !important;
  }

  #dropzonePublico.dropzone-drive {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 10px !important;
    padding: 12px !important;
    overflow: hidden !important;
  }

  #dropzonePublico.dropzone-drive .dz-message {
    grid-column: 1 / -1 !important;
    width: 100% !important;
  }

  #dropzonePublico.dropzone-drive .dz-preview {
    width: 100% !important;
    min-width: 0 !important;
    max-width: none !important;
    margin: 0 !important;
    box-sizing: border-box !important;
  }

  #dropzonePublico.dropzone-drive .dz-preview .dz-image,
  #dropzonePublico.dropzone-drive .dz-preview .dz-details,
  #dropzonePublico.dropzone-drive .dz-preview .dz-progress,
  #dropzonePublico.dropzone-drive .dz-preview .dz-error-message,
  #dropzonePublico.dropzone-drive .dz-preview .dz-success-mark,
  #dropzonePublico.dropzone-drive .dz-preview .dz-error-mark {
    max-width: 100% !important;
  }
}

@media (max-width: 420px) {
  #dropzonePublico.dropzone-drive {
    grid-template-columns: 1fr !important;
  }
}
'''
if marker not in text:
    p.write_text(text.rstrip() + css + "\n")
print("RESPONSIVE_ROUND2_OK")
