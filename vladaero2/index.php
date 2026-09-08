<?php
/**
 * VladAero — Public Entry Point
 * All requests go through this file via .htaccess rewrite
 */

define('PROJECT_ROOT', __DIR__);
define('VLD_VERSION', '1.0.0');

// Load config
$config = require PROJECT_ROOT . '/config/app.php';
$GLOBALS['config'] = $config;

// Load helpers
require PROJECT_ROOT . '/core/helpers/functions.php';

// Autoloader
require PROJECT_ROOT . '/core/autoload.php';

use VladAero\Core\{Database, Session, Router, Cache};

// Start session
Session::start();

// Check maintenance mode
if ($config['maintenance'] && !isAdmin()) {
    http_response_code(503);
    include PROJECT_ROOT . '/core/views/errors/maintenance.php';
    exit;
}

// Check if installed
$installedFile = PROJECT_ROOT . '/config/installed.php';
if (!file_exists($installedFile)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    if (!str_contains($requestUri, 'install.php')) {
        header('Location: ' . url('/install.php'));
        exit;
    }
} else {
    $installed = require $installedFile;
    $config = array_merge($config, $installed);
    $GLOBALS['config'] = $config;
    if (!empty($config['db']['host'])) {
        Database::init($config['db']);
    }
}

// Initialize Cache
$cache = new Cache();

// ─── Define Routes ────────────────────────────────────────────
$router = new Router();

// === HOME ===
$router->get('/', [HomeController::class, 'index']);

// === AUTH ===
$router->get('/auth/login', [AuthController::class, 'loginForm']);
$router->post('/auth/login', [AuthController::class, 'login']);
$router->get('/auth/register', [AuthController::class, 'registerForm']);
$router->post('/auth/register', [AuthController::class, 'register']);
$router->get('/auth/2fa', [AuthController::class, 'twoFactorForm']);
$router->post('/auth/2fa', [AuthController::class, 'twoFactorVerify']);
$router->post('/auth/2fa/resend', [AuthController::class, 'twoFactorResend']);
$router->get('/auth/telegram', [AuthController::class, 'telegramAuth']);
$router->get('/auth/telegram/callback', [AuthController::class, 'telegramCallback']);
$router->post('/auth/logout', [AuthController::class, 'logout']);
$router->get('/auth/password-reset', [AuthController::class, 'passwordResetForm']);
$router->post('/auth/password-reset', [AuthController::class, 'passwordReset']);

// === AIRCRAFT ===
$router->get('/aircraft', [AircraftController::class, 'index']);
$router->get('/aircraft/compare', [AircraftController::class, 'compare']);
$router->get('/aircraft/compare/{slug}', [AircraftController::class, 'compareView']);
$router->get('/aircraft/{slug}', [AircraftController::class, 'show']);

// === AIRLINES ===
$router->get('/airlines', [AirlineController::class, 'index']);
$router->get('/airlines/{slug}', [AirlineController::class, 'show']);

// === AIRPORTS ===
$router->get('/airports', [AirportController::class, 'index']);
$router->get('/airports/{slug}', [AirportController::class, 'show']);

// === PHOTOS ===
$router->get('/photos', [PhotoController::class, 'index']);
$router->get('/photos/upload', [PhotoController::class, 'upload']);
$router->post('/photos/upload', [PhotoController::class, 'upload']);
$router->post('/photos/{id}/rate', [PhotoController::class, 'rate']);
$router->get('/photos/{id}', [PhotoController::class, 'show']);

// === NEWS ===
$router->get('/news', [NewsController::class, 'index']);
$router->get('/news/{slug}', [NewsController::class, 'show']);

// === QUIZZES ===
$router->get('/quizzes', [QuizController::class, 'index']);
$router->get('/quizzes/{slug}', [QuizController::class, 'show']);
$router->post('/quizzes/{slug}/submit', [QuizController::class, 'submit']);

// === CALCULATORS ===
$router->get('/calculators', [CalculatorController::class, 'index']);
$router->get('/calculators/speed', [CalculatorController::class, 'speed']);
$router->post('/calculators/speed', [CalculatorController::class, 'speed']);
$router->get('/calculators/altitude', [CalculatorController::class, 'altitude']);
$router->post('/calculators/altitude', [CalculatorController::class, 'altitude']);
$router->get('/calculators/fuel', [CalculatorController::class, 'fuel']);
$router->post('/calculators/fuel', [CalculatorController::class, 'fuel']);
$router->get('/calculators/weight', [CalculatorController::class, 'weight']);
$router->post('/calculators/weight', [CalculatorController::class, 'weight']);
$router->get('/calculators/temperature', [CalculatorController::class, 'temperature']);
$router->post('/calculators/temperature', [CalculatorController::class, 'temperature']);

// === RADAR ===
$router->get('/radar', [RadarController::class, 'index']);
$router->get('/radar/api', [RadarController::class, 'api']);

// === EVENTS ===
$router->get('/events', [EventController::class, 'index']);
$router->get('/events/{slug}', [EventController::class, 'show']);

// === ARTICLES ===
$router->get('/articles', [ArticleController::class, 'index']);
$router->get('/articles/{slug}', [ArticleController::class, 'show']);
$router->get('/blog/new', [ArticleController::class, 'createForm']);
$router->post('/blog/new', [ArticleController::class, 'create']);
$router->get('/blog/{id}/edit', [ArticleController::class, 'editForm']);
$router->post('/blog/{id}/edit', [ArticleController::class, 'update']);

// === CHECKLISTS ===
$router->get('/checklists', [ChecklistController::class, 'index']);
$router->get('/checklists/{id}', [ChecklistController::class, 'show']);

// === GLOSSARY ===
$router->get('/glossary', [GlossaryController::class, 'index']);
$router->get('/glossary/{slug}', [GlossaryController::class, 'show']);

// === PHRASEOLOGY ===
$router->get('/phraseology', [PhraseologyController::class, 'index']);

// === CLUBS ===
$router->get('/clubs', [ClubController::class, 'index']);
$router->get('/clubs/{slug}', [ClubController::class, 'show']);

// === SEARCH ===
$router->get('/search', [SearchController::class, 'index']);

// === PROFILE ===
$router->get('/profile/{username}', [ProfileController::class, 'show']);
$router->get('/profile/{username}/photos', [ProfileController::class, 'photos']);
$router->get('/profile/{username}/articles', [ProfileController::class, 'articles']);
$router->get('/profile/{username}/logbook', [ProfileController::class, 'logbook']);
$router->get('/profile/{username}/favorites', [ProfileController::class, 'favorites']);

// === SETTINGS ===
$router->get('/settings', [SettingsController::class, 'index']);
$router->post('/settings', [SettingsController::class, 'update']);
$router->post('/settings/privacy', [SettingsController::class, 'updatePrivacy']);
$router->get('/settings/export', [SettingsController::class, 'exportData']);
$router->post('/settings/delete', [SettingsController::class, 'deleteAccount']);

// === COMMENTS ===
$router->post('/comments', [CommentController::class, 'store']);
$router->post('/comments/{id}/delete', [CommentController::class, 'delete']);

// === LIKES ===
$router->post('/photos/{id}/like', [LikeController::class, 'toggle']);

// === FOLLOWS ===
$router->post('/follow/{username}', [FollowController::class, 'toggle']);

// === NOTIFICATIONS ===
$router->get('/notifications', [NotificationController::class, 'index']);
$router->get('/notifications/count', [NotificationController::class, 'count']);
$router->get('/notifications/{id}/read', [NotificationController::class, 'markRead']);
$router->get('/notifications/read-all', [NotificationController::class, 'markAllRead']);

// === AI WIDGET ===
$router->post('/api/ai/chat', [AiWidgetController::class, 'chat']);
$router->get('/api/ai/chat', [AiWidgetController::class, 'apiChat']);

// === API v1 ===
$router->group('/api/v1', function ($r) {
    $r->get('/aircraft', [\Api\AircraftApiController::class, 'index']);
    $r->get('/aircraft/{typeCode}', [\Api\AircraftApiController::class, 'show']);
    $r->get('/airports', [\Api\AirportApiController::class, 'index']);
    $r->get('/airports/{icao}', [\Api\AirportApiController::class, 'show']);
    $r->get('/airports/{icao}/metar', [\Api\AirportApiController::class, 'metar']);
    $r->get('/airlines', [\Api\AirlineApiController::class, 'index']);
    $r->get('/airlines/{slug}', [\Api\AirlineApiController::class, 'show']);
    $r->get('/radar', [\Api\AirlineApiController::class, 'radar']);
});

// === ADMIN ===
$router->group('/admin', function ($r) {
    $r->get('', [\Admin\DashboardController::class, 'index']);
    $r->get('/', [\Admin\DashboardController::class, 'index']);

    // Aircraft
    $r->get('/aircraft', [\Admin\AircraftCrudController::class, 'index']);
    $r->get('/aircraft/new', [\Admin\AircraftCrudController::class, 'create']);
    $r->post('/aircraft/save', [\Admin\AircraftCrudController::class, 'save']);
    $r->get('/aircraft/{id}/edit', [\Admin\AircraftCrudController::class, 'edit']);
    $r->post('/aircraft/{id}/edit', [\Admin\AircraftCrudController::class, 'save']);
    $r->post('/aircraft/{id}/delete', [\Admin\AircraftCrudController::class, 'delete']);

    // Airports
    $r->get('/airports', [\Admin\AirportCrudController::class, 'index']);
    $r->get('/airports/new', [\Admin\AirportCrudController::class, 'create']);
    $r->post('/airports/save', [\Admin\AirportCrudController::class, 'save']);
    $r->get('/airports/{id}/edit', [\Admin\AirportCrudController::class, 'edit']);
    $r->post('/airports/{id}/edit', [\Admin\AirportCrudController::class, 'save']);
    $r->post('/airports/{id}/delete', [\Admin\AirportCrudController::class, 'delete']);

    // Airlines
    $r->get('/airlines', [\Admin\AirlineCrudController::class, 'index']);
    $r->get('/airlines/new', [\Admin\AirlineCrudController::class, 'create']);
    $r->post('/airlines/save', [\Admin\AirlineCrudController::class, 'save']);
    $r->get('/airlines/{id}/edit', [\Admin\AirlineCrudController::class, 'edit']);
    $r->post('/airlines/{id}/edit', [\Admin\AirlineCrudController::class, 'save']);
    $r->post('/airlines/{id}/delete', [\Admin\AirlineCrudController::class, 'delete']);

    // News
    $r->get('/news', [\Admin\NewsCrudController::class, 'index']);
    $r->get('/news/new', [\Admin\NewsCrudController::class, 'create']);
    $r->post('/news/save', [\Admin\NewsCrudController::class, 'save']);
    $r->get('/news/{id}/edit', [\Admin\NewsCrudController::class, 'edit']);
    $r->post('/news/{id}/edit', [\Admin\NewsCrudController::class, 'save']);
    $r->post('/news/{id}/delete', [\Admin\NewsCrudController::class, 'delete']);

    // Photos moderation
    $r->get('/photos', [\Admin\PhotoModerationController::class, 'index']);
    $r->post('/photos/{id}/approve', [\Admin\PhotoModerationController::class, 'approve']);
    $r->post('/photos/{id}/reject', [\Admin\PhotoModerationController::class, 'reject']);
    $r->post('/photos/bulk', [\Admin\PhotoModerationController::class, 'bulk']);

    // AI Settings
    $r->get('/ai-settings', [\Admin\AiSettingsController::class, 'index']);
    $r->post('/ai-settings/save', [\Admin\AiSettingsController::class, 'save']);
    $r->post('/ai-settings/test-ai', [\Admin\AiSettingsController::class, 'testAi']);
    $r->post('/ai-settings/test-firecrawl', [\Admin\AiSettingsController::class, 'testFirecrawl']);
    $r->post('/ai-settings/fetch-models', [\Admin\AiSettingsController::class, 'fetchModels']);

    // Site Settings
    $r->get('/settings', [\Admin\SiteSettingsController::class, 'index']);
    $r->post('/settings/update', [\Admin\SiteSettingsController::class, 'update']);
    $r->post('/settings/clear-cache', [\Admin\SiteSettingsController::class, 'clearCache']);
    $r->post('/settings/optimize-db', [\Admin\SiteSettingsController::class, 'optimizeDb']);
    $r->post('/settings/reset-media', [\Admin\SiteSettingsController::class, 'resetMedia']);

    // Super AI Agent
    $r->get('/ai', [\Admin\SuperAgentController::class, 'index']);
    $r->post('/ai/execute', [\Admin\SuperAgentController::class, 'execute']);

    // Checklists
    $r->get('/checklists', [\Admin\ChecklistCrudController::class, 'index']);
    $r->get('/checklists/new', [\Admin\ChecklistCrudController::class, 'create']);
    $r->post('/checklists/save', [\Admin\ChecklistCrudController::class, 'save']);
    $r->get('/checklists/{id}/edit', [\Admin\ChecklistCrudController::class, 'edit']);
    $r->post('/checklists/{id}/edit', [\Admin\ChecklistCrudController::class, 'save']);
    $r->post('/checklists/{id}/delete', [\Admin\ChecklistCrudController::class, 'delete']);

    // Glossary
    $r->get('/glossary', [\Admin\GlossaryCrudController::class, 'index']);
    $r->get('/glossary/new', [\Admin\GlossaryCrudController::class, 'create']);
    $r->post('/glossary/save', [\Admin\GlossaryCrudController::class, 'save']);
    $r->get('/glossary/{id}/edit', [\Admin\GlossaryCrudController::class, 'edit']);
    $r->post('/glossary/{id}/edit', [\Admin\GlossaryCrudController::class, 'save']);
    $r->post('/glossary/{id}/delete', [\Admin\GlossaryCrudController::class, 'delete']);
    $r->get('/glossary/generate', [\Admin\GlossaryCrudController::class, 'generate']);
    $r->post('/glossary/generate-run', [\Admin\GlossaryCrudController::class, 'generateRun']);

    // Phraseology
    $r->get('/phraseology', [\Admin\PhraseologyCrudController::class, 'index']);
    $r->get('/phraseology/new', [\Admin\PhraseologyCrudController::class, 'create']);
    $r->post('/phraseology/save', [\Admin\PhraseologyCrudController::class, 'save']);
    $r->get('/phraseology/{id}/edit', [\Admin\PhraseologyCrudController::class, 'edit']);
    $r->post('/phraseology/{id}/edit', [\Admin\PhraseologyCrudController::class, 'save']);
    $r->post('/phraseology/{id}/delete', [\Admin\PhraseologyCrudController::class, 'delete']);
});

// ─── Telegram Webhook ─────────────────────────────────────────
$router->post('/telegram/webhook', [TelegramController::class, 'webhook']);

// ─── Sitemap ──────────────────────────────────────────────────
$router->get('/sitemap.xml', function () {
    include PROJECT_ROOT . '/sitemap.php';
});

// ─── 404 handler ──────────────────────────────────────────────
$router->get('/{slug}', function ($slug) {
    http_response_code(404);
    include PROJECT_ROOT . '/core/views/errors/404.php';
});

// ─── Dispatch ─────────────────────────────────────────────────
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Handle OPTIONS for API CORS
if ($requestMethod === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    http_response_code(204);
    exit;
}

$result = $router->dispatch($requestMethod, $requestUri);

if ($result === null) {
    http_response_code(404);
    include PROJECT_ROOT . '/core/views/errors/404.php';
}

// ─── Helper ───────────────────────────────────────────────────
function isAdmin(): bool
{
    if (!class_exists('VladAero\Core\Session')) return false;
    $user = Session::getAuth();
    return $user && in_array($user['role'] ?? '', ['admin', 'moderator'], true);
}
