<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_login();

if (!(can('inventory_manage') || can('inventory_transfers'))) {
    http_response_code(403);
    exit('Forbidden');
}

function inventory_safe_redirect(string $candidate): string {
    $candidate = trim($candidate);
    if ($candidate === '') {
        return 'inventory.php';
    }
    $parts = parse_url($candidate);
    if (!is_array($parts)) {
        return 'inventory.php';
    }
    if (!empty($parts['scheme']) || !empty($parts['host'])) {
        return 'inventory.php';
    }
    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/') {
        return 'inventory.php';
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $path . $query;
}

$respondJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

$respond = function (bool $ok, string $message, string $redirect = 'inventory.php') use ($respondJson) {
    if ($respondJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message, 'redirect' => $redirect]);
        return;
    }
    $type = $ok ? 'success' : 'error';
    redirect_with_message($redirect, $message, $type);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(false, 'Method not allowed');
    return;
}

if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
    $respond(false, 'Invalid CSRF token', 'inventory.php');
    return;
}

$movementId = (int)($_POST['movement_id'] ?? 0);
$label = trim((string)($_POST['label'] ?? ''));
$kind = in_array($_POST['kind'] ?? 'signature', ['signature', 'photo', 'other'], true) ? (string)$_POST['kind'] : 'signature';
$redirectUrl = inventory_safe_redirect((string)($_POST['redirect'] ?? 'inventory.php'));

if ($movementId <= 0) {
    $respond(false, 'Movement not found', $redirectUrl);
    return;
}

if (!isset($_FILES['document']) || !is_uploaded_file($_FILES['document']['tmp_name'] ?? '')) {
    $respond(false, 'No file uploaded', $redirectUrl);
    return;
}

$file = $_FILES['document'];
$err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    $respond(false, 'Upload error (code '.$err.')', $redirectUrl);
    return;
}

$size = (int)($file['size'] ?? 0);
if ($size <= 0 || $size > 80 * 1024 * 1024) {
    $respond(false, 'File size invalid (max 80MB)', $redirectUrl);
    return;
}

$tmpPath = (string)$file['tmp_name'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = '';
if ($finfo) {
    $mime = (string)finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
}

$allowed = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
    'image/heif'      => 'heic',
];
$ext = $allowed[$mime] ?? null;
if ($ext === null) {
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','jpg','jpeg','png','webp','heic','heif'], true)) {
        $respond(false, 'Unsupported file type (please upload PDF or image)', $redirectUrl);
        return;
    }
    if ($ext === 'jpeg') { $ext = 'jpg'; }
    if ($ext === 'heif') { $ext = 'heic'; }
}

if ($mime === '' || $mime === 'application/octet-stream') {
    $mime = match ($ext) {
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        default => 'application/octet-stream',
    };
}

try {
    $apps = get_pdo();
    $movementStmt = $apps->prepare('SELECT m.*, i.sector_id, i.name AS item_name FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id WHERE m.id = :id');
    $movementStmt->execute([':id' => $movementId]);
    $movement = $movementStmt->fetch(PDO::FETCH_ASSOC);
    if (!$movement) {
        $respond(false, 'Movement not found', $redirectUrl);
        return;
    }

    $currentUser = current_user();
    $userSectorId = current_user_sector_id();
    if ($currentUser && current_user_role_key() !== 'root' && $movement['sector_id'] !== null && $userSectorId !== null && (int)$movement['sector_id'] !== (int)$userSectorId) {
        $respond(false, 'Cannot modify transfers for other sectors', $redirectUrl);
        return;
    }

    $key = sprintf('inventory/transfers/%d/attachments/%s.%s', $movementId, bin2hex(random_bytes(8)), $ext);
    $client = s3_client();
    $client->putObject([
        'Bucket' => S3_BUCKET,
        'Key' => $key,
        'SourceFile' => $tmpPath,
        'ContentType' => $mime,
    ]);
    $fileUrl = s3_object_url($key);

    $apps->prepare('INSERT INTO inventory_movement_files (movement_id, file_key, file_url, mime, label, kind, uploaded_by) VALUES (:movement_id, :file_key, :file_url, :mime, :label, :kind, :uploaded_by)')
        ->execute([
            ':movement_id' => $movementId,
            ':file_key' => $key,
            ':file_url' => $fileUrl,
            ':mime' => $mime,
            ':label' => $label !== '' ? $label : null,
            ':kind' => $kind,
            ':uploaded_by' => $currentUser['id'] ?? null,
        ]);

    if ($kind === 'signature') {
        $apps->prepare('UPDATE inventory_movements SET transfer_status = "signed" WHERE id = :id')
            ->execute([':id' => $movementId]);
    }

    log_event('inventory.transfer_upload', 'inventory_movement', $movementId, ['kind' => $kind, 'file' => $key]);

    $respond(true, 'Attachment uploaded', $redirectUrl);
} catch (Throwable $e) {
    try { error_log('inventory_transfer_upload failed: '.$e->getMessage()); } catch (Throwable $_) {}
    $respond(false, 'Unable to upload attachment', $redirectUrl);
}