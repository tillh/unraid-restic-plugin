<?php
declare(strict_types=1);

require __DIR__ . '/restic-manager.php';

header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'status');

try {
    $payload = match ($action) {
        'status' => [
            'success' => true,
            'message' => 'Status loaded.',
            'status' => ResticManager::status(true),
        ],
        'install', 'update' => array_merge(['success' => true], ResticManager::installLatest()),
        'remove' => array_merge(['success' => true], ResticManager::removeManagedBinary()),
        default => throw new InvalidArgumentException(sprintf('Unknown action: %s', $action)),
    };

    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $throwable->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
