<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class ActivityCostPageRenderer
{
    private const ACTION_LABELS = [
        'upload' => 'Subir archivo',
        'multipart_upload' => 'Subida multipart',
        'multipart_resume' => 'Reanudar multipart',
        'download' => 'Descargar',
        'preview' => 'Vista previa',
        'thumbnail' => 'Miniatura',
        'delete' => 'Eliminar',
        'move' => 'Mover',
        'rename' => 'Renombrar',
        'zip' => 'Descargar ZIP',
        'share' => 'Compartir',
        'textract' => 'Textract',
        'rekognition' => 'Rekognition',
        'polly' => 'Polly',
        'polly_load_text' => 'Cargar texto para Polly',
        'translate' => 'Translate',
        'comprehend' => 'Comprehend',
        'transcribe' => 'Transcribe',
        'cost_explorer' => 'Consultar Cost Explorer',
    ];

    private const UNIT_LABELS = [
        's3.put_request' => 'PUT',
        's3.copy_request' => 'COPY',
        's3.list_request' => 'LIST',
        's3.get_request' => 'GET',
        's3.head_request' => 'HEAD',
        's3.delete_request' => 'DELETE',
        's3.presigned_get_intent' => 'GET firmado (intención)',
        's3.multipart_requests_unknown' => 'peticiones multipart no determinadas',
        's3.storage_bytes_delta' => 'bytes nuevos',
        's3.transfer_bytes' => 'bytes transferidos',
        'rekognition.group2_image' => 'imagen API grupo 2',
        'textract.detect_document_text_page' => 'página OCR',
        'polly.standard_character' => 'carácter Standard',
        'polly.neural_character' => 'carácter Neural',
        'polly.long-form_character' => 'carácter Long-Form',
        'polly.generative_character' => 'carácter Generative',
        'translate.standard_character' => 'carácter traducido',
        'comprehend.nlp_unit' => 'unidad NLP (100 caracteres, mínimo 3 por API)',
        'transcribe.job_started' => 'job iniciado',
        'transcribe.job_completed' => 'job completado',
        'cost_explorer.api_request' => 'consulta API Cost Explorer',
        'drive.no_direct_aws_charge' => 'sin cargo AWS directo medido',
    ];

    public function render(array $vm, int $userId): string
    {
        $totals = $vm['totals'] ?? [];
        $real = is_array($vm['real_aws'] ?? null) ? $vm['real_aws'] : null;
        $estimated = (float)($totals['estimated_cost'] ?? 0);
        $operations = (int)($totals['operations'] ?? 0);
        $partial = (int)($totals['partial_count'] ?? 0);
        $unpriced = (int)($totals['unpriced_count'] ?? 0);
        $errors = (int)($totals['error_count'] ?? 0);

        $periodOptions = $this->options([
            'today' => 'Hoy', '7d' => '7 días', 'month' => 'Mes actual', 'previous_month' => 'Mes anterior'
        ], (string)($vm['period'] ?? 'month'));
        $serviceOptions = $this->optionsFromValues((array)($vm['filters']['services'] ?? []), (string)($vm['selected_service'] ?? ''), 'Todos');
        $actionOptions = $this->optionsFromValues((array)($vm['filters']['actions'] ?? []), (string)($vm['selected_action'] ?? ''), 'Todas', true);

        $realAmount = 'No disponible';
        $realBadge = 'REAL AWS';
        if ($real !== null && ($real['available'] ?? false) === true) {
            $realAmount = $this->money((float)$real['amount']);
            $realBadge .= !empty($real['cached']) ? ' · caché ≤ 1 h' : '';
        }
        $difference = $vm['difference'] !== null ? $this->money((float)$vm['difference']) : 'No comparable';

        $serviceRows = $this->summaryRows((array)($vm['by_service'] ?? []), 'service');
        $actionRows = $this->summaryRows((array)($vm['by_action'] ?? []), 'action', true);
        $dailyRows = $this->dailyRows((array)($vm['daily'] ?? []));
        $recentRows = $this->recentRows((array)($vm['recent'] ?? []), $userId);

        $note = $this->e((string)($vm['real_aws_note'] ?? ''));
        $periodLabel = $this->e((string)($vm['period_label'] ?? 'Período'));
        $incomplete = $partial + $unpriced;

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Actividad y costos · ArcadeCloud Drive</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="icon" href="ellogo.png" type="image/png">
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/responsive.css">
  <link rel="stylesheet" href="css/vision-accessibility.css">
  <link rel="stylesheet" href="css/activity-costs.css">
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on activity-cost-page">
<script>
(function(){
  try {
    const saved = JSON.parse(localStorage.getItem('ui-theme-state') || '{}');
    const body = document.body;
    ['theme-neon-green','theme-neon-blue','theme-neon-red','theme-neon-yellow','theme-dark','theme-light','vision-normal','vision-myopia','vision-presbyopia','vision-protanopia','vision-deuteranopia','vision-tritanopia'].forEach(c => body.classList.remove(c));
    body.classList.add(saved.theme || 'theme-neon-green', saved.mode || 'theme-dark', saved.vision || 'vision-normal');
    if (saved.ascii === false) body.classList.remove('ascii-on');
  } catch (e) {}
})();
</script>

<main class="activity-shell container-fluid">
  <div class="activity-header d-flex flex-wrap align-items-center justify-content-between">
    <div>
      <div class="activity-eyebrow">ArcadeCloud Drive</div>
      <h1><i class="fas fa-receipt mr-2"></i>Actividad y costos</h1>
      <p class="mb-0">Auditoría por usuario y atribución prudente de consumo AWS.</p>
    </div>
    <a class="btn btn-outline-primary mt-2 mt-md-0" href="s3.php"><i class="fas fa-arrow-left mr-1"></i> Volver al Drive</a>
  </div>

  <form class="activity-filter-card" method="get" action="activity_costs.php">
    <div class="form-row align-items-end">
      <div class="form-group col-12 col-md-4">
        <label for="period">Período</label>
        <select class="form-control" id="period" name="period">{$periodOptions}</select>
      </div>
      <div class="form-group col-12 col-md-3">
        <label for="service">Servicio</label>
        <select class="form-control" id="service" name="service">{$serviceOptions}</select>
      </div>
      <div class="form-group col-12 col-md-3">
        <label for="action">Operación</label>
        <select class="form-control" id="action" name="action">{$actionOptions}</select>
      </div>
      <div class="form-group col-12 col-md-2">
        <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-filter mr-1"></i> Aplicar</button>
      </div>
    </div>
  </form>

  <section aria-labelledby="resumen-costos">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
      <h2 id="resumen-costos" class="activity-section-title mb-0">{$periodLabel}</h2>
      <small>Fechas de almacenamiento: UTC · visualización: hora local del navegador</small>
    </div>
    <div class="row activity-card-row">
      <div class="col-12 col-sm-6 col-xl-3 mb-3">
        <article class="activity-stat-card h-100">
          <span class="activity-badge estimated">ESTIMADO</span>
          <div class="activity-stat-label">Costo atribuido</div>
          <div class="activity-stat-value">{$this->money($estimated)}</div>
          <small>{$incomplete} operación(es) con precio parcial/no tasado</small>
        </article>
      </div>
      <div class="col-12 col-sm-6 col-xl-3 mb-3">
        <article class="activity-stat-card h-100">
          <span class="activity-badge real">{$this->e($realBadge)}</span>
          <div class="activity-stat-label">Costo real AWS</div>
          <div class="activity-stat-value">{$this->e($realAmount)}</div>
          <small>Cuenta AWS completa, no costo exacto por archivo</small>
        </article>
      </div>
      <div class="col-12 col-sm-6 col-xl-3 mb-3">
        <article class="activity-stat-card h-100">
          <span class="activity-badge neutral">RECONCILIACIÓN</span>
          <div class="activity-stat-label">Diferencia</div>
          <div class="activity-stat-value">{$this->e($difference)}</div>
          <small>Real AWS menos costo atribuido del Drive</small>
        </article>
      </div>
      <div class="col-12 col-sm-6 col-xl-3 mb-3">
        <article class="activity-stat-card h-100">
          <span class="activity-badge neutral">ACTIVIDAD</span>
          <div class="activity-stat-label">Operaciones</div>
          <div class="activity-stat-value">{$operations}</div>
          <small>{$errors} con estado de error</small>
        </article>
      </div>
    </div>
    <div class="activity-notice"><i class="fas fa-circle-info mr-1"></i> {$note}</div>
  </section>

  <section class="row mt-4">
    <div class="col-12 col-lg-6 mb-4">
      <div class="activity-panel h-100">
        <h2 class="activity-section-title">Costo atribuido por servicio</h2>
        <div class="table-responsive"><table class="table table-sm activity-table"><thead><tr><th>Servicio</th><th>Operaciones</th><th class="text-right">Estimado</th></tr></thead><tbody>{$serviceRows}</tbody></table></div>
      </div>
    </div>
    <div class="col-12 col-lg-6 mb-4">
      <div class="activity-panel h-100">
        <h2 class="activity-section-title">Costo atribuido por operación</h2>
        <div class="table-responsive"><table class="table table-sm activity-table"><thead><tr><th>Operación</th><th>Operaciones</th><th class="text-right">Estimado</th></tr></thead><tbody>{$actionRows}</tbody></table></div>
      </div>
    </div>
  </section>

  <section class="activity-panel mb-4">
    <h2 class="activity-section-title">Costo diario atribuido</h2>
    <div class="table-responsive"><table class="table table-sm activity-table"><thead><tr><th>Día</th><th>Operaciones</th><th class="text-right">Estimado</th></tr></thead><tbody>{$dailyRows}</tbody></table></div>
  </section>

  <section class="activity-panel mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center">
      <h2 class="activity-section-title">Actividad reciente</h2>
      <small>Máximo 100 eventos del filtro actual</small>
    </div>
    <div class="table-responsive">
      <table class="table table-sm activity-table activity-detail-table">
        <thead><tr><th>Fecha</th><th>Operación</th><th>Servicio</th><th>Quién</th><th>Archivo / referencia</th><th>Unidades</th><th class="text-right">Costo</th><th>Tipo</th><th>Estado</th></tr></thead>
        <tbody>{$recentRows}</tbody>
      </table>
    </div>
  </section>
</main>
<script>
document.querySelectorAll('[data-utc]').forEach(function(el){
  const raw = el.getAttribute('data-utc');
  if (!raw) return;
  const date = new Date(raw.replace(' ', 'T') + 'Z');
  if (!Number.isNaN(date.getTime())) el.textContent = date.toLocaleString();
});
</script>
</body>
</html>
HTML;
    }

    public function renderError(string $message): string
    {
        $message = $this->e($message);
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actividad y costos</title><link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"></head><body class="p-4"><div class="container"><div class="alert alert-danger"><strong>No se pudo cargar Actividad y costos.</strong><br>' . $message . '</div><a class="btn btn-primary" href="s3.php">Volver al Drive</a></div></body></html>';
    }

    private function summaryRows(array $rows, string $key, bool $action = false): string
    {
        if ($rows === []) return '<tr><td colspan="3" class="text-muted">Sin actividad en este período.</td></tr>';
        $html = '';
        foreach ($rows as $row) {
            $value = (string)($row[$key] ?? '');
            $label = $action ? $this->actionLabel($value) : $value;
            $html .= '<tr><td>' . $this->e($label) . '</td><td>' . (int)($row['operations'] ?? 0) . '</td><td class="text-right">' . $this->money((float)($row['estimated_cost'] ?? 0)) . '</td></tr>';
        }
        return $html;
    }

    private function dailyRows(array $rows): string
    {
        if ($rows === []) return '<tr><td colspan="3" class="text-muted">Sin actividad en este período.</td></tr>';
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . $this->e((string)($row['day'] ?? '')) . '</td><td>' . (int)($row['operations'] ?? 0) . '</td><td class="text-right">' . $this->money((float)($row['estimated_cost'] ?? 0)) . '</td></tr>';
        }
        return $html;
    }

    private function recentRows(array $rows, int $userId): string
    {
        if ($rows === []) return '<tr><td colspan="9" class="text-muted">Todavía no hay eventos para este filtro.</td></tr>';
        $html = '';
        foreach ($rows as $row) {
            $fileId = (int)($row['FileId'] ?? 0);
            $fileName = trim((string)($row['file_name'] ?? ''));
            $reference = $fileId > 0 ? ($fileName !== '' ? $fileName . ' · #' . $fileId : 'file_id #' . $fileId) : '—';
            $actor = (int)($row['actor_user_id_'] ?? 0);
            $who = $actor === $userId ? 'Tú' : ($actor > 0 ? 'Usuario #' . $actor : '—');
            $state = (string)($row['PricingState'] ?? 'unpriced');
            $type = 'Estimado' . ($state === 'partial' ? ' · parcial' : ($state === 'unpriced' ? ' · no tasado' : ''));
            $cost = $row['EstimatedCost'] === null ? '—' : $this->money((float)$row['EstimatedCost']);
            $status = (string)($row['Status'] ?? '');
            $statusClass = $status === 'ok' ? 'ok' : 'error';

            $html .= '<tr>'
                . '<td><span data-utc="' . $this->e((string)($row['CreatedAt'] ?? '')) . '">' . $this->e((string)($row['CreatedAt'] ?? '')) . '</span></td>'
                . '<td>' . $this->e($this->actionLabel((string)($row['Action'] ?? ''))) . '</td>'
                . '<td>' . $this->e((string)($row['Service'] ?? '')) . '</td>'
                . '<td>' . $this->e($who) . '</td>'
                . '<td>' . $this->e($reference) . '</td>'
                . '<td>' . $this->e($this->units((string)($row['UnitsJson'] ?? ''))) . '</td>'
                . '<td class="text-right">' . $this->e($cost) . '</td>'
                . '<td><span class="activity-badge estimated">' . $this->e($type) . '</span></td>'
                . '<td><span class="activity-status ' . $statusClass . '">' . $this->e(strtoupper($status)) . '</span></td>'
                . '</tr>';
        }
        return $html;
    }

    private function units(string $json): string
    {
        $units = json_decode($json, true);
        if (!is_array($units) || $units === []) return '—';
        $parts = [];
        foreach ($units as $unit => $quantity) {
            if (!is_numeric($quantity)) continue;
            $label = self::UNIT_LABELS[$unit] ?? $unit;
            if (str_contains($unit, 'bytes')) {
                $parts[] = $this->bytes((float)$quantity) . ' ' . $label;
            } else {
                $number = ((float)$quantity == (int)$quantity) ? (string)(int)$quantity : number_format((float)$quantity, 3, '.', ',');
                $parts[] = $number . ' ' . $label;
            }
        }
        return implode(' + ', $parts) ?: '—';
    }

    private function options(array $values, string $selected): string
    {
        $html = '';
        foreach ($values as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return $html;
    }

    private function optionsFromValues(array $values, string $selected, string $empty, bool $action = false): string
    {
        $html = '<option value="">' . $this->e($empty) . '</option>';
        foreach ($values as $value) {
            $value = (string)$value;
            $label = $action ? $this->actionLabel($value) : $value;
            $html .= '<option value="' . $this->e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return $html;
    }

    private function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? $action;
    }

    private function money(float $value): string
    {
        $decimals = abs($value) < 0.01 && $value != 0.0 ? 8 : 2;
        return '$' . number_format($value, $decimals, '.', ',');
    }

    private function bytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return number_format($bytes, $index === 0 ? 0 : 2, '.', ',') . ' ' . $units[$index];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
