<?php
declare(strict_types=1);

// Benutzerverwaltung. Liste: Recht "users" oder "permissions". Konten anlegen, bearbeiten, löschen: "users".
// Rechte vergeben: "permissions" (nur Rechte, die man selbst hat; der Owner alle).

$actor = $auth->user();
$actorPermissions = $auth->permissions();
$canManage = $auth->hasPermission('users');
$canGrant = $auth->hasPermission('permissions');
if (!$canManage && !$canGrant)
{
    return $forbidden();
}

$usersTable = $db->table('users');
$grantable = $permissions->grantableBy($actor, $actorPermissions);
$action = (string) ($_GET['action'] ?? '');
$userId = filter_var($_GET['id'] ?? $_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$errors = [];

$findUser = static function (int $id) use ($pdo, $usersTable): ?array {
    $stmt = $pdo->prepare('SELECT id, username, email, language, is_owner, created_at, last_login_at FROM ' . $usersTable . ' WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row)
    {
        return null;
    }
    $row['is_owner'] = (bool) $row['is_owner'];

    return $row;
};

/**
 * Prüft die Kontodaten aus dem Formular. Gibt die bereinigten Werte und die Fehlermeldungen zurück.
 */
$validateAccount = static function (array $input, ?int $exceptId) use ($pdo, $usersTable, $lang): array {
    $data = [
        'username' => trim((string) ($input['username'] ?? '')),
        'email' => trim((string) ($input['email'] ?? '')),
        'language' => (string) ($input['language'] ?? ''),
        'password' => (string) ($input['password'] ?? ''),
        'password_repeat' => (string) ($input['password_repeat'] ?? ''),
    ];
    $errors = [];

    if (preg_match('/^[\p{L}\p{N}_.\- ]{2,30}$/u', $data['username']) !== 1)
    {
        $errors[] = $lang->t('users.error_username');
    }
    if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($data['email']) > 150)
    {
        $errors[] = $lang->t('users.error_email');
    }
    if ($data['language'] !== '' && !$lang->isAvailable($data['language']))
    {
        $data['language'] = '';
    }
    if ($exceptId === null || $data['password'] !== '')
    {
        if (mb_strlen($data['password']) < 8)
        {
            $errors[] = $lang->t('users.error_password_length');
        }
        elseif ($data['password'] !== $data['password_repeat'])
        {
            $errors[] = $lang->t('users.error_password_repeat');
        }
    }

    foreach (['username', 'email'] as $field)
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $usersTable . ' WHERE ' . $field . ' = :value AND id <> :id');
        $stmt->execute(['value' => $data[$field], 'id' => $exceptId ?? 0]);
        if ((int) $stmt->fetchColumn() > 0)
        {
            $errors[] = $lang->t('users.error_' . $field . '_taken');
        }
    }

    return [$data, $errors];
};

$submittedPermissions = static fn (): array => array_values(array_filter(
    (array) ($_POST['permissions'] ?? []),
    static fn ($value): bool => is_string($value) && in_array($value, Permissions::ALL, true)
));

// --- Anlegen -------------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['create_user']))
{
    if (!$canManage)
    {
        return $forbidden();
    }
    [$data, $errors] = $validateAccount($_POST, null);
    if ($errors === [])
    {
        $pdo->prepare(
            'INSERT INTO ' . $usersTable . ' (username, email, password_hash, language) VALUES (:username, :email, :hash, :language)'
        )->execute([
            'username' => $data['username'],
            'email' => $data['email'],
            'hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'language' => $data['language'] !== '' ? $data['language'] : null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        if ($canGrant)
        {
            $permissions->save($newId, $submittedPermissions(), $grantable);
        }
        Flash::set('success', $lang->t('users.created', ['user' => $data['username']]));
        $redirect('index.php?section=users');
    }
    $action = 'create';
}

// --- Speichern -----------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['save_user']) && is_int($userId))
{
    $target = $findUser($userId);
    if ($target === null)
    {
        Flash::set('error', $lang->t('users.not_found'));
        $redirect('index.php?section=users');
    }
    $mayEditAccount = Permissions::canEditUser($actor, $actorPermissions, $target, $permissions->ofUser($userId));
    $mayEditPermissions = Permissions::canEditPermissions($actor, $actorPermissions, $target);
    if (!$mayEditAccount && !$mayEditPermissions)
    {
        return $forbidden();
    }

    if ($mayEditAccount)
    {
        [$data, $errors] = $validateAccount($_POST, $userId);
    }
    if ($errors === [])
    {
        if ($mayEditAccount)
        {
            $pdo->prepare(
                'UPDATE ' . $usersTable . ' SET username = :username, email = :email, language = :language WHERE id = :id'
            )->execute([
                'username' => $data['username'],
                'email' => $data['email'],
                'language' => $data['language'] !== '' ? $data['language'] : null,
                'id' => $userId,
            ]);
            if ($data['password'] !== '')
            {
                $pdo->prepare('UPDATE ' . $usersTable . ' SET password_hash = :hash WHERE id = :id')
                    ->execute(['hash' => password_hash($data['password'], PASSWORD_DEFAULT), 'id' => $userId]);
                // Andere Sitzungen des Kontos enden über den Passwort-Stempel; die eigene bleibt gültig.
                if ($userId === (int) $actor['id'])
                {
                    $auth->refreshSession();
                }
                else
                {
                    $auth->revokeRememberedLogins($userId);
                }
            }
        }
        if ($mayEditPermissions)
        {
            $permissions->save($userId, $submittedPermissions(), $grantable);
        }
        Flash::set('success', $lang->t('users.saved', ['user' => $mayEditAccount ? $data['username'] : $target['username']]));
        $redirect('index.php?section=users');
    }
    $action = 'edit';
}

// --- Löschen -------------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['delete_user']) && is_int($userId))
{
    $target = $findUser($userId);
    if ($target === null)
    {
        Flash::set('error', $lang->t('users.not_found'));
    }
    elseif (!Permissions::canDeleteUser($actor, $actorPermissions, $target, $permissions->ofUser($userId)))
    {
        return $forbidden();
    }
    else
    {
        $pdo->prepare('DELETE FROM ' . $usersTable . ' WHERE id = :id')->execute(['id' => $userId]);
        Flash::set('success', $lang->t('users.deleted', ['user' => $target['username']]));
    }
    $redirect('index.php?section=users');
}

// --- Formular (anlegen / bearbeiten) -------------------------------------------------------------------------------
if ($action === 'create' || $action === 'edit')
{
    if ($action === 'create')
    {
        if (!$canManage)
        {
            return $forbidden();
        }
        $target = ['id' => 0, 'username' => '', 'email' => '', 'language' => null, 'is_owner' => false];
        $mayEditAccount = true;
        $mayEditPermissions = $canGrant;
        $currentPermissions = [];
    }
    else
    {
        $target = is_int($userId) ? $findUser($userId) : null;
        if ($target === null)
        {
            Flash::set('error', $lang->t('users.not_found'));
            $redirect('index.php?section=users');
        }
        $mayEditAccount = Permissions::canEditUser($actor, $actorPermissions, $target, $permissions->ofUser($userId));
        $mayEditPermissions = Permissions::canEditPermissions($actor, $actorPermissions, $target);
        if (!$mayEditAccount && !$mayEditPermissions)
        {
            return $forbidden();
        }
        $currentPermissions = $target['is_owner'] ? Permissions::ALL : $permissions->ofUser($userId);
    }

    // Nach einem Fehler die eingegebenen Werte wieder anzeigen.
    if ($isPost)
    {
        $target['username'] = trim((string) ($_POST['username'] ?? $target['username']));
        $target['email'] = trim((string) ($_POST['email'] ?? $target['email']));
        $target['language'] = (string) ($_POST['language'] ?? $target['language']);
        if ($mayEditPermissions)
        {
            $currentPermissions = array_values(array_unique(array_merge(
                array_diff($currentPermissions, $grantable),
                array_intersect($grantable, $submittedPermissions())
            )));
        }
    }

    $permissionRows = [];
    foreach (Permissions::ALL as $permission)
    {
        $permissionRows[] = [
            'key' => $permission,
            'label' => $lang->t('permissions.' . $permission),
            'checked' => in_array($permission, $currentPermissions, true),
            'is_disabled' => $permissions->isDisabled($permission),
            // Rechte, die man selbst nicht hat, bleiben sichtbar, lassen sich aber nicht ändern.
            'locked' => !$mayEditPermissions || !in_array($permission, $grantable, true),
        ];
    }

    $isCreate = $action === 'create';

    return [
        'title' => $lang->t('nav.users') . ' - ' . $lang->t($isCreate ? 'users.create_title' : 'users.edit_title'),
        'title_path' => [
            ['label' => $lang->t('nav.users'), 'url' => 'index.php?section=users'],
            ['label' => $lang->t($isCreate ? 'users.create_title' : 'users.edit_title')],
        ],
        'content' => $template->render('pages/users.html', [
            'show_form' => true,
            'form_title' => $isCreate ? $lang->t('users.create_title') : $lang->t('users.edit_title_user', ['user' => $target['username']]),
            'form_action' => $isCreate ? 'index.php?section=users&action=create' : 'index.php?section=users&action=edit&id=' . (int) $target['id'],
            'submit_name' => $isCreate ? 'create_user' : 'save_user',
            'user_id' => (int) $target['id'],
            'is_create' => $isCreate,
            'is_owner_target' => $target['is_owner'],
            'has_errors' => $errors !== [],
            'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
            'may_edit_account' => $mayEditAccount,
            'account_readonly' => !$mayEditAccount,
            'may_edit_permissions' => $mayEditPermissions,
            'show_permissions_readonly_hint' => !$mayEditPermissions && !$target['is_owner'],
            'form' => [
                'username' => $target['username'],
                'email' => $target['email'],
            ],
            'languages' => $lang->options($target['language'], true),
            'permission_rows' => $permissionRows,
        ]),
    ];
}

// --- Liste ---------------------------------------------------------------------------------------------------------
$search = trim((string) ($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '')
{
    $where = ' WHERE username LIKE :q1 OR email LIKE :q2';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $params = ['q1' => $like, 'q2' => $like];
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $usersTable . $where);
$countStmt->execute($params);
$pager = new Pager((int) $countStmt->fetchColumn(), $settings->int('users_per_page', 5, 200), (int) ($_GET['page'] ?? 1));

$listStmt = $pdo->prepare(
    'SELECT id, username, email, language, is_owner, last_login_at FROM ' . $usersTable . $where
    . ' ORDER BY is_owner DESC, username ASC LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value)
{
    $listStmt->bindValue($key, $value);
}
$listStmt->bindValue('limit', $pager->perPage, PDO::PARAM_INT);
$listStmt->bindValue('offset', $pager->offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

// Rechte aller angezeigten Benutzer mit einer Abfrage holen.
$permissionsByUser = [];
if ($rows !== [])
{
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $stmt = $pdo->prepare(
        'SELECT user_id, permission FROM ' . $db->table('user_permissions')
        . ' WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row)
    {
        $permissionsByUser[(int) $row['user_id']][] = (string) $row['permission'];
    }
}

$languages = $lang->available();
$userRows = [];
foreach ($rows as $row)
{
    $row['is_owner'] = (bool) $row['is_owner'];
    $id = (int) $row['id'];
    $userPermissions = array_values(array_intersect(Permissions::ALL, $permissionsByUser[$id] ?? []));
    $language = $row['language'] !== null && isset($languages[$row['language']]) ? $languages[$row['language']] : null;
    $userRows[] = [
        'id' => $id,
        'username' => $row['username'],
        'initials' => mb_strtoupper(mb_substr((string) $row['username'], 0, 2)),
        'email' => $row['email'],
        'is_owner' => $row['is_owner'],
        'is_self' => $id === (int) $actor['id'],
        'language' => $language['name'] ?? $lang->t('common.default_option'),
        'flag' => $language['flag'] ?? '',
        'has_flag' => $language !== null,
        'permissions' => array_map(static fn (string $p): array => [
            'label' => $lang->t('permissions.' . $p),
            'is_disabled' => $permissions->isDisabled($p),
        ], $userPermissions),
        'has_no_permissions' => !$row['is_owner'] && $userPermissions === [],
        'last_login' => $row['last_login_at'] !== null ? date('d.m.Y H:i', strtotime((string) $row['last_login_at'])) : '–',
        'can_edit' => Permissions::canEditUser($actor, $actorPermissions, $row, $userPermissions)
            || Permissions::canEditPermissions($actor, $actorPermissions, $row),
        'can_delete' => Permissions::canDeleteUser($actor, $actorPermissions, $row, $userPermissions),
        'delete_question' => $lang->t('users.delete_question', ['user' => $row['username']]),
    ];
}

$pagerView = $pager->view('index.php?section=users' . ($search !== '' ? '&q=' . rawurlencode($search) : ''));

return [
    'title' => $lang->t('nav.users'),
    'content' => $template->render('pages/users.html', [
        'show_list' => true,
        'can_create' => $canManage,
        'search' => $search,
        'has_search' => $search !== '',
        'users' => $userRows,
        'has_users' => $userRows !== [],
        'has_no_users' => $userRows === [],
        'pager' => $pagerView,
        'count_text' => $lang->t('common.showing', [
            'from' => $pagerView['from'],
            'to' => $pagerView['to'],
            'total' => $pagerView['total'],
        ]),
    ]),
];
