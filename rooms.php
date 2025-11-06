<?php
require_once __DIR__ . '/helpers.php';
require_login();
if (!can('view')) {
    http_response_code(403);
    exit('Forbidden');
}

if (isset($_GET['action']) && $_GET['action'] === 'by_building') {
    $buildingId = (int)($_GET['id'] ?? 0);
    $rooms = fetch_rooms_by_building($buildingId);
    $formatted = array_map(fn($room) => [
        'id' => $room['id'],
        'label' => $room['room_number'] . ($room['label'] ? ' - ' . $room['label'] : ''),
        'room_number' => $room['room_number'],
        'sector_id' => $room['sector_id'] ?? null,
        'floor_label' => $room['floor_label'] ?? null,
        'capacity' => $room['capacity'] ?? null,
        'notes' => $room['notes'] ?? null,
    ], $rooms);
    json_response($formatted);
}

$pdo = get_pdo();
$corePdo = core_pdo_optional();
$sectors = [];
$sectorLookup = [];
if ($corePdo) {
    try {
        $sectors = $corePdo->query('SELECT id, name FROM sectors ORDER BY name')->fetchAll();
        foreach ($sectors as $sector) {
            if (isset($sector['id'])) {
                $sectorLookup[(int)$sector['id']] = $sector;
            }
        }
    } catch (Throwable $e) {
        $sectors = [];
    }
}
$errors = [];
$selectedBuilding = (int)($_GET['building'] ?? 0);
$searchTerm = trim((string)($_GET['search'] ?? ''));

if (is_post()) {
    if (!can('edit')) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors['csrf'] = 'Invalid CSRF token.';
    } elseif (isset($_POST['add_building'])) {
        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            $errors['building'] = 'Name required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO buildings (name) VALUES (?)');
            $stmt->execute([$name]);
            redirect_with_message('rooms.php', 'Building added.');
        }
    } elseif (isset($_POST['delete_building'])) {
        $buildingId = (int)$_POST['delete_building'];
        $stmt = $pdo->prepare('DELETE FROM buildings WHERE id = ?');
        $stmt->execute([$buildingId]);
        redirect_with_message('rooms.php', 'Building removed.');
    } elseif (isset($_POST['add_room'])) {
        $buildingId = (int)($_POST['building_id'] ?? 0);
        $roomNumber = trim($_POST['room_number'] ?? '');
        $label = trim($_POST['label'] ?? '') ?: null;
        $sectorInput = $_POST['sector_id'] ?? '';
        $sectorId = ($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput;
        $floorLabel = trim((string)($_POST['floor_label'] ?? '')) ?: null;
        $capacityRaw = $_POST['capacity'] ?? '';
        $capacity = ($capacityRaw === '' || $capacityRaw === null) ? null : max(0, (int)$capacityRaw);
        $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
        if (!$buildingId || $roomNumber === '') {
            $errors['room'] = 'Building and room number required.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO rooms (building_id, room_number, label, sector_id, floor_label, capacity, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$buildingId, $roomNumber, $label, $sectorId, $floorLabel, $capacity, $notes]);
                redirect_with_message('rooms.php', 'Room added.');
            } catch (PDOException $e) {
                $errors['room'] = 'Room already exists for building.';
            }
        }
    } elseif (isset($_POST['update_room'])) {
        $roomId = (int)($_POST['update_room'] ?? 0);
        $label = trim((string)($_POST['label'] ?? '')) ?: null;
        $sectorInput = $_POST['sector_id'] ?? '';
        $sectorId = ($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput;
        $floorLabel = trim((string)($_POST['floor_label'] ?? '')) ?: null;
        $capacityRaw = $_POST['capacity'] ?? '';
        $capacity = ($capacityRaw === '' || $capacityRaw === null) ? null : max(0, (int)$capacityRaw);
        $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
        if ($roomId <= 0) {
            $errors['room'] = 'Invalid room.';
        } else {
            $stmt = $pdo->prepare('UPDATE rooms SET label = :label, sector_id = :sector_id, floor_label = :floor_label, capacity = :capacity, notes = :notes WHERE id = :id');
            $stmt->execute([
                ':label' => $label,
                ':sector_id' => $sectorId,
                ':floor_label' => $floorLabel,
                ':capacity' => $capacity,
                ':notes' => $notes,
                ':id' => $roomId,
            ]);
            redirect_with_message('rooms.php', 'Room updated.');
        }
    } elseif (isset($_POST['delete_room'])) {
        $roomId = (int)$_POST['delete_room'];
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->execute([$roomId]);
        redirect_with_message('rooms.php', 'Room removed.');
    }
}

$buildings = $pdo->query('SELECT * FROM buildings ORDER BY name')->fetchAll();
$displayBuildings = $buildings;
if ($selectedBuilding) {
    $displayBuildings = array_values(array_filter($buildings, fn($b) => (int)$b['id'] === $selectedBuilding));
    if (!$displayBuildings) {
        $displayBuildings = $buildings;
    }
}

$roomsByBuilding = [];
if ($buildings) {
    $conditions = [];
    $params = [];
    if ($selectedBuilding) {
        $conditions[] = 'r.building_id = :building';
        $params[':building'] = $selectedBuilding;
    }
    if ($searchTerm !== '') {
        $conditions[] = '(r.room_number LIKE :search OR r.label LIKE :search OR COALESCE(s.name, "") LIKE :search OR COALESCE(r.notes, "") LIKE :search)';
        $params[':search'] = '%' . $searchTerm . '%';
    }

    $sql = 'SELECT r.*, b.name AS building_name, s.name AS sector_name '
         . 'FROM rooms r '
         . 'JOIN buildings b ON b.id = r.building_id '
         . 'LEFT JOIN sectors s ON s.id = r.sector_id';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY b.name, r.room_number';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $roomsByBuilding[$row['building_id']][] = $row;
    }
    foreach ($displayBuildings as $building) {
        $bid = (int)$building['id'];
        if (!isset($roomsByBuilding[$bid])) {
            $roomsByBuilding[$bid] = [];
        }
    }
}

$title = 'Buildings & Rooms';
include __DIR__ . '/includes/header.php';
?>
<section class="card">
    <h1>Buildings</h1>
    <form method="post" class="grid two">
        <label>New Building
            <input type="text" name="name" required placeholder="Building name">
            <?php if (!empty($errors['building'])): ?><span class="error"><?php echo sanitize($errors['building']); ?></span><?php endif; ?>
        </label>
        <div class="card-footer">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
            <button class="btn primary" type="submit" name="add_building" value="1">Add Building</button>
        </div>
    </form>
</section>

<section class="card">
    <h2>Rooms</h2>
    <form method="get" class="grid two" style="margin-bottom:1rem;">
        <label>Filter by building
            <select name="building">
                <option value="0">All buildings</option>
                <?php foreach ($buildings as $building): ?>
                    <option value="<?php echo (int)$building['id']; ?>" <?php echo $selectedBuilding === (int)$building['id'] ? 'selected' : ''; ?>><?php echo sanitize($building['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Search rooms
            <input type="search" name="search" value="<?php echo sanitize($searchTerm); ?>" placeholder="Room number, label, notes">
        </label>
        <div class="card-footer">
            <button class="btn primary" type="submit">Apply</button>
            <a class="btn secondary" href="rooms.php">Reset</a>
        </div>
    </form>
    <form method="post" class="grid two">
        <label>Building
            <select name="building_id" required>
                <option value="">Select building</option>
                <?php foreach ($buildings as $building): ?>
                    <option value="<?php echo $building['id']; ?>"><?php echo sanitize($building['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Room Number
            <input type="text" name="room_number" required>
        </label>
        <label>Label (optional)
            <input type="text" name="label">
        </label>
        <label>Sector
            <select name="sector_id">
                <option value="null">Unassigned</option>
                <?php foreach ($sectors as $sector): ?>
                    <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize($sector['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Floor / area
            <input type="text" name="floor_label" placeholder="e.g. Level 2">
        </label>
        <label>Capacity
            <input type="number" name="capacity" min="0" step="1" placeholder="Optional">
        </label>
        <label class="field-span-2">Notes
            <textarea name="notes" rows="2" placeholder="Access notes, equipment, etc."></textarea>
        </label>
        <div class="card-footer">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
            <button class="btn primary" type="submit" name="add_room" value="1">Add Room</button>
        </div>
        <?php if (!empty($errors['room'])): ?><span class="error"><?php echo sanitize($errors['room']); ?></span><?php endif; ?>
    </form>
</section>

<section class="card">
    <h2>Buildings &amp; Rooms</h2>
    <?php foreach ($displayBuildings as $building): ?>
        <!-- removed "open" so it starts collapsed -->
        <details class="card sub-card building-collapsible">
            <summary class="card-header building-summary">
                <h3><?php echo sanitize($building['name']); ?></h3>
                <div class="actions">
                    <form method="post" onsubmit="return confirm('Delete building and its rooms?');">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                        <button class="btn danger" type="submit" name="delete_building" value="<?php echo $building['id']; ?>">Delete Building</button>
                    </form>
                </div>
            </summary>

            <?php if (!empty($roomsByBuilding[$building['id']])): ?>
                <div class="building-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Details</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roomsByBuilding[$building['id']] as $room):
                                $roomSector = null;
                                if (!empty($room['sector_id']) && isset($sectorLookup[(int)$room['sector_id']])) {
                                    $roomSector = $sectorLookup[(int)$room['sector_id']]['name'] ?? null;
                                } elseif (!empty($room['sector_name'])) {
                                    $roomSector = $room['sector_name'];
                                }
                            ?>
                                <tr>
                                    <td data-label="Room">
                                        <strong><?php echo sanitize($room['room_number']); ?></strong>
                                        <?php if (!empty($room['label'])): ?>
                                            <div class="muted small"><?php echo sanitize((string)$room['label']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Details">
                                        <ul class="muted small" style="list-style:none;padding-left:0;margin:0;display:grid;gap:4px;">
                                            <?php if ($roomSector): ?>
                                                <li><strong>Sector:</strong> <?php echo sanitize($roomSector); ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($room['floor_label'])): ?>
                                                <li><strong>Floor:</strong> <?php echo sanitize((string)$room['floor_label']); ?></li>
                                            <?php endif; ?>
                                            <?php if ($room['capacity'] !== null && $room['capacity'] !== ''): ?>
                                                <li><strong>Capacity:</strong> <?php echo (int)$room['capacity']; ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($room['notes'])): ?>
                                                <li><strong>Notes:</strong> <?php echo sanitize((string)$room['notes']); ?></li>
                                            <?php endif; ?>
                                        </ul>
                                    </td>
                                    <td data-label="Actions">
                                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <a class="btn small" href="tasks.php?building_id=<?php echo $building['id']; ?>&room_id=<?php echo $room['id']; ?>">View Tasks</a>
                                            <a class="btn small" href="export_room_pdf.php?room_id=<?php echo $room['id']; ?>" target="_blank">Export PDF</a>
                                            <details style="flex-basis:100%;">
                                                <summary class="btn small secondary">Edit room</summary>
                                                <form method="post" class="form-compact" style="margin-top:.5rem;">
                                                    <label class="field">Label
                                                        <input type="text" name="label" value="<?php echo sanitize((string)($room['label'] ?? '')); ?>">
                                                    </label>
                                                    <label class="field">Sector
                                                        <select name="sector_id">
                                                            <option value="null">Unassigned</option>
                                                            <?php foreach ($sectors as $sector): ?>
                                                                <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((int)($room['sector_id'] ?? 0) === (int)$sector['id']) ? 'selected' : ''; ?>><?php echo sanitize($sector['name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>
                                                    <label class="field">Floor / area
                                                        <input type="text" name="floor_label" value="<?php echo sanitize((string)($room['floor_label'] ?? '')); ?>">
                                                    </label>
                                                    <label class="field">Capacity
                                                        <input type="number" name="capacity" min="0" step="1" value="<?php echo isset($room['capacity']) && $room['capacity'] !== null ? (int)$room['capacity'] : ''; ?>">
                                                    </label>
                                                    <label class="field">Notes
                                                        <textarea name="notes" rows="2"><?php echo sanitize((string)($room['notes'] ?? '')); ?></textarea>
                                                    </label>
                                                    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                                                    <button class="btn primary small" type="submit" name="update_room" value="<?php echo (int)$room['id']; ?>">Save</button>
                                                </form>
                                            </details>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Delete room?');">
                                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                                                <button class="btn small danger" type="submit" name="delete_room" value="<?php echo $room['id']; ?>">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="building-content">
                    <p class="muted">No rooms yet.</p>
                </div>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>
</section>

<script>
document.addEventListener('click', function (e) {
  const summary = e.target.closest('summary.building-summary');
  if (!summary) return;
  if (e.target.closest('.actions')) {
    e.preventDefault();
    e.stopPropagation();
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>