<?php
declare(strict_types=1);

$error = '';
$username = '';
$remember = false;

if ($isPost)
{
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = ($_POST['remember'] ?? '') === '1';
    $clientIp = Http::clientIp();

    if ($username === '' || $password === '')
    {
        $error = $lang->t('login.error_empty');
    }
    elseif ($rateLimiter->tooMany('login_ip', $clientIp, 20, 900)
        || $rateLimiter->tooMany('login_user', $clientIp . '|' . $username, 5, 900)
        || $rateLimiter->tooMany('login_name', $username, 40, 900))
    {
        http_response_code(429);
        $error = $lang->t('login.error_rate_limit');
    }
    elseif (!$auth->login($username, $password, $remember))
    {
        $rateLimiter->hit('login_ip', $clientIp);
        $rateLimiter->hit('login_user', $clientIp . '|' . $username);
        $rateLimiter->hit('login_name', $username);
        $error = $lang->t('login.error_invalid');
    }
    else
    {
        $rateLimiter->clear('login_user', $clientIp . '|' . $username);
        $redirect('index.php');
    }
}

return [
    'title' => $lang->t('login.title'),
    'content' => $template->render('pages/login.html', [
        'has_error' => $error !== '',
        'error' => $error,
        'username' => $username,
        'remember_checked' => $remember,
    ]),
];
