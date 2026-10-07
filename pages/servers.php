<?php
declare(strict_types=1);

// Gameserver (Recht "servers"): Liste mit Live-Status, anlegen, bearbeiten (RCON, FTP), Verbindung testen, löschen.
// Altes SMWA: server.php. Passwörter liegen verschlüsselt in der Datenbank (SecretCipher).

if (!$auth->hasPermission('servers'))
{
    return $forbidden();
}

$serversTable = $db->table('servers');
$gamesTable = $db->table('games');
$action = (string) ($_GET['action'] ?? '');
$serverId = filter_var($_GET['id'] ?? $_POST['server_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$timeout = $settings->int('server_query_timeout', 1, 10);
$errors = [];
$testResults = null;

$findServer = static function (int $id) use ($pdo, $serversTable): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, name, host, port, game_id, detected_folder, rcon_password, ftp_host, ftp_port, ftp_tls, ftp_username, ftp_password, ftp_path FROM '
        . $serversTable . ' WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
};

$isValidHost = static fn (string $host): bool => $host !== '' && strlen($host) <= 255
    && (filter_var($host, FILTER_VALIDATE_IP) !== false || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
$isValidPort = static fn (string $port): bool => filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) !== false;
$address = static fn (string $host, int $port): string => (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
$games = static fn (): array => $pdo->query('SELECT id, name FROM ' . $gamesTable . ' ORDER BY name ASC')->fetchAll();

// --- Live-Status (JSON für assets/js/app.js) -----------------------------------------------------------------------
if ($action === 'status' && is_int($serverId))
{
    $server = $findServer($serverId);
    // Die Abfrage kann bis zum Timeout dauern; ohne Session-Sperre laufen die Abfragen der Liste parallel.
    session_write_close();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($server === null)
    {
        http_response_code(404);
        echo json_encode(['online' => false]);
        exit;
    }

    $info = ServerQuery::info((string) $server['host'], (int) $server['port'], $timeout);
    if ($info === null)
    {
        echo json_encode(['online' => false, 'status_label' => $lang->t('servers.offline')]);
        exit;
    }
    // Gemeldeten Spielordner merken, damit die Liste das Game auch offline zeigt.
    if ($info['folder'] !== (string) $server['detected_folder'] && mb_strlen($info['folder']) <= 64)
    {
        $pdo->prepare('UPDATE ' . $serversTable . ' SET detected_folder = ? WHERE id = ?')->execute([$info['folder'], $serverId]);
    }

    // Zugewiesenes Game vor dem Spielordner, den der Server meldet.
    if ($server['game_id'] !== null)
    {
        $stmt = $pdo->prepare('SELECT name, icon FROM ' . $gamesTable . ' WHERE id = ?');
        $stmt->execute([(int) $server['game_id']]);
    }
    else
    {
        $stmt = $pdo->prepare('SELECT name, icon FROM ' . $gamesTable . ' WHERE folder = ?');
        $stmt->execute([$info['folder']]);
    }
    $game = $stmt->fetch() ?: null;

    $labels = [];
    if (isset(['l' => 1, 'w' => 1, 'm' => 1][$info['os']]))
    {
        $labels[] = $lang->t('servers.os_' . $info['os']);
    }
    if ($info['vac'])
    {
        $labels[] = 'VAC';
    }
    if ($info['password'])
    {
        $labels[] = $lang->t('servers.password_protected');
    }

    echo json_encode([
        'online' => true,
        'status_label' => $lang->t('servers.online'),
        'hostname' => $info['name'],
        'map' => $info['map'],
        'players' => $lang->t($info['bots'] > 0 ? 'servers.players_bots' : 'servers.players_count', [
            'players' => $info['players'],
            'max' => $info['max_players'],
            'bots' => $info['bots'],
        ]),
        'game' => $game['name'] ?? ($info['game'] !== '' ? $info['game'] : $info['folder']),
        'icon' => GameIcon::url($game['icon'] ?? null),
        'labels' => $labels,
        'ping' => $info['ping'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// --- Anlegen / Speichern -------------------------------------------------------------------------------------------
if ($isPost && (isset($_POST['create_server']) || isset($_POST['save_server'])))
{
    $isCreate = isset($_POST['create_server']);
    $current = null;
    if (!$isCreate)
    {
        $current = is_int($serverId) ? $findServer($serverId) : null;
        if ($current === null)
        {
            Flash::set('error', $lang->t('servers.not_found'));
            $redirect('index.php?section=servers');
        }
    }

    $data = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'host' => trim((string) ($_POST['host'] ?? '')),
        'port' => trim((string) ($_POST['port'] ?? '')),
        // '' = automatisch
        'game_id' => trim((string) ($_POST['game_id'] ?? '')),
        'rcon_password' => (string) ($_POST['rcon_password'] ?? ''),
        'remove_rcon_password' => isset($_POST['remove_rcon_password']),
        'ftp_host' => trim((string) ($_POST['ftp_host'] ?? '')),
        'ftp_port' => trim((string) ($_POST['ftp_port'] ?? '21')),
        'ftp_tls' => isset($_POST['ftp_tls']),
        'ftp_username' => trim((string) ($_POST['ftp_username'] ?? '')),
        'ftp_password' => (string) ($_POST['ftp_password'] ?? ''),
        'remove_ftp_password' => isset($_POST['remove_ftp_password']),
        'ftp_path' => trim((string) ($_POST['ftp_path'] ?? '')),
    ];
    // "IP:Port" im Adressfeld (wie im alten SMWA) wird aufgeteilt.
    if (preg_match('/^([^:\s]+):(\d{1,5})$/', $data['host'], $match) === 1)
    {
        $data['host'] = $match[1];
        $data['port'] = $match[2];
    }
    if ($data['ftp_port'] === '')
    {
        $data['ftp_port'] = '21';
    }

    if ($data['name'] === '' || mb_strlen($data['name']) > 64)
    {
        $errors[] = $lang->t('servers.error_name');
    }
    if (!$isValidHost($data['host']))
    {
        $errors[] = $lang->t('servers.error_host');
    }
    if (!$isValidPort($data['port']))
    {
        $errors[] = $lang->t('servers.error_port');
    }
    if ($data['game_id'] !== '' && !in_array($data['game_id'], array_map('strval', array_column($games(), 'id')), true))
    {
        $errors[] = $lang->t('servers.error_game');
    }
    if ($errors === [])
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $serversTable . ' WHERE host = ? AND port = ? AND id <> ?');
        $stmt->execute([$data['host'], (int) $data['port'], (int) ($current['id'] ?? 0)]);
        if ((int) $stmt->fetchColumn() > 0)
        {
            $errors[] = $lang->t('servers.error_address_taken');
        }
    }
    if ($data['ftp_host'] !== '')
    {
        if (!$isValidHost($data['ftp_host']))
        {
            $errors[] = $lang->t('servers.error_ftp_host');
        }
        if (!$isValidPort($data['ftp_port']))
        {
            $errors[] = $lang->t('servers.error_ftp_port');
        }
        if ($data['ftp_username'] === '' || mb_strlen($data['ftp_username']) > 255 || preg_match('/[\x00-\x1F]/', $data['ftp_username']) === 1)
        {
            $errors[] = $lang->t('servers.error_ftp_username');
        }
        if (mb_strlen($data['ftp_path']) > 255 || preg_match('/[\x00-\x1F]/', $data['ftp_path']) === 1)
        {
            $errors[] = $lang->t('servers.error_ftp_path');
        }
    }
    foreach (['rcon_password', 'ftp_password'] as $field)
    {
        if ($data[$field] !== '' && mb_strlen($data[$field]) > 255)
        {
            $errors[] = $lang->t('servers.error_password_length');
        }
        elseif ($data[$field] !== '' && !$secrets->isConfigured())
        {
            $errors[] = $lang->t('servers.error_master_key');
        }
    }

    if ($errors === [])
    {
        // Ohne neue Eingabe bleibt ein Passwort, außer es soll entfernt werden.
        $secret = static function (string $field) use ($data, $current, $secrets): ?string {
            if ($data[$field] !== '')
            {
                return $secrets->encrypt($data[$field]);
            }

            return $data['remove_' . $field] ? null : ($current[$field] ?? null);
        };
        $values = [
            $data['name'], $data['host'], (int) $data['port'], $data['game_id'] === '' ? null : (int) $data['game_id'],
            $secret('rcon_password'), $data['ftp_host'], (int) $data['ftp_port'], $data['ftp_tls'] ? 1 : 0,
            $data['ftp_username'], $secret('ftp_password'), $data['ftp_path'],
        ];
        if ($isCreate)
        {
            $pdo->prepare(
                'INSERT INTO ' . $serversTable . ' (name, host, port, game_id, rcon_password, ftp_host, ftp_port, ftp_tls,'
                . ' ftp_username, ftp_password, ftp_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute($values);
        }
        else
        {
            $pdo->prepare(
                'UPDATE ' . $serversTable . ' SET name = ?, host = ?, port = ?, game_id = ?, rcon_password = ?, ftp_host = ?,'
                . ' ftp_port = ?, ftp_tls = ?, ftp_username = ?, ftp_password = ?, ftp_path = ? WHERE id = ?'
            )->execute([...$values, $serverId]);
        }
        Flash::set('success', $lang->t($isCreate ? 'servers.created' : 'servers.saved', ['name' => $data['name']]));
        $redirect('index.php?section=servers');
    }
    $action = $isCreate ? 'create' : 'edit';
}

// --- Verbindung testen (gespeicherte Daten) ------------------------------------------------------------------------
if ($isPost && isset($_POST['test_server']) && is_int($serverId))
{
    $server = $findServer($serverId);
    if ($server === null)
    {
        Flash::set('error', $lang->t('servers.not_found'));
        $redirect('index.php?section=servers');
    }
    $host = (string) $server['host'];
    $port = (int) $server['port'];
    $result = static fn (bool $ok, string $label, string $text): array => ['ok' => $ok, 'failed' => !$ok, 'label' => $label, 'text' => $text];
    $testResults = [];

    $info = ServerQuery::info($host, $port, $timeout);
    $testResults[] = $info !== null
        ? $result(true, $lang->t('servers.test_query'), $lang->t('servers.test_query_ok', ['name' => $info['name'], 'map' => $info['map'], 'folder' => $info['folder'], 'ping' => $info['ping']]))
        : $result(false, $lang->t('servers.test_query'), $lang->t('servers.test_query_failed', ['address' => $address($host, $port)]));

    try
    {
        $rconPassword = $secrets->decrypt((string) ($server['rcon_password'] ?? ''));
    }
    catch (RuntimeException)
    {
        $rconPassword = null;
    }
    if ($rconPassword === null)
    {
        $testResults[] = $result(false, 'RCON', $lang->t('servers.test_decrypt_failed'));
    }
    elseif ($rconPassword === '')
    {
        $testResults[] = $result(false, 'RCON', $lang->t('servers.test_rcon_none'));
    }
    else
    {
        $rcon = ServerQuery::rcon($host, $port, $rconPassword, 'sm version', $timeout);
        if ($rcon['error'] !== null)
        {
            $testResults[] = $result(false, 'RCON', $lang->t('servers.test_rcon_failed', ['error' => $rcon['error']]));
        }
        elseif (preg_match('/SourceMod Version:\s*(\S+)/i', (string) $rcon['response'], $versionMatch) === 1)
        {
            $testResults[] = $result(true, 'RCON', $lang->t('servers.test_rcon_ok', ['version' => $versionMatch[1]]));
        }
        else
        {
            $testResults[] = $result(false, 'RCON', $lang->t('servers.test_rcon_no_sourcemod'));
        }
    }

    if ((string) $server['ftp_host'] === '')
    {
        $testResults[] = $result(false, 'FTP', $lang->t('servers.test_ftp_none'));
    }
    else
    {
        try
        {
            $ftpPassword = $secrets->decrypt((string) ($server['ftp_password'] ?? ''));
            $ftpError = FtpClient::test(
                (string) $server['ftp_host'], (int) $server['ftp_port'], (bool) $server['ftp_tls'],
                (string) $server['ftp_username'], $ftpPassword, (string) $server['ftp_path'], $timeout
            );
            $testResults[] = $ftpError === null
                ? $result(true, 'FTP', $lang->t('servers.test_ftp_ok'))
                : $result(false, 'FTP', $lang->t($ftpError, ['path' => (string) $server['ftp_path']]));
        }
        catch (RuntimeException)
        {
            $testResults[] = $result(false, 'FTP', $lang->t('servers.test_decrypt_failed'));
        }
    }
    $action = 'edit';
}

// --- Löschen -------------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['delete_server']) && is_int($serverId))
{
    $target = $findServer($serverId);
    if ($target === null)
    {
        Flash::set('error', $lang->t('servers.not_found'));
    }
    else
    {
        $pdo->prepare('DELETE FROM ' . $serversTable . ' WHERE id = ?')->execute([$serverId]);
        Flash::set('success', $lang->t('servers.deleted', ['name' => $target['name']]));
    }
    $redirect('index.php?section=servers');
}

// --- Formular (anlegen / bearbeiten) -------------------------------------------------------------------------------
if ($action === 'create' || $action === 'edit')
{
    $isCreate = $action === 'create';
    if ($isCreate)
    {
        $target = ['id' => 0, 'name' => '', 'host' => '', 'port' => 27015, 'game_id' => null, 'detected_folder' => '', 'rcon_password' => null, 'ftp_host' => '',
            'ftp_port' => 21, 'ftp_tls' => 0, 'ftp_username' => '', 'ftp_password' => null, 'ftp_path' => ''];
    }
    else
    {
        $target = is_int($serverId) ? $findServer($serverId) : null;
        if ($target === null)
        {
            Flash::set('error', $lang->t('servers.not_found'));
            $redirect('index.php?section=servers');
        }
    }
    $form = isset($data) ? $data : [
        'name' => $target['name'],
        'host' => $target['host'],
        'port' => (string) $target['port'],
        'game_id' => (string) ($target['game_id'] ?? ''),
        'remove_rcon_password' => false,
        'ftp_host' => $target['ftp_host'],
        'ftp_port' => (string) $target['ftp_port'],
        'ftp_tls' => (bool) $target['ftp_tls'],
        'ftp_username' => $target['ftp_username'],
        'remove_ftp_password' => false,
        'ftp_path' => $target['ftp_path'],
    ];
    $gameOptions = [['value' => '', 'label' => $lang->t('servers.game_auto'), 'selected' => $form['game_id'] === '']];
    foreach ($games() as $game)
    {
        $gameOptions[] = ['value' => (string) $game['id'], 'label' => $game['name'], 'selected' => $form['game_id'] === (string) $game['id']];
    }
    $hasRconPassword = ($target['rcon_password'] ?? '') !== '';
    $hasFtpPassword = ($target['ftp_password'] ?? '') !== '';
    $title = $isCreate ? $lang->t('servers.create_title') : $lang->t('servers.edit_title');

    return [
        'title' => $lang->t('nav.manage_servers') . ' - ' . $title,
        'title_path' => [
            ['label' => $lang->t('nav.manage_servers'), 'url' => 'index.php?section=servers'],
            ['label' => $title],
        ],
        'content' => $template->render('pages/servers.html', [
            'show_form' => true,
            'is_edit' => !$isCreate,
            'form_title' => $isCreate ? $title : $lang->t('servers.edit_title_name', ['name' => $target['name']]),
            'form_action' => $isCreate ? 'index.php?section=servers&action=create' : 'index.php?section=servers&action=edit&id=' . (int) $target['id'],
            'submit_name' => $isCreate ? 'create_server' : 'save_server',
            'server_id' => (int) $target['id'],
            'has_errors' => $errors !== [],
            'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
            'form' => $form,
            'game_options' => $gameOptions,
            'has_rcon_password' => $hasRconPassword,
            'has_no_rcon_password' => !$hasRconPassword,
            'has_ftp_password' => $hasFtpPassword,
            'has_no_ftp_password' => !$hasFtpPassword,
            'no_master_key' => !$secrets->isConfigured(),
            'has_test_results' => $testResults !== null,
            'test_results' => $testResults ?? [],
        ]),
    ];
}

// --- Liste ---------------------------------------------------------------------------------------------------------
$serverRows = [];
foreach ($pdo->query(
    'SELECT s.id, s.name, s.host, s.port, COALESCE(g.name, d.name) AS game_name, IF(g.id IS NULL, d.icon, g.icon) AS game_icon'
    . ' FROM ' . $serversTable . ' s LEFT JOIN ' . $gamesTable . ' g ON g.id = s.game_id'
    . ' LEFT JOIN ' . $gamesTable . " d ON d.folder = s.detected_folder AND s.detected_folder <> ''"
    . ' ORDER BY s.name ASC, s.id ASC'
) as $row)
{
    $serverAddress = $address((string) $row['host'], (int) $row['port']);
    // Zugewiesenes oder zuletzt erkanntes Game sofort zeigen (auch offline); der Live-Status ersetzt es, wenn online.
    $gameIcon = GameIcon::url($row['game_icon']);
    $serverRows[] = [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'game_icon' => $gameIcon,
        'game_name' => (string) ($row['game_name'] ?? ''),
        'has_game_icon' => $gameIcon !== '',
        'has_no_game_icon' => $gameIcon === '',
        'address' => $serverAddress,
        'connect_url' => 'steam://connect/' . $serverAddress,
        'delete_question' => $lang->t('servers.delete_question', ['name' => $row['name']]),
    ];
}

return [
    'title' => $lang->t('nav.manage_servers'),
    'content' => $template->render('pages/servers.html', [
        'show_list' => true,
        'servers' => $serverRows,
        'has_servers' => $serverRows !== [],
        'has_no_servers' => $serverRows === [],
        'count_text' => $lang->t('servers.count', ['count' => count($serverRows)]),
    ]),
];
