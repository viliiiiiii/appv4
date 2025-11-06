<?php
declare(strict_types=1);

namespace App\Domain\Inventory;

use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use Throwable;

class TransferService
{
    public function __construct(
        private PDO $appsPdo,
        private PDO $corePdo
    ) {}

    public function generateForMovement(int $movementId): ?array
    {
        $movement = $this->fetchMovement($movementId);
        if (!$movement || (int)($movement['requires_signature'] ?? 0) !== 1) {
            return null;
        }

        $tmpFile = null;
        try {
            $html = $this->renderTemplate($movement);
            $options = new Options();
            $options->set('isRemoteEnabled', true);
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $tmpFile = tempnam(sys_get_temp_dir(), 'transfer') ?: null;
            if ($tmpFile === null) {
                throw new \RuntimeException('Unable to create temp file for transfer form');
            }
            file_put_contents($tmpFile, $dompdf->output());

            $key = sprintf(
                'inventory/transfers/%d/transfer-%s.pdf',
                $movementId,
                (new DateTimeImmutable('now'))->format('Ymd-His')
            );

            $client = \s3_client();
            $client->putObject([
                'Bucket' => S3_BUCKET,
                'Key' => $key,
                'SourceFile' => $tmpFile,
                'ContentType' => 'application/pdf',
            ]);
            @unlink($tmpFile);

            $url = \s3_object_url($key);
            $this->appsPdo->prepare('UPDATE inventory_movements SET transfer_form_key = :key, transfer_form_url = :url WHERE id = :id')
                ->execute([':key' => $key, ':url' => $url, ':id' => $movementId]);

            return ['key' => $key, 'url' => $url];
        } catch (Throwable $e) {
            if ($tmpFile !== null) {
                try { @unlink($tmpFile); } catch (Throwable $_) {}
            }
            throw $e;
        }
    }

    private function fetchMovement(int $movementId): ?array
    {
        $stmt = $this->appsPdo->prepare('
            SELECT m.*, i.name AS item_name, i.sku AS item_sku, i.location AS item_location,
                   i.sector_id AS item_sector_id
            FROM inventory_movements m
            JOIN inventory_items i ON i.id = m.item_id
            WHERE m.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $movementId]);
        $movement = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$movement) {
            return null;
        }

        $sectorIds = array_filter([
            $movement['source_sector_id'] ?? null,
            $movement['target_sector_id'] ?? null,
            $movement['item_sector_id'] ?? null,
        ], static fn($id) => $id !== null);

        $sectorMap = [];
        if ($sectorIds) {
            $placeholders = implode(',', array_fill(0, count($sectorIds), '?'));
            $sectorStmt = $this->corePdo->prepare('SELECT id, name FROM sectors WHERE id IN ('.$placeholders.')');
            $sectorStmt->execute(array_map('intval', $sectorIds));
            foreach ($sectorStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sectorMap[(int)$row['id']] = (string)($row['name'] ?? '');
            }
        }

        $movement['source_sector_name'] = $movement['source_sector_id'] !== null
            ? ($sectorMap[(int)$movement['source_sector_id']] ?? ('Sector #'.(int)$movement['source_sector_id']))
            : 'Unassigned';
        $movement['target_sector_name'] = $movement['target_sector_id'] !== null
            ? ($sectorMap[(int)$movement['target_sector_id']] ?? ('Sector #'.(int)$movement['target_sector_id']))
            : 'Unassigned';
        $movement['item_sector_name'] = $movement['item_sector_id'] !== null
            ? ($sectorMap[(int)$movement['item_sector_id']] ?? ('Sector #'.(int)$movement['item_sector_id']))
            : 'Unassigned';

        if (!empty($movement['user_id'])) {
            $movement['actor_label'] = $this->resolveUserLabel((int)$movement['user_id']);
        } else {
            $movement['actor_label'] = 'System';
        }

        return $movement;
    }

    private function resolveUserLabel(int $userId): string
    {
        try {
            $stmt = $this->corePdo->prepare('SELECT email, name FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['name'])) {
                    return (string)$row['name'];
                }
                if (!empty($row['email'])) {
                    return (string)$row['email'];
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return 'User #'.$userId;
    }

    private function renderTemplate(array $movement): string
    {
        $ts = $movement['ts'] ?? '';
        try {
            $tsFormatted = (new DateTimeImmutable((string)$ts))->format('F j, Y g:i A');
        } catch (Throwable $e) {
            $tsFormatted = (string)$ts;
        }

        $direction = strtoupper((string)($movement['direction'] ?? ''));
        $amount = (int)($movement['amount'] ?? 0);
        $movementId = (int)($movement['id'] ?? 0);
        $sourceLocation = trim((string)($movement['source_location'] ?? ''));
        $targetLocation = trim((string)($movement['target_location'] ?? ''));
        $reason = trim((string)($movement['reason'] ?? ''));
        $notes = trim((string)($movement['notes'] ?? ''));

        $sourceBlock = $movement['source_sector_name'];
        if ($sourceLocation !== '') {
            $sourceBlock .= ' — ' . $sourceLocation;
        }
        $targetBlock = $movement['target_sector_name'];
        if ($targetLocation !== '') {
            $targetBlock .= ' — ' . $targetLocation;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Inventory transfer {$movementId}</title>
  <style>
    body { font-family: "Inter", "Helvetica Neue", Arial, sans-serif; color:#0f172a; font-size:12px; }
    h1 { font-size:20px; margin:0 0 8px 0; }
    table { width:100%; border-collapse:collapse; margin:14px 0; }
    th, td { padding:6px 8px; border:1px solid #d0d7e2; text-align:left; }
    .meta { margin: 10px 0 18px; }
    .section-title { font-size:14px; margin:18px 0 6px; text-transform:uppercase; letter-spacing:.08em; color:#475569; }
    .signatures { margin-top:24px; display:flex; gap:32px; }
    .signature-line { flex:1; }
    .signature-line span { display:block; border-bottom:1px solid #1e293b; margin-bottom:4px; height:28px; }
    .signature-line label { font-size:11px; color:#475569; }
  </style>
</head>
<body>
  <h1>Inventory Transfer #{$movementId}</h1>
  <div class="meta">
    <strong>Date:</strong> {$tsFormatted}<br>
    <strong>Recorded by:</strong> {$movement['actor_label']}
  </div>
  <table>
    <tr>
      <th>Item</th>
      <th>SKU</th>
      <th>Quantity</th>
      <th>Direction</th>
    </tr>
    <tr>
      <td>{$this->escape($movement['item_name'] ?? '')}</td>
      <td>{$this->escape($movement['item_sku'] ?? '')}</td>
      <td>{$amount}</td>
      <td>{$this->escape($direction)}</td>
    </tr>
  </table>
  <table>
    <tr>
      <th>From</th>
      <th>To</th>
    </tr>
    <tr>
      <td>{$this->escape($sourceBlock)}</td>
      <td>{$this->escape($targetBlock)}</td>
    </tr>
  </table>
HTML;
        if ($reason !== '') {
            $html .= '\n  <div class="section-title">Reason</div>\n  <p>'.$this->escape($reason).'</p>';
        }
        if ($notes !== '') {
            $html .= '\n  <div class="section-title">Notes</div>\n  <p>'.$this->escape($notes).'</p>';
        }
        $html .= <<<HTML
  <div class="section-title">Sign-off</div>
  <div class="signatures">
    <div class="signature-line">
      <span></span>
      <label>Requested by</label>
    </div>
    <div class="signature-line">
      <span></span>
      <label>Received by</label>
    </div>
  </div>
</body>
</html>
HTML;

        return $html;
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}