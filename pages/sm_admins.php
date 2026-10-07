<?php
declare(strict_types=1);

// SourceMod-SQL-Admins (Recht "sqladmins"): Liste, anlegen, bearbeiten (mit Flags und Gruppen), löschen.
// Altes SMWA: clients.php, flags.php (Client-Flags), management.php (Gruppen eines Clients).

if (($blocked = $sourcemodGuard()) !== null)
{
    return $blocked;
}

$adminsTable = $sourcemod->table('admins');
$adminsGroupsTable = $sourcemod->table('admins_groups');
$action = (string) ($_GET['action'] ?? '');
$adminId = filter_var($_GET['id'] ?? $_POST['admin_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$errors = [];
$groups = $sourcemod->groups();
$groupIds = array_map(static fn (array $group): int => $group['id'], $groups);

$findAdmin = static function (int $id) use ($pdo, $adminsTable): ?array {
    $stmt = $pdo->prepare('SELECT id, authtype, identity, password, flags, name, immunity FROM ' . $adminsTable . ' WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
};

/** @return array<int, int> Gruppen-ID => inherit_order */
$groupsOfAdmin = static function (int $id) use ($pdo, $adminsGroupsTable): array {
    $stmt = $pdo->prepare('SELECT group_id, inherit_order FROM ' . $adminsGroupsTable . ' WHERE admin_id = ?');
    $stmt->execute([$id]);
    $memberships = [];
    foreach ($stmt->fetchAll() as $row)
    {
        $memberships[(int) $row['group_id']] = (int) $row['inherit_order'];
    }

    return $memberships;
};

/**
 * Prüft die Eingaben. Gibt die bereinigten Werte und die Fehlermeldungen zurück.
 */
$validate = static function (array $input, ?array $current) use ($pdo, $adminsTable, $sourcemod, $lang, $groupIds): array {
    $authType = (string) ($input['authtype'] ?? '');
    $data = [
        'name' => trim((string) ($input['name'] ?? '')),
        'authtype' => in_array($authType, SourceMod::AUTH_TYPES, true) ? $authType : '',
        'identity' => SourceMod::normalizeIdentity($authType, (string) ($input['identity'] ?? '')),
        'immunity_input' => trim((string) ($input['immunity'] ?? '')),
        'immunity' => SourceMod::parseImmunity($input['immunity'] ?? ''),
        'password' => (string) ($input['password'] ?? ''),
        'remove_password' => isset($input['remove_password']),
        'flags' => SourceMod::normalizeFlags((array) ($input['flags'] ?? [])),
        'groups' => array_values(array_intersect($groupIds, array_map('intval', array_filter((array) ($input['groups'] ?? []), 'is_scalar')))),
    ];
    $errors = [];

    if ($data['name'] === '' || mb_strlen($data['name']) > 65)
    {
        $errors[] = $lang->t('sm_admins.error_name');
    }
    elseif (SourceMod::hasControlChars($data['name']))
    {
        $errors[] = $lang->t('sm.error_control_chars', ['field' => $lang->t('sm_admins.name')]);
    }
    elseif (!$sourcemod->fitsCharset('admins', 'name', $data['name']))
    {
        $errors[] = $lang->t('sm.error_charset', ['field' => $lang->t('sm_admins.name')]);
    }
    if ($data['authtype'] === '')
    {
        $errors[] = $lang->t('sm_admins.error_authtype');
    }
    elseif (!SourceMod::isValidIdentity($data['authtype'], $data['identity']))
    {
        $errors[] = $lang->t('sm_admins.error_identity_' . $data['authtype']);
    }
    elseif (SourceMod::hasControlChars($data['identity']))
    {
        $errors[] = $lang->t('sm.error_control_chars', ['field' => $lang->t('sm_admins.identity')]);
    }
    elseif (!$sourcemod->fitsCharset('admins', 'identity', $data['identity']))
    {
        $errors[] = $lang->t('sm.error_charset', ['field' => $lang->t('sm_admins.identity')]);
    }
    else
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $adminsTable . ' WHERE authtype = ? AND identity = ? AND id <> ?');
        $stmt->execute([$data['authtype'], $data['identity'], (int) ($current['id'] ?? 0)]);
        if ((int) $stmt->fetchColumn() > 0)
        {
            $errors[] = $lang->t('sm_admins.error_identity_taken');
        }
    }
    if ($data['immunity'] === null)
    {
        $errors[] = $lang->t('sm.error_immunity');
    }
    if ($data['password'] !== '')
    {
        if (mb_strlen($data['password']) > 65)
        {
            $errors[] = $lang->t('sm_admins.error_password');
        }
        elseif (SourceMod::hasControlChars($data['password']))
        {
            $errors[] = $lang->t('sm.error_control_chars', ['field' => $lang->t('sm_admins.password')]);
        }
        elseif (!$sourcemod->fitsCharset('admins', 'password', $data['password']))
        {
            $errors[] = $lang->t('sm.error_charset', ['field' => $lang->t('sm_admins.password')]);
        }
    }

    return [$data, $errors];
};

/** Speichert die Gruppen eines Admins. Bestehende Zuordnungen behalten ihre Reihenfolge (inherit_order). */
$saveGroups = static function (PDO $pdo, int $id, array $selected, array $currentOrders) use ($adminsGroupsTable): void {
    $pdo->prepare('DELETE FROM ' . $adminsGroupsTable . ' WHERE admin_id = ?')->execute([$id]);
    $insert = $pdo->prepare('INSERT INTO ' . $adminsGroupsTable . ' (admin_id, group_id, inherit_order) VALUES (?, ?, ?)');
    foreach ($selected as $groupId)
    {
        $insert->execute([$id, $groupId, $currentOrders[$groupId] ?? 0]);
    }
};

// --- Anlegen / Speichern -------------------------------------------------------------------------------------------
if ($isPost && (isset($_POST['create_admin']) || isset($_POST['save_admin'])))
{
    $isCreate = isset($_POST['create_admin']);
    $current = null;
    if (!$isCreate)
    {
        $current = is_int($adminId) ? $findAdmin($adminId) : null;
        if ($current === null)
        {
            Flash::set('error', $lang->t('sm_admins.not_found'));
            $redirect('index.php?section=sm_admins');
        }
    }

    [$data, $errors] = $validate($_POST, $current);
    if ($errors === [])
    {
        $sourcemod->transaction(function (PDO $pdo) use ($isCreate, $current, $data, $adminsTable, $saveGroups, $groupsOfAdmin, &$adminId): void {
            if ($isCreate)
            {
                $pdo->prepare(
                    'INSERT INTO ' . $adminsTable . ' (authtype, identity, password, flags, name, immunity) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $data['authtype'], $data['identity'], $data['password'] !== '' ? $data['password'] : null,
                    $data['flags'], $data['name'], $data['immunity'],
                ]);
                $adminId = (int) $pdo->lastInsertId();
                $saveGroups($pdo, $adminId, $data['groups'], []);
                return;
            }

            // Ohne neue Eingabe bleibt das Passwort, außer es soll entfernt werden.
            $password = $data['password'] !== '' ? $data['password'] : ($data['remove_password'] ? null : $current['password']);
            $pdo->prepare(
                'UPDATE ' . $adminsTable . ' SET authtype = ?, identity = ?, password = ?, flags = ?, name = ?, immunity = ? WHERE id = ?'
            )->execute([
                $data['authtype'], $data['identity'], $password, $data['flags'], $data['name'], $data['immunity'], $adminId,
            ]);
            $saveGroups($pdo, $adminId, $data['groups'], $groupsOfAdmin($adminId));
        });
        Flash::set('success', $lang->t($isCreate ? 'sm_admins.created' : 'sm_admins.saved', ['name' => $data['name']]));
        $redirect('index.php?section=sm_admins');
    }
    $action = $isCreate ? 'create' : 'edit';
}

// --- Löschen -------------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['delete_admin']) && is_int($adminId))
{
    $target = $findAdmin($adminId);
    if ($target === null)
    {
        Flash::set('error', $lang->t('sm_admins.not_found'));
    }
    else
    {
        $sourcemod->deleteAdmin($adminId);
        Flash::set('success', $lang->t('sm_admins.deleted', ['name' => $target['name']]));
    }
    $redirect('index.php?section=sm_admins');
}

// --- Formular (anlegen / bearbeiten) -------------------------------------------------------------------------------
if ($action === 'create' || $action === 'edit')
{
    $isCreate = $action === 'create';
    if ($isCreate)
    {
        $target = ['id' => 0, 'authtype' => 'steam', 'identity' => '', 'password' => null, 'flags' => '', 'name' => '', 'immunity' => 0];
        $memberOf = [];
    }
    else
    {
        $target = is_int($adminId) ? $findAdmin($adminId) : null;
        if ($target === null)
        {
            Flash::set('error', $lang->t('sm_admins.not_found'));
            $redirect('index.php?section=sm_admins');
        }
        $memberOf = array_keys($groupsOfAdmin($adminId));
    }
    $hasPassword = ($target['password'] ?? '') !== '';

    // Nach einem Fehler die eingegebenen Werte wieder anzeigen (Passwörter nicht).
    $form = [
        'name' => (string) $target['name'],
        'authtype' => (string) $target['authtype'],
        'identity' => (string) $target['identity'],
        'immunity' => (string) $target['immunity'],
        'flags' => (string) $target['flags'],
        'remove_password' => false,
    ];
    if (isset($data))
    {
        $form = [
            'name' => $data['name'],
            'authtype' => $data['authtype'],
            'identity' => $data['identity'],
            'immunity' => $data['immunity_input'],
            'flags' => $data['flags'],
            'remove_password' => $data['remove_password'],
        ];
        $memberOf = $data['groups'];
    }

    $authTypes = [];
    foreach (SourceMod::AUTH_TYPES as $type)
    {
        $authTypes[] = ['value' => $type, 'label' => $lang->t('sm.authtype_' . $type), 'selected' => $form['authtype'] === $type];
    }
    $groupRows = array_map(static fn (array $group): array => [
        'id' => $group['id'],
        'name' => $group['name'],
        'flags' => SourceMod::flagPills($group['flags']),
        'immunity_level' => $group['immunity_level'],
        'checked' => in_array($group['id'], $memberOf, true),
    ], $groups);

    $title = $isCreate ? $lang->t('sm_admins.create_title') : $lang->t('sm_admins.edit_title');

    return [
        'title' => $lang->t('nav.sm_admins') . ' - ' . $title,
        'title_path' => [
            ['label' => $lang->t('nav.sm_admins'), 'url' => 'index.php?section=sm_admins'],
            ['label' => $title],
        ],
        'content' => $template->render('pages/sm_admins.html', [
            'show_form' => true,
            'form_title' => $isCreate ? $title : $lang->t('sm_admins.edit_title_name', ['name' => $target['name']]),
            'form_action' => $isCreate ? 'index.php?section=sm_admins&action=create' : 'index.php?section=sm_admins&action=edit&id=' . (int) $target['id'],
            'submit_name' => $isCreate ? 'create_admin' : 'save_admin',
            'admin_id' => (int) $target['id'],
            'has_errors' => $errors !== [],
            'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
            'form' => $form,
            'authtypes' => $authTypes,
            'has_password' => $hasPassword,
            'has_no_password' => !$hasPassword,
            'flag_rows' => SourceMod::flagRows($lang, $form['flags']),
            'group_rows' => $groupRows,
            'has_groups' => $groupRows !== [],
            'has_no_groups' => $groupRows === [],
        ]),
    ];
}

// --- Liste ---------------------------------------------------------------------------------------------------------
$search = trim((string) ($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '')
{
    $where = ' WHERE name LIKE ? OR identity LIKE ?';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $params = [$like, $like];
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $adminsTable . $where);
$countStmt->execute($params);
$pager = new Pager((int) $countStmt->fetchColumn(), $settings->int('sm_per_page', 5, 200), (int) ($_GET['page'] ?? 1));

$listStmt = $pdo->prepare(
    'SELECT id, authtype, identity, password, flags, name, immunity FROM ' . $adminsTable . $where
    . ' ORDER BY name ASC, id ASC LIMIT ' . $pager->perPage . ' OFFSET ' . $pager->offset
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

// Gruppen aller angezeigten Admins mit einer Abfrage holen.
$groupNames = array_column($groups, 'name', 'id');
$groupsByAdmin = [];
if ($rows !== [])
{
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $stmt = $pdo->prepare(
        'SELECT admin_id, group_id FROM ' . $adminsGroupsTable
        . ' WHERE admin_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY inherit_order ASC'
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row)
    {
        $groupId = (int) $row['group_id'];
        // Zuordnungen zu Gruppen, die es nicht mehr gibt, werden nicht angezeigt.
        if (isset($groupNames[$groupId]))
        {
            $groupsByAdmin[(int) $row['admin_id']][] = ['name' => $groupNames[$groupId]];
        }
    }
}

$adminRows = [];
foreach ($rows as $row)
{
    $id = (int) $row['id'];
    $flags = SourceMod::flagPills((string) $row['flags']);
    $adminGroups = $groupsByAdmin[$id] ?? [];
    $adminRows[] = [
        'id' => $id,
        'name' => $row['name'],
        'authtype' => $lang->t('sm.authtype_' . $row['authtype']),
        'identity' => $row['identity'],
        'has_password' => ($row['password'] ?? '') !== '',
        'immunity' => (int) $row['immunity'],
        'flags' => $flags,
        'has_no_flags' => $flags === [],
        'groups' => $adminGroups,
        'has_no_groups' => $adminGroups === [],
        'delete_question' => $lang->t('sm_admins.delete_question', ['name' => $row['name']]),
    ];
}

$pagerView = $pager->view('index.php?section=sm_admins' . ($search !== '' ? '&q=' . rawurlencode($search) : ''));

return [
    'title' => $lang->t('nav.sm_admins'),
    'content' => $template->render('pages/sm_admins.html', [
        'show_list' => true,
        'search' => $search,
        'has_search' => $search !== '',
        'admins' => $adminRows,
        'has_admins' => $adminRows !== [],
        'has_no_admins' => $adminRows === [],
        'pager' => $pagerView,
        'count_text' => $lang->t('common.showing', [
            'from' => $pagerView['from'],
            'to' => $pagerView['to'],
            'total' => $pagerView['total'],
        ]),
    ]),
];
