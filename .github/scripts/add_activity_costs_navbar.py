from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
S3 = ROOT / 'drive' / 's3.php'

text = S3.read_text(encoding='utf-8')

owner_line = "$canViewRealAws = $app->personalToolAccessService()->state() === 'owner';"
user_line = "$userId = $session->userId();"

if owner_line not in text:
    if user_line not in text:
        raise SystemExit('No se encontró la asignación de userId en drive/s3.php')
    text = text.replace(user_line, user_line + "\n" + owner_line, 1)

links_button = '''          <button class="dropdown-item" data-toggle="modal" data-target="#modalEnlacesUtiles">
            <i class="fas fa-link"></i> Enlaces
          </button>'''

activity_link = '''          <a class="dropdown-item" href="activity_costs.php">
            <i class="fas fa-receipt"></i> Actividad y costos
          </a>'''

if 'href="activity_costs.php"' not in text:
    if links_button not in text:
        raise SystemExit('No se encontró el botón Enlaces en el menú de usuario')
    text = text.replace(links_button, links_button + "\n\n" + activity_link, 1)

cost_button = '''          <button class="dropdown-item" data-toggle="modal" data-target="#modalCostosAws">
              <i class="fas fa-chart-line"></i> Costos AWS
          </button>'''

protected_cost_button = '''          <?php if ($canViewRealAws): ?>
          <button class="dropdown-item" data-toggle="modal" data-target="#modalCostosAws">
              <i class="fas fa-chart-line"></i> Costos AWS
          </button>
          <?php endif; ?>'''

if '<?php if ($canViewRealAws): ?>' not in text:
    if cost_button not in text:
        raise SystemExit('No se encontró el botón Costos AWS en el menú de usuario')
    text = text.replace(cost_button, protected_cost_button, 1)

required = [
    owner_line,
    'href="activity_costs.php"',
    '<i class="fas fa-receipt"></i> Actividad y costos',
    '<?php if ($canViewRealAws): ?>',
    'data-target="#modalCostosAws"',
]
for item in required:
    if item not in text:
        raise SystemExit('Falta resultado esperado en drive/s3.php: ' + item)

S3.write_text(text, encoding='utf-8')
print('Activity/cost navbar migration applied')
