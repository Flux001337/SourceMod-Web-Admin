<?php
declare(strict_types=1);

// Abmelden nur per POST (Formular mit CSRF-Token in der Seitenleiste), damit ein fremder Link niemanden abmeldet.
if ($isPost)
{
    $auth->logout();
}

$redirect('index.php');
