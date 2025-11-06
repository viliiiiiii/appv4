<?php
declare(strict_types=1);

use App\Domain\Inventory\TransferService;

require_once __DIR__ . '/helpers.php';
require_login();

// Optional dev diagnostics: uncomment for troubleshooting only
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

$appsPdo = get_pdo();        // APPS (punchlist) DB
$corePdo = core_pdo_optional();      // CORE (users/roles/sectors/activity) DB — may be same as APPS if not split
$coreAvailable = $corePdo instanceof PDO;

$canManage    = can('inventory_manage');
$canSign      = $canManage || can('inventory_transfers');
$isRoot       = current_user_role_key() === 'root';
$userSectorId = current_user_sector_id();

$errors = [];

// --- POST actions ---
if (is_post()) {
    try {
        if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
            $errors[] = 'Invalid CSRF token.';
        } elseif (!$canManage) {
            $errors[] = 'Insufficient permissions.';
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'create_item') {
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $quantity = max(0, (int)($_POST['quantity'] ?? 0));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorInput = $_POST['sector_id'] ?? '';
                $sectorId = $isRoot ? (($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput) : $userSectorId;

                if ($name === '') {
                    $errors[] = 'Name is required.';
                }
                if (!$isRoot && $sectorId === null) {
                    $errors[] = 'Your sector must be assigned before creating items.';
                }

                if (!$errors) {
                    $stmt = $appsPdo->prepare('
                        INSERT INTO inventory_items (sku, name, sector_id, quantity, location)
                        VALUES (:sku, :name, :sector_id, :quantity, :location)
                    ');
                    $stmt->execute([
                        ':sku'       => $sku !== '' ? $sku : null,
                        ':name'      => $name,
                        ':sector_id' => $sectorId,
                        ':quantity'  => $quantity,
                        ':location'  => $location !== '' ? $location : null,
                    ]);
                    $itemId = (int)$appsPdo->lastInsertId();

                    if ($quantity > 0) {
                        $movStmt = $appsPdo->prepare('
                            INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id)
                            VALUES (:item_id, :direction, :amount, :reason, :user_id)
                        ');
                        $movStmt->execute([
                            ':item_id'  => $itemId,
                            ':direction'=> 'in',
                            ':amount'   => $quantity,
                            ':reason'   => 'Initial quantity',
                            ':user_id'  => current_user()['id'] ?? null,
                        ]);
                    }
                    log_event('inventory.add', 'inventory_item', $itemId, ['quantity' => $quantity, 'sector_id' => $sectorId]);
                    redirect_with_message('inventory.php', 'Item added.');
                }

            } elseif ($action === 'update_item') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorInput = $_POST['sector_id'] ?? '';

                $itemStmt = $appsPdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $errors[] = 'Item not found.';
                } else {
                    $sectorId = $isRoot ? (($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput) : $userSectorId;
                    if (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                        $errors[] = 'Cannot edit items from other sectors.';
                    }
                    if ($name === '') {
                        $errors[] = 'Name is required.';
                    }
                    if (!$isRoot && $sectorId === null) {
                        $errors[] = 'Your sector must be assigned before editing items.';
                    }
                    if (!$errors) {
                        $updStmt = $appsPdo->prepare('
                            UPDATE inventory_items
                            SET name=:name, sku=:sku, location=:location, sector_id=:sector_id
                            WHERE id=:id
                        ');
                        $updStmt->execute([
                            ':name'      => $name,
                            ':sku'       => $sku !== '' ? $sku : null,
                            ':location'  => $location !== '' ? $location : null,
                            ':sector_id' => $sectorId,
                            ':id'        => $itemId,
                        ]);
                        redirect_with_message('inventory.php', 'Item updated.');
                    }
                }

            } elseif ($action === 'move_stock') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $direction= $_POST['direction'] === 'out' ? 'out' : 'in';
                $amount   = max(1, (int)($_POST['amount'] ?? 0));
                $reason   = trim((string)($_POST['reason'] ?? ''));
                $sourceLocation = trim((string)($_POST['source_location'] ?? ''));
                $targetLocation = trim((string)($_POST['target_location'] ?? ''));
                $notes          = trim((string)($_POST['notes'] ?? ''));
                $requiresSignature = isset($_POST['requires_signature']) ? 1 : 0;

                $fromInput = $_POST['from_sector_id'] ?? '';
                $toInput   = $_POST['to_sector_id'] ?? '';

                $sourceSectorId = ($fromInput === '' || $fromInput === 'null') ? null : (int)$fromInput;
                $targetSectorId = ($toInput === '' || $toInput === 'null') ? null : (int)$toInput;

                $itemStmt = $appsPdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $errors[] = 'Item not found.';
                } elseif (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                    $errors[] = 'Cannot move stock for other sectors.';
                } else {
                    if ($direction === 'out') {
                        $sourceSectorId = $sourceSectorId !== null ? $sourceSectorId : ($item['sector_id'] !== null ? (int)$item['sector_id'] : null);
                    } else {
                        $targetSectorId = $targetSectorId !== null ? $targetSectorId : ($item['sector_id'] !== null ? (int)$item['sector_id'] : null);
                    }

                    $delta = $direction === 'in' ? $amount : -$amount;
                    $newQuantity = (int)$item['quantity'] + $delta;
                    if ($newQuantity < 0) {
                        $errors[] = 'Not enough stock to move.';
                    } else {
                        $appsPdo->beginTransaction();
                        try {
                            $appsPdo->prepare('UPDATE inventory_items SET quantity = quantity + :delta WHERE id = :id')
                                    ->execute([':delta' => $delta, ':id' => $itemId]);

                            $appsPdo->prepare('
                                INSERT INTO inventory_movements (
                                    item_id, direction, amount, reason, user_id,
                                    source_sector_id, target_sector_id,
                                    source_location, target_location,
                                    requires_signature, transfer_status, notes
                                ) VALUES (
                                    :item_id, :direction, :amount, :reason, :user_id,
                                    :source_sector_id, :target_sector_id,
                                    :source_location, :target_location,
                                    :requires_signature, :transfer_status, :notes
                                )
                            ')->execute([
                                ':item_id'           => $itemId,
                                ':direction'         => $direction,
                                ':amount'            => $amount,
                                ':reason'            => $reason !== '' ? $reason : null,
                                ':user_id'           => current_user()['id'] ?? null,
                                ':source_sector_id'  => $sourceSectorId !== null ? (int)$sourceSectorId : null,
                                ':target_sector_id'  => $targetSectorId !== null ? (int)$targetSectorId : null,
                                ':source_location'   => $sourceLocation !== '' ? $sourceLocation : null,
                                ':target_location'   => $targetLocation !== '' ? $targetLocation : null,
                                ':requires_signature'=> $requiresSignature ? 1 : 0,
                                ':transfer_status'   => $requiresSignature ? 'pending' : 'signed',
                                ':notes'             => $notes !== '' ? $notes : null,
                            ]);

                            $movementId = (int)$appsPdo->lastInsertId();

                            $appsPdo->commit();
                            log_event('inventory.move', 'inventory_item', $itemId, [
                                'direction' => $direction,
                                'amount' => $amount,
                                'requires_signature' => (bool)$requiresSignature,
                                'source_sector' => $sourceSectorId,
                                'target_sector' => $targetSectorId,
                            ]);

                            $flashMessage = 'Stock updated.';
                            $flashType = 'success';
                            if ($requiresSignature) {
                                if ($coreAvailable) {
                                    try {
                                        $service = new TransferService($appsPdo, $corePdo);
                                        $doc = $service->generateForMovement($movementId);
                                        if ($doc) {
                                            $flashMessage = 'Stock updated and transfer form generated.';
                                        } else {
                                            $flashMessage = 'Stock updated. Transfer form will be available once generated.';
                                        }
                                    } catch (Throwable $e) {
                                        $flashMessage = 'Stock updated, but transfer form generation failed.';
                                        try { error_log('inventory transfer form failed: ' . $e->getMessage()); } catch (Throwable $_) {}
                                        $flashType = 'error';
                                    }
                                } else {
                                    $flashMessage = 'Stock updated. Configure the core database to generate transfer forms.';
                                    $flashType = 'error';
                                }
                            }

                            redirect_with_message('inventory.php', $flashMessage, $flashType);
                        } catch (Throwable $e) {
                            $appsPdo->rollBack();
                            $errors[] = 'Unable to record movement.';
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Server error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// --- Fetch sectors (CORE) ---
$sectorOptions = [];
if ($coreAvailable) {
    try {
        $sectorOptions = $corePdo->query('SELECT id, name, key_slug, color_hex, contact_email, contact_phone, manager_user_id FROM sectors ORDER BY name')->fetchAll();
    } catch (Throwable $e) {
        $errors[] = 'Sectors table missing in CORE DB (or query failed).';
    }
} else {
    $errors[] = 'CORE database unavailable. Sector-specific filters are limited.';
}
$sectorMap = [];
foreach ((array)$sectorOptions as $sectorRow) {
    $sectorMap[(int)$sectorRow['id']] = $sectorRow;
}

// --- Sector filter logic ---
if ($isRoot) {
    $sectorFilter = $_GET['sector'] ?? '';
} elseif ($userSectorId !== null) {
    $sectorFilter = (string)$userSectorId;
} else {
    $sectorFilter = 'null';
}

$where = [];
$params= [];
if ($sectorFilter !== '' && $sectorFilter !== 'all') {
    if ($sectorFilter === 'null') {
        $where[] = 'sector_id IS NULL';
    } else {
        $where[] = 'sector_id = :sector';
        $params[':sector'] = (int)$sectorFilter;
    }
}
if (!$isRoot && $userSectorId !== null) {
    $where[] = 'sector_id = :my_sector';
    $params[':my_sector'] = (int)$userSectorId;
}
if (!$isRoot && $userSectorId === null) {
    $where[] = 'sector_id IS NULL';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Fetch items & recent movements (APPS) ---
$items = [];
$movementsByItem = [];

try {
    $itemStmt = $appsPdo->prepare("SELECT * FROM inventory_items $whereSql ORDER BY name");
    $itemStmt->execute($params);
    $items = $itemStmt->fetchAll();

    if ($items) {
        $movementStmt = $appsPdo->prepare('SELECT * FROM inventory_movements WHERE item_id = ? ORDER BY ts DESC LIMIT 5');
        $fileStmt = $appsPdo->prepare('SELECT * FROM inventory_movement_files WHERE movement_id = ? ORDER BY uploaded_at');
        foreach ($items as $item) {
            $movementStmt->execute([$item['id']]);
            $movementRows = [];
            foreach ($movementStmt->fetchAll() as $moveRow) {
                $fileStmt->execute([$moveRow['id']]);
                $moveRow['files'] = $fileStmt->fetchAll();
                $movementRows[] = $moveRow;
            }
            $movementsByItem[$item['id']] = $movementRows;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Inventory tables missing in APPS DB (or query failed).';
}

$pendingTransfers = [];
if ($canSign) {
    try {
        $pendingStmt = $appsPdo->prepare('
            SELECT m.*, i.name AS item_name, i.sku AS item_sku
            FROM inventory_movements m
            JOIN inventory_items i ON i.id = m.item_id
            WHERE m.requires_signature = 1 AND m.transfer_status = "pending"
            ORDER BY m.ts DESC
            LIMIT 15
        ');
        $pendingStmt->execute();
        $pendingTransfers = $pendingStmt->fetchAll();
    } catch (Throwable $e) {
        $pendingTransfers = [];
    }
}

// --- Helper to resolve sector name ---
function sector_name_by_id(array $sectors, $id): string {
    foreach ($sectors as $s) {
        if ((string)$s['id'] === (string)$id) return (string)$s['name'];
    }
    return '';
}

function sector_display_label(array $sectorMap, $id): string {
    if ($id === null) {
        return 'Unassigned';
    }
    $key = (int)$id;
    if (isset($sectorMap[$key]) && !empty($sectorMap[$key]['name'])) {
        return (string)$sectorMap[$key]['name'];
    }
    return 'Sector #' . $key;
}

function inventory_user_display_name(?int $userId): string {
    static $cache = [];
    if ($userId === null || $userId <= 0) {
        return 'System';
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $record = null;
    try {
        $record = core_user_record($userId);
    } catch (Throwable $e) {
        $record = null;
    }
    if ($record && !empty($record['email'])) {
        return $cache[$userId] = (string)$record['email'];
    }
    try {
        $pdo = get_pdo();
        $st = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['email'])) {
            return $cache[$userId] = (string)$row['email'];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $cache[$userId] = 'User #' . $userId;
}

$title = 'Inventory';
include __DIR__ . '/includes/header.php';
?>

<section class="card">
  <div class="card-header">
    <h1>Inventory</h1>
    <div class="actions">
      <span class="badge">Items: <?php echo number_format(count($items)); ?></span>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <!-- Filter toolbar: mobile stacked, desktop compact via .filters -->
  <form method="get" class="filters" autocomplete="off">
    <label>Sector
      <select name="sector" <?php echo $isRoot ? '' : 'disabled'; ?>>
        <option value="all" <?php echo ($sectorFilter === '' || $sectorFilter === 'all') ? 'selected' : ''; ?>>All</option>
        <option value="null" <?php echo $sectorFilter === 'null' ? 'selected' : ''; ?>>Unassigned</option>
        <?php foreach ((array)$sectorOptions as $sector): ?>
          <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$sector['id'] === (string)$sectorFilter) ? 'selected' : ''; ?>>
            <?php echo sanitize((string)$sector['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="filter-actions">
      <?php if ($isRoot): ?>
        <button class="btn primary" type="submit">Filter</button>
        <a class="btn secondary" href="inventory.php">Reset</a>
      <?php else: ?>
        <span class="muted small">Filtering limited to your sector.</span>
      <?php endif; ?>
    </div>
  </form>
</section>

<?php if ($canSign && $pendingTransfers): ?>
<section class="card card--attention">
  <div class="card-header">
    <h2>Transfers awaiting signature</h2>
    <div class="actions">
      <span class="badge"><?php echo number_format(count($pendingTransfers)); ?></span>
    </div>
  </div>
  <ul class="pending-transfers">
    <?php foreach ($pendingTransfers as $transfer):
      $sourceLabel = sector_display_label($sectorMap, $transfer['source_sector_id'] ?? null);
      $targetLabel = sector_display_label($sectorMap, $transfer['target_sector_id'] ?? null);
      $transferKey = $transfer['transfer_form_key'] ?? '';
      $formHref = $transferKey !== ''
        ? '/download.php?key=' . rawurlencode($transferKey)
        : (string)($transfer['transfer_form_url'] ?? '');
      $anchor = '#movement-' . (int)$transfer['id'];
    ?>
    <li>
      <div class="pending-transfers__title">
        <strong><?php echo sanitize((string)$transfer['item_name']); ?></strong>
        <span class="chip <?php echo $transfer['direction'] === 'out' ? 'chip-out' : 'chip-in'; ?>">
          <?php echo sanitize(strtoupper((string)$transfer['direction'])); ?>
        </span>
        <span class="pending-transfers__qty"><?php echo (int)$transfer['amount']; ?></span>
      </div>
      <div class="pending-transfers__meta">
        <span><strong>From:</strong> <?php echo sanitize($sourceLabel); ?></span>
        <span><strong>To:</strong> <?php echo sanitize($targetLabel); ?></span>
        <span class="muted small"><?php echo sanitize((string)$transfer['ts']); ?></span>
      </div>
      <div class="pending-transfers__actions">
        <?php if ($formHref !== ''): ?>
          <a class="btn ghost small" href="<?php echo sanitize($formHref); ?>" target="_blank" rel="noopener">Form</a>
        <?php endif; ?>
        <a class="btn primary small" href="inventory.php<?php echo sanitize($anchor); ?>">Review</a>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($canManage): ?>
<section class="card">
  <div class="card-header">
    <h2>Add Item</h2>
  </div>

  <!-- Compact multi-field form -->
  <form method="post" class="filters" autocomplete="off">
    <label>Name
      <input type="text" name="name" required placeholder="e.g. Light bulb E27">
    </label>

    <label>SKU
      <input type="text" name="sku" placeholder="Optional SKU">
    </label>

    <label>Initial Quantity
      <input type="number" name="quantity" min="0" value="0">
    </label>

    <label>Location
      <input type="text" name="location" placeholder="Aisle / Shelf">
    </label>

    <?php if ($isRoot): ?>
      <label>Sector
        <select name="sector_id">
          <option value="null">Unassigned</option>
          <?php foreach ((array)$sectorOptions as $sector): ?>
            <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize((string)$sector['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>

    <div class="filter-actions">
      <input type="hidden" name="action" value="create_item">
      <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
      <button class="btn primary" type="submit">Add</button>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-header">
    <h2>Items</h2>
  </div>

  <!-- table--cards switches rows into cards on mobile -->
  <table class="table table--cards compact-rows">
    <thead>
      <tr>
        <th>Name</th>
        <th>SKU</th>
        <th>Sector</th>
        <th>Quantity</th>
        <th>Location</th>
        <th class="text-right">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td data-label="Name"><?php echo sanitize((string)$item['name']); ?></td>

        <td data-label="SKU">
          <?php echo !empty($item['sku']) ? sanitize((string)$item['sku']) : '<em class="muted">—</em>'; ?>
        </td>

        <td data-label="Sector">
          <?php
            $sn = sector_name_by_id((array)$sectorOptions, $item['sector_id']);
            echo $sn !== '' ? sanitize($sn) : '<span class="badge">Unassigned</span>';
          ?>
        </td>

        <td data-label="Quantity"><strong><?php echo (int)$item['quantity']; ?></strong></td>

        <td data-label="Location">
          <?php echo !empty($item['location']) ? sanitize((string)$item['location']) : '<em class="muted">—</em>'; ?>
        </td>

        <td data-label="Actions" class="text-right">
          <details class="item-actions">
            <summary class="btn small">Manage</summary>
            <div class="item-actions__box">
              <?php if ($canManage && ($isRoot || (int)$item['sector_id'] === (int)$userSectorId)): ?>
                <!-- Update item -->
                <form method="post" class="filters" style="margin-top:.5rem;">
                  <label>Name
                    <input type="text" name="name" value="<?php echo sanitize((string)$item['name']); ?>" required>
                  </label>
                  <label>SKU
                    <input type="text" name="sku" value="<?php echo sanitize((string)($item['sku'] ?? '')); ?>">
                  </label>
                  <label>Location
                    <input type="text" name="location" value="<?php echo sanitize((string)($item['location'] ?? '')); ?>">
                  </label>
                  <?php if ($isRoot): ?>
                    <label>Sector
                      <select name="sector_id">
                        <option value="null" <?php echo $item['sector_id'] === null ? 'selected':''; ?>>Unassigned</option>
                        <?php foreach ((array)$sectorOptions as $sector): ?>
                          <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$item['sector_id'] === (string)$sector['id']) ? 'selected' : ''; ?>>
                            <?php echo sanitize((string)$sector['name']); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  <?php endif; ?>
                  <div class="filter-actions">
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                    <button class="btn small" type="submit">Save</button>
                  </div>
                </form>

                <!-- Move stock -->
                <form method="post" class="filters movement-form" style="margin-top:.5rem;">
                  <label>Direction
                    <select name="direction">
                      <option value="in">In</option>
                      <option value="out">Out</option>
                    </select>
                  </label>
                  <label>Amount
                    <input type="number" name="amount" min="1" value="1" required>
                  </label>
                  <label>Reason
                    <input type="text" name="reason" placeholder="Optional reason (e.g. requested by)">
                  </label>
                  <label>From sector
                    <select name="from_sector_id" <?php echo $isRoot ? '' : 'disabled'; ?>>
                      <option value="null">Unassigned</option>
                      <?php foreach ((array)$sectorOptions as $sector): ?>
                        <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$item['sector_id'] === (string)$sector['id']) ? 'selected' : ''; ?>><?php echo sanitize((string)$sector['name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <?php if (!$isRoot): ?>
                    <input type="hidden" name="from_sector_id" value="<?php echo $item['sector_id'] !== null ? (int)$item['sector_id'] : 'null'; ?>">
                  <?php endif; ?>
                  <label>To sector
                    <select name="to_sector_id">
                      <option value="null">Unassigned</option>
                      <?php foreach ((array)$sectorOptions as $sector): ?>
                        <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize((string)$sector['name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>From location
                    <input type="text" name="source_location" placeholder="e.g. Warehouse A - Shelf 4">
                  </label>
                  <label>To location
                    <input type="text" name="target_location" placeholder="e.g. Site trailer">
                  </label>
                  <label>Notes
                    <textarea name="notes" rows="2" placeholder="Optional extra context"></textarea>
                  </label>
                  <label class="checkbox-inline">
                    <input type="checkbox" name="requires_signature" value="1" checked>
                    Require signed transfer form
                  </label>
                  <div class="filter-actions">
                    <input type="hidden" name="action" value="move_stock">
                    <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                    <button class="btn small primary" type="submit">Record movement</button>
                  </div>
                </form>
              <?php else: ?>
                <p class="muted small" style="margin:.5rem 0 0;">No management rights for this item.</p>
              <?php endif; ?>

              <!-- Recent movements -->
              <h3 class="movements-title">Recent Movements</h3>
              <?php $itemMovements = $movementsByItem[$item['id']] ?? []; ?>
              <ul class="movements">
                <?php if (!$itemMovements): ?>
                  <li class="movements__empty muted small">No movements yet.</li>
                <?php else: ?>
                  <?php foreach ($itemMovements as $move):
                      $movementId = (int)($move['id'] ?? 0);
                      $anchorId = 'movement-' . $movementId;
                      $formKey = $move['transfer_form_key'] ?? '';
                      $formUrl = $formKey !== '' ? '/download.php?key=' . rawurlencode($formKey) : (string)($move['transfer_form_url'] ?? '');
                      $files = is_array($move['files'] ?? null) ? $move['files'] : [];
                      $sourceLabel = sector_display_label($sectorMap, $move['source_sector_id'] ?? null);
                      $targetLabel = sector_display_label($sectorMap, $move['target_sector_id'] ?? null);
                  ?>
                  <li class="movement" id="<?php echo sanitize($anchorId); ?>">
                    <header class="movement__header">
                      <span class="chip <?php echo ($move['direction'] ?? '') === 'out' ? 'chip-out' : 'chip-in'; ?>">
                        <?php echo sanitize(strtoupper((string)($move['direction'] ?? ''))); ?>
                      </span>
                      <div class="movement__qty">
                        <strong><?php echo (int)($move['amount'] ?? 0); ?></strong>
                        <span class="muted small">units</span>
                      </div>
                      <span class="movement__time muted small"><?php echo sanitize((string)($move['ts'] ?? '')); ?></span>
                      <?php if (!empty($move['requires_signature'])): ?>
                        <span class="movement__status movement__status--<?php echo ($move['transfer_status'] ?? '') === 'signed' ? 'signed' : 'pending'; ?>">
                          <?php echo ($move['transfer_status'] ?? '') === 'signed' ? 'Signed' : 'Awaiting signature'; ?>
                        </span>
                      <?php endif; ?>
                    </header>
                    <div class="movement__meta">
                      <div><strong>From:</strong> <?php echo sanitize($sourceLabel); ?><?php if (!empty($move['source_location'])): ?> · <?php echo sanitize((string)$move['source_location']); ?><?php endif; ?></div>
                      <div><strong>To:</strong> <?php echo sanitize($targetLabel); ?><?php if (!empty($move['target_location'])): ?> · <?php echo sanitize((string)$move['target_location']); ?><?php endif; ?></div>
                    </div>
                    <?php if (!empty($move['reason'])): ?>
                      <div class="movement__reason"><strong>Reason:</strong> <?php echo sanitize((string)$move['reason']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($move['notes'])): ?>
                      <div class="movement__notes"><strong>Notes:</strong> <?php echo nl2br(sanitize((string)$move['notes'])); ?></div>
                    <?php endif; ?>
                    <div class="movement__links">
                      <?php if ($formUrl !== ''): ?>
                        <a class="btn ghost small" href="<?php echo sanitize($formUrl); ?>" target="_blank" rel="noopener">Transfer form</a>
                      <?php endif; ?>
                      <?php if (($move['transfer_status'] ?? '') === 'pending' && $canSign): ?>
                        <span class="movement__hint">Upload signed copy below</span>
                      <?php endif; ?>
                    </div>
                    <?php if ($files): ?>
                      <ul class="movement-files">
                        <?php foreach ($files as $file):
                            $fileKey = $file['file_key'] ?? '';
                            $fileHref = $fileKey !== '' ? '/download.php?key=' . rawurlencode($fileKey) : (string)($file['file_url'] ?? '#');
                            $fileLabel = $file['label'] !== null && $file['label'] !== '' ? $file['label'] : ucfirst((string)($file['kind'] ?? 'file'));
                        ?>
                        <li>
                          <a href="<?php echo sanitize($fileHref); ?>" target="_blank" rel="noopener"><?php echo sanitize((string)$fileLabel); ?></a>
                          <span class="muted small"><?php echo sanitize((string)($file['uploaded_at'] ?? '')); ?><?php if (!empty($file['uploaded_by'])): ?> · <?php echo sanitize(inventory_user_display_name((int)$file['uploaded_by'])); ?><?php endif; ?></span>
                        </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                    <?php if ($canSign): ?>
                      <form method="post" action="inventory_transfer_upload.php" enctype="multipart/form-data" class="movement-upload">
                        <div class="movement-upload__grid">
                          <label>Attachment
                            <input type="file" name="document" required>
                          </label>
                          <label>Type
                            <select name="kind">
                              <option value="signature">Signed form</option>
                              <option value="photo">Photo</option>
                              <option value="other">Other</option>
                            </select>
                          </label>
                          <label>Label
                            <input type="text" name="label" placeholder="Description (optional)">
                          </label>
                        </div>
                        <div class="movement-upload__actions">
                          <input type="hidden" name="movement_id" value="<?php echo $movementId; ?>">
                          <input type="hidden" name="redirect" value="<?php echo sanitize('inventory.php#' . $anchorId); ?>">
                          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                          <button class="btn small" type="submit">Upload proof</button>
                        </div>
                      </form>
                    <?php endif; ?>
                  </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>
            </div>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<!-- Small page-specific polish; move to app.css if you like -->
<style>
.item-actions summary.btn.small { cursor: pointer; }
.item-actions[open] summary.btn.small { opacity: .85; }

.item-actions__box{
  margin-top:.4rem;
  padding:.6rem .7rem;
  border:1px solid var(--line,#e7ecf3);
  background:#fff;
  border-radius:12px;
  box-shadow: 0 1px 0 rgba(0,0,0,.02);
}

.card--attention {
  border-left:4px solid #f97316;
  background:linear-gradient(135deg,#fffaf0 0%,#fff7ed 100%);
}

.pending-transfers {
  list-style:none;
  margin:0;
  padding:0;
  display:flex;
  flex-direction:column;
  gap:.75rem;
}
.pending-transfers li {
  display:flex;
  flex-direction:column;
  gap:.35rem;
  padding:.55rem .8rem;
  background:#fff;
  border:1px solid rgba(249,115,22,.25);
  border-radius:12px;
}
.pending-transfers__title {
  display:flex;
  align-items:center;
  gap:.45rem;
  font-weight:600;
}
.pending-transfers__qty {
  font-size:.85rem;
  color:#475569;
}
.pending-transfers__meta {
  display:flex;
  flex-wrap:wrap;
  gap:.75rem;
  font-size:.8rem;
  color:#475569;
}
.pending-transfers__actions {
  display:flex;
  flex-wrap:wrap;
  gap:.5rem;
}

.movements-title{
  margin:.8rem 0 .35rem;
  font-size:.95rem;
  font-weight:700;
}
.movements{
  list-style:none;
  padding:0;
  margin:.3rem 0 0;
  display:flex;
  flex-direction:column;
  gap:.65rem;
}
.movements__empty { font-style:italic; color:#64748b; }
.movement{
  display:flex;
  flex-direction:column;
  gap:.45rem;
  padding:.65rem .75rem;
  border:1px solid var(--line,#e2e8f0);
  border-radius:12px;
  background:#fff;
}
.movement__header{
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:.6rem;
}
.movement__qty strong{ font-size:1rem; }
.movement__time{ margin-left:auto; font-size:.75rem; color:#64748b; }
.movement__status{
  font-size:.75rem;
  font-weight:600;
  padding:.2rem .6rem;
  border-radius:999px;
  background:#fef3c7;
  color:#92400e;
}
.movement__status--signed{ background:#dcfce7; color:#166534; }
.movement__meta{ display:flex; flex-direction:column; gap:.2rem; font-size:.85rem; color:#475569; }
.movement__reason,.movement__notes{ font-size:.85rem; color:#334155; }
.movement__notes{ white-space:pre-line; }
.movement__links{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
.movement__hint{ font-size:.75rem; color:#f97316; font-weight:600; }
.movement-files{ list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:.25rem; font-size:.8rem; }
.movement-files a{ font-weight:600; }
.movement-upload{
  margin-top:.55rem;
  padding-top:.55rem;
  border-top:1px dashed #e2e8f0;
  display:flex;
  flex-direction:column;
  gap:.45rem;
}
.movement-upload__grid{ display:grid; gap:.5rem; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); align-items:end; }
.movement-upload__actions{ display:flex; gap:.5rem; flex-wrap:wrap; }
.checkbox-inline{ display:flex; align-items:center; gap:.4rem; font-size:.85rem; color:#475569; }
.checkbox-inline input{ accent-color:#2563eb; }

.chip{
  display:inline-block; padding:.15rem .5rem; border-radius:999px; font-size:.75rem; font-weight:700;
  background:#eef2ff; color:#111827;
}
.chip-in{ background:#eaf7ef; }
.chip-out{ background:#fff1f2; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>