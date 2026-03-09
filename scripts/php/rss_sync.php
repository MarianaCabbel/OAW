<?php

function fetchRssContent(string $url): string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'NoticiasRSSBot/1.0',
        ]);

        $response = curl_exec($handle);
        $error = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
            return $response;
        }

        throw new RuntimeException('No fue posible descargar el RSS por cURL: ' . $error);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "User-Agent: NoticiasRSSBot/1.0\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        throw new RuntimeException('No fue posible descargar el RSS por file_get_contents.');
    }

    return $response;
}

function parseRssDateToMysql(?string $dateString): ?string
{
    if (empty($dateString)) {
        return null;
    }

    $timestamp = strtotime($dateString);

    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function extractImageAndText(string $html): array
{
    $imageUrl = '';
    $plainText = html_entity_decode(trim(strip_tags($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if ($html === '') {
        return [$imageUrl, $plainText];
    }

    libxml_use_internal_errors(true);

    $document = new DOMDocument();
    $wrappedHtml = '<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>';

    if ($document->loadHTML($wrappedHtml, LIBXML_NOERROR | LIBXML_NOWARNING)) {
        $images = $document->getElementsByTagName('img');

        if ($images->length > 0) {
            $imageUrl = trim((string) $images->item(0)->getAttribute('src'));
        }

        $textContent = trim((string) $document->textContent);

        if ($textContent !== '') {
            $normalizedText = html_entity_decode($textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plainText = preg_replace('/\s+/u', ' ', $normalizedText);
        }
    }

    libxml_clear_errors();

    return [$imageUrl, $plainText];
}

function syncNewsFromRss(mysqli $connection, string $rssUrl): array
{
    $inserted = 0;
    $updated = 0;

    $rssContent = fetchRssContent($rssUrl);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rssContent);

    if (!$xml) {
        throw new RuntimeException('RSS inválido o no se pudo parsear.');
    }

    $items = $xml->channel->item ?? [];

    $statement = $connection->prepare(
        "INSERT INTO news (guid, title, link, author, description, image_url, published_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            link = VALUES(link),
            author = VALUES(author),
            description = VALUES(description),
            image_url = VALUES(image_url),
            published_at = VALUES(published_at),
            source = VALUES(source)"
    );

    if (!$statement) {
        throw new RuntimeException('No se pudo preparar sentencia SQL para guardar noticias.');
    }

    foreach ($items as $item) {
        $namespaces = $item->getNamespaces(true);
        $dcCreator = '';

        if (isset($namespaces['dc'])) {
            $dcFields = $item->children($namespaces['dc']);
            $dcCreator = trim((string) ($dcFields->creator ?? ''));
        }

        $title = trim((string) ($item->title ?? ''));
        $link = trim((string) ($item->link ?? ''));
        $guid = md5($link);
        $rawDescription = trim((string) ($item->description ?? ''));
        [$imageUrl, $plainDescription] = extractImageAndText($rawDescription);
        $publishedAt = parseRssDateToMysql((string) ($item->pubDate ?? ''));
        $source = 'xataka';

        if ($guid === '' || $title === '' || $link === '') {
            continue;
        }

        $statement->bind_param(
            'ssssssss',
            $guid,
            $title,
            $link,
            $dcCreator,
            $plainDescription,
            $imageUrl,
            $publishedAt,
            $source
        );

        if (!$statement->execute()) {
            error_log('Error al guardar noticia GUID ' . $guid . ': ' . $statement->error);
            continue;
        }

        if ($statement->affected_rows === 1) {
            $inserted++;
        } elseif ($statement->affected_rows > 1) {
            $updated++;
        }
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
    ];
}
