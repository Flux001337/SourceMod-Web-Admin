<?php
declare(strict_types=1);

$user = $auth->user();

// Kacheln zu den Bereichen, die der Benutzer öffnen darf.
$tiles = [
    ['section' => 'sm_admins', 'icon' => 'shield', 'visible' => $auth->hasPermission('sqladmins')],
    ['section' => 'sm_groups', 'icon' => 'layers', 'visible' => $auth->hasPermission('sqladmins')],
    ['section' => 'sm_overrides', 'icon' => 'lock', 'visible' => $auth->hasPermission('sqladmins')],
    ['section' => 'sm_plugins', 'icon' => 'plug', 'visible' => $auth->hasPermission('plugincontrol')],
    ['section' => 'sm_extensions', 'icon' => 'puzzle', 'visible' => $auth->hasPermission('plugincontrol')],
    ['section' => 'sm_export', 'icon' => 'export', 'visible' => $auth->hasPermission('sqladmins')],
    ['section' => 'servers', 'icon' => 'server', 'visible' => $auth->hasPermission('servers')],
    ['section' => 'games', 'icon' => 'gamepad', 'visible' => $auth->hasPermission('games')],
    ['section' => 'users', 'icon' => 'users', 'visible' => $auth->hasPermission('users') || $auth->hasPermission('permissions')],
    ['section' => 'settings', 'icon' => 'settings', 'visible' => $auth->hasPermission('settings')],
    ['section' => 'profile', 'icon' => 'profile', 'visible' => true],
];
$tileView = [];
foreach ($tiles as $tile)
{
    if ($tile['visible'])
    {
        $tileView[] = [
            'url' => 'index.php?section=' . $tile['section'],
            'icon' => $tile['icon'],
            'title' => $lang->t('nav.' . $tile['section']),
            'text' => $lang->t('home.tile_' . $tile['section']),
        ];
    }
}

$permissionView = [];
foreach ($auth->permissions() as $permission)
{
    $permissionView[] = ['label' => $lang->t('permissions.' . $permission)];
}

$userCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . $db->table('users'))->fetchColumn();
$lastLogin = $user['last_login_at'] !== null ? date('d.m.Y H:i', strtotime((string) $user['last_login_at'])) : '–';

return [
    'title' => $lang->t('nav.home'),
    'content' => $template->render('pages/home.html', [
        'welcome' => $lang->t('home.welcome', ['user' => $user['username']]),
        'tiles' => $tileView,
        'is_owner' => $user['is_owner'],
        'permissions' => $permissionView,
        'has_permissions' => $permissionView !== [],
        'has_no_permissions' => $permissionView === [],
        'user_count' => $userCount,
        'last_login' => $lastLogin,
        'version' => SMWA_VERSION,
        'php_version' => PHP_VERSION,
    ]),
];
