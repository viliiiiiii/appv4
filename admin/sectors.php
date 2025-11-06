<?php
require_once __DIR__ . '/../helpers.php';
require_perm('manage_sectors');

$corePdo = get_pdo('core', false);
$errors = [];
$managerOptions = core_user_options();
$managerLookup = [];
foreach ($managerOptions as $option) {
    if (isset($option['id'])) {
        $managerLookup[(int)$option['id']] = $option;
    }
}

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        $slug = strtolower(trim((string)($_POST['key_slug'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
        $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));
        $colorHex = trim((string)($_POST['color_hex'] ?? ''));
        $managerInput = $_POST['manager_user_id'] ?? '';
        $managerUserId = ($managerInput === '' || $managerInput === 'null') ? null : (int)$managerInput;

        if ($action === 'create') {
            if (!preg_match('/^[a-z0-9_-]+$/', $slug)) { $errors[] = 'Slug must be lowercase letters, numbers, dashes, or underscores.'; }
            if ($name === '') { $errors[] = 'Name is required.'; }
            if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Contact email is invalid.'; }
            if ($colorHex !== '' && !preg_match('/^#?[0-9a-fA-F]{6}$/', $colorHex)) { $errors[] = 'Color must be a 6-digit hex code.'; }
            if ($colorHex !== '' && $colorHex[0] !== '#') { $colorHex = '#' . $colorHex; }
            if ($managerUserId !== null && !isset($managerLookup[$managerUserId])) {
                $managerUserId = null;
            }

            if (!$errors) {
                try {
                    $stmt = $corePdo->prepare('INSERT INTO sectors (key_slug, name, description, contact_email, contact_phone, color_hex, manager_user_id) VALUES (:slug, :name, :description, :contact_email, :contact_phone, :color_hex, :manager_user_id)');
                    $stmt->execute([
                        ':slug' => $slug,
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                        ':contact_email' => $contactEmail !== '' ? $contactEmail : null,
                        ':contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                        ':color_hex' => $colorHex !== '' ? strtolower($colorHex) : null,
                        ':manager_user_id' => $managerUserId,
                    ]);
                    $sectorId = (int)$corePdo->lastInsertId();
                    log_event('sector.create', 'sector', $sectorId, ['slug' => $slug]);
                    redirect_with_message('sectors.php', 'Sector created.');
                } catch (Throwable $e) {
                    $errors[] = 'Could not create sector.';
                }
            }

        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid sector.'; }
            if (!preg_match('/^[a-z0-9_-]+$/', $slug)) { $errors[] = 'Slug must be lowercase letters, numbers, dashes, or underscores.'; }
            if ($name === '') { $errors[] = 'Name is required.'; }
            if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Contact email is invalid.'; }
            if ($colorHex !== '' && !preg_match('/^#?[0-9a-fA-F]{6}$/', $colorHex)) { $errors[] = 'Color must be a 6-digit hex code.'; }
            if ($colorHex !== '' && $colorHex[0] !== '#') { $colorHex = '#' . $colorHex; }
            if ($managerUserId !== null && !isset($managerLookup[$managerUserId])) {
                $managerUserId = null;
            }

            if (!$errors) {
                try {
                    $stmt = $corePdo->prepare('UPDATE sectors SET key_slug=:slug, name=:name, description=:description, contact_email=:contact_email, contact_phone=:contact_phone, color_hex=:color_hex, manager_user_id=:manager_user_id WHERE id=:id');
                    $stmt->execute([
                        ':slug' => $slug,
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                        ':contact_email' => $contactEmail !== '' ? $contactEmail : null,
                        ':contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                        ':color_hex' => $colorHex !== '' ? strtolower($colorHex) : null,
                        ':manager_user_id' => $managerUserId,
                        ':id' => $id,
                    ]);
                    log_event('sector.update', 'sector', $id, ['slug' => $slug]);
                    redirect_with_message('sectors.php', 'Sector updated.');
                } catch (Throwable $e) {
                    $errors[] = 'Could not update sector.';
                }
            }

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid sector.';
            } else {
                try {
                    $stmt = $corePdo->prepare('DELETE FROM sectors WHERE id=:id');
                    $stmt->execute([':id' => $id]);
                    log_event('sector.delete', 'sector', $id);
                    redirect_with_message('sectors.php', 'Sector deleted.');
                } catch (Throwable $e) {
                    $errors[] = 'Could not delete sector (in use?).';
                }
            }
        }
    }
}

$sectors = $corePdo->query('SELECT * FROM sectors ORDER BY name')->fetchAll();

$title = 'Manage Sectors';
include __DIR__ . '/../includes/header.php';
?>

<section class="card">
  <div class="card-header">
    <h1>Sectors</h1>
    <div class="actions">
      <span class="badge">Total: <?php echo number_format(count($sectors)); ?></span>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <!-- Create sector (mobile stacked, desktop compact multi-column via .filters CSS) -->
  <form method="post" class="filters" autocomplete="off">
    <label>Slug
      <input type="text" name="key_slug" id="new_slug" required placeholder="e.g. facilities">
    </label>

    <label>Name
      <input type="text" name="name" id="new_name" required placeholder="Display name (e.g. Facilities)">
    </label>

    <label>Description
      <textarea name="description" rows="2" placeholder="Optional description of responsibilities"></textarea>
    </label>

    <label>Contact email
      <input type="email" name="contact_email" placeholder="team@example.com">
    </label>

    <label>Contact phone
      <input type="text" name="contact_phone" placeholder="+1 555-1234">
    </label>

    <label>Color hex
      <input type="text" name="color_hex" placeholder="#0ea5e9">
    </label>

    <label>Manager
      <select name="manager_user_id">
        <option value="null">Unassigned</option>
        <?php foreach ($managerOptions as $manager): ?>
          <option value="<?php echo (int)$manager['id']; ?>"><?php echo sanitize($manager['email'] ?? ('User #' . $manager['id'])); ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="filter-actions">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
      <button class="btn primary" type="submit">Add Sector</button>
    </div>
  </form>
</section>

<section class="card">
  <h2>Existing Sectors</h2>

  <?php if (!$sectors): ?>
    <p class="muted">No sectors yet.</p>
  <?php else: ?>
    <!-- table--cards turns rows into tidy cards on mobile -->
    <table class="table table--cards compact-rows">
      <thead>
        <tr>
          <th>Slug</th>
          <th>Name</th>
          <th class="text-right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sectors as $sector): ?>
        <tr>
          <td data-label="Slug"><code><?php echo sanitize($sector['key_slug']); ?></code></td>
          <td data-label="Name">
            <?php echo sanitize($sector['name']); ?>
            <?php if (!empty($sector['description'])): ?>
              <div class="muted small"><?php echo sanitize((string)$sector['description']); ?></div>
            <?php endif; ?>
            <div class="muted small">
              <?php if (!empty($sector['contact_email'])): ?>Email: <?php echo sanitize((string)$sector['contact_email']); ?><?php endif; ?>
              <?php if (!empty($sector['contact_phone'])): ?><?php echo !empty($sector['contact_email']) ? ' · ' : ''; ?>Phone: <?php echo sanitize((string)$sector['contact_phone']); ?><?php endif; ?>
              <?php if (!empty($sector['manager_user_id']) && isset($managerLookup[(int)$sector['manager_user_id']])): ?><?php echo (!empty($sector['contact_email']) || !empty($sector['contact_phone'])) ? ' · ' : ''; ?>Lead: <?php echo sanitize((string)($managerLookup[(int)$sector['manager_user_id']]['email'] ?? ('User #' . $sector['manager_user_id']))); ?><?php endif; ?>
            </div>
          </td>
          <td data-label="Actions" class="text-right">
            <details class="fx-border-aurora" style="display:inline-block; padding:.25rem .5rem; border-radius:12px; background:#fff;">
              <summary class="btn small">Edit</summary>
              <div class="mt-2" style="min-width:min(520px,90vw);">
                <form method="post" class="filters" style="margin-top:.5rem;">
                  <label>Slug
                    <input type="text" name="key_slug" value="<?php echo sanitize($sector['key_slug']); ?>" required>
                  </label>
                  <label>Name
                    <input type="text" name="name" value="<?php echo sanitize($sector['name']); ?>" required>
                  </label>
                  <label>Description
                    <textarea name="description" rows="2" placeholder="Optional summary"><?php echo sanitize((string)($sector['description'] ?? '')); ?></textarea>
                  </label>
                  <label>Contact email
                    <input type="email" name="contact_email" value="<?php echo sanitize((string)($sector['contact_email'] ?? '')); ?>" placeholder="team@example.com">
                  </label>
                  <label>Contact phone
                    <input type="text" name="contact_phone" value="<?php echo sanitize((string)($sector['contact_phone'] ?? '')); ?>" placeholder="+1 555-1234">
                  </label>
                  <?php $colorValue = (string)($sector['color_hex'] ?? ''); if ($colorValue === '') { $colorValue = ''; } ?>
                  <label>Color hex
                    <input type="text" name="color_hex" value="<?php echo sanitize($colorValue); ?>" placeholder="#0ea5e9">
                    <?php if ($colorValue): ?><span style="display:inline-block;width:18px;height:18px;border-radius:4px;border:1px solid #cbd5f5;vertical-align:middle;margin-left:6px;background:<?php echo sanitize($colorValue); ?>;"></span><?php endif; ?>
                  </label>
                  <label>Manager
                    <select name="manager_user_id">
                      <option value="null">Unassigned</option>
                      <?php foreach ($managerOptions as $manager): ?>
                        <option value="<?php echo (int)$manager['id']; ?>" <?php echo ((int)($sector['manager_user_id'] ?? 0) === (int)$manager['id']) ? 'selected' : ''; ?>><?php echo sanitize($manager['email'] ?? ('User #' . $manager['id'])); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <div class="filter-actions">
                    <input type="hidden" name="id" value="<?php echo (int)$sector['id']; ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                    <button class="btn small primary" type="submit">Save</button>
                  </div>
                </form>

                <form method="post" class="mt-2" onsubmit="return confirm('Delete this sector?');">
                  <input type="hidden" name="id" value="<?php echo (int)$sector['id']; ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                  <button class="btn small danger" type="submit">Delete</button>
                </form>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<script>
/* UX: Auto-fill slug from Name on the create form (can be overridden) */
document.addEventListener('DOMContentLoaded', () => {
  const nameEl = document.getElementById('new_name');
  const slugEl = document.getElementById('new_slug');
  if (!nameEl || !slugEl) return;

  let slugTouched = false;
  slugEl.addEventListener('input', () => { slugTouched = slugEl.value.trim().length > 0; });

  function slugify(s){
    return s.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g,'') // strip accents
            .replace(/[^a-z0-9]+/g,'-')
            .replace(/(^-|-$)+/g,'')
            .replace(/-{2,}/g,'-');
  }
  nameEl.addEventListener('input', () => {
    if (slugTouched) return;
    slugEl.value = slugify(nameEl.value);
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>