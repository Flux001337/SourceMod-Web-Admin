<?php
declare(strict_types=1);

// RCON-Konsole (Recht "console"): Befehle an einen Server senden und die Antworten untereinander anzeigen. Nur Server
// mit RCON-Passwort stehen zur Auswahl. Der Verlauf liegt je Server in der Sitzung (kein Live-Log, RCON liefert nur die
// Antwort auf den jeweiligen Befehl); er lässt sich als Textdatei herunterladen und leeren.

if (!$auth->hasPermission('console'))
{
    return $forbidden();
}

// Höchstens so viele Einträge je Server und so lange Befehle (die Source-Konsole nimmt bis 511 Zeichen). Gespeicherte
// Antworten werden gekürzt, damit die Sitzung klein bleibt (sie wird bei jedem Aufruf geladen); direkt nach dem Senden
// kommt die Antwort vollständig.
$maxEntries = 100;
$maxCommand = 511;
$maxStoredResponse = 16000;

$timeout = $settings->int('server_query_timeout', 1, 10);
$servers = [];
// Spielordner: zugewiesenes Game vor dem zuletzt erkannten (für "games" der Konsole-Buttons).
foreach ($pdo->query(
    'SELECT s.id, s.name, s.host, s.port, s.rcon_password, COALESCE(g.folder, s.detected_folder) AS game_folder FROM '
    . $db->table('servers') . ' s LEFT JOIN ' . $db->table('games') . ' g ON g.id = s.game_id'
    . " WHERE s.rcon_password IS NOT NULL AND s.rcon_password <> '' ORDER BY s.name ASC, s.id ASC"
) as $row)
{
    $servers[(int) $row['id']] = $row;
}
$serverId = (int) ($_GET['server'] ?? $_POST['server'] ?? 0);
$server = $servers[$serverId] ?? null;
if ($server === null)
{
    $serverId = 0;
}
$action = (string) ($_GET['action'] ?? '');
$pageUrl = 'index.php?' . http_build_query(['section' => 'console', 'server' => $serverId]);
// Antwort als JSON statt Weiterleitung (Senden ohne Neuladen, assets/js/app.js).
$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

$_SESSION['console_log'] ??= [];
$log = &$_SESSION['console_log'];

// Eintrag für Template und JSON: Zeit, Befehl, Antwort, Fehler.
$entryView = static fn (array $entry): array => [
    'time' => $entry['time'],
    'command' => $entry['command'],
    'response' => $entry['response'],
    'error' => $entry['error'],
    'has_response' => $entry['response'] !== '',
];

// RCON zum gewählten Server: ['response' => string|null, 'error' => string|null] (Fehler unübersetzt bzw. Entschlüsselung).
$rcon = static function (string $command) use (&$server, $secrets, $timeout, $lang): array {
    try
    {
        $password = $secrets->decrypt((string) $server['rcon_password']);
    }
    catch (RuntimeException)
    {
        return ['response' => null, 'error' => $lang->t('servers.test_decrypt_failed')];
    }

    return ServerQuery::rcon((string) $server['host'], (int) $server['port'], $password, $command, $timeout);
};

// Befehls-Buttons aus console/*.json (app/ConsoleButtons.php), nur die für das Game des Servers.
$consoleButtons = new ConsoleButtons(dirname(__DIR__) . '/console', $lang->code());
$groups = $server !== null ? $consoleButtons->groupsFor((string) $server['game_folder']) : [];

// --- Laufende Plugins für "requires" (JSON, app.js blendet die Gruppen danach ein) ----------------------------------
if ($action === 'requires' && $server !== null)
{
    session_write_close();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    // Mehrere Abfragen je RCON-Befehl; beim ersten Fehler (Server antwortet nicht) abbrechen.
    $response = '';
    $error = null;
    foreach (ConsoleButtons::requiresCommands($groups) as $command)
    {
        $result = $rcon($command);
        if ($result['error'] !== null)
        {
            $error = $result['error'];
            break;
        }
        $response .= $result['response'] . "\n";
    }
    $running = $error === null ? ConsoleButtons::runningPlugins($response) : [];
    $available = [];
    foreach ($groups as $group)
    {
        if ($group['requires'] !== [])
        {
            $available[$group['file']] = array_intersect($group['requires'], $running) !== [];
        }
    }
    // Ohne Antwort lässt sich nichts prüfen: app.js zeigt dann alle Gruppen.
    echo json_encode(['available' => $available, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// --- Spieler aus "status" (JSON, Vorschläge für Felder mit "suggest") ----------------------------------------------
if ($action === 'players' && $server !== null)
{
    session_write_close();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $result = $rcon('status');
    echo json_encode([
        'players' => $result['error'] === null ? ConsoleButtons::statusPlayers((string) $result['response']) : [],
        'error' => $result['error'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// --- Befehl senden -------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['send_command']))
{
    $command = trim((string) ($_POST['command'] ?? ''));
    $error = null;
    if ($server === null)
    {
        $error = $lang->t('console.select_server_first');
    }
    elseif ($command === '')
    {
        $error = $lang->t('console.error_empty');
    }
    elseif (mb_strlen($command) > $maxCommand || preg_match('/[\x00-\x1F\x7F]/', $command) === 1)
    {
        // Keine Steuerzeichen: Ein Zeilenumbruch würde auf dem Server einen zweiten Befehl ausführen.
        $error = $lang->t('console.error_invalid', ['max' => $maxCommand]);
    }

    $entry = null;
    if ($error === null)
    {
        $result = $rcon($command);
        $entry = [
            'time' => date('H:i:s'),
            'command' => $command,
            // Pakete langer Antworten enthalten NUL-Bytes an den Grenzen; ungültiges UTF-8 wird ersetzt.
            'response' => $result['error'] !== null
                ? $lang->t('console.error_rcon', ['error' => $result['error']])
                : mb_scrub(rtrim(str_replace("\0", '', (string) $result['response'])), 'UTF-8'),
            'error' => $result['error'] !== null,
        ];
        $stored = $entry;
        if (mb_strlen($stored['response']) > $maxStoredResponse)
        {
            $stored['response'] = mb_substr($stored['response'], 0, $maxStoredResponse) . "\n" . $lang->t('console.truncated');
        }
        $log[$serverId][] = $stored;
        $log[$serverId] = array_slice($log[$serverId], -$maxEntries);
    }

    if ($wantsJson)
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(
            ['entry' => $entry !== null ? $entryView($entry) : null, 'error' => $error],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
    if ($error !== null)
    {
        Flash::set('error', $error);
    }
    $redirect($pageUrl);
}

// --- Konsole leeren ------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['clear_console']) && $server !== null)
{
    unset($log[$serverId]);
    Flash::set('success', $lang->t('console.cleared'));
    $redirect($pageUrl);
}

// --- Log herunterladen ---------------------------------------------------------------------------------------------
if ($action === 'download' && $server !== null)
{
    $lines = [];
    foreach ($log[$serverId] ?? [] as $entry)
    {
        $lines[] = '[' . $entry['time'] . '] > ' . $entry['command'];
        if ($entry['response'] !== '')
        {
            $lines[] = $entry['response'];
        }
        $lines[] = '';
    }
    $filename = 'console-' . (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $server['name']) ?: 'server') . '-' . date('Y-m-d-His') . '.txt';
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo implode("\n", $lines);
    exit;
}

// --- Status (JSON für die Pille im Kopf der Konsole) ---------------------------------------------------------------
if ($action === 'status')
{
    // Die Abfrage kann bis zum Timeout dauern; ohne Session-Sperre bleibt die Seite bedienbar.
    session_write_close();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $info = $server !== null ? ServerQuery::info((string) $server['host'], (int) $server['port'], $timeout) : null;
    echo json_encode([
        'online' => $info !== null,
        'status_label' => $lang->t($info !== null ? 'servers.online' : 'servers.offline'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$entries = array_map($entryView, $log[$serverId] ?? []);

// Die Angaben je Button gehen als JSON an app.js. Gruppen mit "requires" bleiben ausgeblendet, bis app.js die Plugins
// geprüft hat.
$openGroup = (string) ($_GET['open'] ?? '');
$buttonGroups = array_map(static fn (array $group): array => [
    'name' => $group['name'],
    'description' => $group['description'],
    'file' => $group['file'],
    'has_requires' => $group['requires'] !== [],
    // Aus der Plugin-Liste (open=<datei>): diese Gruppe aufklappen und in die Spalte scrollen (app.js).
    'open' => $group['open'] || $group['file'] === $openGroup,
    'focus' => $group['file'] === $openGroup,
    'buttons' => array_map(static fn (array $button): array => [
        'label' => $button['label'],
        'title' => $button['description'] !== '' ? $button['description'] : $button['command'],
        'spec' => json_encode([
            'label' => $button['label'],
            'command' => $button['command'],
            'confirm' => $button['confirm'],
            'underscore' => $button['underscore'],
            'args' => $button['args'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], $group['buttons']),
], $groups);
$buttonErrors = array_map(static fn (string $error): array => ['text' => $error], $consoleButtons->errors());

return [
    'title' => $lang->t('nav.console'),
    'content' => $template->render('pages/console.html', [
        'has_servers' => $servers !== [],
        'has_no_servers' => $servers === [],
        'can_manage_servers' => $auth->hasPermission('servers'),
        // Eine per "open" geöffnete Gruppe (z. B. aus der Plugin-Liste) bleibt beim Wechsel des Servers offen.
        'server_tabs' => array_map(static fn (array $row): array => [
            'name' => $row['name'],
            'url' => 'index.php?' . http_build_query(['section' => 'console', 'server' => (int) $row['id']]
                + ($openGroup !== '' ? ['open' => $openGroup] : [])),
            'active' => (int) $row['id'] === $serverId,
        ], array_values($servers)),
        'has_server' => $server !== null,
        'has_no_selection' => $servers !== [] && $server === null,
        'server_id' => $serverId,
        'console_title' => $server !== null ? $lang->t('console.title', ['server' => $server['name']]) : '',
        'status_url' => 'index.php?' . http_build_query(['section' => 'console', 'server' => $serverId, 'action' => 'status']),
        'download_url' => 'index.php?' . http_build_query(['section' => 'console', 'server' => $serverId, 'action' => 'download']),
        'page_url' => $pageUrl,
        'entries' => $entries,
        'has_entries' => $entries !== [],
        'max_command' => $maxCommand,
        'button_groups' => $buttonGroups,
        'requires_url' => 'index.php?' . http_build_query(['section' => 'console', 'server' => $serverId, 'action' => 'requires']),
        'players_url' => 'index.php?' . http_build_query(['section' => 'console', 'server' => $serverId, 'action' => 'players']),
        'has_buttons' => $buttonGroups !== [],
        'button_errors' => $buttonErrors,
        'has_button_errors' => $buttonErrors !== [],
    ]),
];
