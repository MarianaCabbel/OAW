<?php

declare(strict_types=1);

const NEWS_IMAGE_CACHE_DIR = __DIR__ . '/../../cache/news-images';
const NEWS_IMAGE_CACHE_TTL = 604800;
const NEWS_IMAGE_DEFAULT_WIDTH = 640;

function sendStatus(int $code): void
{
    http_response_code($code);
    exit;
}

function getRequestedWidth(): int
{
    $width = isset($_GET['w']) ? (int) $_GET['w'] : NEWS_IMAGE_DEFAULT_WIDTH;

    $allowedWidths = [320, 480, 640, 960];

    if (!in_array($width, $allowedWidths, true)) {
        return NEWS_IMAGE_DEFAULT_WIDTH;
    }

    return $width;
}

function getRequestedSourceUrl(): string
{
    $source = isset($_GET['src']) ? trim((string) $_GET['src']) : '';

    if ($source === '') {
        return '';
    }

    if (!filter_var($source, FILTER_VALIDATE_URL)) {
        return '';
    }

    $parts = parse_url($source);

    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));

    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return '';
    }

    return $source;
}

function canEncodeWebp(): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }

    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'image/webp') !== false;
}

function canResizeImages(): bool
{
    return function_exists('imagecreatefromstring')
        && function_exists('imagecreatetruecolor')
        && function_exists('imagecopyresampled')
        && function_exists('imagesx')
        && function_exists('imagesy');
}

function ensureCacheDirectory(): bool
{
    if (is_dir(NEWS_IMAGE_CACHE_DIR)) {
        return true;
    }

    return mkdir(NEWS_IMAGE_CACHE_DIR, 0775, true);
}

function getCacheFilePath(string $sourceUrl, int $width, string $extension): string
{
    $hash = sha1($sourceUrl . '|' . $width . '|v1');
    return NEWS_IMAGE_CACHE_DIR . '/' . $hash . '-' . $width . '.' . $extension;
}

function shouldRegenerateCache(string $cacheFilePath): bool
{
    if (!is_file($cacheFilePath)) {
        return true;
    }

    $modifiedAt = filemtime($cacheFilePath);

    if ($modifiedAt === false) {
        return true;
    }

    return (time() - $modifiedAt) > NEWS_IMAGE_CACHE_TTL;
}

function sendCachedImage(string $cacheFilePath, string $contentType): void
{
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=' . NEWS_IMAGE_CACHE_TTL . ', immutable');
    header('Vary: Accept');
    readfile($cacheFilePath);
    exit;
}

function fetchImageBinary(string $sourceUrl): string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($sourceUrl);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'NoticiasImageProxy/1.0',
            CURLOPT_HTTPHEADER => ['Accept: image/*,*/*;q=0.8'],
        ]);

        $body = curl_exec($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if (is_string($body) && $body !== '' && $httpCode >= 200 && $httpCode < 400) {
            return $body;
        }

        return '';
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'header' => "User-Agent: NoticiasImageProxy/1.0\r\nAccept: image/*,*/*;q=0.8\r\n",
        ],
    ]);

    $content = @file_get_contents($sourceUrl, false, $context);

    if (!is_string($content) || $content === '') {
        return '';
    }

    return $content;
}

function detectImageContentType(string $binary): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $mimeType = finfo_buffer($finfo, $binary);
            finfo_close($finfo);

            if (is_string($mimeType) && strpos($mimeType, 'image/') === 0) {
                return strtolower($mimeType);
            }
        }
    }

    return 'image/jpeg';
}

function getFileExtensionByContentType(string $contentType): string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'image/bmp' => 'bmp',
    ];

    return $map[$contentType] ?? 'jpg';
}

function saveBinaryCache(string $binary, string $targetFilePath): bool
{
    $tmpPath = $targetFilePath . '.tmp';

    if (file_put_contents($tmpPath, $binary) === false || !is_file($tmpPath)) {
        @unlink($tmpPath);
        return false;
    }

    return rename($tmpPath, $targetFilePath);
}

function createResizedImageFile(string $binary, int $targetWidth, string $targetFilePath, bool $encodeWebp): bool
{
    $sourceImage = @imagecreatefromstring($binary);

    if ($sourceImage === false) {
        return false;
    }

    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);

    if ($sourceWidth < 1 || $sourceHeight < 1) {
        imagedestroy($sourceImage);
        return false;
    }

    $renderWidth = min($targetWidth, $sourceWidth);
    $renderHeight = (int) max(1, round(($sourceHeight * $renderWidth) / $sourceWidth));

    $targetImage = imagecreatetruecolor($renderWidth, $renderHeight);

    if ($targetImage === false) {
        imagedestroy($sourceImage);
        return false;
    }

    imagecopyresampled(
        $targetImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $renderWidth,
        $renderHeight,
        $sourceWidth,
        $sourceHeight
    );

    $tmpPath = $targetFilePath . '.tmp';
    $saved = false;

    if ($encodeWebp) {
        $saved = imagewebp($targetImage, $tmpPath, 78);
    } else {
        $saved = imagejpeg($targetImage, $tmpPath, 82);
    }

    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    if (!$saved || !is_file($tmpPath)) {
        @unlink($tmpPath);
        return false;
    }

    return rename($tmpPath, $targetFilePath);
}

$sourceUrl = getRequestedSourceUrl();

if ($sourceUrl === '') {
    sendStatus(400);
}

$width = getRequestedWidth();
$supportsResize = canResizeImages();
$asWebp = $supportsResize && canEncodeWebp();
$extension = $asWebp ? 'webp' : 'jpg';
$contentType = $asWebp ? 'image/webp' : 'image/jpeg';

if (!ensureCacheDirectory()) {
    header('Location: ' . $sourceUrl, true, 302);
    exit;
}

$cacheWidthKey = $supportsResize ? $width : 0;
$cacheFilePath = getCacheFilePath($sourceUrl, $cacheWidthKey, $extension);

if (!shouldRegenerateCache($cacheFilePath)) {
    sendCachedImage($cacheFilePath, $contentType);
}

$imageBinary = fetchImageBinary($sourceUrl);

if ($imageBinary === '') {
    if (is_file($cacheFilePath)) {
        sendCachedImage($cacheFilePath, $contentType);
    }

    header('Location: ' . $sourceUrl, true, 302);
    exit;
}

if (!$supportsResize) {
    $contentType = detectImageContentType($imageBinary);
    $extension = getFileExtensionByContentType($contentType);
    $cacheFilePath = getCacheFilePath($sourceUrl, 0, $extension);

    if (!shouldRegenerateCache($cacheFilePath)) {
        sendCachedImage($cacheFilePath, $contentType);
    }

    if (!saveBinaryCache($imageBinary, $cacheFilePath)) {
        header('Location: ' . $sourceUrl, true, 302);
        exit;
    }

    sendCachedImage($cacheFilePath, $contentType);
}

if (!createResizedImageFile($imageBinary, $width, $cacheFilePath, $asWebp)) {
    $fallbackContentType = detectImageContentType($imageBinary);
    $fallbackExtension = getFileExtensionByContentType($fallbackContentType);
    $fallbackPath = getCacheFilePath($sourceUrl, 0, $fallbackExtension);

    if (!shouldRegenerateCache($fallbackPath)) {
        sendCachedImage($fallbackPath, $fallbackContentType);
    }

    if (saveBinaryCache($imageBinary, $fallbackPath)) {
        sendCachedImage($fallbackPath, $fallbackContentType);
    }

    if (is_file($cacheFilePath)) {
        sendCachedImage($cacheFilePath, $contentType);
    }

    header('Location: ' . $sourceUrl, true, 302);
    exit;
}

sendCachedImage($cacheFilePath, $contentType);
