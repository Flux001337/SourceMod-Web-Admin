<?php
declare(strict_types=1);

// Export der SQL-Admins als Dateien von SourceMod (Recht "sqladmins"): Vorschau, Download und Upload per FTP auf die
// Server, auf Wunsch danach "sm_reloadadmins" per RCON. Altes SMWA: export.php.

if (($blocked = $sourcemodGuard()) !== null)
{
    return $blocked;
}

$export = new SourceModExport($pdo, $sourcemod);
$file = (string) ($_GET['file'] ?? SourceModExport::FILES[0]);
if (!in_array($file, SourceModExport::FILES, true))
{
    $file = SourceModExport::FILES[0];
}
$timeout = $settings->int('server_query_timeout', 1, 10);
$results = null;

// --- Download ------------------------------------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'download')
{
    $generated = $export->generate($file);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store');
    echo $generated['content'];
    exit;
}

// Server mit FTP-Zugang (nur diese können Dateien bekommen).
$servers = $pdo->query(
    'SELECT id, name, host, port, rcon_password, ftp_host, ftp_port, ftp_tls, ftp_username, ftp_password, ftp_path FROM '
    . $db->table('servers') . ' ORDER BY name ASC'
)->fetchAll();
$ftpServers = array_values(array_filter($servers, static fn (array $server): bool => (string) $server['ftp_host'] !== ''));

// --- Upload --------------------------------------------------------------------------------------------------------
$selectedFiles = [$file];
$selectedServers = [];
$reload = true;
if ($isPost && isset($_POST['upload_export']))
{
    $selectedFiles = array_values(array_intersect(SourceModExport::FILES, array_filter((array) ($_POST['files'] ?? []), 'is_string')));
    $selectedServers = array_map('intval', array_filter((array) ($_POST['servers'] ?? []), 'is_scalar'));
    $reload = isset($_POST['reload']);

    if ($selectedFiles === [] || $selectedServers === [])
    {
        Flash::set('error', $lang->t('sm_export.error_selection'));
        $redirect('index.php?section=sm_export&file=' . rawurlencode($file));
    }

    $contents = [];
    foreach ($selectedFiles as $name)
    {
        $contents[$name] = $export->generate($name)['content'];
    }
    $results = [];
    foreach ($ftpServers as $server)
    {
        if (!in_array((int) $server['id'], $selectedServers, true))
        {
            continue;
        }
        $row = ['name' => $server['name'], 'ok' => false, 'failed' => true, 'text' => ''];
        try
        {
            $ftpPassword = $secrets->decrypt((string) ($server['ftp_password'] ?? ''));
            $error = FtpClient::upload(
                (string) $server['ftp_host'], (int) $server['ftp_port'], (bool) $server['ftp_tls'], (string) $server['ftp_username'],
                $ftpPassword, (string) $server['ftp_path'], $contents, $timeout
            );
        }
        catch (RuntimeException)
        {
            $error = 'servers.test_decrypt_failed';
        }
        if ($error !== null)
        {
            $row['text'] = $lang->t($error, ['path' => (string) $server['ftp_path']]);
            $results[] = $row;
            continue;
        }

        $row['ok'] = true;
        $row['failed'] = false;
        $row['text'] = $lang->t('sm_export.uploaded', ['files' => implode(', ', array_keys($contents))]);
        if ($reload)
        {
            try
            {
                $rconPassword = $secrets->decrypt((string) ($server['rcon_password'] ?? ''));
            }
            catch (RuntimeException)
            {
                $rconPassword = '';
            }
            if ($rconPassword === '')
            {
                $row['text'] .= ' ' . $lang->t('sm_export.reload_no_rcon');
            }
            else
            {
                $rcon = ServerQuery::rcon((string) $server['host'], (int) $server['port'], $rconPassword, 'sm_reloadadmins', $timeout);
                $row['text'] .= ' ' . ($rcon['error'] === null
                    ? $lang->t('sm_export.reload_ok')
                    : $lang->t('sm_export.reload_failed', ['error' => $rcon['error']]));
            }
        }
        $results[] = $row;
    }
}

// --- Seite ---------------------------------------------------------------------------------------------------------
$generated = $export->generate($file);
$fileTabs = array_map(static fn (string $name): array => [
    'name' => $name,
    'url' => 'index.php?section=sm_export&file=' . rawurlencode($name),
    'current' => $name === $file,
], SourceModExport::FILES);
$fileChecks = array_map(static fn (string $name): array => [
    'name' => $name,
    'checked' => in_array($name, $selectedFiles, true),
], SourceModExport::FILES);
$serverChecks = array_map(static fn (array $server): array => [
    'id' => (int) $server['id'],
    'name' => $server['name'],
    'target' => $server['ftp_host'] . ($server['ftp_path'] !== '' ? '/' . ltrim((string) $server['ftp_path'], '/') : ''),
    'checked' => in_array((int) $server['id'], $selectedServers, true),
], $ftpServers);
$withoutFtp = count($servers) - count($ftpServers);

return [
    'title' => $lang->t('nav.sm_export'),
    'content' => $template->render('pages/sm_export.html', [
        'file' => $file,
        'file_tabs' => $fileTabs,
        'file_info' => $lang->t('sm_export.info_' . str_replace('.', '_', $file)),
        'notes' => array_map(static fn (array $note): array => ['text' => $lang->t($note['key'], $note['replace'])], $generated['notes']),
        'has_notes' => $generated['notes'] !== [],
        'content_text' => $generated['content'],
        'download_url' => 'index.php?section=sm_export&action=download&file=' . rawurlencode($file),
        'upload_action' => 'index.php?section=sm_export&file=' . rawurlencode($file),
        'file_checks' => $fileChecks,
        'servers' => $serverChecks,
        'has_servers' => $serverChecks !== [],
        'has_no_servers' => $serverChecks === [],
        'can_manage_servers' => $auth->hasPermission('servers'),
        'has_servers_without_ftp' => $withoutFtp > 0,
        'servers_without_ftp' => $lang->t('sm_export.servers_without_ftp', ['count' => $withoutFtp]),
        'reload_checked' => $reload,
        'has_results' => $results !== null,
        'results' => $results ?? [],
    ]),
];
