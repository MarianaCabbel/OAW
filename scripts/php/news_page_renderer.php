<?php

require_once __DIR__ . '/utils.php';

function renderStatusHtml(bool $dbConnected, string $dbError, string $syncMessage): string
{
    if (!$dbConnected) {
        return '<p class="status status-error">Conexion a BD fallida: ' . escapeHtml($dbError) . '</p>';
    }

    if ($syncMessage !== '') {
        return '<p class="status status-ok">' . escapeHtml($syncMessage) . '</p>';
    }

    return '';
}

function renderPanelMessageHtml(string $panelMessage, string $panelMessageType): string
{
    if ($panelMessage === '') {
        return '';
    }

    $statusClass = $panelMessageType === 'error' ? 'status-error' : 'status-ok';
    return '<p class="status ' . $statusClass . '">' . escapeHtml($panelMessage) . '</p>';
}

function renderNewsCardsHtml(array $newsItems): string
{
    if (empty($newsItems)) {
        return '<article class="news-card news-card-empty"><div class="news-content full-width"><h2>Sin resultados</h2><p class="news-description">No se encontraron noticias.</p></div></article>';
    }

    $html = '';

    foreach ($newsItems as $news) {
        $imageUrl = !empty($news['image_url']) ? (string) $news['image_url'] : 'https://static.vecteezy.com/system/resources/previews/022/059/000/non_2x/no-image-available-icon-vector.jpg';
        $publishedAt = formatDateSpanish((string) ($news['published_at'] ?? ''));
        $description = trim((string) ($news['description'] ?? ''));

        if ($description === '') {
            $description = 'Sin descripcion disponible.';
        }

        $html .= '<article class="news-card">';
        $html .= '<img src="' . escapeHtml($imageUrl) . '" alt="Imagen de noticia" width="300" height="200" />';
        $html .= '<div class="news-content">';
        $html .= '<h2><a href="' . escapeHtml((string) $news['link']) . '" target="_blank">' . escapeHtml((string) $news['title']) . '</a></h2>';
        $html .= '<p class="news-date">Publicado: ' . escapeHtml($publishedAt) . ' | <b class="news-category">' . escapeHtml((string) ($news['category'] ?? 'General')) . '</b></p>';
        $html .= '<p class="news-description">' . escapeHtml(shortenText($description, 200)) . '</p>';
        $html .= '</div>';
        $html .= '</article>';
    }

    return $html;
}

function renderOptionsHtml(array $items, string $selectedValue, string $allLabel = 'Todas'): string
{
    $html = '<option value="all"' . ($selectedValue === 'all' ? ' selected' : '') . '>' . escapeHtml($allLabel) . '</option>';

    foreach ($items as $item) {
        $value = (string) $item;
        $selected = $selectedValue === $value ? ' selected' : '';
        $html .= '<option value="' . escapeHtml($value) . '"' . $selected . '>' . escapeHtml($value) . '</option>';
    }

    return $html;
}

function renderFeedItemsHtml(array $feeds): string
{
    $html = '';

    foreach ($feeds as $feed) {
        $feedId = (int) $feed['id'];
        $feedUrl = escapeHtml((string) $feed['url']);

        $html .= '<div class="feed-item">';
        $html .= '<form method="POST" class="feed-edit-form">';
        $html .= '<input type="hidden" name="feed_action" value="update" />';
        $html .= '<input type="hidden" name="feed_id" value="' . $feedId . '" />';
        $html .= '<input type="url" name="edit_feed_url" value="' . $feedUrl . '" required />';
        $html .= '<button type="submit">Guardar</button>';
        $html .= '</form>';
        $html .= '<form method="POST" class="feed-delete-form" onsubmit="return confirm(\'Eliminar esta fuente RSS?\');">';
        $html .= '<input type="hidden" name="feed_action" value="delete" />';
        $html .= '<input type="hidden" name="feed_id" value="' . $feedId . '" />';
        $html .= '<button type="submit" class="danger">Eliminar</button>';
        $html .= '</form>';
        $html .= '</div>';
    }

    return $html;
}

function renderNewsListPageTemplate(array $state, string $refreshUrl): string
{
    $templatePath = __DIR__ . '/../../templates/html/news-list.html';
    $template = file_get_contents($templatePath);

    if ($template === false) {
        return '<p>No se pudo cargar la plantilla de noticias.</p>';
    }

    $replacements = [
        '{{TODAY}}' => escapeHtml((string) $state['today']),
        '{{SEARCH_QUERY}}' => escapeHtml((string) $state['searchQuery']),
        '{{SORT_BY}}' => escapeHtml((string) $state['sortBy']),
        '{{SOURCE_FILTER}}' => escapeHtml((string) $state['sourceFilter']),
        '{{CATEGORY_FILTER}}' => escapeHtml((string) $state['categoryFilter']),
        '{{REFRESH_URL}}' => escapeHtml($refreshUrl),
        '{{STATUS_HTML}}' => renderStatusHtml((bool) $state['dbConnected'], (string) $state['dbError'], (string) $state['syncMessage']),
        '{{NEWS_CARDS_HTML}}' => renderNewsCardsHtml((array) $state['newsItems']),
        '{{FILTER_SOURCE_OPTIONS}}' => renderOptionsHtml((array) $state['availableSources'], (string) $state['sourceFilter'], 'Todas'),
        '{{FILTER_CATEGORY_OPTIONS}}' => renderOptionsHtml((array) $state['availableCategories'], (string) $state['categoryFilter'], 'Todas'),
        '{{SORT_DATE_SELECTED}}' => (string) $state['sortBy'] === 'published_at' ? ' selected' : '',
        '{{SORT_TITLE_SELECTED}}' => (string) $state['sortBy'] === 'title' ? ' selected' : '',
        '{{SORT_CATEGORY_SELECTED}}' => (string) $state['sortBy'] === 'category' ? ' selected' : '',
        '{{SETTINGS_MODAL_CLASS}}' => (bool) $state['modalOpen'] ? 'is-open' : '',
        '{{FILTERS_MODAL_CLASS}}' => (bool) $state['filtersModalOpen'] ? 'is-open' : '',
        '{{PANEL_MESSAGE_HTML}}' => renderPanelMessageHtml((string) $state['panelMessage'], (string) $state['panelMessageType']),
        '{{FEED_ITEMS_HTML}}' => renderFeedItemsHtml((array) $state['feeds']),
    ];

    return strtr($template, $replacements);
}
