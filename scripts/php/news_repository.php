<?php

function createNewsTableIfNotExists(mysqli $connection): bool
{
    $sqlFilePath = __DIR__ . '/../../database/create.sql';

    if (!is_file($sqlFilePath)) {
        error_log('No se encontró create.sql en: ' . $sqlFilePath);
        return false;
    }

    $sql = file_get_contents($sqlFilePath);

    if ($sql === false || trim($sql) === '') {
        error_log('No se pudo leer create.sql o está vacío: ' . $sqlFilePath);
        return false;
    }

    if (!$connection->multi_query($sql)) {
        error_log('Error al ejecutar create.sql: ' . $connection->error);
        return false;
    }

    do {
        if ($result = $connection->store_result()) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());

    if ($connection->errno) {
        error_log('Error posterior a multi_query(create.sql): ' . $connection->error);
        return false;
    }

    return true;
}

function getNewsCount(mysqli $connection): int
{
    $result = $connection->query("SELECT COUNT(*) AS total FROM news");

    if (!$result) {
        error_log('Error al obtener conteo de noticias: ' . $connection->error);
        return 0;
    }

    $row = $result->fetch_assoc();

    return isset($row['total']) ? (int) $row['total'] : 0;
}

function searchNews(
    mysqli $connection,
    string $searchTerm = '',
    string $orderBy = 'published_at',
    int $limit = 20,
    string $sourceFilter = 'all',
    string $categoryFilter = 'all'
): array
{
    $allowedSort = ['published_at', 'title', 'category', 'author'];
    if (!in_array($orderBy, $allowedSort, true)) {
        $orderBy = 'published_at';
    }

    $limit = max(1, min($limit, 100));

    $sql = "SELECT id, guid, title, link, author, category, description, image_url, published_at, source FROM news";

    $hasSearch = $searchTerm !== '';
    $hasSource = $sourceFilter !== '' && $sourceFilter !== 'all';
    $hasCategory = $categoryFilter !== '' && $categoryFilter !== 'all';

    $where = [];
    $types = '';
    $values = [];

    if ($hasSearch) {
        $where[] = "(title LIKE CONCAT('%', ?, '%') OR description LIKE CONCAT('%', ?, '%'))";
        $types .= 'ss';
        $values[] = $searchTerm;
        $values[] = $searchTerm;
    }

    if ($hasSource) {
        $where[] = "source = ?";
        $types .= 's';
        $values[] = $sourceFilter;
    }

    if ($hasCategory) {
        $where[] = "category = ?";
        $types .= 's';
        $values[] = $categoryFilter;
    }

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    if ($orderBy === 'published_at') {
        $sql .= " ORDER BY published_at DESC, id DESC LIMIT ?";
    } else {
        $sql .= " ORDER BY $orderBy ASC, published_at DESC, id DESC LIMIT ?";
    }

    $statement = $connection->prepare($sql);

    if (!$statement) {
        error_log('Error al preparar búsqueda de noticias: ' . $connection->error);
        return [];
    }

    $types .= 'i';
    $values[] = $limit;

    $statement->bind_param($types, ...$values);

    if (!$statement->execute()) {
        error_log('Error al ejecutar búsqueda de noticias: ' . $statement->error);
        return [];
    }

    $result = $statement->get_result();

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function addFeed(mysqli $connection, string $url): bool
{
    $stmt = $connection->prepare("INSERT IGNORE INTO feeds (url) VALUES (?)");

    if (!$stmt) {
        error_log('Error al preparar inserción de feed: ' . $connection->error);
        return false;
    }

    $stmt->bind_param('s', $url);
    return $stmt->execute();
}

function updateFeedById(mysqli $connection, int $feedId, string $url): bool
{
    $stmt = $connection->prepare("UPDATE feeds SET url = ? WHERE id = ?");

    if (!$stmt) {
        error_log('Error al preparar actualización de feed: ' . $connection->error);
        return false;
    }

    $stmt->bind_param('si', $url, $feedId);

    return $stmt->execute();
}

function deleteFeedById(mysqli $connection, int $feedId): bool
{
    $stmt = $connection->prepare("DELETE FROM feeds WHERE id = ?");

    if (!$stmt) {
        error_log('Error al preparar eliminación de feed: ' . $connection->error);
        return false;
    }

    $stmt->bind_param('i', $feedId);

    return $stmt->execute();
}

function getAllFeeds(mysqli $connection): array
{
    $res = $connection->query("SELECT id, url, created_at FROM feeds ORDER BY id ASC");

    if (!$res) {
        error_log('Error al consultar feeds: ' . $connection->error);
        return [];
    }

    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function ensureDefaultFeed(mysqli $connection, string $defaultFeedUrl): void
{
    $countResult = $connection->query("SELECT COUNT(*) AS total FROM feeds");

    if (!$countResult) {
        error_log('Error al contar feeds: ' . $connection->error);
        return;
    }

    $row = $countResult->fetch_assoc();
    $total = isset($row['total']) ? (int) $row['total'] : 0;

    if ($total === 0) {
        addFeed($connection, $defaultFeedUrl);
    }
}

function getAvailableSources(mysqli $connection): array
{
    $res = $connection->query("SELECT DISTINCT source FROM news WHERE source IS NOT NULL AND source <> '' ORDER BY source ASC");

    if (!$res) {
        error_log('Error al consultar fuentes disponibles: ' . $connection->error);
        return [];
    }

    $sources = [];

    while ($row = $res->fetch_assoc()) {
        $source = trim((string) ($row['source'] ?? ''));
        if ($source !== '') {
            $sources[] = $source;
        }
    }

    return $sources;
}

function getAvailableCategories(mysqli $connection): array
{
    $res = $connection->query("SELECT DISTINCT category FROM news WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");

    if (!$res) {
        error_log('Error al consultar categorias disponibles: ' . $connection->error);
        return [];
    }

    $categories = [];

    while ($row = $res->fetch_assoc()) {
        $category = trim((string) ($row['category'] ?? ''));
        if ($category !== '') {
            $categories[] = $category;
        }
    }

    return $categories;
}