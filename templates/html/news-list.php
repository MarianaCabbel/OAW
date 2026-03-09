<?php
$rssUrl = 'https://www.xataka.com.mx/feedburner.xml';
$searchQuery = trim($_GET['q'] ?? '');
$syncNow = isset($_GET['sync']) && $_GET['sync'] === '1';
$sortBy = $_GET['sort'] ?? 'published_at';
$sourceFilter = trim($_GET['source'] ?? 'all');
$categoryFilter = trim($_GET['category'] ?? 'all');
$syncMessage = '';
$panelMessage = '';
$panelMessageType = 'ok';
$newsItems = [];
$feeds = [];
$availableSources = [];
$availableCategories = [];
$modalOpen = isset($_GET['settings']) && $_GET['settings'] === '1';
$filtersModalOpen = false;

require_once __DIR__ . '/../../scripts/php/news_repository.php';
require_once __DIR__ . '/../../scripts/php/rss_sync.php';
require_once __DIR__ . '/../../scripts/php/utils.php';

if (isset($dbConnected) && $dbConnected && isset($connection) && $connection instanceof mysqli) {
    if (!createNewsTableIfNotExists($connection)) {
        error_log('No se pudo ejecutar create.sql para inicializar tabla news.');
    }

    ensureDefaultFeed($connection, $rssUrl);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $feedAction = $_POST['feed_action'] ?? '';
        $modalOpen = true;

        try {
            if ($feedAction === 'add') {
                $newFeed = trim($_POST['new_feed_url'] ?? '');
                $newFeedUrl = filter_var($newFeed, FILTER_VALIDATE_URL);

                if (!$newFeedUrl) {
                    $panelMessage = 'La URL de la nueva fuente no es válida.';
                    $panelMessageType = 'error';
                } elseif (!addFeed($connection, $newFeedUrl)) {
                    $panelMessage = 'No se pudo agregar la fuente RSS.';
                    $panelMessageType = 'error';
                } else {
                    $panelMessage = 'Fuente RSS agregada correctamente.';
                }
            } elseif ($feedAction === 'update') {
                $feedId = (int) ($_POST['feed_id'] ?? 0);
                $editFeed = trim($_POST['edit_feed_url'] ?? '');
                $editFeedUrl = filter_var($editFeed, FILTER_VALIDATE_URL);

                if ($feedId <= 0 || !$editFeedUrl) {
                    $panelMessage = 'Datos inválidos para actualizar la fuente.';
                    $panelMessageType = 'error';
                } elseif (!updateFeedById($connection, $feedId, $editFeedUrl)) {
                    $panelMessage = 'No se pudo actualizar la fuente RSS.';
                    $panelMessageType = 'error';
                } else {
                    $panelMessage = 'Fuente RSS actualizada correctamente.';
                }
            } elseif ($feedAction === 'delete') {
                $feedId = (int) ($_POST['feed_id'] ?? 0);

                if ($feedId <= 0 || !deleteFeedById($connection, $feedId)) {
                    $panelMessage = 'No se pudo eliminar la fuente RSS.';
                    $panelMessageType = 'error';
                } else {
                    $panelMessage = 'Fuente RSS eliminada correctamente.';
                }
            }
        } catch (Throwable $exception) {
            error_log('Error en operación CRUD de feeds: ' . $exception->getMessage());
            $panelMessage = 'Ocurrió un error al procesar la operación de fuentes.';
            $panelMessageType = 'error';
        }

        ensureDefaultFeed($connection, $rssUrl);
    }

    $feeds = getAllFeeds($connection);
    $availableSources = getAvailableSources($connection);
    $availableCategories = getAvailableCategories($connection);

    $currentCount = getNewsCount($connection);

    if ($syncNow || $currentCount === 0) {
        try {
            $totalInserted = 0;
            $totalUpdated = 0;
            $syncedFeeds = 0;

            foreach ($feeds as $feed) {
                $feedUrl = (string) $feed['url'];
                $feedSource = getSourceFromLink($feedUrl);

                if ($sourceFilter !== 'all' && $feedSource !== $sourceFilter) {
                    continue;
                }

                $res = syncNewsFromRss($connection, $feedUrl);
                $totalInserted += $res['inserted'];
                $totalUpdated += $res['updated'];
                $syncedFeeds++;
            }

            if ($syncedFeeds === 0) {
                $syncMessage = 'No hay fuentes para sincronizar con el filtro actual.';
            } else {
                $syncMessage = "Sincronización completa. Nuevas: $totalInserted | Actualizadas: $totalUpdated";
            }
        } catch (Throwable $exception) {
            error_log('Error de sincronización RSS en UI: ' . $exception->getMessage());
            $syncMessage = 'Error: ' . $exception->getMessage();
        }
    }

    $newsItems = searchNews($connection, $searchQuery, $sortBy, 50, $sourceFilter, $categoryFilter);
}

$today = formatDateSpanish(date('Y-m-d'));
$refreshUrl = '?sync=1&q=' . urlencode($searchQuery) . '&sort=' . urlencode($sortBy) . '&source=' . urlencode($sourceFilter) . '&category=' . urlencode($categoryFilter);
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Noticias</title>
    <link rel="stylesheet" href="templates/css/news.css" />
</head>
<body>
    <main class="page">
        <div class="topbar">
            <span>Fecha de hoy: <?php echo $today; ?></span>
            <button type="button" class="settings-toggle" id="openSettings" aria-label="Abrir ajustes de fuentes RSS"><img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRFN4dkQxwm8sqPFeqJdEThXdMHW-rwGASc1g&s" alt="Ajustes" width="24" height="24" /></button>
        </div>

        <h1><i>Noticias Recientes</i></h1>

        <div class="search-box">
            <form method="GET" action="" class="search-form main-search-form">
                <input
                    class="search-input"
                    type="text"
                    name="q"
                    value="<?php echo escapeHtml($searchQuery); ?>"
                    placeholder="Buscar por título de noticia"
                    aria-label="Buscar por título de noticia" />
                <input type="hidden" name="sort" value="<?php echo escapeHtml($sortBy); ?>" />
                <input type="hidden" name="source" value="<?php echo escapeHtml($sourceFilter); ?>" />
                <input type="hidden" name="category" value="<?php echo escapeHtml($categoryFilter); ?>" />
                <div class="search-actions">
                    <button type="submit">Buscar</button>
                    <button type="button" id="openFilters">Filtros avanzados</button>
                    <a href="<?php echo escapeHtml($refreshUrl); ?>">Refrescar</a>
                </div>
            </form>
        </div>

        <?php if (isset($dbConnected) && !$dbConnected): ?>
            <p class="status status-error">Conexión a BD fallida: <?php echo escapeHtml((string) $dbError); ?></p>
        <?php elseif ($syncMessage !== ''): ?>
            <p class="status status-ok"><?php echo escapeHtml($syncMessage); ?></p>
        <?php endif; ?>

        <section class="news-list">
            <?php if (empty($newsItems)): ?>
                <article class="news-card news-card-empty">
                    <div class="news-content full-width">
                        <h2>Sin resultados</h2>
                        <p class="news-description">No se encontraron noticias.</p>
                    </div>
                </article>
            <?php else: ?>
                <?php foreach ($newsItems as $news): ?>
                    <?php
                    $imageUrl = !empty($news['image_url']) ? $news['image_url'] : 'https://upload.wikimedia.org/wikipedia/commons/5/59/Empty.png';
                    $publishedAt = formatDateSpanish((string) ($news['published_at'] ?? ''));
                    $description = trim((string) ($news['description'] ?? ''));
                    if ($description === '') {
                        $description = 'Sin descripción disponible.';
                    }
                    ?>
                    <article class="news-card">
                        <img src="<?php echo escapeHtml($imageUrl); ?>" alt="Imagen de noticia" />
                        <div class="news-content">
                            <h2>
                                <a href="<?php echo escapeHtml((string) $news['link']); ?>" target="_blank">
                                    <?php echo escapeHtml((string) $news['title']); ?>
                                </a>
                            </h2>
                            <p class="news-date">
                                Publicado: <?php echo escapeHtml($publishedAt); ?> | 
                                <b class="news-category"><?php echo escapeHtml((string) ($news['category'] ?? 'General')); ?></b>
                            </p>
                            <p class="news-description"><?php echo escapeHtml(shortenText((string)$news['description'], 200)); ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <div class="modal-backdrop <?php echo $filtersModalOpen ? 'is-open' : ''; ?>" id="filtersModal">
            <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="filtersTitle">
                <div class="modal-header">
                    <h2 id="filtersTitle">Filtros avanzados</h2>
                    <button type="button" class="modal-close" id="closeFilters" aria-label="Cerrar filtros">X</button>
                </div>

                <form method="GET" class="modal-form">
                    <input type="hidden" name="q" value="<?php echo escapeHtml($searchQuery); ?>" />

                    <label for="sourceFilterSelect">Fuente</label>
                    <select id="sourceFilterSelect" name="source" class="sort-select">
                        <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>Todas</option>
                        <?php foreach ($availableSources as $source): ?>
                            <option value="<?php echo escapeHtml($source); ?>" <?php echo $sourceFilter === $source ? 'selected' : ''; ?>>
                                <?php echo escapeHtml($source); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="sortBySelect">Orden</label>
                    <select id="sortBySelect" name="sort" class="sort-select">
                        <option value="published_at" <?php echo $sortBy == 'published_at' ? 'selected' : ''; ?>>Fecha</option>
                        <option value="title" <?php echo $sortBy == 'title' ? 'selected' : ''; ?>>Titulo</option>
                        <option value="category" <?php echo $sortBy == 'category' ? 'selected' : ''; ?>>Categoria</option>
                    </select>

                    <label for="categorySelect">Categoria</label>
                    <select id="categorySelect" name="category" class="sort-select">
                        <option value="all" <?php echo $categoryFilter === 'all' ? 'selected' : ''; ?>>Todas</option>
                        <?php foreach ($availableCategories as $category): ?>
                            <option value="<?php echo escapeHtml($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>>
                                <?php echo escapeHtml($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">Aplicar filtros</button>
                </form>
            </div>
        </div>

        <div class="modal-backdrop <?php echo $modalOpen ? 'is-open' : ''; ?>" id="settingsModal">
            <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="feedsTitle">
                <div class="modal-header">
                    <h2 id="feedsTitle">Fuentes RSS</h2>
                    <button type="button" class="modal-close" id="closeSettings" aria-label="Cerrar ajustes">X</button>
                </div>

                <?php if ($panelMessage !== ''): ?>
                    <p class="status <?php echo $panelMessageType === 'error' ? 'status-error' : 'status-ok'; ?>"><?php echo escapeHtml($panelMessage); ?></p>
                <?php endif; ?>

                <form method="POST" class="modal-form">
                    <input type="hidden" name="feed_action" value="add" />
                    <input type="url" name="new_feed_url" placeholder="https://sitio.com/feed.xml" required />
                    <button type="submit">Agregar fuente</button>
                </form>

                <div class="feed-list">
                    <?php foreach ($feeds as $feed): ?>
                        <div class="feed-item">
                            <form method="POST" class="feed-edit-form">
                                <input type="hidden" name="feed_action" value="update" />
                                <input type="hidden" name="feed_id" value="<?php echo (int) $feed['id']; ?>" />
                                <input type="url" name="edit_feed_url" value="<?php echo escapeHtml((string) $feed['url']); ?>" required />
                                <button type="submit">Guardar</button>
                            </form>
                            <form method="POST" class="feed-delete-form" onsubmit="return confirm('¿Eliminar esta fuente RSS?');">
                                <input type="hidden" name="feed_action" value="delete" />
                                <input type="hidden" name="feed_id" value="<?php echo (int) $feed['id']; ?>" />
                                <button type="submit" class="danger">Eliminar</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        (function () {
            var openButton = document.getElementById('openSettings');
            var closeButton = document.getElementById('closeSettings');
            var modal = document.getElementById('settingsModal');
            var openFilters = document.getElementById('openFilters');
            var closeFilters = document.getElementById('closeFilters');
            var filtersModal = document.getElementById('filtersModal');

            if (!openButton || !closeButton || !modal) {
                return;
            }

            function openModal() {
                modal.classList.add('is-open');
            }

            function closeModal() {
                modal.classList.remove('is-open');
            }

            openButton.addEventListener('click', openModal);
            closeButton.addEventListener('click', closeModal);

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });

            if (openFilters && closeFilters && filtersModal) {
                openFilters.addEventListener('click', function () {
                    filtersModal.classList.add('is-open');
                });

                closeFilters.addEventListener('click', function () {
                    filtersModal.classList.remove('is-open');
                });

                filtersModal.addEventListener('click', function (event) {
                    if (event.target === filtersModal) {
                        filtersModal.classList.remove('is-open');
                    }
                });
            }
        })();
    </script>
</body>

</html>