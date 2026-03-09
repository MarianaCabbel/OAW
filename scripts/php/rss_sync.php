<?php

function normalizeText(string $text): string
{
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $normalized = preg_replace('/\s+/u', ' ', trim($decoded));
    return $normalized ?? trim($decoded);
}

function getSourceFromLink(string $link): string
{
    $host = (string) parse_url($link, PHP_URL_HOST);

    if ($host === '') {
        return 'rss';
    }

    $host = preg_replace('/^www\./i', '', strtolower($host));
    return $host !== '' ? $host : 'rss';
}

function getFirstCategory(SimpleXMLElement $item): string
{
    $fallback = 'General';

    if (!isset($item->category)) {
        return $fallback;
    }

    foreach ($item->category as $categoryNode) {
        $value = normalizeText((string) $categoryNode);
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function getCreator(SimpleXMLElement $item, array $namespaces): string
{
    if (isset($namespaces['dc'])) {
        $dcFields = $item->children($namespaces['dc']);
        $creator = normalizeText((string) ($dcFields->creator ?? ''));
        if ($creator !== '') {
            return $creator;
        }
    }

    $author = normalizeText((string) ($item->author ?? ''));
    return $author;
}

function getMediaImage(SimpleXMLElement $item, array $namespaces): string
{
    if (!isset($namespaces['media'])) {
        return '';
    }

    $mediaFields = $item->children($namespaces['media']);

    if (isset($mediaFields->content)) {
        foreach ($mediaFields->content as $contentNode) {
            $attributes = $contentNode->attributes();
            $url = trim((string) ($attributes['url'] ?? ''));
            $type = strtolower(trim((string) ($attributes['type'] ?? '')));

            if (isSupportedImageUrl($url, $type)) {
                return $url;
            }
        }
    }

    if (isset($mediaFields->thumbnail)) {
        foreach ($mediaFields->thumbnail as $thumbNode) {
            $attributes = $thumbNode->attributes();
            $url = trim((string) ($attributes['url'] ?? ''));

            if (isSupportedImageUrl($url)) {
                return $url;
            }
        }
    }

    return '';
}

function isSupportedImageUrl(string $url, string $mimeType = ''): bool
{
    $url = trim($url);

    if ($url === '') {
        return false;
    }

    $mimeType = strtolower(trim($mimeType));

    if ($mimeType !== '') {
        if (strpos($mimeType, 'image/') === 0) {
            return true;
        }

        if (strpos($mimeType, 'video/') === 0 || strpos($mimeType, 'audio/') === 0) {
            return false;
        }
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif', 'jfif', 'tif', 'tiff'];
    $nonImageExtensions = ['mp4', 'webm', 'mov', 'm4v', 'mkv', 'avi', 'mp3', 'wav', 'ogg'];

    if ($extension === '') {
        return true;
    }

    if (in_array($extension, $nonImageExtensions, true)) {
        return false;
    }

    return in_array($extension, $imageExtensions, true);
}

function getDescriptionCandidates(SimpleXMLElement $item, array $namespaces): array
{
    $candidates = [];

    $description = trim((string) ($item->description ?? ''));
    if ($description !== '') {
        $candidates[] = $description;
    }

    if (isset($namespaces['content'])) {
        $contentFields = $item->children($namespaces['content']);
        $encoded = trim((string) ($contentFields->encoded ?? ''));
        if ($encoded !== '') {
            $candidates[] = $encoded;
        }
    }

    if (isset($namespaces['dcterms'])) {
        $dctermsFields = $item->children($namespaces['dcterms']);
        $alternative = trim((string) ($dctermsFields->alternative ?? ''));
        if ($alternative !== '') {
            $candidates[] = $alternative;
        }
    }

    return $candidates;
}

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
    $plainText = normalizeText(trim(strip_tags($html)));

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
            $plainText = normalizeText($textContent);
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
    "INSERT INTO news (guid, title, link, author, category, description, image_url, published_at, source)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        title = VALUES(title),
        link = VALUES(link),
        author = VALUES(author),
        category = VALUES(category),
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
        $title = normalizeText((string) ($item->title ?? ''));
        $link = trim((string) ($item->link ?? ''));
        $guid = md5($link);
        $category = getFirstCategory($item);
        $dcCreator = getCreator($item, $namespaces);
        $source = getSourceFromLink($link);

        $imageUrl = getMediaImage($item, $namespaces);
        $plainDescription = '';

        foreach (getDescriptionCandidates($item, $namespaces) as $candidateHtml) {
            [$candidateImage, $candidateText] = extractImageAndText($candidateHtml);

            if ($imageUrl === '' && isSupportedImageUrl($candidateImage)) {
                $imageUrl = $candidateImage;
            }

            if ($plainDescription === '' && $candidateText !== '') {
                $plainDescription = $candidateText;
            }

            if ($imageUrl !== '' && $plainDescription !== '') {
                break;
            }
        }

        if ($plainDescription === '') {
            $plainDescription = 'Sin descripción disponible.';
        }

        $publishedAt = parseRssDateToMysql((string) ($item->pubDate ?? ''));

        if ($guid === '' || $title === '' || $link === '') {
            continue;
        }

        $statement->bind_param(
            'sssssssss', 
            $guid, 
            $title, 
            $link, 
            $dcCreator, 
            $category, 
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
