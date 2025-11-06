<?php
require_once __DIR__ . '/../helpers.php';
require_perm('manage_users');

$corePdo = get_pdo('core', false);
$appsPdo = get_pdo();

$errors = [];
$permissions = permission_catalog();

function normalize_permission_overrides(array $raw, array $catalog): array {
    $normalized = [];
    foreach ($catalog as $key => $_meta) {
        $mode = isset($raw[$key]) ? strtolower((string)$raw[$key]) : 'inherit';
        if (!in_array($mode, ['allow', 'deny'], true)) {
            $mode = 'inherit';
        }
        $normalized[$key] = $mode;
    }
    return $normalized;
}

function save_permission_overrides(PDO $pdo, int $userId, array $overrides): void {
    $deleteStmt = $pdo->prepare('DELETE FROM user_permissions WHERE user_id = :uid AND permission_key = :perm');
    $upsertStmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_key, granted) VALUES (:uid, :perm, :grant)
        ON DUPLICATE KEY UPDATE granted = VALUES(granted)');

    foreach ($overrides as $perm => $mode) {
        if ($mode === 'inherit') {
            $deleteStmt->execute([':uid' => $userId, ':perm' => $perm]);
            continue;
        }
        $upsertStmt->execute([
            ':uid'   => $userId,
            ':perm'  => $perm,
            ':grant' => $mode === 'allow' ? 1 : 0,
        ]);
    }

    permission_invalidate_user_cache($userId);
}

$roles = $corePdo->query('SELECT id, key_slug, label FROM roles ORDER BY label')->fetchAll();
$roleBySlug = [];
foreach ($roles as $role) {
    $roleBySlug[$role['key_slug']] = $role;
}

$sectors = $corePdo->query('SELECT id, key_slug, name FROM sectors ORDER BY name')->fetchAll();
$sectorById = [];
foreach ($sectors as $sector) {
    $sectorById[$sector['id']] = $sector;
}

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $roleSlug = (string)($_POST['role'] ?? 'viewer');
            $sectorInput = $_POST['sector'] ?? '';
            $sectorId = ($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput;
            $overrides = normalize_permission_overrides($_POST['perm_override'] ?? [], $permissions);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Valid email required.';
            }
            if ($password === '') {
                $errors[] = 'Password required.';
            } else {
                $pwError = password_strength_error($password);
                if ($pwError !== null) {
                    $errors[] = $pwError;
                }
            }
            if (!isset($roleBySlug[$roleSlug])) {
                $errors[] = 'Invalid role selection.';
            }
            if ($sectorId !== null && !isset($sectorById[$sectorId])) {
                $errors[] = 'Invalid sector selection.';
            }

            if (!$errors) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $coreStmt = $corePdo->prepare('INSERT INTO users (email, pass_hash, role_id, sector_id) VALUES (:email, :pass_hash, :role_id, :sector_id)');
                $appsStmt = $appsPdo->prepare('INSERT INTO users (id, email, password_hash, role) VALUES (:id, :email, :pass_hash, :role) ON DUPLICATE KEY UPDATE email=VALUES(email), password_hash=VALUES(password_hash), role=VALUES(role)');
                try {
                    $coreStmt->execute([
                        ':email' => $email,
                        ':pass_hash' => $hash,
                        ':role_id' => $roleBySlug[$roleSlug]['id'],
                        ':sector_id' => $sectorId,
                    ]);
                    $userId = (int)$corePdo->lastInsertId();
                    $appsStmt->execute([
                        ':id' => $userId,
                        ':email' => $email,
                        ':pass_hash' => $hash,
                        ':role' => $roleSlug,
                    ]);
                    save_permission_overrides($corePdo, $userId, $overrides);
                    log_event('user.create', 'user', $userId, ['role' => $roleSlug, 'sector_id' => $sectorId]);
                    redirect_with_message('users.php', 'User created.');
                } catch (Throwable $e) {
                    if (!empty($userId)) {
                        try { $corePdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); } catch (Throwable $inner) {}
                    }
                    $errors[] = 'Failed to create user.';
                }
            }
        } elseif ($action === 'update') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $roleSlug = (string)($_POST['role'] ?? 'viewer');
            $sectorInput = $_POST['sector'] ?? '';
            $sectorId = ($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput;
            $overrides = normalize_permission_overrides($_POST['perm_override'] ?? [], $permissions);

            if ($userId <= 0) {
                $errors[] = 'Invalid user.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Valid email required.';
            }
            if (!isset($roleBySlug[$roleSlug])) {
                $errors[] = 'Invalid role selection.';
            }
            if ($sectorId !== null && !isset($sectorById[$sectorId])) {
                $errors[] = 'Invalid sector selection.';
            }
            if ($password !== '') {
                $pwError = password_strength_error($password);
                if ($pwError !== null) {
                    $errors[] = $pwError;
                }
            }

            if (!$errors) {
                try {
                    $coreStmt = $corePdo->prepare('SELECT pass_hash FROM users WHERE id = ?');
                    $coreStmt->execute([$userId]);
                    $current = $coreStmt->fetch();
                    if (!$current) {
                        $errors[] = 'User not found.';
                    } else {
                        $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $current['pass_hash'];
                        $updateCore = $corePdo->prepare('UPDATE users SET email=:email, pass_hash=:pass_hash, role_id=:role_id, sector_id=:sector_id WHERE id=:id');
                        $updateCore->execute([
                            ':email' => $email,
                            ':pass_hash' => $hash,
                            ':role_id' => $roleBySlug[$roleSlug]['id'],
                            ':sector_id' => $sectorId,
                            ':id' => $userId,
                        ]);
                        $appsStmt = $appsPdo->prepare('INSERT INTO users (id, email, password_hash, role) VALUES (:id, :email, :pass_hash, :role) ON DUPLICATE KEY UPDATE email=VALUES(email), password_hash=VALUES(password_hash), role=VALUES(role)');
                        $appsStmt->execute([
                            ':id' => $userId,
                            ':email' => $email,
                            ':pass_hash' => $hash,
                            ':role' => $roleSlug,
                        ]);
                        save_permission_overrides($corePdo, $userId, $overrides);
                        log_event('user.update', 'user', $userId, ['role' => $roleSlug, 'sector_id' => $sectorId]);
                        redirect_with_message('users.php', 'User updated.');
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Failed to update user.';
                }
            }
        } elseif ($action === 'suspend' || $action === 'unsuspend') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                $errors[] = 'Invalid user.';
            } else {
                $now = $action === 'suspend' ? date('Y-m-d H:i:s') : null;
                $by = current_user()['id'] ?? null;
                try {
                    $stmt = $corePdo->prepare('UPDATE users SET suspended_at=:s_at, suspended_by=:s_by WHERE id=:id');
                    $stmt->execute([
                        ':s_at' => $now,
                        ':s_by' => $now ? $by : null,
                        ':id' => $userId,
                    ]);
                    log_event($action === 'suspend' ? 'user.suspend' : 'user.unsuspend', 'user', $userId);
                    redirect_with_message('users.php', $action === 'suspend' ? 'User suspended.' : 'User unsuspended.');
                } catch (Throwable $e) {
                    $errors[] = 'Failed to change suspension status.';
                }
            }
        }
    }
}

$filterRole = trim((string)($_GET['role'] ?? ''));
$filterSector = trim((string)($_GET['sector'] ?? ''));

$where = [];
$params = [];
if ($filterRole !== '') {
    $where[] = 'r.key_slug = :f_role';
    $params[':f_role'] = $filterRole;
}
if ($filterSector !== '') {
    if ($filterSector === 'none') {
        $where[] = 'u.sector_id IS NULL';
    } else {
        $where[] = 'u.sector_id = :f_sector';
        $params[':f_sector'] = (int)$filterSector;
    }
}

$sql = 'SELECT u.*, r.key_slug AS role_key, r.label AS role_label, s.name AS sector_name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN sectors s ON s.id = u.sector_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.email';
$stmt = $corePdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$userOverrides = [];
if ($users) {
    $ids = array_column($users, 'id');
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $ovStmt = $corePdo->prepare('SELECT user_id, permission_key, granted FROM user_permissions WHERE user_id IN (' . $placeholders . ')');
        $ovStmt->execute($ids);
        foreach ($ovStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int)$row['user_id'];
            $perm = (string)$row['permission_key'];
            if (!isset($permissions[$perm])) {
                continue;
            }
            $userOverrides[$uid][$perm] = !empty($row['granted']) ? 'allow' : 'deny';
        }
    }
}

$title = 'Manage Users';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h1>Users</h1>
    <?php if ($errors): ?>
        <div class="flash flash-error">
            <?php echo sanitize(implode(' ', $errors)); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <form class="form-compact" method="get">
        <div class="grid-compact">
            <label class="field">
                <span class="lbl">Role</span>
                <select name="role">
                    <option value="">All</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo sanitize($role['key_slug']); ?>" <?php echo $filterRole === $role['key_slug'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($role['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="lbl">Sector</span>
                <select name="sector">
                    <option value="">All</option>
                    <option value="none" <?php echo $filterSector === 'none' ? 'selected' : ''; ?>>Unassigned</option>
                    <?php foreach ($sectors as $sector): ?>
                        <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$sector['id'] === $filterSector) ? 'selected' : ''; ?>>
                            <?php echo sanitize($sector['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="form-actions-compact field-span-2">
                <button class="btn primary btn-compact" type="submit">Filter</button>
                <a class="btn secondary btn-compact" href="users.php">Reset</a>
            </div>
        </div>
    </form>
</section>

<section class="card">
    <h2>Create User</h2>
    <form method="post" class="form-compact">
        <div class="grid-compact">
            <label class="field">
                <span class="lbl">Email</span>
                <input type="email" name="email" required>
            </label>

            <label class="field">
                <span class="lbl">Password</span>
                <input type="password" name="password" required>
            </label>

            <label class="field">
                <span class="lbl">Role</span>
                <select name="role">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo sanitize($role['key_slug']); ?>"><?php echo sanitize($role['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="lbl">Sector</span>
                <select name="sector">
                    <option value="null">Unassigned</option>
                    <?php foreach ($sectors as $sector): ?>
                        <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize($sector['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="field field-span-2">
                <span class="lbl">Permission overrides</span>
                <p class="muted small">Allow grants access beyond the role; Deny removes access even if the role normally allows it.</p>
                <div class="perm-grid" style="display:grid; gap:8px;">
                    <?php foreach ($permissions as $permKey => $meta): ?>
                        <div class="perm-row" style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:6px 0; border-bottom:1px solid #f1f5f9;">
                            <div class="perm-row__info" style="flex:1 1 auto;">
                                <strong><?php echo sanitize($meta['label']); ?></strong>
                                <div class="muted small"><?php echo sanitize($meta['description']); ?></div>
                            </div>
                            <select name="perm_override[<?php echo sanitize($permKey); ?>]" class="perm-row__select" style="min-width:160px;">
                                <option value="inherit" selected>Inherit role</option>
                                <option value="allow">Allow</option>
                                <option value="deny">Deny</option>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <input type="hidden" name="action" value="create">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">

            <div class="form-actions-compact field-span-2">
                <button class="btn primary btn-compact" type="submit">Create</button>
            </div>
        </div>
    </form>
</section>

<section class="card">
    <h2>Existing Users</h2>
    <table class="table table-excel">
        <thead>
            <tr>
                <th>Email</th>
                <th class="col-status">Role</th>
                <th>Sector</th>
                <th>Overrides</th>
                <th class="col-status">Status</th>
                <th class="col-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td data-label="Email"><?php echo sanitize($user['email']); ?></td>

                <td data-label="Role">
                    <span class="badge"><?php echo sanitize($user['role_label']); ?></span>
                </td>

                <td data-label="Sector">
                    <?php echo $user['sector_name'] ? sanitize($user['sector_name']) : '<em class="muted">Unassigned</em>'; ?>
                </td>

                <td data-label="Overrides">
                    <?php
                        $overrides = $userOverrides[$user['id']] ?? [];
                        if (!$overrides) {
                            echo '<span class="muted small">Inherited</span>';
                        } else {
                            $allow = [];
                            $deny  = [];
                            foreach ($overrides as $permKey => $mode) {
                                $label = $permissions[$permKey]['label'] ?? $permKey;
                                if ($mode === 'allow') {
                                    $allow[] = $label;
                                } elseif ($mode === 'deny') {
                                    $deny[] = $label;
                                }
                            }
                            if ($allow) {
                                echo '<div><strong>Allow:</strong> ' . sanitize(implode(', ', $allow)) . '</div>';
                            }
                            if ($deny) {
                                echo '<div><strong>Deny:</strong> ' . sanitize(implode(', ', $deny)) . '</div>';
                            }
                        }
                    ?>
                </td>

                <td data-label="Status">
                    <?php
                        echo $user['suspended_at']
                            ? '<span class="badge priority-mid">Suspended</span>'
                            : '<span class="badge priority-low">Active</span>';
                    ?>
                </td>

                <td data-label="Actions" class="col-actions">
                    <details>
                        <summary class="btn small">Edit</summary>

                        <form method="post" class="form-compact" style="margin-top:.5rem;">
                            <div class="grid-compact">
                                <label class="field">
                                    <span class="lbl">Email</span>
                                    <input type="email" name="email" value="<?php echo sanitize($user['email']); ?>" required>
                                </label>

                                <label class="field">
                                    <span class="lbl">Role</span>
                                    <select name="role">
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?php echo sanitize($role['key_slug']); ?>" <?php echo $user['role_key'] === $role['key_slug'] ? 'selected' : ''; ?>>
                                                <?php echo sanitize($role['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label class="field">
                                    <span class="lbl">Sector</span>
                                    <select name="sector">
                                        <option value="null">Unassigned</option>
                                        <?php foreach ($sectors as $sector): ?>
                                            <option value="<?php echo (int)$sector['id']; ?>" <?php echo ($user['sector_id'] == $sector['id']) ? 'selected' : ''; ?>>
                                                <?php echo sanitize($sector['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label class="field">
                                    <span class="lbl">New Password</span>
                                    <input type="password" name="password" placeholder="Leave blank to keep current">
                                </label>

                                <div class="field field-span-2">
                                    <span class="lbl">Permission overrides</span>
                                    <p class="muted small">Override access just for this user.</p>
                                    <div class="perm-grid" style="display:grid; gap:8px;">
                                        <?php $currentOverrides = $userOverrides[$user['id']] ?? []; ?>
                                        <?php foreach ($permissions as $permKey => $meta): ?>
                                            <?php $selected = $currentOverrides[$permKey] ?? 'inherit'; ?>
                                            <div class="perm-row" style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:6px 0; border-bottom:1px solid #f1f5f9;">
                                                <div class="perm-row__info" style="flex:1 1 auto;">
                                                    <strong><?php echo sanitize($meta['label']); ?></strong>
                                                    <div class="muted small"><?php echo sanitize($meta['description']); ?></div>
                                                </div>
                                                <select name="perm_override[<?php echo sanitize($permKey); ?>]" class="perm-row__select" style="min-width:160px;">
                                                    <option value="inherit" <?php echo $selected === 'inherit' ? 'selected' : ''; ?>>Inherit role</option>
                                                    <option value="allow" <?php echo $selected === 'allow' ? 'selected' : ''; ?>>Allow</option>
                                                    <option value="deny" <?php echo $selected === 'deny' ? 'selected' : ''; ?>>Deny</option>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">

                                <div class="form-actions-compact field-span-2">
                                    <button class="btn primary btn-compact" type="submit">Save</button>
                                </div>
                            </div>
                        </form>

                        <form method="post" class="form-compact" style="margin-top:.5rem;">
                            <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                            <input type="hidden" name="action" value="<?php echo $user['suspended_at'] ? 'unsuspend' : 'suspend'; ?>">
                            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                            <button class="btn small <?php echo $user['suspended_at'] ? '' : 'secondary'; ?>" type="submit">
                                <?php echo $user['suspended_at'] ? 'Unsuspend' : 'Suspend'; ?>
                            </button>
                        </form>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>