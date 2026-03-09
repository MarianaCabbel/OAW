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

function searchNews(mysqli $connection, string $searchTerm = '', int $limit = 50): array
{
    $limit = max(1, min($limit, 100));

    if ($searchTerm !== '') {
        $sql = "SELECT id, guid, title, link, author, description, image_url, published_at
                FROM news
                WHERE title LIKE CONCAT('%', ?, '%')
                ORDER BY published_at DESC, id DESC
                LIMIT ?";

        $statement = $connection->prepare($sql);

        if (!$statement) {
            error_log('Error al preparar búsqueda de noticias: ' . $connection->error);
            return [];
        }

        $statement->bind_param('si', $searchTerm, $limit);
        $statement->execute();
        $result = $statement->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    $sql = "SELECT id, guid, title, link, author, description, image_url, published_at
            FROM news
            ORDER BY published_at DESC, id DESC
            LIMIT ?";

    $statement = $connection->prepare($sql);

    if (!$statement) {
        error_log('Error al preparar listado de noticias: ' . $connection->error);
        return [];
    }

    $statement->bind_param('i', $limit);
    $statement->execute();
    $result = $statement->get_result();

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
