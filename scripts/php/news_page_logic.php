<?php

require_once __DIR__ . '/news_repository.php';
require_once __DIR__ . '/rss_sync.php';
require_once __DIR__ . '/utils.php';

function loadNewsPageState(
    bool $dbConnected,
    ?mysqli $connection,
    string $dbError,
    array $get,
    array $post,
    string $requestMethod
): array {
    $defaultRssUrl = 'https://www.xataka.com.mx/feedburner.xml';

    $state = [
        'searchQuery' => trim($get['q'] ?? ''),
        'syncNow' => isset($get['sync']) && $get['sync'] === '1',
        'sortBy' => $get['sort'] ?? 'published_at',
        'sourceFilter' => trim($get['source'] ?? 'all'),
        'categoryFilter' => trim($get['category'] ?? 'all'),
        'syncMessage' => '',
        'panelMessage' => '',
        'panelMessageType' => 'ok',
        'newsItems' => [],
        'feeds' => [],
        'availableSources' => [],
        'availableCategories' => [],
        'modalOpen' => isset($get['settings']) && $get['settings'] === '1',
        'filtersModalOpen' => false,
        'today' => formatDateSpanish(date('Y-m-d')),
        'dbConnected' => $dbConnected,
        'dbError' => $dbError,
    ];

    if (!$dbConnected || !$connection instanceof mysqli) {
        return $state;
    }

    if (!createNewsTableIfNotExists($connection)) {
        error_log('No se pudo ejecutar create.sql para inicializar tabla news.');
    }

    ensureDefaultFeed($connection, $defaultRssUrl);

    if ($requestMethod === 'POST') {
        $feedAction = $post['feed_action'] ?? '';
        $state['modalOpen'] = true;

        try {
            if ($feedAction === 'add') {
                $newFeed = trim($post['new_feed_url'] ?? '');
                $newFeedUrl = filter_var($newFeed, FILTER_VALIDATE_URL);

                if (!$newFeedUrl) {
                    $state['panelMessage'] = 'La URL de la nueva fuente no es valida.';
                    $state['panelMessageType'] = 'error';
                } elseif (!addFeed($connection, $newFeedUrl)) {
                    $state['panelMessage'] = 'No se pudo agregar la fuente RSS.';
                    $state['panelMessageType'] = 'error';
                } else {
                    $state['panelMessage'] = 'Fuente RSS agregada correctamente.';
                }
            } elseif ($feedAction === 'update') {
                $feedId = (int) ($post['feed_id'] ?? 0);
                $editFeed = trim($post['edit_feed_url'] ?? '');
                $editFeedUrl = filter_var($editFeed, FILTER_VALIDATE_URL);

                if ($feedId <= 0 || !$editFeedUrl) {
                    $state['panelMessage'] = 'Datos invalidos para actualizar la fuente.';
                    $state['panelMessageType'] = 'error';
                } elseif (!updateFeedById($connection, $feedId, $editFeedUrl)) {
                    $state['panelMessage'] = 'No se pudo actualizar la fuente RSS.';
                    $state['panelMessageType'] = 'error';
                } else {
                    $state['panelMessage'] = 'Fuente RSS actualizada correctamente.';
                }
            } elseif ($feedAction === 'delete') {
                $feedId = (int) ($post['feed_id'] ?? 0);

                if ($feedId <= 0 || !deleteFeedById($connection, $feedId)) {
                    $state['panelMessage'] = 'No se pudo eliminar la fuente RSS.';
                    $state['panelMessageType'] = 'error';
                } else {
                    $state['panelMessage'] = 'Fuente RSS eliminada correctamente.';
                }
            }
        } catch (Throwable $exception) {
            error_log('Error en operacion CRUD de feeds: ' . $exception->getMessage());
            $state['panelMessage'] = 'Ocurrio un error al procesar la operacion de fuentes.';
            $state['panelMessageType'] = 'error';
        }

        ensureDefaultFeed($connection, $defaultRssUrl);
    }

    $state['feeds'] = getAllFeeds($connection);
    $state['availableSources'] = getAvailableSources($connection);
    $state['availableCategories'] = getAvailableCategories($connection);

    if ($state['syncNow']) {
        try {
            $totalInserted = 0;
            $totalUpdated = 0;
            $syncedFeeds = 0;

            foreach ($state['feeds'] as $feed) {
                $feedUrl = (string) $feed['url'];
                $feedSource = getSourceFromLink($feedUrl);

                if ($state['sourceFilter'] !== 'all' && $feedSource !== $state['sourceFilter']) {
                    continue;
                }

                $res = syncNewsFromRss($connection, $feedUrl);
                $totalInserted += $res['inserted'];
                $totalUpdated += $res['updated'];
                $syncedFeeds++;
            }

            if ($syncedFeeds === 0) {
                $state['syncMessage'] = 'No hay fuentes para sincronizar con el filtro actual.';
            } else {
                $state['syncMessage'] = "Sincronizacion completa. Nuevas: $totalInserted | Actualizadas: $totalUpdated";
            }
        } catch (Throwable $exception) {
            error_log('Error de sincronizacion RSS en UI: ' . $exception->getMessage());
            $state['syncMessage'] = 'Error: ' . $exception->getMessage();
        }
    }

    $state['newsItems'] = searchNews(
        $connection,
        $state['searchQuery'],
        $state['sortBy'],
        20,
        $state['sourceFilter'],
        $state['categoryFilter']
    );

    return $state;
}

function buildRefreshUrl(string $searchQuery, string $sortBy, string $sourceFilter, string $categoryFilter): string
{
    return '?sync=1&q=' . urlencode($searchQuery)
        . '&sort=' . urlencode($sortBy)
        . '&source=' . urlencode($sourceFilter)
        . '&category=' . urlencode($categoryFilter);
}
