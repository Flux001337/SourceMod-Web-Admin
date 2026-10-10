<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Version.php';
const SMWA_VERSION = Version::APP;

// PHP-Fehler sind standardmäßig verborgen und werden nur über config debug.enabled freigegeben.
$debug = false;
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Unbehandelte Fehler landen im Log; Besucher sehen nur eine neutrale Seite.
set_exception_handler(static function (Throwable $exception) use (&$debug): void {
    error_log(sprintf(
        'Unbehandelte Ausnahme %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    if (!headers_sent())
    {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Error</title></head><body>'
        . '<h1>Internal error</h1><p>The request could not be processed. Please try again later.</p>';
    if ($debug)
    {
        echo '<pre>' . htmlspecialchars((string) $exception, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
    echo '</body></html>';
});

// Noch nicht installiert: zum Installer (install/index.php), sonst Hinweis auf die Vorlage.
if (!is_file(__DIR__ . '/config/config.php'))
{
    if (is_file(__DIR__ . '/install/index.php'))
    {
        header('Location: install/');
        exit;
    }
    throw new RuntimeException('config/config.php fehlt (Vorlage: config/config.php.example).');
}
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/Http.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Template.php';
require_once __DIR__ . '/app/Permissions.php';
require_once __DIR__ . '/app/Auth.php';
require_once __DIR__ . '/app/Settings.php';
require_once __DIR__ . '/app/Lang.php';
require_once __DIR__ . '/app/Themes.php';
require_once __DIR__ . '/app/RateLimiter.php';
require_once __DIR__ . '/app/Flash.php';
require_once __DIR__ . '/app/Pager.php';
require_once __DIR__ . '/app/SourceMod.php';
require_once __DIR__ . '/app/SourceModExport.php';
require_once __DIR__ . '/app/SourceModPlugins.php';
require_once __DIR__ . '/app/SecretCipher.php';
require_once __DIR__ . '/app/ServerQuery.php';
require_once __DIR__ . '/app/FtpClient.php';
require_once __DIR__ . '/app/GameIcon.php';
require_once __DIR__ . '/app/ConsoleButtons.php';

$debug = ($config['debug']['enabled'] ?? false) === true;
ini_set('display_errors', $debug ? '1' : '0');

Http::configure((array) ($config['security']['trusted_proxies'] ?? []));
$isHttps = Http::isHttps();

// Die Session wird einmal zentral gestartet, bevor eine Seite Ausgabe erzeugt.
$sessionIdleTimeout = max(300, (int) ($config['security']['session_idle_timeout'] ?? 7200));
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.gc_maxlifetime', (string) max(1440, $sessionIdleTimeout));
session_name('smwa_session');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Lax']);
session_start();

// Sitzungen ohne Aktivität laufen ab ("Passwort merken" stellt die Anmeldung über das Cookie wieder her).
if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $sessionIdleTimeout)
{
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

// Sicherheits-Header. Skripte und Styles dürfen nur aus assets/ kommen (keine Inline-Skripte oder -Styles).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
if ($isHttps)
{
    header('Strict-Transport-Security: max-age=15552000');
}

$db = new Database($config['db'], (array) ($config['sourcemod'] ?? []));
$pdo = $db->pdo();
$settings = new Settings($db);

// Datenbank älter als die Dateien (neue Version hochgeladen): zuerst das Update in install/.
if ((int) $settings->get('db_version') < Version::DB)
{
    if (is_file(__DIR__ . '/install/index.php'))
    {
        header('Location: install/');
        exit;
    }
    throw new RuntimeException('Datenbank-Update nötig: den Ordner install/ der neuen Version hochladen und aufrufen.');
}

// Abgeschaltete SQL-Admins (Einstellungen): Admins, Gruppen, Overrides und Export gibt es dann für niemanden; das Recht
// "sqladmins" bleibt den Benutzern erhalten.
$permissions = new Permissions($db, $settings->get('sql_admins_enabled') === '1' ? [] : ['sqladmins']);
$auth = new Auth($db, $permissions);
$auth->restoreRememberedLogin();
$rateLimiter = new RateLimiter($db, (string) ($config['security']['master_key'] ?? ''));
$sourcemod = new SourceMod($db);
$secrets = new SecretCipher((string) ($config['security']['master_key'] ?? ''));

// Sprache: eigene Wahl des Benutzers, sonst das Sprach-Cookie (z. B. auf der Login-Seite), sonst der Standard der Seite.
const LANG_COOKIE = 'smwa_lang';
$lang = new Lang(__DIR__ . '/lang');
$lang->select($auth->user()['language'] ?? null, $_COOKIE[LANG_COOKIE] ?? null, $settings->get('default_language'));

$template = new Template(__DIR__ . '/templates');
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$template->setGlobalData(['csrf_token' => $csrfToken, 't' => $lang->all()]);

// Aktuelle Adresse ohne Formulardaten, z. B. für Weiterleitungen nach einem POST.
$currentUrl = 'index.php' . ($_GET !== [] ? '?' . http_build_query($_GET) : '');

$redirect = static function (string $url): never {
    header('Location: ' . $url);
    exit;
};

$message = static function (string $title, string $text, int $status = 200) use ($template, $lang): array {
    http_response_code($status);
    return ['title' => $title, 'content' => $template->render('pages/message.html', [
        'title' => $title,
        'message' => $text,
        'back_label' => $lang->t('common.back_home'),
    ])];
};

$forbidden = static fn (): array => $message($lang->t('errors.forbidden_title'), $lang->t('errors.forbidden_text'), 403);

// Gemeinsame Prüfung der SourceMod-Seiten: eingeschaltet, Recht "sqladmins" und vorhandene SourceMod-Tabellen. null = alles
// in Ordnung. Fehlen die Tabellen, eine Warnung mit Anleitung (sm_create_adm_tables auf dem Gameserver, auf Wunsch direkt
// aus der Konsole mit der Gruppe "SQL Admins", console/sql-admin-manager.json).
$sourcemodGuard = static function () use ($auth, $permissions, $sourcemod, $forbidden, $message, $lang, $template, $config, $db, $currentUrl): ?array {
    if ($permissions->isDisabled('sqladmins'))
    {
        return $message($lang->t('errors.not_found_title'), $lang->t('errors.not_found_text'), 404);
    }
    if (!$auth->hasPermission('sqladmins'))
    {
        return $forbidden();
    }
    $missing = $sourcemod->missingTables();
    if ($missing !== [])
    {
        http_response_code(503);
        return ['title' => $lang->t('sm.tables_missing_title'), 'content' => $template->render('pages/sm_tables_missing.html', [
            'tables' => implode(', ', $missing),
            'database' => $db->sourcemodDatabase() ?? (string) $config['db']['database'],
            'can_console' => $auth->hasPermission('console'),
            'console_url' => 'index.php?' . http_build_query(['section' => 'console', 'open' => 'sql-admin-manager.json']),
            'recheck_url' => $currentUrl,
        ])];
    }

    return null;
};

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost && !hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? '')))
{
    $pageContent = $message($lang->t('errors.csrf_title'), $lang->t('errors.csrf_text'), 403);
    $section = 'error';
}
else
{
    // Sprachauswahl (Kopfzeile und Login-Seite): als Cookie merken, bei angemeldeten Benutzern auch im Konto.
    if ($isPost && isset($_POST['switch_lang']))
    {
        $code = (string) $_POST['switch_lang'];
        if ($lang->isAvailable($code))
        {
            setcookie(LANG_COOKIE, $code, [
                'expires' => time() + 365 * 86400,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            if ($auth->check())
            {
                $pdo->prepare('UPDATE ' . $db->table('users') . ' SET language = :language WHERE id = :id')
                    ->execute(['language' => $code, 'id' => $auth->id()]);
            }
        }
        $redirect($currentUrl);
    }

    // Ohne Anmeldung gibt es nur die Login-Seite (wie im alten SMWA).
    $publicPages = ['login' => __DIR__ . '/pages/login.php'];
    $memberPages = [
        'home' => __DIR__ . '/pages/home.php',
        'users' => __DIR__ . '/pages/users.php',
        'sm_admins' => __DIR__ . '/pages/sm_admins.php',
        'sm_groups' => __DIR__ . '/pages/sm_groups.php',
        'sm_overrides' => __DIR__ . '/pages/sm_overrides.php',
        'sm_export' => __DIR__ . '/pages/sm_export.php',
        'sm_plugins' => __DIR__ . '/pages/sm_plugins.php',
        'sm_extensions' => __DIR__ . '/pages/sm_plugins.php',
        'servers' => __DIR__ . '/pages/servers.php',
        'games' => __DIR__ . '/pages/games.php',
        'console' => __DIR__ . '/pages/console.php',
        'profile' => __DIR__ . '/pages/profile.php',
        'settings' => __DIR__ . '/pages/settings.php',
        'logout' => __DIR__ . '/pages/logout.php',
    ];

    $section = (string) ($_GET['section'] ?? 'home');
    if (!$auth->check())
    {
        $section = 'login';
        $pageContent = require $publicPages['login'];
    }
    elseif ($section === 'login')
    {
        $redirect('index.php');
    }
    elseif (isset($memberPages[$section]))
    {
        $pageContent = require $memberPages[$section];
    }
    else
    {
        $pageContent = $message($lang->t('errors.not_found_title'), $lang->t('errors.not_found_text'), 404);
    }
}

$siteTitle = $settings->get('site_title');
$pageTitle = (string) ($pageContent['title'] ?? '');
// Menüpfad als einzelne Titel rendern, damit alle Trenner gleich gestaltet werden.
$titlePath = $pageContent['title_path'] ?? [$pageTitle];
$pageTitleParts = [];
foreach ($titlePath as $part)
{
    $entry = is_array($part) ? $part : ['label' => (string) $part];
    $url = (string) ($entry['url'] ?? '');
    $pageTitleParts[] = [
        'label' => (string) $entry['label'],
        'url' => $url,
        'is_link' => $url !== '',
        'is_current' => $url === '',
        'has_separator' => $pageTitleParts !== [],
    ];
}
$user = $auth->user();
$theme = Themes::active($settings->get('site_theme'), $user['theme'] ?? null);
$flash = Flash::pull();

$languageSwitch = $template->render('partials/lang_switch.html', [
    'flag' => $lang->flag(),
    'languages' => $lang->options($lang->code()),
]);

$layoutData = [
    'html_lang' => $lang->code(),
    'title' => $pageTitle !== '' ? $pageTitle . ' | ' . $siteTitle : $siteTitle,
    'site_title' => $siteTitle,
    'site_subtitle' => $settings->get('site_subtitle'),
    'has_site_subtitle' => $settings->get('site_subtitle') !== '',
    'page_title' => $pageTitle,
    'page_title_parts' => $pageTitleParts,
    'has_theme' => $theme !== '',
    // Änderungszeit als Version, damit Browser nach einer Änderung nicht die alte Datei aus dem Cache nehmen.
    'theme_path' => 'assets/css/themes/' . $theme . '.css',
    'theme_version' => $theme !== '' ? (string) (filemtime(Themes::DIRECTORY . '/' . $theme . '.css') ?: 0) : '',
    'styles_version' => (string) (filemtime(__DIR__ . '/assets/css/styles.css') ?: 0),
    'app_js_version' => (string) (filemtime(__DIR__ . '/assets/js/app.js') ?: 0),
    'language_switch' => $languageSwitch,
    'has_flash' => $flash !== null,
    'flash_type' => $flash['type'] ?? '',
    'flash_message' => $flash['message'] ?? '',
    'view' => (string) ($pageContent['content'] ?? ''),
    'version' => SMWA_VERSION,
];

if ($user === null)
{
    echo $template->render('layouts/auth.html', $layoutData);
    exit;
}

// Seitenleiste: nur Einträge, die der Benutzer öffnen darf; der aktuelle Bereich ist markiert.
$navigation = [
    ['heading' => '', 'items' => [
        ['section' => 'home', 'icon' => 'home', 'label' => $lang->t('nav.home'), 'visible' => true],
    ]],
    ['heading' => $lang->t('nav.heading_sourcemod'), 'items' => [
        ['section' => 'sm_admins', 'icon' => 'shield', 'label' => $lang->t('nav.sm_admins'), 'visible' => $auth->hasPermission('sqladmins')],
        ['section' => 'sm_groups', 'icon' => 'layers', 'label' => $lang->t('nav.sm_groups'), 'visible' => $auth->hasPermission('sqladmins')],
        ['section' => 'sm_overrides', 'icon' => 'lock', 'label' => $lang->t('nav.sm_overrides'), 'visible' => $auth->hasPermission('sqladmins')],
        ['section' => 'sm_plugins', 'icon' => 'plug', 'label' => $lang->t('nav.sm_plugins'), 'visible' => $auth->hasPermission('plugincontrol')],
        ['section' => 'sm_extensions', 'icon' => 'puzzle', 'label' => $lang->t('nav.sm_extensions'), 'visible' => $auth->hasPermission('plugincontrol')],
        ['section' => 'sm_export', 'icon' => 'export', 'label' => $lang->t('nav.sm_export'), 'visible' => $auth->hasPermission('sqladmins')],
    ]],
    ['heading' => $lang->t('nav.heading_servers'), 'items' => [
        ['section' => 'servers', 'icon' => 'server', 'label' => $lang->t('nav.manage_servers'), 'visible' => $auth->hasPermission('servers')],
        ['section' => 'console', 'icon' => 'terminal', 'label' => $lang->t('nav.console'), 'visible' => $auth->hasPermission('console')],
        ['section' => 'games', 'icon' => 'gamepad', 'label' => $lang->t('nav.games'), 'visible' => $auth->hasPermission('games')],
    ]],
    ['heading' => $lang->t('nav.heading_interface'), 'items' => [
        ['section' => 'users', 'icon' => 'users', 'label' => $lang->t('nav.users'),
            'visible' => $auth->hasPermission('users') || $auth->hasPermission('permissions')],
        ['section' => 'settings', 'icon' => 'settings', 'label' => $lang->t('nav.settings'),
            'visible' => $auth->hasPermission('settings')],
        ['section' => 'profile', 'icon' => 'profile', 'label' => $lang->t('nav.profile'), 'visible' => true],
    ]],
];
$navigationGroups = [];
foreach ($navigation as $group)
{
    $items = array_values(array_filter($group['items'], static fn (array $item): bool => $item['visible']));
    if ($items === [])
    {
        continue;
    }
    foreach ($items as &$item)
    {
        $item['active'] = $item['section'] === $section;
    }
    unset($item);
    $navigationGroups[] = ['heading' => $group['heading'], 'has_heading' => $group['heading'] !== '', 'items' => $items];
}

echo $template->render('layouts/main.html', $layoutData + [
    'navigation_groups' => $navigationGroups,
    'username' => $user['username'],
    'initials' => strtoupper(mb_substr($user['username'], 0, 2)),
    'is_owner' => $user['is_owner'],
]);
