<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/config.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function requireCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
}

function loggedIn(): bool
{
    return !empty($_SESSION['admin_user']);
}

$errors = [];

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (isset($_GET['export']) && loggedIn()) {
    $stmt = db()->query('SELECT reference_number, full_name, amount, currency, iban, bank_name, from_platform, status, transaction_date, notes FROM transfers ORDER BY transaction_date DESC');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gmt_transfers.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['reference_number', 'full_name', 'amount', 'currency', 'iban', 'bank_name', 'from_platform', 'status', 'transaction_date', 'notes']);
    while ($row = $stmt->fetch()) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

if (isset($_POST['login'])) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    try {
        $stmt = db()->prepare('SELECT username, password_hash FROM admin_users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, (string)$admin['password_hash'])) {
            $_SESSION['admin_user'] = $admin['username'];
            header('Location: admin.php');
            exit;
        }
        $errors[] = 'Invalid username or password.';
    } catch (Throwable $exception) {
        $errors[] = 'Database connection failed. Check config.php and schema setup.';
    }
}

if (loggedIn() && isset($_POST['save_transfer'])) {
    requireCsrf();

    $id = (int)($_POST['id'] ?? 0);
    $payload = [
        'reference_number' => strtoupper(trim((string)($_POST['reference_number'] ?? ''))),
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'amount' => (float)($_POST['amount'] ?? 0),
        'currency' => strtoupper(trim((string)($_POST['currency'] ?? 'USD'))),
        'iban' => strtoupper(trim((string)($_POST['iban'] ?? ''))),
        'bank_name' => trim((string)($_POST['bank_name'] ?? '')),
        'from_platform' => trim((string)($_POST['from_platform'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? 'Pending')),
        'transaction_date' => (string)($_POST['transaction_date'] ?? ''),
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];

    if ($payload['reference_number'] === '' || $payload['full_name'] === '' || $payload['amount'] <= 0 || $payload['iban'] === '' || $payload['bank_name'] === '' || $payload['from_platform'] === '' || $payload['transaction_date'] === '') {
        $errors[] = 'All required fields must be provided.';
    } else {
        try {
            if ($id > 0) {
                $payload['id'] = $id;
                $sql = 'UPDATE transfers SET reference_number=:reference_number, full_name=:full_name, amount=:amount, currency=:currency, iban=:iban, bank_name=:bank_name, from_platform=:from_platform, status=:status, transaction_date=:transaction_date, notes=:notes WHERE id=:id';
                db()->prepare($sql)->execute($payload);
            } else {
                $sql = 'INSERT INTO transfers (reference_number, full_name, amount, currency, iban, bank_name, from_platform, status, transaction_date, notes) VALUES (:reference_number, :full_name, :amount, :currency, :iban, :bank_name, :from_platform, :status, :transaction_date, :notes)';
                db()->prepare($sql)->execute($payload);
            }

            header('Location: admin.php');
            exit;
        } catch (Throwable $exception) {
            $errors[] = 'Unable to save transfer. Make sure the reference number is unique.';
        }
    }
}

if (loggedIn() && isset($_POST['delete_transfer'])) {
    requireCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        db()->prepare('DELETE FROM transfers WHERE id = :id')->execute(['id' => $id]);
    }
    header('Location: admin.php');
    exit;
}

$editing = null;
if (loggedIn() && isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM transfers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $editing = $stmt->fetch() ?: null;
    }
}

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'sort' => trim((string)($_GET['sort'] ?? 'transaction_date_desc')),
];

$stats = ['total' => 0, 'pending' => 0, 'processing' => 0, 'delivered' => 0];
$rows = [];

if (loggedIn()) {
    $stats['total'] = (int)db()->query('SELECT COUNT(*) FROM transfers')->fetchColumn();
    $stats['pending'] = (int)db()->query("SELECT COUNT(*) FROM transfers WHERE status='Pending'")->fetchColumn();
    $stats['processing'] = (int)db()->query("SELECT COUNT(*) FROM transfers WHERE status='Processing'")->fetchColumn();
    $stats['delivered'] = (int)db()->query("SELECT COUNT(*) FROM transfers WHERE status='Delivered'")->fetchColumn();

    $where = [];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = '(reference_number LIKE :q OR full_name LIKE :q OR iban LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }

    if ($filters['status'] !== '') {
        $where[] = 'status = :status';
        $params['status'] = $filters['status'];
    }

    $sortSql = match ($filters['sort']) {
        'reference_asc' => 'reference_number ASC',
        'name_asc' => 'full_name ASC',
        'amount_desc' => 'amount DESC',
        default => 'transaction_date DESC'
    };

    $sql = 'SELECT * FROM transfers';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $sortSql;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$formValues = $editing ?: [
    'id' => 0,
    'reference_number' => '',
    'full_name' => '',
    'amount' => '',
    'currency' => 'USD',
    'iban' => '',
    'bank_name' => '',
    'from_platform' => '',
    'status' => 'Pending',
    'transaction_date' => date('Y-m-d'),
    'notes' => '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>GMT Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
  <style>
    body { background: #f1f5f9; }
    .card-soft { border:0; border-radius:16px; box-shadow:0 12px 30px rgba(15,23,42,.08); }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">GMT Admin Dashboard</h1>
    <div class="d-flex gap-2">
      <a href="../index.php" class="btn btn-outline-secondary btn-sm">Public Tracker</a>
      <?php if (loggedIn()): ?><a href="?logout=1" class="btn btn-outline-danger btn-sm">Logout</a><?php endif; ?>
    </div>
  </div>

  <?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endforeach; ?>

  <?php if (!loggedIn()): ?>
    <div class="card card-soft p-4 mx-auto" style="max-width:420px;">
      <h2 class="h5">Admin Login</h2>
      <p class="text-muted small">Default seed account: admin / admin123</p>
      <form method="post">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-control" required />
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required />
        </div>
        <button class="btn btn-primary w-100" type="submit" name="login" value="1">Login</button>
      </form>
    </div>
  <?php else: ?>
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3"><div class="card card-soft p-3"><small class="text-muted">Total</small><div class="h4 mb-0"><?= $stats['total'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card card-soft p-3"><small class="text-muted">Pending</small><div class="h4 mb-0"><?= $stats['pending'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card card-soft p-3"><small class="text-muted">Processing</small><div class="h4 mb-0"><?= $stats['processing'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="card card-soft p-3"><small class="text-muted">Delivered</small><div class="h4 mb-0"><?= $stats['delivered'] ?></div></div></div>
    </div>

    <div class="card card-soft p-3 mb-3">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label">Search</label><input class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Reference, name, IBAN" /></div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="">All</option>
            <?php foreach (['Pending','Processing','Sent','Delivered','Failed'] as $status): ?>
              <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Sort</label>
          <select class="form-select" name="sort">
            <option value="transaction_date_desc" <?= $filters['sort'] === 'transaction_date_desc' ? 'selected' : '' ?>>Date (newest)</option>
            <option value="reference_asc" <?= $filters['sort'] === 'reference_asc' ? 'selected' : '' ?>>Reference</option>
            <option value="name_asc" <?= $filters['sort'] === 'name_asc' ? 'selected' : '' ?>>Name</option>
            <option value="amount_desc" <?= $filters['sort'] === 'amount_desc' ? 'selected' : '' ?>>Amount</option>
          </select>
        </div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary">Apply</button></div>
      </form>
      <div class="mt-2"><a href="?export=1" class="btn btn-outline-success btn-sm">Export CSV</a></div>
    </div>

    <div class="card card-soft p-3 mb-3">
      <h2 class="h6"><?= $editing ? 'Edit Transfer' : 'Add Transfer' ?></h2>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>" />
        <input type="hidden" name="id" value="<?= (int)$formValues['id'] ?>" />
        <div class="col-md-4"><input class="form-control" name="reference_number" placeholder="Reference" value="<?= e((string)$formValues['reference_number']) ?>" required /></div>
        <div class="col-md-4"><input class="form-control" name="full_name" placeholder="Full name" value="<?= e((string)$formValues['full_name']) ?>" required /></div>
        <div class="col-md-2"><input type="number" step="0.01" min="0.01" class="form-control" name="amount" placeholder="Amount" value="<?= e((string)$formValues['amount']) ?>" required /></div>
        <div class="col-md-2"><input class="form-control" name="currency" maxlength="3" value="<?= e((string)$formValues['currency']) ?>" required /></div>
        <div class="col-md-4"><input class="form-control" name="iban" placeholder="IBAN" value="<?= e((string)$formValues['iban']) ?>" required /></div>
        <div class="col-md-4"><input class="form-control" name="bank_name" placeholder="Bank name" value="<?= e((string)$formValues['bank_name']) ?>" required /></div>
        <div class="col-md-4"><input class="form-control" name="from_platform" placeholder="From platform" value="<?= e((string)$formValues['from_platform']) ?>" required /></div>
        <div class="col-md-4">
          <select class="form-select" name="status" required>
            <?php foreach (['Pending','Processing','Sent','Delivered','Failed'] as $status): ?>
              <option value="<?= e($status) ?>" <?= (string)$formValues['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4"><input type="date" class="form-control" name="transaction_date" value="<?= e((string)$formValues['transaction_date']) ?>" required /></div>
        <div class="col-md-4"><input class="form-control" name="notes" placeholder="Notes" value="<?= e((string)$formValues['notes']) ?>" /></div>
        <div class="col-md-12 d-flex gap-2">
          <button class="btn btn-primary" type="submit" name="save_transfer" value="1">Save Transfer</button>
          <?php if ($editing): ?><a class="btn btn-outline-secondary" href="admin.php">Cancel Edit</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card card-soft p-3">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
          <tr>
            <th>Reference</th><th>Name</th><th>Amount</th><th>IBAN</th><th>Bank</th><th>Platform</th><th>Status</th><th>Date</th><th>Actions</th>
          </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No records found.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= e((string)$row['reference_number']) ?></td>
              <td><?= e((string)$row['full_name']) ?></td>
              <td><?= e(number_format((float)$row['amount'], 2)) ?> <?= e((string)$row['currency']) ?></td>
              <td><?= e((string)$row['iban']) ?></td>
              <td><?= e((string)$row['bank_name']) ?></td>
              <td><?= e((string)$row['from_platform']) ?></td>
              <td><span class="badge text-bg-light border"><?= e((string)$row['status']) ?></span></td>
              <td><?= e((string)$row['transaction_date']) ?></td>
              <td>
                <a href="?edit=<?= (int)$row['id'] ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this transfer?');">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>" />
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>" />
                  <button type="submit" name="delete_transfer" value="1" class="btn btn-outline-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
