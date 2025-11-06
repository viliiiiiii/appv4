<?php
declare(strict_types=1);

namespace App\Domain;

use App\Support\Cache;
use PDO;
use RuntimeException;

final class TaskRepository
{
    private PDO $pdo;
    private Cache $cache;

    public function __construct(?PDO $pdo = null, ?Cache $cache = null)
    {
        $this->pdo   = $pdo ?? get_pdo();
        $this->cache = $cache ?? new Cache();
    }

    /**
     * Fetch a compact paginated list of tasks.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginated(array $filters, int $limit = 25, int $page = 1, string $sort = 'recent'): array
    {
        $limit = max(1, min(100, $limit));
        $page  = max(1, $page);
        $offset = ($page - 1) * $limit;

        [$whereSql, $params] = $this->buildFilters($filters);
        [$orderSql, $orderParams] = $this->buildSort($sort);
        $params = array_merge($params, $orderParams);

        $countSql = 'SELECT COUNT(*) FROM tasks t ' . $whereSql;
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = <<<SQL
        SELECT t.id, t.title, t.status, t.priority, t.due_date, t.updated_at, t.created_at,
               t.assigned_to, b.name AS building_name,
               CONCAT(r.room_number, IF(r.label IS NULL OR r.label = '', '', CONCAT(' - ', r.label))) AS room_label
        FROM tasks t
        JOIN buildings b ON b.id = t.building_id
        JOIN rooms r ON r.id = t.room_id
        $whereSql
        $orderSql
        LIMIT :limit OFFSET :offset
        SQL;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $limit,
                'total_pages' => $total > 0 ? (int)ceil($total / $limit) : 0,
            ],
        ];
    }

    public function recentlyUpdated(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $sql = <<<SQL
        SELECT t.id, t.title, t.status, t.priority, t.updated_at, t.due_date,
               b.name AS building_name,
               CONCAT(r.room_number, IF(r.label IS NULL OR r.label = '', '', CONCAT(' - ', r.label))) AS room_label
        FROM tasks t
        JOIN buildings b ON b.id = t.building_id
        JOIN rooms r ON r.id = t.room_id
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT :limit
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summaryCounts(): array
    {
        return $this->cache->remember('tasks:summary', 30, function () {
            $sql = <<<SQL
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN status = 'done' AND updated_at >= (CURRENT_DATE - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS done30,
                SUM(CASE WHEN status <> 'done' AND due_date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due_week,
                SUM(CASE WHEN status <> 'done' AND due_date IS NOT NULL AND due_date < CURRENT_DATE THEN 1 ELSE 0 END) AS overdue
            FROM tasks
            SQL;

            $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'total'   => (int)($row['total'] ?? 0),
                'open'    => (int)($row['open'] ?? 0),
                'done30'  => (int)($row['done30'] ?? 0),
                'dueWeek' => (int)($row['due_week'] ?? 0),
                'overdue' => (int)($row['overdue'] ?? 0),
            ];
        });
    }

    public function create(array $payload): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Title is required');
        }

        $buildingId = (int)($payload['building_id'] ?? 0);
        $roomId     = (int)($payload['room_id'] ?? 0);

        if (!$buildingId || !$roomId) {
            throw new RuntimeException('Building and room are required');
        }

        if (!ensure_building_room_valid($buildingId, $roomId)) {
            throw new RuntimeException('Room does not belong to the selected building');
        }

        $priority = (string)($payload['priority'] ?? '');
        if (!in_array($priority, get_priorities(), true)) {
            $priority = '';
        }

        $status = (string)($payload['status'] ?? 'open');
        if (!in_array($status, get_statuses(), true)) {
            $status = 'open';
        }

        $assignedTo = trim((string)($payload['assigned_to'] ?? '')) ?: null;
        $dueDate    = $this->normalizeDate($payload['due_date'] ?? null);

        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (building_id, room_id, title, description, priority, assigned_to, status, due_date, created_by)'
            . ' VALUES (:building, :room, :title, :description, :priority, :assigned_to, :status, :due_date, :created_by)'
        );

        $stmt->execute([
            ':building'     => $buildingId,
            ':room'         => $roomId,
            ':title'        => $title,
            ':description'  => trim((string)($payload['description'] ?? '')) ?: null,
            ':priority'     => $priority,
            ':assigned_to'  => $assignedTo,
            ':status'       => $status,
            ':due_date'     => $dueDate,
            ':created_by'   => current_user()['id'] ?? null,
        ]);

        $this->cache->forget('tasks:summary');

        $id = (int)$this->pdo->lastInsertId();
        return $this->find($id);
    }

    public function updatePartial(int $id, array $payload): array
    {
        $task = $this->find($id);
        if (!$task) {
            throw new RuntimeException('Task not found');
        }

        $fields = [];
        $params = [];

        if (array_key_exists('status', $payload)) {
            $status = (string)$payload['status'];
            if (!in_array($status, get_statuses(), true)) {
                throw new RuntimeException('Invalid status');
            }
            $fields[] = 'status = :status';
            $params[':status'] = $status;
        }

        if (array_key_exists('priority', $payload)) {
            $priority = (string)$payload['priority'];
            if (!in_array($priority, get_priorities(), true)) {
                throw new RuntimeException('Invalid priority');
            }
            $fields[] = 'priority = :priority';
            $params[':priority'] = $priority;
        }

        if (array_key_exists('assigned_to', $payload)) {
            $assigned = trim((string)$payload['assigned_to']);
            $fields[] = 'assigned_to = :assigned_to';
            $params[':assigned_to'] = $assigned ?: null;
        }

        if (array_key_exists('due_date', $payload)) {
            $fields[] = 'due_date = :due_date';
            $params[':due_date'] = $this->normalizeDate($payload['due_date']);
        }

        if (empty($fields)) {
            return $task;
        }

        $params[':id'] = $id;

        $sql = 'UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->cache->forget('tasks:summary');

        return $this->find($id);
    }

    public function find(int $id): array
    {
        $sql = <<<SQL
        SELECT t.*, b.name AS building_name,
               CONCAT(r.room_number, IF(r.label IS NULL OR r.label = '', '', CONCAT(' - ', r.label))) AS room_label
        FROM tasks t
        JOIN buildings b ON b.id = t.building_id
        JOIN rooms r ON r.id = t.room_id
        WHERE t.id = :id
        LIMIT 1
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Task not found');
        }

        return $row;
    }

    private function buildFilters(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 't.status = :status';
            $params[':status'] = (string)$filters['status'];
        }

        if (!empty($filters['building'])) {
            $where[] = 't.building_id = :building';
            $params[':building'] = (int)$filters['building'];
        }

        if (!empty($filters['assigned_to'])) {
            $where[] = 't.assigned_to = :assigned_to';
            $params[':assigned_to'] = (string)$filters['assigned_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(t.title LIKE :search OR t.description LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function buildSort(string $sort): array
    {
        return match ($sort) {
            'due_asc'    => ['ORDER BY t.due_date IS NULL, t.due_date ASC, t.id DESC', []],
            'priority'   => ['ORDER BY FIELD(t.priority, "high", "mid/high", "mid", "low/mid", "low", ""), t.updated_at DESC', []],
            'updated'    => ['ORDER BY t.updated_at DESC, t.id DESC', []],
            default      => ['ORDER BY t.created_at DESC, t.id DESC', []],
        };
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }
        $value = (string)$value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException('Invalid date format. Use YYYY-MM-DD');
        }
        return $value;
    }
}