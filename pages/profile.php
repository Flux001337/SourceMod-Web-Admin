<?php
declare(strict_types=1);

// Eigenes Konto: E-Mail, Sprache, Theme und Passwort. Den Benutzernamen ändert die Benutzerverwaltung.

$user = $auth->user();
$usersTable = $db->table('users');
$errors = [];
$passwordErrors = [];
$themes = Themes::available();

if ($isPost && isset($_POST['save_profile']))
{
    $email = trim((string) ($_POST['email'] ?? ''));
    $language = (string) ($_POST['language'] ?? '');
    $theme = (string) ($_POST['theme'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150)
    {
        $errors[] = $lang->t('users.error_email');
    }
    else
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $usersTable . ' WHERE email = :email AND id <> :id');
        $stmt->execute(['email' => $email, 'id' => $user['id']]);
        if ((int) $stmt->fetchColumn() > 0)
        {
            $errors[] = $lang->t('users.error_email_taken');
        }
    }

    if ($errors === [])
    {
        $pdo->prepare('UPDATE ' . $usersTable . ' SET email = :email, language = :language, theme = :theme WHERE id = :id')
            ->execute([
                'email' => $email,
                'language' => $lang->isAvailable($language) ? $language : null,
                'theme' => in_array($theme, $themes, true) ? $theme : null,
                'id' => $user['id'],
            ]);
        Flash::set('success', $lang->t('profile.saved'));
        $redirect('index.php?section=profile');
    }
    $user['email'] = $email;
}

if ($isPost && isset($_POST['change_password']))
{
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $repeat = (string) ($_POST['new_password_repeat'] ?? '');
    $clientIp = Http::clientIp();

    if ($rateLimiter->tooMany('password_check', $clientIp . '|' . $user['id'], 10, 900))
    {
        $passwordErrors[] = $lang->t('login.error_rate_limit');
    }
    elseif (!$auth->verifyPassword($current))
    {
        $rateLimiter->hit('password_check', $clientIp . '|' . $user['id']);
        $passwordErrors[] = $lang->t('profile.error_current_password');
    }
    elseif (mb_strlen($new) < 8)
    {
        $passwordErrors[] = $lang->t('users.error_password_length');
    }
    elseif ($new !== $repeat)
    {
        $passwordErrors[] = $lang->t('users.error_password_repeat');
    }
    else
    {
        $pdo->prepare('UPDATE ' . $usersTable . ' SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $user['id']]);
        // Diese Sitzung bleibt, alle anderen Sitzungen und gemerkten Anmeldungen enden.
        $auth->refreshSession();
        Flash::set('success', $lang->t('profile.password_changed'));
        $redirect('index.php?section=profile');
    }
}

$permissionView = [];
foreach ($auth->permissions() as $permission)
{
    $permissionView[] = ['label' => $lang->t('permissions.' . $permission)];
}

return [
    'title' => $lang->t('nav.profile'),
    'content' => $template->render('pages/profile.html', [
        'username' => $user['username'],
        'initials' => mb_strtoupper(mb_substr((string) $user['username'], 0, 2)),
        'email' => $user['email'],
        'is_owner' => $user['is_owner'],
        'member_since' => date('d.m.Y', strtotime((string) $user['created_at'])),
        'languages' => $lang->options($user['language'], true),
        'has_theme_choice' => count($themes) > 1,
        'themes' => Themes::options($user['theme'], $lang->t('common.default_option')),
        'has_errors' => $errors !== [],
        'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
        'has_password_errors' => $passwordErrors !== [],
        'password_errors' => array_map(static fn (string $error): array => ['text' => $error], $passwordErrors),
        'permissions' => $permissionView,
        'has_permissions' => $permissionView !== [],
        'has_no_permissions' => $permissionView === [],
    ]),
];
