<?php
require_once __DIR__ . '/../../scripts/php/news_page_logic.php';
require_once __DIR__ . '/../../scripts/php/news_page_renderer.php';

$state = loadNewsPageState(
    isset($dbConnected) ? (bool) $dbConnected : false,
    isset($connection) && $connection instanceof mysqli ? $connection : null,
    (string) ($dbError ?? ''),
    $_GET,
    $_POST,
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

$searchQuery = $state['searchQuery'];
$sortBy = $state['sortBy'];
$sourceFilter = $state['sourceFilter'];
$categoryFilter = $state['categoryFilter'];
$syncMessage = $state['syncMessage'];
$panelMessage = $state['panelMessage'];
$panelMessageType = $state['panelMessageType'];
$newsItems = $state['newsItems'];
$feeds = $state['feeds'];
$availableSources = $state['availableSources'];
$availableCategories = $state['availableCategories'];
$modalOpen = $state['modalOpen'];
$filtersModalOpen = $state['filtersModalOpen'];
$today = $state['today'];
$dbConnected = $state['dbConnected'];
$dbError = $state['dbError'];

$refreshUrl = buildRefreshUrl($searchQuery, $sortBy, $sourceFilter, $categoryFilter);

echo renderNewsListPageTemplate($state, $refreshUrl);