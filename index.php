<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function statusMeta(string $status): array
{
    return [
        'Pending' => ['badge' => 'secondary', 'progress' => 20],
        'Processing' => ['badge' => 'warning text-dark', 'progress' => 60],
        'Sent' => ['badge' => 'info text-dark', 'progress' => 85],
        'Delivered' => ['badge' => 'success', 'progress' => 100],
        'Failed' => ['badge' => 'danger', 'progress' => 45],
    ][$status] ?? ['badge' => 'primary', 'progress' => 50];
}

function timelineForStatus(string $status, string $date): array
{
    $steps = ['Transfer Initiated', 'Payment Received', 'Processing', 'Funds Sent to Recipient', 'Delivered'];
    $indexMap = ['Pending' => 0, 'Processing' => 2, 'Sent' => 3, 'Delivered' => 4, 'Failed' => 2];
    $active = $indexMap[$status] ?? 2;

    $timeline = [];
    foreach ($steps as $index => $label) {
        $state = $index < $active ? 'done' : ($index === $active ? 'active' : 'pending');
        $time = $index <= $active ? date('M j, Y', strtotime($date)) : 'Pending';
        $timeline[] = ['label' => $label, 'state' => $state, 'time' => $time];
    }

    return $timeline;
}

$reference = strtoupper(trim((string)($_GET['reference'] ?? '')));
$transfer = null;
$error = null;

if ($reference !== '') {
    try {
        $stmt = db()->prepare('SELECT reference_number, full_name, amount, currency, iban, bank_name, from_platform, status, transaction_date, notes FROM transfers WHERE reference_number = :reference LIMIT 1');
        $stmt->execute(['reference' => $reference]);
        $transfer = $stmt->fetch() ?: null;
        if (!$transfer) {
            $error = 'Reference not found. Please check your tracking number.';
        }
    } catch (Throwable $exception) {
        $error = 'Database connection failed. Please verify config.php credentials and database setup.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Global Money Transfer Ltd - Track Transfer</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <style>
    body { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); min-height: 100vh; }
    .card-soft { border: 0; border-radius: 18px; box-shadow: 0 16px 40px rgba(15,23,42,.08); }
    .status-dot { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color:#fff; font-size: .8rem; }
    .status-dot.done { background:#22c55e; }
    .status-dot.active { background:#2563eb; }
    .status-dot.pending { background:#cbd5e1; color:#334155; }
  </style>
</head>
<body>
  <div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="h3 mb-0">Global Money Transfer Ltd</h1>
      <a href="admin/admin.php" class="btn btn-outline-primary">Admin Dashboard</a>
    </div>

    <div class="card card-soft p-4 mb-4">
      <h2 class="h5">Track Your Transfer</h2>
      <p class="text-muted">Search by reference number to view full transfer details and processing status.</p>
      <form method="get" id="trackForm">
        <div class="input-group">
          <input type="text" class="form-control" name="reference" id="referenceInput" maxlength="80" value="<?= e($reference) ?>" placeholder="e.g. GMT-2024-001234" required />
          <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Track</button>
        </div>
      </form>
      <div class="mt-3 d-none" id="searchLoader">
        <div class="d-flex justify-content-between small text-muted mb-1"><span>Searching transfer...</span><span id="loaderText">0%</span></div>
        <div class="progress"><div id="loaderBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($transfer): ?>
      <?php $meta = statusMeta((string)$transfer['status']); ?>
      <div class="card card-soft p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h3 class="h5 mb-1">Transfer <?= e((string)$transfer['status']) ?></h3>
            <div class="text-muted small">Ref: <?= e((string)$transfer['reference_number']) ?></div>
          </div>
          <span class="badge rounded-pill bg-<?= e($meta['badge']) ?> px-3 py-2"><?= e((string)$transfer['status']) ?></span>
        </div>

        <div class="mt-3">
          <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Processing progress</span><span><?= (int)$meta['progress'] ?>%</span></div>
          <div class="progress"><div class="progress-bar" style="width: <?= (int)$meta['progress'] ?>%"></div></div>
        </div>

        <div class="row g-3 mt-2">
          <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted d-block">Client Name</small><strong><?= e((string)$transfer['full_name']) ?></strong></div></div>
          <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted d-block">Amount</small><strong><?= e(number_format((float)$transfer['amount'], 2)) ?> <?= e((string)$transfer['currency']) ?></strong></div></div>
          <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted d-block">Transaction Date</small><strong><?= e((string)$transfer['transaction_date']) ?></strong></div></div>
          <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted d-block">IBAN</small><strong><?= e(maskIban((string)$transfer['iban'])) ?></strong></div></div>
          <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted d-block">Bank Name</small><strong><?= e((string)$transfer['bank_name']) ?></strong></div></div>
          <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted d-block">From Platform</small><strong><?= e((string)$transfer['from_platform']) ?></strong></div></div>
        </div>

        <ul class="list-unstyled mt-4 mb-0">
          <?php $icons = ['done' => '✓', 'active' => '→', 'pending' => '○']; ?>
          <?php foreach (timelineForStatus((string)$transfer['status'], (string)$transfer['transaction_date']) as $item): ?>
            <li class="d-flex gap-3 align-items-start py-2 border-bottom">
              <span class="status-dot <?= e($item['state']) ?>"><?= e($icons[$item['state']] ?? '○') ?></span>
              <div>
                <div class="fw-semibold"><?= e($item['label']) ?></div>
                <div class="text-muted small"><?= e($item['time']) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <script>
    const form = document.getElementById('trackForm');
    const loader = document.getElementById('searchLoader');
    const loaderBar = document.getElementById('loaderBar');
    const loaderText = document.getElementById('loaderText');

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      let value = 0;
      loader.classList.remove('d-none');
      const timer = setInterval(() => {
        value = Math.min(value + 10, 90);
        loaderBar.style.width = value + '%';
        loaderText.textContent = value + '%';
      }, 60);

      setTimeout(() => {
        clearInterval(timer);
        loaderBar.style.width = '100%';
        loaderText.textContent = '100%';
        form.submit();
      }, 700);
    });
  </script>
</body>
</html>
