<?php
declare(strict_types=1);

// Installer und Update:
// - ohne config/config.php: Neuinstallation (?mode=install) oder Übernahme einer SMWA-2.x-Installation (?mode=migrate),
//   Systemprüfung, Datenbank, Einstellungen (und Owner) in einem Formular,
// - mit config/config.php: Update der bestehenden Installation nach Anmeldung mit dem Owner-Konto.
// Den Ordner install/ danach löschen.

$root = dirname(__DIR__);
foreach (['Version', 'Http', 'Database', 'Template', 'Lang', 'Settings', 'Permissions', 'Auth', 'RateLimiter', 'SecretCipher',
    'GameIcon', 'SourceMod', 'Installer', 'Smwa2Import', 'Updater'] as $class)
{
    require_once $root . '/app/' . $class . '.php';
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('smwa_install');
session_set_cookie_params(['httponly' => true, 'secure' => Http::isHttps(), 'samesite' => 'Lax']);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

// Sprache: Auswahl (?lang=), sonst die erste passende aus dem Browser, sonst Englisch.
$lang = new Lang($root . '/lang');
$browser = array_map(
    static fn (string $part): string => strtolower(substr(trim(explode(';', $part)[0]), 0, 2)),
    explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))
);
$chosen = (string) ($_GET['lang'] ?? $_POST['lang'] ?? '');
$lang->select($chosen !== '' ? $chosen : null, ...$browser);

// Neuinstallation oder Übernahme von SMWA 2.x (nur ohne config/config.php).
$mode = (string) ($_GET['mode'] ?? $_POST['mode'] ?? '') === 'migrate' ? 'migrate' : 'install';

$template = new Template($root . '/templates');
$csrfToken = $_SESSION['install_csrf'] ??= bin2hex(random_bytes(32));
$template->setGlobalData(['csrf_token' => $csrfToken, 't' => $lang->all()]);

$render = static function (array $data) use ($template, $lang, $root, $mode): never {
    echo $template->render('install/page.html', $data + [
        'html_lang' => $lang->code(),
        'version' => Version::APP,
        'languages' => array_map(
            static fn (array $option): array => $option + ['url' => '?mode=' . $mode . '&lang=' . rawurlencode($option['code'])],
            $lang->options($lang->code())
        ),
        'lang_code' => $lang->code(),
        'mode' => $mode,
        'styles_version' => (string) (filemtime($root . '/assets/css/styles.css') ?: 0),
        'install_js_version' => (string) (@filemtime($root . '/assets/js/install.js') ?: 0),
    ]);
    exit;
};

// Unerwartete Fehler (z. B. eine veraltete oder fehlende Datei nach dem Hochladen) als Meldung statt einer leeren Seite
// mit Status 500. Bei der Installation mit Details; beim Update nur allgemein, dort ist noch niemand angemeldet.
set_exception_handler(static function (Throwable $exception) use ($render, $lang): void {
    error_log('Installer: ' . $exception);
    http_response_code(500);
    $error = $exception::class . ': ' . $exception->getMessage() . ' (' . basename($exception->getFile()) . ':' . $exception->getLine() . ')';
    $text = is_file(Installer::CONFIG) ? $lang->t('install.error_unexpected_update') : $lang->t('install.error_unexpected', ['error' => $error]);
    // Fehlt der Text (z. B. alte Sprachdateien nach einem unvollständigen Upload), die Meldung trotzdem zeigen.
    if ($text === 'install.error_unexpected')
    {
        $text = 'Unexpected error: ' . $error;
    }
    $render(['show_message' => true, 'message_title' => $lang->t('install.title'), 'message_error' => $text]);
});

// =====================================================================================================================
// Update: config/config.php existiert.
// =====================================================================================================================
if (is_file(Installer::CONFIG))
{
    $config = [];
    require Installer::CONFIG;
    Http::configure((array) ($config['security']['trusted_proxies'] ?? []));

    try
    {
        $db = new Database($config['db'], (array) ($config['sourcemod'] ?? []));
    }
    catch (PDOException|RuntimeException $exception)
    {
        // Ohne Anmeldung keine Details der Verbindung zeigen.
        error_log('Update: ' . $exception->getMessage());
        $render(['show_message' => true, 'message_title' => $lang->t('install.update_title'),
            'message_error' => $lang->t('install.update_error_connect')]);
    }
    if (!Installer::isInstalled($db))
    {
        $render(['show_message' => true, 'message_title' => $lang->t('install.update_title'),
            'message_error' => $lang->t('install.update_error_not_installed')]);
    }
    $settings = new Settings($db);
    $versions = [
        'from_version' => $settings->get('version'),
        'from_db' => Updater::installedVersion($db),
        'to_version' => Version::APP,
        'to_db' => Version::DB,
    ];
    if ($versions['from_db'] > Version::DB)
    {
        $render(['show_message' => true, 'message_title' => $lang->t('install.update_title'),
            'message_error' => $lang->t('install.update_error_newer', $versions)]);
    }
    if (!Updater::needed($db))
    {
        $render(['show_message' => true, 'message_title' => $lang->t('install.current_title'),
            'message_text' => $lang->t('install.current_text', $versions), 'show_delete' => true]);
    }

    $errors = [];
    $username = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
    {
        $username = trim((string) ($_POST['owner_username'] ?? ''));
        $password = (string) ($_POST['owner_password'] ?? '');
        $rateLimiter = new RateLimiter($db, (string) ($config['security']['master_key'] ?? ''));
        $clientIp = Http::clientIp();

        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? '')))
        {
            $errors[] = $lang->t('errors.csrf_text');
        }
        elseif ($rateLimiter->tooMany('update_ip', $clientIp, 10, 900))
        {
            $errors[] = $lang->t('install.update_error_rate');
        }
        else
        {
            // Nur der Owner darf das Update starten.
            $stmt = $db->pdo()->prepare('SELECT password_hash FROM ' . $db->table('users') . ' WHERE username = :username AND is_owner = 1');
            $stmt->execute(['username' => $username]);
            $hash = $stmt->fetchColumn();
            if (!Auth::verifyHash($password, is_string($hash) ? $hash : Auth::DUMMY_HASH) || !is_string($hash))
            {
                $rateLimiter->hit('update_ip', $clientIp);
                $errors[] = $lang->t('install.update_error_login');
            }
            else
            {
                $rateLimiter->clear('update_ip', $clientIp);
            }
        }

        if ($errors === [])
        {
            try
            {
                $steps = Updater::run($db);
                unset($_SESSION['install_csrf']);
                $render([
                    'show_update_done' => true,
                    'update_done_text' => $lang->t('install.update_done_text', $versions),
                    'has_steps' => $steps !== [],
                    'update_steps' => $lang->t('install.update_done_steps', ['steps' => implode(', ', $steps)]),
                ]);
            }
            catch (PDOException|RuntimeException $exception)
            {
                error_log('Update: ' . $exception->getMessage());
                $errors[] = $lang->t('install.update_error_failed', ['error' => $exception->getMessage()]);
            }
        }
    }

    $render([
        'show_update' => true,
        'update_versions' => $lang->t('install.update_versions', $versions),
        'has_errors' => $errors !== [],
        'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
        'owner_username' => $username,
    ]);
}

// =====================================================================================================================
// Neuinstallation oder Übernahme von SMWA 2.x.
// =====================================================================================================================
$isMigrate = $mode === 'migrate';
$requirements = Installer::requirements();
$canInstall = array_filter($requirements, static fn (array $r): bool => $r['required'] && !$r['ok']) === [];

$form = [
    // Bei der Übernahme ein anderes Präfix als das der alten Tabellen (smwa_…) vorschlagen.
    'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_prefix' => $isMigrate ? 'smwa3_' : 'smwa_',
    'old_database' => '', 'old_prefix' => 'smwa',
    'sm_database' => '', 'sm_prefix' => 'sm_',
    'site_title' => 'SourceMod Web Admin', 'default_language' => $lang->code(),
    'owner_username' => '', 'owner_email' => '',
];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canInstall)
{
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? '')))
    {
        $errors[] = $lang->t('errors.csrf_text');
    }
    foreach (array_keys($form) as $field)
    {
        $form[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $dbPassword = (string) ($_POST['db_password'] ?? '');
    $ownerPassword = (string) ($_POST['owner_password'] ?? '');
    $ownerRepeat = (string) ($_POST['owner_password_repeat'] ?? '');
    // Präfix des alten SMWA wie in seiner config ($table = "smwa"). Das alte SMWA hängt "_" an: aus "smwa" wird smwa_users,
    // aus "smwa_" wird smwa__users. Wer das "_" schon mitschreibt, meint oft trotzdem smwa_users, daher beide versuchen.
    $oldPrefixes = array_unique([$form['old_prefix'] . '_', rtrim($form['old_prefix'], '_') . '_']);

    $name = '/^[A-Za-z0-9_\-]{1,64}$/';
    $prefix = '/^[A-Za-z0-9_]{0,20}$/';
    if (preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $form['db_host']) !== 1)
    {
        $errors[] = $lang->t('install.error_db_host');
    }
    $port = filter_var($form['db_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false)
    {
        $errors[] = $lang->t('install.error_db_port');
    }
    if (preg_match($name, $form['db_name']) !== 1)
    {
        $errors[] = $lang->t('install.error_db_name');
    }
    if ($form['db_user'] === '' || mb_strlen($form['db_user']) > 80)
    {
        $errors[] = $lang->t('install.error_db_user');
    }
    if (preg_match($prefix, $form['db_prefix']) !== 1 || preg_match($prefix, $form['sm_prefix']) !== 1
        || ($form['sm_database'] !== '' && preg_match($name, $form['sm_database']) !== 1))
    {
        $errors[] = $lang->t('install.error_prefix');
    }
    if ($isMigrate)
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/', $form['old_prefix']) !== 1
            || ($form['old_database'] !== '' && preg_match($name, $form['old_database']) !== 1))
        {
            $errors[] = $lang->t('install.error_old_smwa');
        }
    }
    if ($form['site_title'] === '' || mb_strlen($form['site_title']) > 80)
    {
        $errors[] = $lang->t('settings.error_title');
    }
    if (!$lang->isAvailable($form['default_language']))
    {
        $errors[] = $lang->t('settings.error_language');
    }
    // Bei der Übernahme kommt der Owner aus dem alten SMWA.
    if (!$isMigrate)
    {
        if (preg_match('/^[\p{L}\p{N}_.\- ]{2,30}$/u', $form['owner_username']) !== 1)
        {
            $errors[] = $lang->t('users.error_username');
        }
        if (filter_var($form['owner_email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($form['owner_email']) > 150)
        {
            $errors[] = $lang->t('users.error_email');
        }
        if (mb_strlen($ownerPassword) < 8)
        {
            $errors[] = $lang->t('users.error_password_length');
        }
        elseif ($ownerPassword !== $ownerRepeat)
        {
            $errors[] = $lang->t('users.error_password_repeat');
        }
    }

    $db = null;
    if ($errors === [])
    {
        $connect = static fn (string $password): Database => new Database(
            ['host' => $form['db_host'], 'port' => (int) $port, 'database' => $form['db_name'],
                'username' => $form['db_user'], 'password' => $password, 'prefix' => $form['db_prefix']],
            ['database' => $form['sm_database'], 'prefix' => $form['sm_prefix']]
        );
        try
        {
            $db = $connect($dbPassword);
        }
        catch (PDOException $exception)
        {
            // Leerzeichen am Rand sind ein häufiger, im Feld unsichtbarer Kopierfehler: ohne sie noch einmal versuchen.
            // Klappt das, gilt dieses Passwort (auch für config.php).
            if (trim($dbPassword) !== $dbPassword)
            {
                try
                {
                    $db = $connect(trim($dbPassword));
                    $dbPassword = trim($dbPassword);
                }
                catch (PDOException)
                {
                    $db = null;
                }
            }
            if ($db === null)
            {
                $errors[] = $lang->t('install.error_connect', ['error' => $exception->getMessage()]);
            }
        }
    }
    // Liegen mit diesem Präfix noch Tabellen von SMWA 2.x in der Datenbank (z. B. smwa_users), ließe
    // CREATE TABLE IF NOT EXISTS sie stehen. Gibt es sie nicht (mehr), ist das Präfix frei, auch "smwa_".
    $legacyTables = $db !== null && $errors === [] ? Installer::legacyTables($db) : [];
    if ($legacyTables !== [])
    {
        $errors[] = $lang->t('install.error_migrate_prefix', ['prefix' => $form['db_prefix'], 'tables' => implode(', ', $legacyTables)]);
    }
    elseif ($db !== null && $errors === [] && Installer::isInstalled($db))
    {
        $errors[] = $lang->t('install.error_installed', ['prefix' => $form['db_prefix']]);
    }

    $import = null;
    $oldVersion = null;
    if ($db !== null && $errors === [] && $isMigrate)
    {
        foreach ($oldPrefixes as $oldPrefix)
        {
            $import = new Smwa2Import($db, $form['old_database'], $oldPrefix);
            $oldVersion = $import->version();
            if ($oldVersion !== null)
            {
                break;
            }
        }
        if ($oldVersion === null)
        {
            $import = null;
            $errors[] = $lang->t('install.error_migrate_not_found', [
                'prefix' => $oldPrefixes[0],
                'database' => $form['old_database'] !== '' ? $form['old_database'] : $form['db_name'],
            ]);
        }
    }

    $masterKey = Installer::newMasterKey();
    $migrated = [];
    if ($db !== null && $errors === [])
    {
        // Tabellen zuerst (DDL beendet in MySQL jede Transaktion), die Daten dann in einer Transaktion: Schlägt etwas
        // fehl, bleibt die Benutzertabelle leer und die Installation lässt sich wiederholen.
        try
        {
            Installer::importSchema($db, $form['db_prefix']);
            $db->pdo()->beginTransaction();
            Installer::saveSettings($db, $form['site_title'], $form['default_language']);
            if ($import !== null)
            {
                $users = $import->users(array_keys($lang->available()));
                $settingsCount = $import->settings(new Settings($db));
                $servers = $import->servers(new SecretCipher($masterKey));
                $games = $import->games();
            }
            else
            {
                Installer::createOwner($db, $form['owner_username'], $form['owner_email'], $ownerPassword, $form['default_language']);
            }
            $db->pdo()->commit();
        }
        catch (PDOException|Smwa2ImportException $exception)
        {
            if ($db->pdo()->inTransaction())
            {
                $db->pdo()->rollBack();
            }
            error_log('Installer: ' . $exception->getMessage());
            $errors[] = $exception instanceof Smwa2ImportException
                ? $lang->t('install.error_migrate_' . $exception->getMessage())
                : $lang->t('install.error_install', ['error' => $exception->getMessage()]);
        }

        if ($import !== null && $errors === [])
        {
            $owner = array_values(array_filter($users, static fn (array $user): bool => $user['owner']))[0]['name'];
            $countImported = static fn (array $rows): int => count(array_filter($rows, static fn (array $row): bool => $row['imported']));
            $migrated[] = $lang->t('install.done_migrated_users', ['count' => count($users), 'owner' => $owner]);
            $migrated[] = $lang->t('install.done_migrated_servers', ['count' => $countImported($servers)]);
            $migrated[] = $lang->t('install.done_migrated_games', ['count' => $countImported($games),
                'skipped' => count($games) - $countImported($games)]);
            $migrated[] = $lang->t('install.done_migrated_settings', ['count' => $settingsCount]);
            $noPassword = array_column(array_filter($users, static fn (array $user): bool => !$user['password']), 'name');
            if ($noPassword !== [])
            {
                $migrated[] = $lang->t('install.done_migrated_no_password', ['users' => implode(', ', $noPassword)]);
            }
        }
    }

    if ($db !== null && $errors === [])
    {
        $config = Installer::configFile(
            ['host' => $form['db_host'], 'port' => (int) $port, 'database' => $form['db_name'],
                'username' => $form['db_user'], 'password' => $dbPassword, 'prefix' => $form['db_prefix']],
            ['database' => $form['sm_database'], 'prefix' => $form['sm_prefix']],
            $masterKey
        );
        $written = Installer::writeConfig($config);
        // Sprach-Cookie der Oberfläche (index.php, LANG_COOKIE) auf die gewählte Sprache setzen, damit ein älteres
        // Cookie (z. B. "en") nicht vor der Standardsprache gilt; Pfad wie dort "/".
        setcookie('smwa_lang', $form['default_language'], [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $missing = (new SourceMod($db))->missingTables();
        unset($_SESSION['install_csrf']);
        $render([
            'show_done' => true,
            'done_text' => $isMigrate ? $lang->t('install.done_text_migrate') : $lang->t('install.done_text'),
            'is_migrate' => $isMigrate,
            'migrated_title' => $lang->t('install.done_migrated', ['version' => (string) $oldVersion]),
            'migrated' => array_map(static fn (string $text): array => ['text' => $text], $migrated),
            'config_written' => $written,
            'config_manual' => !$written,
            'config_content' => $written ? '' : $config,
            'has_sm_missing' => $missing !== [],
            'sm_missing' => $lang->t('install.done_sm_missing', ['tables' => implode(', ', $missing)]),
        ]);
    }
}

$render([
    'show_form' => true,
    'is_install' => !$isMigrate,
    'is_migrate' => $isMigrate,
    'mode_install_url' => '?mode=install&lang=' . rawurlencode($lang->code()),
    'mode_migrate_url' => '?mode=migrate&lang=' . rawurlencode($lang->code()),
    'recheck_url' => '?mode=' . $mode . '&lang=' . rawurlencode($lang->code()),
    'requirements' => array_map(static fn (array $r): array => $r + [
        'label' => $lang->t('install.req_' . $r['key']),
        'is_missing_required' => !$r['ok'] && $r['required'],
        'is_missing_optional' => !$r['ok'] && !$r['required'],
        'has_detail' => $r['detail'] !== '',
    ], $requirements),
    'can_install' => $canInstall,
    'cannot_install' => !$canInstall,
    'has_errors' => $errors !== [],
    'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
    'form' => $form,
    'language_options' => $lang->options($form['default_language']),
]);
