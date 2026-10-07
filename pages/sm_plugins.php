<?php
declare(strict_types=1);

// SourceMod-Plugins (section=sm_plugins) und -Extensions (section=sm_extensions) eines Servers per RCON (Recht
// "plugincontrol"): Liste mit Suche und Filtern, aufklappbare Details, neu laden, entladen, hier entladene wieder laden,
// bei Plugins "Plugins aktualisieren". Altes SMWA: plugincontrol.php. Nur Server mit RCON-Passwort stehen zur Auswahl.

if (!$auth->hasPermission('plugincontrol'))
{
    return $forbidden();
}

// Was Plugins und Extensions unterscheidet; Texte stehen in lang/*.json unter "sm_plugins" bzw. "sm_extensions".
$kind = $section === 'sm_extensions' ? [
    'section' => 'sm_extensions',
    'command' => 'sm exts',
    'parse' => SourceModPlugins::parseExtensionList(...),
    'is_file' => SourceModPlugins::isExtensionFile(...),
    'file_key' => 'File',
    'name_key' => 'Name',
    'extra' => 'description',
    'search' => ['name', 'version', 'description', 'file'],
    'can_hide_am' => false,
    'can_refresh' => false,
    'has_cvars' => false,
    // Neu laden entlädt auch alles, was von der Extension abhängt.
    'confirm_reload' => true,
] : [
    'section' => 'sm_plugins',
    'command' => 'sm plugins',
    'parse' => SourceModPlugins::parseList(...),
    'is_file' => SourceModPlugins::isPluginFile(...),
    'file_key' => 'Filename',
    'name_key' => 'Title',
    'extra' => 'author',
    'search' => ['name', 'author', 'version', 'file'],
    'can_hide_am' => true,
    'can_refresh' => true,
    // "sm cvars <id>": ConVars des Plugins mit aktuellem Wert (nur lesen).
    'has_cvars' => true,
    'confirm_reload' => false,
];
$sectionName = $kind['section'];
$text = static fn (string $key, array $replace = []): string => $lang->t($sectionName . '.' . $key, $replace);

$timeout = $settings->int('server_query_timeout', 1, 10);
$servers = [];
// Spielordner (zugewiesenes Game vor dem erkannten) für die Konsole-Buttons ("games" in console/*.json).
foreach ($pdo->query(
    'SELECT s.id, s.name, s.host, s.port, s.rcon_password, COALESCE(g.folder, s.detected_folder) AS game_folder FROM '
    . $db->table('servers') . ' s LEFT JOIN ' . $db->table('games') . ' g ON g.id = s.game_id'
    . " WHERE s.rcon_password IS NOT NULL AND s.rcon_password <> '' ORDER BY s.name ASC, s.id ASC"
) as $row)
{
    $servers[(int) $row['id']] = $row;
}
// Ohne Auswahl bleibt die Seite leer ("Bitte auswählen"), es wird kein Server abgefragt.
$serverId = (int) ($_GET['server'] ?? $_POST['server'] ?? 0);
$server = $servers[$serverId] ?? null;
if ($server === null)
{
    $serverId = 0;
}
$action = (string) ($_GET['action'] ?? '');

// Filter der Liste; sie bleiben in allen Links und nach Aktionen erhalten.
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    // Standard: AlliedModders-Plugins ausgeblendet; abgewählt schickt das Formular hide_am=0 (verstecktes Feld).
    'hide_am' => $kind['can_hide_am'] && (string) ($_GET['hide_am'] ?? '1') !== '0',
    'only_failed' => isset($_GET['only_failed']),
];
// Sortierung per Klick auf die Spaltenköpfe; Standard ist die Reihenfolge von SourceMod (ID aufsteigend).
$sortColumns = ['id', 'status', 'name', 'version', 'extra'];
$sort = [
    'by' => in_array($_GET['sort'] ?? '', $sortColumns, true) ? (string) $_GET['sort'] : 'id',
    'desc' => ($_GET['dir'] ?? '') === 'desc',
];
$listUrl = static function (array $extra = []) use (&$serverId, $filters, $kind, $sort): string {
    $params = ['section' => $kind['section'], 'server' => $serverId];
    if ($filters['q'] !== '')
    {
        $params['q'] = $filters['q'];
    }
    if ($kind['can_hide_am'] && !$filters['hide_am'])
    {
        $params['hide_am'] = 0;
    }
    if ($filters['only_failed'])
    {
        $params['only_failed'] = 1;
    }
    if ($sort['by'] !== 'id')
    {
        $params['sort'] = $sort['by'];
    }
    if ($sort['desc'])
    {
        $params['dir'] = 'desc';
    }

    // null in $extra nimmt einen Parameter heraus (http_build_query überspringt ihn).
    return 'index.php?' . http_build_query([...$params, ...$extra]);
};

// RCON zum gewählten Server: ['response' => string|null, 'error' => Fehlertext|null].
$rcon = static function (string $command) use (&$server, $secrets, $timeout, $lang): array {
    try
    {
        $password = $secrets->decrypt((string) $server['rcon_password']);
    }
    catch (RuntimeException)
    {
        return ['response' => null, 'error' => $lang->t('servers.test_decrypt_failed')];
    }
    $result = ServerQuery::rcon((string) $server['host'], (int) $server['port'], $password, $command, $timeout);
    if ($result['error'] !== null)
    {
        return ['response' => null, 'error' => $lang->t('sm_plugins.error_rcon', ['error' => $result['error']])];
    }
    if (SourceModPlugins::isMissing((string) $result['response']))
    {
        return ['response' => null, 'error' => $lang->t('sm_plugins.error_sm_missing')];
    }

    return ['response' => (string) $result['response'], 'error' => null];
};

// Liste vom Server: ['items' => list, 'error' => ?string].
$loadItems = static function () use ($rcon, $kind): array {
    $result = $rcon($kind['command'] . ' list');

    return [
        'items' => $result['error'] === null ? ($kind['parse'])((string) $result['response']) : [],
        'error' => $result['error'],
    ];
};
$findItem = static function (array $items, int $id): ?array {
    foreach ($items as $item)
    {
        if ($item['id'] === $id)
        {
            return $item;
        }
    }

    return null;
};
// Antwort von SourceMod für eine Meldung: eine Zeile, ohne NUL-Bytes, gekürzt.
$responseText = static fn (?string $response): string => mb_substr(
    trim((string) preg_replace('/\s+/', ' ', str_replace("\0", '', (string) $response))),
    0,
    300
);

// Nach einer Aktion zurück zur Liste mit denselben Filtern und derselben Seite.
$returnUrl = static function () use ($listUrl, $kind): string {
    $return = (string) ($_POST['return'] ?? '');

    return str_starts_with($return, 'index.php?section=' . $kind['section'] . '&') ? $return : $listUrl();
};
// Meldung zu einer Antwort von SourceMod (oder dem Fehler der Abfrage), sonst $fallback.
$flashResult = static function (array $result, string $fallback) use ($responseText): void {
    if ($result['error'] !== null)
    {
        Flash::set('error', $result['error']);
        return;
    }
    $response = $responseText($result['response']);
    Flash::set('success', $response !== '' ? $response : $fallback);
};

// Hier entladene Plugins/Extensions je Server (Dateiname => Name), damit sie sich bis zum Mapwechsel wieder laden lassen.
$_SESSION['sm_unloaded'][$sectionName] ??= [];
$unloadedStore = &$_SESSION['sm_unloaded'][$sectionName];
// Extensions: Entladen, das SourceMod erst nach Bestätigung ausführt (andere hängen davon ab).
$_SESSION['sm_exts_pending'] ??= [];
$pendingStore = &$_SESSION['sm_exts_pending'];

// Entlädt das Element und merkt sich die Datei; $code ist die Bestätigung von SourceMod (Extensions).
$unload = static function (array $item, ?int $code) use ($rcon, $kind, $flashResult, $text, &$unloadedStore, &$pendingStore, $serverId): void {
    // Den Dateinamen vor dem Entladen holen: danach kennt SourceMod das Element nicht mehr.
    $file = $item['file'];
    if ($file === '')
    {
        $info = $rcon($kind['command'] . ' info ' . $item['id']);
        $file = SourceModPlugins::parseInfo((string) ($info['response'] ?? ''))[$kind['file_key']] ?? '';
    }
    $result = $rcon($kind['command'] . ' unload ' . $item['id'] . ($code !== null ? ' ' . $code : ''));
    unset($pendingStore[$serverId]);

    $response = (string) ($result['response'] ?? '');
    $verifyCode = $kind['section'] === 'sm_extensions' && $code === null ? SourceModPlugins::unloadCode($response, $item['id']) : null;
    if ($result['error'] === null && $verifyCode !== null)
    {
        // SourceMod nennt, was mit entladen würde, und wartet auf die Bestätigung mit dem Code.
        $pendingStore[$serverId] = [
            'id' => $item['id'],
            'name' => $item['name'],
            'code' => $verifyCode,
            'file' => $file,
            'text' => trim(implode("\n", array_filter(
                SourceModPlugins::lines($response),
                static fn (string $line): bool => trim($line) !== '' && !str_contains($line, 'To verify unloading')
            ))),
        ];
        Flash::set('error', $text('confirm_needed', ['name' => $item['name']]));
        return;
    }
    $flashResult($result, $text('unloaded', ['name' => $item['name']]));
    if ($result['error'] === null && ($kind['is_file'])($file))
    {
        $unloadedStore[$serverId][$file] = $item['name'];
    }
};

// Aktionen brauchen einen gewählten Server (z. B. "Plugins aktualisieren", bevor einer ausgewählt ist).
$actions = ['reload_item', 'unload_item', 'confirm_unload', 'cancel_unload', 'load_item', 'refresh_items'];
if ($isPost && $server === null && array_intersect($actions, array_keys($_POST)) !== [])
{
    Flash::set('error', $text('select_server_first'));
    $redirect($returnUrl());
}

// --- Neu laden / entladen ------------------------------------------------------------------------------------------
if ($isPost && (isset($_POST['reload_item']) || isset($_POST['unload_item'])) && $server !== null)
{
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $itemName = (string) ($_POST['item_name'] ?? '');

    // Die IDs ändern sich, wenn etwas geladen oder entladen wird: vorher prüfen, ob unter der ID noch dasselbe steht,
    // damit nie etwas anderes getroffen wird.
    $loaded = $loadItems();
    $item = $findItem($loaded['items'], $itemId);
    if ($loaded['error'] !== null)
    {
        Flash::set('error', $loaded['error']);
    }
    elseif ($item === null || $item['name'] !== $itemName)
    {
        Flash::set('error', $text('error_changed'));
    }
    elseif (isset($_POST['unload_item']))
    {
        $unload($item, null);
    }
    else
    {
        $flashResult($rcon($kind['command'] . ' reload ' . $itemId), $text('reloaded', ['name' => $itemName]));
    }
    $redirect($returnUrl());
}

// --- Entladen bestätigen / abbrechen (Extensions, von denen andere abhängen) ---------------------------------------
if ($isPost && (isset($_POST['confirm_unload']) || isset($_POST['cancel_unload'])) && $server !== null)
{
    $pending = $pendingStore[$serverId] ?? null;
    if (isset($_POST['cancel_unload']) || $pending === null)
    {
        unset($pendingStore[$serverId]);
    }
    else
    {
        $loaded = $loadItems();
        $item = $findItem($loaded['items'], (int) $pending['id']);
        if ($loaded['error'] !== null)
        {
            Flash::set('error', $loaded['error']);
        }
        elseif ($item === null || $item['name'] !== $pending['name'])
        {
            unset($pendingStore[$serverId]);
            Flash::set('error', $text('error_changed'));
        }
        else
        {
            $item['file'] = $item['file'] !== '' ? $item['file'] : (string) $pending['file'];
            $unload($item, (int) $pending['code']);
        }
    }
    $redirect($returnUrl());
}

// --- Wieder laden (nur hier entladene) -----------------------------------------------------------------------------
if ($isPost && isset($_POST['load_item']) && $server !== null)
{
    $file = (string) ($_POST['item_file'] ?? '');
    // Nur Dateien aus der eigenen Liste: so lässt sich über das Formular keine beliebige Datei laden.
    $name = $unloadedStore[$serverId][$file] ?? null;
    if ($name === null || !($kind['is_file'])($file))
    {
        Flash::set('error', $text('not_found'));
    }
    else
    {
        $flashResult($rcon($kind['command'] . ' load ' . $file), $text('loaded', ['name' => $name]));
    }
    $redirect($returnUrl());
}

// --- Plugins aktualisieren (neue und geänderte Plugins aus dem Plugin-Ordner laden) --------------------------------
if ($isPost && isset($_POST['refresh_items']) && $server !== null && $kind['can_refresh'])
{
    $flashResult($rcon($kind['command'] . ' refresh'), $text('refreshed'));
    $redirect($returnUrl());
}

// --- Details und ConVars (JSON für das Aufklappen bzw. den Dialog in der Liste, assets/js/app.js) ------------------
if ($action === 'info' || ($action === 'cvars' && $kind['has_cvars']))
{
    // Die Abfrage kann bis zum Timeout dauern; ohne Session-Sperre bleibt die Seite bedienbar.
    session_write_close();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $itemId = (int) ($_GET['id'] ?? 0);
    $loaded = $server !== null ? $loadItems() : ['items' => [], 'error' => $text('not_found')];
    $item = $findItem($loaded['items'], $itemId);
    $error = $loaded['error'];
    // Wie bei den Aktionen: steht unter der ID inzwischen etwas anderes, nichts Falsches anzeigen.
    if ($error === null && ($item === null || $item['name'] !== (string) ($_GET['name'] ?? '')))
    {
        $error = $text('error_changed');
    }

    $rows = [];
    if ($error === null && $action === 'cvars')
    {
        $result = $rcon('sm cvars ' . $itemId);
        $error = $result['error'];
        foreach ($error === null ? SourceModPlugins::parseCvars((string) $result['response']) : [] as $name => $value)
        {
            $rows[] = ['label' => $name, 'value' => $value, 'link' => false, 'error' => false];
        }
    }
    elseif ($error === null)
    {
        $result = $rcon($kind['command'] . ' info ' . $itemId);
        $error = $result['error'];
        $info = $error === null ? SourceModPlugins::parseInfo((string) $result['response']) : [];
        // Name (Plugins "Title", Extensions "Name") ist "Name (Beschreibung)"; der Name ist aus der Liste bekannt, so
        // bleiben Klammern im Namen erhalten.
        $title = $info[$kind['name_key']] ?? null;
        if ($title !== null && str_starts_with($title, $item['name'] . ' (') && str_ends_with($title, ')'))
        {
            unset($info[$kind['name_key']]);
            $info = ['Title' => $item['name'], 'Description' => substr($title, strlen($item['name']) + 2, -1)] + $info;
        }
        elseif ($title !== null)
        {
            unset($info[$kind['name_key']]);
            $info = ['Title' => $title] + $info;
        }
        // Extensions: "Author: Name (URL)".
        if (isset($info['Author']) && !isset($info['URL']) && preg_match('~^(.*?)\s+\((https?://[^\s)]+)\)$~', $info['Author'], $m) === 1)
        {
            $info['Author'] = $m[1];
            $info['URL'] = $m[2];
        }
        // Extensions: "Method: Loaded by SourceMod" unter "Geladen von".
        if (isset($info['Method']) && str_starts_with($info['Method'], 'Loaded by '))
        {
            $info['Method'] = substr($info['Method'], strlen('Loaded by '));
        }
        $labels = [
            'Title' => 'info_title', 'Description' => 'info_description', 'Filename' => 'info_filename', 'File' => 'info_filename',
            'Author' => 'info_author', 'Version' => 'info_version', 'URL' => 'info_url', 'Status' => 'info_status',
            'Reloads' => 'info_reloads', 'Timestamp' => 'info_timestamp', 'Hash' => 'info_hash',
            'File info' => 'info_file_info', 'File Version' => 'info_version', 'File URL' => 'info_url',
            'Load error' => 'info_load_error', 'Error' => 'info_error',
            'Loaded' => 'info_loaded', 'Binary info' => 'info_binary', 'Method' => 'info_method',
        ];
        foreach ($info as $key => $value)
        {
            $rows[] = [
                'label' => isset($labels[$key]) ? $lang->t('sm_plugins.' . $labels[$key]) : $key,
                'value' => $value,
                'link' => preg_match('~^https?://\S+$~i', $value) === 1,
                // Extensions: "Loaded: No (Fehler)".
                'error' => $key === 'Load error' || $key === 'Error' || ($key === 'Loaded' && str_starts_with($value, 'No')),
            ];
        }
        // Plugins: Anzahl der ConVars; der Link öffnet denselben Dialog wie der Button in der Zeile.
        if ($kind['has_cvars'] && !$item['failed'])
        {
            $cvars = $rcon('sm cvars ' . $itemId);
            if ($cvars['error'] === null)
            {
                $count = count(SourceModPlugins::parseCvars((string) $cvars['response']));
                $rows[] = [
                    'label' => $text('cvars'),
                    'value' => $count > 0 ? $text('cvars_show', ['count' => $count]) : '0',
                    'link' => false,
                    'error' => false,
                    'dialog' => $count > 0 ? 'cvars-item-' . $itemId : null,
                ];
            }
        }
    }

    echo json_encode(['rows' => $rows, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Spaltenköpfe: Klick auf die aktive Spalte dreht die Richtung um, auf eine andere sortiert aufsteigend. Ohne Seite,
// die neue Sortierung beginnt vorn.
$sortLinks = [];
foreach ($sortColumns as $column)
{
    $active = $sort['by'] === $column;
    $desc = $active && !$sort['desc'];
    $sortLinks[$column] = [
        'url' => $listUrl(['sort' => $column !== 'id' ? $column : null, 'dir' => $desc ? 'desc' : null]),
        'active' => $active,
        'aria' => $sort['desc'] ? 'descending' : 'ascending',
        'class' => $active ? ($sort['desc'] ? ' is-desc' : ' is-asc') : '',
    ];
}

$serverOptions = [
    ['value' => '', 'label' => $lang->t('sm_plugins.select_server'), 'selected' => $server === null],
    ...array_map(static fn (array $row): array => [
        'value' => (int) $row['id'],
        'label' => $row['name'],
        'selected' => (int) $row['id'] === $serverId,
    ], array_values($servers)),
];

// --- Liste ---------------------------------------------------------------------------------------------------------
$error = null;
$version = null;
$metamodVersion = null;
$rows = [];
$pagerView = null;
$total = 0;
$failedCount = 0;
$errorCount = 0;
$unloaded = [];
$pending = null;
$pageUrl = $listUrl();
// Status als Text (lang: sm_plugins.status_*), unbekannte so, wie SourceMod sie meldet.
$statusLabel = static function (array $item) use ($lang): string {
    $key = 'sm_plugins.status_' . $item['status'];
    $label = $lang->t($key);

    return $label !== $key ? $label : $item['status'];
};
if ($server !== null)
{
    $loaded = $loadItems();
    $error = $loaded['error'];
    if ($error === null)
    {
        // Metamod:Source und SourceMod in einem RCON-Aufruf.
        $versionResult = $rcon('meta version;sm version');
        $version = SourceModPlugins::version((string) ($versionResult['response'] ?? ''));
        $metamodVersion = SourceModPlugins::metamodVersion((string) ($versionResult['response'] ?? ''));

        $total = count($loaded['items']);
        $failedCount = count(array_filter($loaded['items'], static fn (array $item): bool => $item['failed']));
        $errorCount = count(array_filter($loaded['items'], static fn (array $item): bool => $item['failed'] && $item['status'] !== 'optional'));
        $visible = array_values(array_filter($loaded['items'], static function (array $item) use ($filters, $kind): bool {
            if ($filters['hide_am'] && $item['author'] === SourceModPlugins::ALLIEDMODDERS)
            {
                return false;
            }
            if ($filters['only_failed'] && !$item['failed'])
            {
                return false;
            }
            if ($filters['q'] === '')
            {
                return true;
            }
            foreach ($kind['search'] as $field)
            {
                if (mb_stripos($item[$field], $filters['q']) !== false)
                {
                    return true;
                }
            }

            return ctype_digit($filters['q']) && (int) $filters['q'] === $item['id'];
        }));

        // Vor dem Aufteilen in Seiten sortieren. Status nach dem angezeigten Text; leere Werte (z. B. ohne Version)
        // stehen in beiden Richtungen hinten, gleiche Werte nach ID.
        $sortValue = static fn (array $item): int|string => match ($sort['by']) {
            'id' => $item['id'],
            'status' => $statusLabel($item),
            'extra' => $item[$kind['extra']],
            default => $item[$sort['by']],
        };
        usort($visible, static function (array $a, array $b) use ($sortValue, $sort): int {
            $x = $sortValue($a);
            $y = $sortValue($b);
            if (($x === '') !== ($y === ''))
            {
                return $x === '' ? 1 : -1;
            }
            $result = is_int($x) ? $x <=> $y : strnatcasecmp($x, $y);
            $result = $result !== 0 ? $result : $a['id'] <=> $b['id'];

            return $sort['desc'] ? -$result : $result;
        });

        $pager = new Pager(count($visible), $settings->int('sm_per_page', 5, 200), (int) ($_GET['page'] ?? 1));
        $pagerView = $pager->view($listUrl());
        $pageUrl = $listUrl($pager->page > 1 ? ['page' => $pager->page] : []);

        // Plugins mit Konsole-Buttons (console/*.json über "requires"): Link zur Konsole mit aufgeklappter Gruppe. Die
        // Liste kennt laufende Plugins nur mit Namen; "sm plugins info <datei>" liefert Datei und Titel dazu.
        $consoleGroup = static fn (array $item): ?string => null;
        if ($kind['section'] === 'sm_plugins' && $auth->hasPermission('console'))
        {
            $groups = (new ConsoleButtons(dirname(__DIR__) . '/console', $lang->code()))->groupsFor((string) $server['game_folder']);
            $response = '';
            foreach (ConsoleButtons::requiresCommands($groups) as $command)
            {
                $response .= ($rcon($command)['response'] ?? '') . "\n";
            }
            $groupByTitle = [];
            foreach (ConsoleButtons::pluginInfos($response) as $file => $info)
            {
                foreach ($groups as $group)
                {
                    if (in_array($file, $group['requires'], true))
                    {
                        $groupByTitle[$info['title']] ??= $group['file'];
                    }
                }
            }
            // Titel ist "Name (Beschreibung)" oder nur "Name".
            $consoleGroup = static function (array $item) use ($groupByTitle): ?string {
                foreach ($groupByTitle as $title => $file)
                {
                    if ($item['name'] !== '' && ($title === $item['name'] || str_starts_with($title, $item['name'] . ' (')))
                    {
                        return $file;
                    }
                }

                return null;
            };
        }

        foreach (array_slice($visible, $pager->offset, $pager->perPage) as $item)
        {
            $consoleFile = $item['failed'] ? null : $consoleGroup($item);
            $rows[] = [
                ...$item,
                'extra' => $item[$kind['extra']],
                'status_label' => $statusLabel($item),
                'is_running' => $item['status'] === 'running',
                // Optionale Extensions, die nicht geladen sind: gelb statt rot.
                'is_warning' => $item['status'] === 'optional',
                'is_failed' => $item['failed'] && $item['status'] !== 'optional',
                'is_other' => $item['status'] !== 'running' && !$item['failed'],
                'has_version' => $item['version'] !== '',
                'info_url' => $listUrl(['action' => 'info', 'id' => $item['id'], 'name' => $item['name']]),
                // Nicht geladene Plugins haben keine ConVars.
                'has_cvars' => $kind['has_cvars'] && !$item['failed'],
                'cvars_url' => $listUrl(['action' => 'cvars', 'id' => $item['id'], 'name' => $item['name']]),
                'cvars_title' => $text('cvars_title', ['name' => $item['name']]),
                'has_console' => $consoleFile !== null,
                'console_url' => 'index.php?' . http_build_query(['section' => 'console', 'server' => $serverId, 'open' => (string) $consoleFile]),
                'return_url' => $pageUrl,
                'reload_question' => $text('reload_question', ['name' => $item['name'], 'server' => $server['name']]),
                'unload_question' => $text('unload_question', ['name' => $item['name'], 'server' => $server['name']]),
            ];
        }

        // Hier entladene; was wieder in der Liste steht (neu geladen, Mapwechsel, auch als fehlgeschlagen), fällt heraus.
        foreach ($unloadedStore[$serverId] ?? [] as $file => $name)
        {
            foreach ($loaded['items'] as $item)
            {
                if ($item['name'] === $name || $item['file'] === $file)
                {
                    unset($unloadedStore[$serverId][$file]);
                    continue 2;
                }
            }
            $unloaded[] = ['name' => $name, 'file' => $file, 'return_url' => $pageUrl];
        }

        // Offene Bestätigung zum Entladen einer Extension.
        if ($kind['section'] === 'sm_extensions' && isset($pendingStore[$serverId]))
        {
            $pending = [...$pendingStore[$serverId], 'return_url' => $pageUrl];
        }
    }
}

return [
    'title' => $lang->t('nav.' . $sectionName),
    'content' => $template->render('pages/sm_plugins.html', [
        // Texte der jeweiligen Art (lang: sm_plugins / sm_extensions).
        'k' => $lang->all()[$sectionName] ?? [],
        'section' => $sectionName,
        'can_hide_am' => $kind['can_hide_am'],
        'can_refresh' => $kind['can_refresh'],
        'confirm_reload' => $kind['confirm_reload'],
        'reload_direct' => !$kind['confirm_reload'],
        'server_options' => $serverOptions,
        'server_id' => $serverId,
        'filters' => $filters,
        'sort' => $sortLinks,
        // Für die versteckten Felder im Suchformular; leer beim Standard, so bleibt er aus der Adresse.
        'sort_by' => $sort['by'] !== 'id' ? $sort['by'] : '',
        'sort_dir' => $sort['desc'] ? 'desc' : '',
        'show_list' => true,
        'has_servers' => $servers !== [],
        'has_no_servers' => $servers === [],
        'has_no_selection' => $servers !== [] && $server === null,
        'can_manage_servers' => $auth->hasPermission('servers'),
        'has_error' => $error !== null,
        'error' => (string) $error,
        'has_sm_version' => $version !== null,
        'has_mm_version' => $metamodVersion !== null,
        'mm_version_text' => $lang->t('sm_plugins.mm_version', ['version' => (string) $metamodVersion]),
        'version_text' => $lang->t('sm_plugins.sm_version', ['version' => (string) $version]),
        'summary' => $text($failedCount > 0 ? 'summary_failed' : 'summary', ['total' => $total, 'failed' => $failedCount]),
        'has_failed' => $failedCount > 0,
        // Anzahl hinter der SourceMod-Pille: rot mit Fehlern, sonst türkis (auch wenn nur optionale Extensions fehlen).
        'summary_class' => $errorCount > 0 ? 'text-danger' : 'text-teal',
        'has_no_filters' => $filters['q'] === '' && ($filters['hide_am'] || !$kind['can_hide_am']) && !$filters['only_failed'],
        'reset_url' => 'index.php?' . http_build_query(['section' => $sectionName, 'server' => $serverId]),
        'page_url' => $pageUrl,
        'pending' => $pending,
        'has_pending' => $pending !== null,
        'unloaded' => $unloaded,
        'has_unloaded' => $unloaded !== [],
        'items' => $rows,
        'has_items' => $rows !== [],
        'has_no_items' => $server !== null && $error === null && $rows === [],
        'pager' => $pagerView,
        'count_text' => $pagerView === null ? '' : $lang->t('common.showing', [
            'from' => $pagerView['from'],
            'to' => $pagerView['to'],
            'total' => $pagerView['total'],
        ]),
    ]),
];
