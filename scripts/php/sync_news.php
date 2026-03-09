<?php
require_once __DIR__ . '/../../database/connection.php';
require_once __DIR__ . '/news_repository.php';
require_once __DIR__ . '/rss_sync.php';

header('Content-Type: application/json; charset=utf-8');

if (!$dbConnected) {
    error_log('Conexión a BD fallida en sync_news.php: ' . $dbError);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Conexión a BD fallida',
        'error' => $dbError,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rssUrl = 'https://www.xataka.com.mx/feedburner.xml';

if (!createNewsTableIfNotExists($connection)) {
    error_log('No se pudo ejecutar create.sql en sync_news.php');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'No se pudo inicializar la estructura de base de datos',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = syncNewsFromRss($connection, $rssUrl);

    echo json_encode([
        'ok' => true,
        'message' => 'Sincronización completada',
        'inserted' => $result['inserted'],
        'updated' => $result['updated'],
        'total' => getNewsCount($connection),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Error de sincronización RSS en sync_news.php: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Error en sincronización',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
