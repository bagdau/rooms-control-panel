<?php
// public/api.php
declare(strict_types=1);
require_once __DIR__ . '/lib/JsonStore.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function bad_request(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$inputRaw = file_get_contents('php://input') ?: '';
$input = json_decode($inputRaw, true);
if (!is_array($input)) $input = [];

$action = $_GET['action'] ?? $input['action'] ?? null;
$room   = $_GET['room']   ?? $input['room']   ?? null;

$store = new JsonStore(__DIR__ . '/../db/rooms');

try {
    switch ($action) {
        case 'read':
            if (!$room) bad_request('room is required');
            echo json_encode($store->read($room), JSON_UNESCAPED_UNICODE);
            break;

        case 'init':
            if (!$room) bad_request('room is required');
            $total = (int)($input['total'] ?? 0);
            if ($total < 0 || $total > 1000) bad_request('total must be 0..1000');
            $data = $store->initRoom($room, $total);
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            break;

        case 'update':
            if (!$room) bad_request('room is required');
            $id     = (string)($input['id']     ?? '');
            $status = (string)($input['status'] ?? '');
            $note   = $input['note'] ?? '';
            if ($id === '' || $status === '') bad_request('id and status are required');
            $data = $store->updateComputer($room, $id, $status, $note);
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            break;

        case 'listRooms':
            $files = glob(__DIR__ . '/../db/rooms/*.json') ?: [];
            $rooms = array_map(fn($p) => basename($p, '.json'), $files);
            echo json_encode(['rooms' => $rooms], JSON_UNESCAPED_UNICODE);
            break;

        default:
            bad_request('unknown action', 404);
    }
} catch (Throwable $e) {
    bad_request('server error: ' . $e->getMessage(), 500);
}
        