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

function searchNews(mysqli $connection, string $searchTerm = '', string $orderBy = 'published_at', int $limit = 50): array
{
    $allowedSort = ['published_at', 'title', 'category', 'author'];
    if (!in_array($orderBy, $allowedSort)) { $orderBy = 'published_at'; }

    $sql = "SELECT id, guid, title, link, author, category, description, image_url, published_at  FROM news";
    
    if ($searchTerm !== '') {
        $sql .= " WHERE title LIKE CONCAT('%', ?, '%') OR description LIKE CONCAT('%', ?, '%')";
    }
    
    if ($orderBy === 'published_at') {
        $sql .= " ORDER BY published_at DESC, id DESC LIMIT ?";
    } else {
        $sql .= " ORDER BY $orderBy ASC, published_at DESC, id DESC LIMIT ?";
    }

    $statement = $connection->prepare($sql);
    
    if ($searchTerm !== '') {
        $statement->bind_param('ssi', $searchTerm, $searchTerm, $limit);
    } else {
        $statement->bind_param('i', $limit);
    }

    $statement->execute();
    $result = $statement->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function addFeed(mysqli $connection, string $url): bool {
    $stmt = $connection->prepare("INSERT IGNORE INTO feeds (url) VALUES (?)");
    $stmt->bind_param('s', $url);
    return $stmt->execute();
}

function getAllFeeds(mysqli $connection): array {
    $res = $connection->query("SELECT url FROM feeds");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}