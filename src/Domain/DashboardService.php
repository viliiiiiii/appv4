<?php
declare(strict_types=1);

namespace App\Domain;

use App\Support\Cache;

final class DashboardService
{
    public function __construct(
        private TaskRepository $tasks,
        private Cache $cache = new Cache()
    ) {
    }

    public function overview(): array
    {
        $counts = $this->tasks->summaryCounts();
        $recent = $this->tasks->recentlyUpdated(6);
        $status = $this->cachedGrouped('dashboard:status', 'SELECT status AS label, COUNT(*) AS total FROM tasks GROUP BY status');
        $priority = $this->cachedGrouped('dashboard:priority', 'SELECT priority AS label, COUNT(*) AS total FROM tasks GROUP BY priority');
        $assignees = $this->cachedGrouped(
            'dashboard:assignees',
            "SELECT COALESCE(NULLIF(TRIM(assigned_to), ''), 'Unassigned') AS label, COUNT(*) AS total FROM tasks GROUP BY label ORDER BY total DESC LIMIT 10"
        );
        $buildings = $this->cachedGrouped(
            'dashboard:buildings',
            'SELECT b.name AS label, COUNT(*) AS total FROM tasks t JOIN buildings b ON b.id = t.building_id GROUP BY b.id ORDER BY total DESC LIMIT 10'
        );
        $rooms = $this->cachedGrouped(
            'dashboard:rooms',
            "SELECT CONCAT(r.room_number, IF(r.label IS NULL OR r.label = '', '', CONCAT(' - ', r.label))) AS label, COUNT(*) AS total FROM tasks t JOIN rooms r ON r.id = t.room_id GROUP BY r.id ORDER BY total DESC LIMIT 10"
        );
        $age = $this->cache->remember('dashboard:age', 60, function () {
            $ageSql = 'SELECT ' . get_age_bucket_sql() . " AS label, COUNT(*) AS total FROM tasks t WHERE t.status <> 'done' GROUP BY label";
            return get_pdo()->query($ageSql)->fetchAll() ?: [];
        });

        return [
            'counts'     => $counts,
            'status'     => $status,
            'priority'   => $priority,
            'assignees'  => $assignees,
            'buildings'  => $buildings,
            'rooms'      => $rooms,
            'age'        => $age,
            'recent'     => $recent,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function cachedGrouped(string $key, string $sql): array
    {
        return $this->cache->remember($key, 60, function () use ($sql) {
            return get_pdo()->query($sql)->fetchAll() ?: [];
        });
    }
}