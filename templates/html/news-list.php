<?php
$today = date('d/m/Y');
$searchQuery = trim($_GET['q'] ?? '');
$syncNow = isset($_GET['sync']) && $_GET['sync'] === '1';
$sortBy = $_GET['sort'] ?? 'published_at'; 
$syncMessage = '';
$newsItems = [];

require_once __DIR__ . '/../../scripts/php/news_repository.php';
require_once __DIR__ . '/../../scripts/php/rss_sync.php';

if (isset($dbConnected) && $dbConnected && isset($connection) && $connection instanceof mysqli) {
    if (!createNewsTableIfNotExists($connection)) {
        error_log('No se pudo ejecutar create.sql para inicializar tabla news.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_feed'])) {
        $newUrl = filter_var($_POST['new_feed'], FILTER_VALIDATE_URL);
        if ($newUrl) {
            $stmt = $connection->prepare("INSERT IGNORE INTO feeds (url) VALUES (?)");
            $stmt->bind_param('s', $newUrl);
            $stmt->execute();
        }
    }

    $currentCount = getNewsCount($connection);

    if ($syncNow || $currentCount === 0) {
        try {
            $resFeeds = $connection->query("SELECT url FROM feeds");
            $totalInserted = 0;
            $totalUpdated = 0;
            
            while ($f = $resFeeds->fetch_assoc()) {
                $res = syncNewsFromRss($connection, $f['url']);
                $totalInserted += $res['inserted'];
                $totalUpdated += $res['updated'];
            }
            $syncMessage = "Sincronización completa. Nuevas: $totalInserted | Actualizadas: $totalUpdated";
        } catch (Throwable $exception) {
            $syncMessage = 'Error: ' . $exception->getMessage();
        }
    }
    
    $newsItems = searchNews($connection, $searchQuery, $sortBy, 50);
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function shortenText(string $text, int $maxLength = 300): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $maxLength, '...');
    }
    if (strlen($text) <= $maxLength) return $text;
    return substr($text, 0, $maxLength - 3) . '...';
}
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

        <h1>Noticias</h1>

        <div class="search-box">
            <form method="POST" action="" style="margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 15px;">
                <input type="url" name="new_feed" placeholder="Pegar URL de nuevo RSS (ej. https://site.com/feed)" required />
                <div class="search-actions">
                    <button type="submit">Agregar Fuente</button>
                </div>
            </form>

            <form method="GET" action="">
                <input type="text" name="q" value="<?php echo escapeHtml($searchQuery); ?>" placeholder="Buscar en noticias..." />
                <div class="search-actions">
                    <select name="sort" onchange="this.form.submit()" style="padding: 8px; border-radius: 8px; border: 1px solid #d1d5db;">
                        <option value="published_at" <?php echo $sortBy == 'published_at' ? 'selected' : ''; ?>>Ordenar por: Fecha</option>
                        <option value="title" <?php echo $sortBy == 'title' ? 'selected' : ''; ?>>Ordenar por: Título</option>
                        <option value="category" <?php echo $sortBy == 'category' ? 'selected' : ''; ?>>Ordenar por: Categoría</option>
                    </select>
                    <button type="submit">Buscar</button>
                    <a href="?sync=1">Actualizar Todo</a>
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
                <?php foreach ($newsItems as $news): 
                    $imageUrl = !empty($news['image_url']) ? $news['image_url'] : 'https://picsum.photos/300/300';
                    $publishedAt = !empty($news['published_at']) ? date('d/m/Y', strtotime((string) $news['published_at'])) : 'Sin fecha';
                    $cat = !empty($news['category']) ? $news['category'] : 'General';
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
                                <b style="color: #2563eb;"><?php echo escapeHtml($cat); ?></b>
                            </p>
                            <p class="news-description"><?php echo escapeHtml(shortenText((string)$news['description'], 200)); ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>