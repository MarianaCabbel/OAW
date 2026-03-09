<?php
$rssUrl = 'https://www.xataka.com.mx/feedburner.xml';
$searchQuery = trim($_GET['q'] ?? '');
$syncNow = isset($_GET['sync']) && $_GET['sync'] === '1';
$syncMessage = '';
$newsItems = [];

require_once __DIR__ . '/../../scripts/php/news_repository.php';
require_once __DIR__ . '/../../scripts/php/rss_sync.php';
require_once __DIR__ . '/../../scripts/php/utils.php';

if (isset($dbConnected) && $dbConnected && isset($connection) && $connection instanceof mysqli) {
    if (!createNewsTableIfNotExists($connection)) {
        error_log('No se pudo ejecutar create.sql para inicializar tabla news.');
    }

    $currentCount = getNewsCount($connection);

    if ($syncNow || $currentCount === 0) {
        try {
            $syncResult = syncNewsFromRss($connection, $rssUrl);
            $syncMessage = 'Sincronización completada. Nuevas: ' . $syncResult['inserted'] . ' | Actualizadas: ' . $syncResult['updated'];
        } catch (Throwable $exception) {
            error_log('Error de sincronización RSS en UI: ' . $exception->getMessage());
            $syncMessage = 'No se pudo sincronizar RSS: ' . $exception->getMessage();
        }
    }

    $newsItems = searchNews($connection, $searchQuery, 50);
}

$today = formatDateSpanish(date('Y-m-d'));
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
        </div>

        <h1><i>Noticias de Xataka M&eacute;xico</i></h1>

        <div class="search-box">
            <form method="GET" action="" class="search-form">
                <input
                    class="search-input"
                    type="text"
                    name="q"
                    value="<?php echo escapeHtml($searchQuery); ?>"
                    placeholder="Buscar por título de noticia"
                    aria-label="Buscar por título de noticia" />
                <div class="search-actions">
                    <button type="submit">Buscar</button>
                    <a href="?sync=1">Refrescar</a>
                </div>
            </form>
        </div>

        <?php if (isset($dbConnected) && !$dbConnected): ?>
            <p class="status status-error">Conexión a BD fallida: <?php echo escapeHtml((string) $dbError); ?></p>
        <?php elseif ($syncMessage !== ''): ?>
            <p class="status status-ok"><?php echo escapeHtml($syncMessage); ?></p>
        <?php endif; ?>

        <section class="news-list" aria-label="Listado de noticias encontradas">
            <?php if (empty($newsItems)): ?>
                <article class="news-card news-card-empty">
                    <div class="news-content full-width">
                        <h2>Sin resultados</h2>
                        <p class="news-description">No se encontraron noticias con el criterio de búsqueda actual.</p>
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
                                <a href="<?php echo escapeHtml((string) $news['link']); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo escapeHtml((string) $news['title']); ?>
                                </a>
                            </h2>
                            <p class="news-date">Publicado: <?php echo escapeHtml($publishedAt); ?></p>
                            <p class="news-description"><?php echo escapeHtml(shortenText($description, 300)); ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>

</html>
