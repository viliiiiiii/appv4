<?php
declare(strict_types=1);

use App\Bootstrap\AppBootstrap;
use App\Domain\DashboardService;
use App\Domain\TaskRepository;
use App\Http\Router;
use App\Support\Request;
use App\Support\Response;
use App\Support\Cache;

require_once __DIR__ . '/../vendor/autoload.php';

AppBootstrap::boot();
require_login();

$request = Request::capture();
$router  = new Router();
$tasks   = new TaskRepository();
$dashboard = new DashboardService($tasks, new Cache());

$router->get('/api/v2/health', function () {
    Response::json([
        'ok'    => true,
        'time'  => date(DATE_ATOM),
        'user'  => current_user()['email'] ?? null,
    ]);
});

$router->get('/api/v2/dashboard', function () use ($dashboard) {
    Response::json($dashboard->overview());
});

$router->get('/api/v2/tasks', function (Request $request) use ($tasks) {
    $filters = [
        'status'      => $request->query('status'),
        'building'    => $request->query('building'),
        'assigned_to' => $request->query('assigned_to'),
        'search'      => $request->query('search'),
    ];

    $limit = (int)$request->query('limit', 25);
    $page  = (int)$request->query('page', 1);
    $sort  = (string)$request->query('sort', 'recent');

    $result = $tasks->paginated($filters, $limit, $page, $sort);

    Response::json($result);
});

$router->get('/api/v2/tasks/{id}', function (Request $request, array $vars) use ($tasks) {
    $id = (int)($vars['id'] ?? 0);
    if ($id <= 0) {
        Response::json(['error' => 'Invalid id'], 422);
        return;
    }

    try {
        $task = $tasks->find($id);
        Response::json($task);
    } catch (Throwable $e) {
        Response::json(['error' => $e->getMessage()], 404);
    }
});

$router->post('/api/v2/tasks', function (Request $request) use ($tasks) {
    $token = $request->header('X-CSRF-Token') ?? $request->input('csrf_token');
    if (!verify_csrf_token($token)) {
        Response::json(['error' => 'Invalid CSRF token'], 419);
        return;
    }

    try {
        $task = $tasks->create($request->inputs());
        Response::json($task, 201);
    } catch (Throwable $e) {
        Response::json(['error' => $e->getMessage()], 422);
    }
});

$router->patch('/api/v2/tasks/{id}', function (Request $request, array $vars) use ($tasks) {
    $token = $request->header('X-CSRF-Token') ?? $request->input('csrf_token');
    if (!verify_csrf_token($token)) {
        Response::json(['error' => 'Invalid CSRF token'], 419);
        return;
    }

    $id = (int)($vars['id'] ?? 0);
    if ($id <= 0) {
        Response::json(['error' => 'Invalid id'], 422);
        return;
    }

    try {
        $task = $tasks->updatePartial($id, $request->inputs());
        Response::json($task);
    } catch (Throwable $e) {
        Response::json(['error' => $e->getMessage()], 422);
    }
});

try {
    $response = $router->dispatch($request);
    if ($response === null) {
        Response::json(['error' => 'Not found'], 404);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    $showDetail = !defined('APP_ENV') || APP_ENV !== 'production';
    Response::json([
        'error' => 'Server error',
        'detail' => $showDetail ? $e->getMessage() : null,
    ], 500);
}