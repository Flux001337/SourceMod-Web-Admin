<?php
declare(strict_types=1);

// Einstellungen der Oberfläche (Recht "settings").

if (!$auth->hasPermission('settings'))
{
    return $forbidden();
}

$errors = [];
$themes = Themes::available();
$values = [
    'site_title' => $settings->get('site_title'),
    'site_subtitle' => $settings->get('site_subtitle'),
    'default_language' => $settings->get('default_language'),
    'site_theme' => $settings->get('site_theme'),
    'users_per_page' => $settings->get('users_per_page'),
    'sm_per_page' => $settings->get('sm_per_page'),
    'server_query_timeout' => $settings->get('server_query_timeout'),
    'sql_admins_enabled' => $settings->get('sql_admins_enabled'),
];

if ($isPost && isset($_POST['save_settings']))
{
    $values = [
        'site_title' => trim((string) ($_POST['site_title'] ?? '')),
        'site_subtitle' => trim((string) ($_POST['site_subtitle'] ?? '')),
        'default_language' => (string) ($_POST['default_language'] ?? ''),
        // Ohne Auswahlfeld (nur ein Theme vorhanden) bleibt das aktive Theme.
        'site_theme' => (string) ($_POST['site_theme'] ?? Themes::active($values['site_theme'])),
        'users_per_page' => trim((string) ($_POST['users_per_page'] ?? '')),
        'sm_per_page' => trim((string) ($_POST['sm_per_page'] ?? '')),
        'server_query_timeout' => trim((string) ($_POST['server_query_timeout'] ?? '')),
        'sql_admins_enabled' => isset($_POST['sql_admins_enabled']) ? '1' : '0',
    ];

    if ($values['site_title'] === '' || mb_strlen($values['site_title']) > 80)
    {
        $errors[] = $lang->t('settings.error_title');
    }
    if (mb_strlen($values['site_subtitle']) > 160)
    {
        $errors[] = $lang->t('settings.error_subtitle');
    }
    if (!$lang->isAvailable($values['default_language']))
    {
        $errors[] = $lang->t('settings.error_language');
    }
    if ($themes !== [] && !in_array($values['site_theme'], $themes, true))
    {
        $errors[] = $lang->t('settings.error_theme');
    }
    foreach (['users_per_page', 'sm_per_page'] as $key)
    {
        $perPage = filter_var($values[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 200]]);
        if ($perPage === false)
        {
            $errors[] = $lang->t('settings.error_' . $key);
        }
    }

    if (filter_var($values['server_query_timeout'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]) === false)
    {
        $errors[] = $lang->t('settings.error_server_query_timeout');
    }

    if ($errors === [])
    {
        foreach ($values as $key => $value)
        {
            $settings->set($key, (string) $value);
        }
        Flash::set('success', $lang->t('settings.saved'));
        $redirect('index.php?section=settings');
    }
}

return [
    'title' => $lang->t('nav.settings'),
    'content' => $template->render('pages/settings.html', [
        'has_errors' => $errors !== [],
        'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
        'values' => $values,
        'sql_admins_checked' => $values['sql_admins_enabled'] === '1',
        'languages' => $lang->options($values['default_language']),
        'has_theme_choice' => count($themes) > 1,
        'themes' => Themes::options($values['site_theme']),
    ]),
];
