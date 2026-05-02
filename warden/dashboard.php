<?php // warden/dashboard.php — Garden Warden panel
require_once __DIR__ . '/../includes/functions.php';
requireRole(['warden','admin']);
$pdo = getPDO(); $user = currentUser();
$success = $error = '';

// Submit inspection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inspection'])) {
    validateCsrf();
    $plotId  = (int)$_POST['plot_id'];
    $notes   = trim($_POST['notes'] ?? '');
    $status  = $_POST['result_status'] ?? 'compliant';
    $allowed = ['compliant','warning','non_compliant'];
    if (!in_array($status, $allowed)) { $error = 'Invalid status.'; }
    else {
        // Handle photo upload
        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $uploadDir = __DIR__ . '/../../assets/uploads/inspections/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext      = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed  = ['jpg','jpeg','png','webp'];
            if (!in_array($ext, $allowed)) { $error = 'Photo must be JPG, PNG, or WebP.'; }
            else {
                $filename  = 'insp_' . $plotId . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename);
                $photoPath = 'assets/uploads/inspections/' . $filename;
            }
        }

        if (!$error) {
            $pdo->prepare("
                INSERT INTO compliance_inspections (plot_id, warden_id, notes, photo_path, result_status)
                VALUES (?,?,?,?,?)
            ")->execute([$plotId, $user['id'], $notes, $photoPath, $status]);

            // Update plot compliance status
            $pdo->prepare("UPDATE plots SET compliance_status = ? WHERE id = ?")
                ->execute([$status, $plotId]);

            // Notify plot owner
            $ownerStmt = $pdo->prepare("
                SELECT l.user_id FROM leases l WHERE l.plot_id = ? AND l.status = 'active' LIMIT 1
            ");
            $ownerStmt->execute([$plotId]); $owner = $ownerStmt->fetch();
            if ($owner) {
                sendNotification($owner['user_id'], 'inspection_done',
                    "Your plot was inspected by a Garden Warden. Result: $status." .
                    ($notes ? " Note: $notes" : ''));
            }

            logAudit('inspection_submitted', 'compliance_inspections', (int)$pdo->lastInsertId(),
                "Plot $plotId inspected: $status");
            $success = "Inspection submitted. Plot compliance updated to: $status.";
        }
    }
}

// Fetch all plots with latest inspection
$plots = $pdo->query("
    SELECT p.*,
        ci.result_status AS last_result,
        ci.inspected_at  AS last_inspected,
        u.full_name      AS owner_name
    FROM plots p
    LEFT JOIN leases l  ON l.plot_id = p.id AND l.status = 'active'
    LEFT JOIN users u   ON l.user_id = u.id
    LEFT JOIN compliance_inspections ci ON ci.id = (
        SELECT id FROM compliance_inspections
        WHERE plot_id = p.id ORDER BY inspected_at DESC LIMIT 1
    )
    ORDER BY p.compliance_status DESC, p.plot_code ASC
")->fetchAll();

// Recent inspections by this warden
$recentInspections = $pdo->prepare("
    SELECT ci.*, p.plot_code
    FROM compliance_inspections ci
    JOIN plots p ON ci.plot_id = p.id
    WHERE ci.warden_id = ?
    ORDER BY ci.inspected_at DESC LIMIT 10
");
$recentInspections->execute([$user['id']]); $recent = $recentInspections->fetchAll();

$pageTitle = 'Warden Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex-between mb-2">
  <h1 class="page-title">🔍 Garden Warden Panel</h1>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<!-- Alert: non-compliant plots -->
<?php $nonCompliant = array_filter($plots, fn($p) => $p['compliance_status'] === 'non_compliant'); ?>
<?php if ($nonCompliant): ?>
<div class="alert alert-error">⚠️ <?= count($nonCompliant) ?> plot(s) are marked Non-Compliant and require attention.</div>
<?php endif; ?>

<div class="grid-2">
<!-- Inspection form -->
<div class="card">
  <div class="card-title">Submit Plot Inspection</div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="form-group">
      <label>Select Plot</label>
      <select name="plot_id" required>
        <option value="">— Select a plot —</option>
        <?php foreach ($plots as $p): ?>
        <option value="<?= $p['id'] ?>">
          <?= clean($p['plot_code']) ?> —
          <?= $p['owner_name'] ? clean($p['owner_name']) : 'Unoccupied' ?>
          [<?= $p['compliance_status'] ?>]
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Inspection Result</label>
      <select name="result_status" required>
        <option value="compliant">✅ Compliant</option>
        <option value="warning">⚠️ Warning Issued</option>
        <option value="non_compliant">❌ Non-Compliant</option>
      </select>
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea name="notes" rows="3" placeholder="Describe findings…"></textarea>
    </div>
    <div class="form-group">
      <label>Upload Photo (optional)</label>
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
             style="border:1px solid #ced4da;border-radius:6px;padding:6px;">
    </div>
    <button type="submit" name="submit_inspection" class="btn btn-primary">Submit Inspection</button>
  </form>
</div>

<!-- Recent inspections by this warden -->
<div class="card">
  <div class="card-title">My Recent Inspections</div>
  <?php if ($recent): ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Plot</th><th>Result</th><th>Notes</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= clean($r['plot_code']) ?></td>
          <td><span class="status status-<?= $r['result_status'] === 'compliant' ? 'active' : ($r['result_status'] === 'warning' ? 'warning' : 'expired') ?>"><?= $r['result_status'] ?></span></td>
          <td style="font-size:13px"><?= clean(mb_strimwidth($r['notes'] ?? '', 0, 40, '…')) ?></td>
          <td class="text-muted" style="font-size:12px"><?= date('d M Y', strtotime($r['inspected_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="text-muted">No inspections recorded yet.</p>
  <?php endif; ?>
</div>
</div>

<!-- Full plots table -->
<div class="card mt-2">
  <div class="card-title">All Plots — Compliance Overview</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Plot</th><th>Owner</th><th>Compliance</th><th>Last Inspected</th><th>Last Result</th></tr></thead>
      <tbody>
        <?php foreach ($plots as $p): ?>
        <tr>
          <td><strong><?= clean($p['plot_code']) ?></strong></td>
          <td><?= $p['owner_name'] ? clean($p['owner_name']) : '<span class="text-muted">Unoccupied</span>' ?></td>
          <td><span class="status status-<?= $p['compliance_status'] === 'compliant' ? 'active' : ($p['compliance_status'] === 'warning' ? 'warning' : 'expired') ?>"><?= $p['compliance_status'] ?></span></td>
          <td class="text-muted"><?= $p['last_inspected'] ? date('d M Y', strtotime($p['last_inspected'])) : 'Never' ?></td>
          <td><?= $p['last_result'] ? '<span class="status status-'.($p['last_result'] === 'compliant' ? 'active' : 'warning').'">'.$p['last_result'].'</span>' : '<span class="text-muted">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
