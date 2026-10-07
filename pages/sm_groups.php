<?php
declare(strict_types=1);

// SourceMod-Gruppen (Recht "sqladmins"): Liste, anlegen, bearbeiten (Flags, Immunität gegenüber anderen Gruppen),
// Gruppen-Overrides, löschen. Mitglieder werden beim Admin zugeordnet und hier nur angezeigt.
// Altes SMWA: groups.php, flags.php (Gruppen-Flags), management.php (Immunität, Mitglieder), overrides.php (Gruppen-Overrides).

if (($blocked = $sourcemodGuard()) !== null)
{
    return $blocked;
}

$groupsTable = $sourcemod->table('groups');
$immunityTable = $sourcemod->table('group_immunity');
$groupOverridesTable = $sourcemod->table('group_overrides');
$adminsGroupsTable = $sourcemod->table('admins_groups');
$action = (string) ($_GET['action'] ?? '');
$groupId = filter_var($_GET['id'] ?? $_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$errors = [];
$overrideErrors = [];
$groups = $sourcemod->groups();
$groupIds = array_map(static fn (array $group): int => $group['id'], $groups);

$findGroup = static function (int $id) use ($pdo, $groupsTable): ?array {
    $stmt = $pdo->prepare('SELECT id, flags, name, immunity_level FROM ' . $groupsTable . ' WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
};

/** @return list<int> Gruppen, gegen deren Admins $id immun ist */
$immuneFrom = static function (int $id) use ($pdo, $immunityTable): array {
    $stmt = $pdo->prepare('SELECT other_id FROM ' . $immunityTable . ' WHERE group_id = ?');
    $stmt->execute([$id]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

$editUrl = static fn (int $id): string => 'index.php?section=sm_groups&action=edit&id=' . $id;

/**
 * Prüft die Eingaben. Gibt die bereinigten Werte und die Fehlermeldungen zurück.
 */
$validate = static function (array $input, int $exceptId) use ($pdo, $groupsTable, $sourcemod, $lang, $groupIds): array {
    $data = [
        'name' => trim((string) ($input['name'] ?? '')),
        'immunity_input' => trim((string) ($input['immunity_level'] ?? '')),
        'immunity_level' => SourceMod::parseImmunity($input['immunity_level'] ?? ''),
        'flags' => SourceMod::normalizeFlags((array) ($input['flags'] ?? [])),
        'immune_from' => array_values(array_diff(
            array_intersect($groupIds, array_map('intval', array_filter((array) ($input['immune_from'] ?? []), 'is_scalar'))),
            [$exceptId]
        )),
    ];
    $errors = [];

    if ($data['name'] === '' || mb_strlen($data['name']) > 120)
    {
        $errors[] = $lang->t('sm_groups.error_name');
    }
    elseif (SourceMod::hasControlChars($data['name']))
    {
        $errors[] = $lang->t('sm.error_control_chars', ['field' => $lang->t('sm_groups.name')]);
    }
    elseif (!$sourcemod->fitsCharset('groups', 'name', $data['name']))
    {
        $errors[] = $lang->t('sm.error_charset', ['field' => $lang->t('sm_groups.name')]);
    }
    else
    {
        // SourceMod sucht Gruppen über den Namen, er muss also eindeutig sein.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $groupsTable . ' WHERE name = ? AND id <> ?');
        $stmt->execute([$data['name'], $exceptId]);
        if ((int) $stmt->fetchColumn() > 0)
        {
            $errors[] = $lang->t('sm_groups.error_name_taken');
        }
    }
    if ($data['immunity_level'] === null)
    {
        $errors[] = $lang->t('sm.error_immunity');
    }

    return [$data, $errors];
};

// --- Anlegen / Speichern -------------------------------------------------------------------------------------------
if ($isPost && (isset($_POST['create_group']) || isset($_POST['save_group'])))
{
    $isCreate = isset($_POST['create_group']);
    if (!$isCreate && (!is_int($groupId) || $findGroup($groupId) === null))
    {
        Flash::set('error', $lang->t('sm_groups.not_found'));
        $redirect('index.php?section=sm_groups');
    }

    [$data, $errors] = $validate($_POST, $isCreate ? 0 : $groupId);
    if ($errors === [])
    {
        $sourcemod->transaction(function (PDO $pdo) use ($isCreate, $data, $groupsTable, $immunityTable, &$groupId): void {
            if ($isCreate)
            {
                $pdo->prepare('INSERT INTO ' . $groupsTable . ' (flags, name, immunity_level) VALUES (?, ?, ?)')
                    ->execute([$data['flags'], $data['name'], $data['immunity_level']]);
                $groupId = (int) $pdo->lastInsertId();
            }
            else
            {
                $pdo->prepare('UPDATE ' . $groupsTable . ' SET flags = ?, name = ?, immunity_level = ? WHERE id = ?')
                    ->execute([$data['flags'], $data['name'], $data['immunity_level'], $groupId]);
            }
            $pdo->prepare('DELETE FROM ' . $immunityTable . ' WHERE group_id = ?')->execute([$groupId]);
            $insert = $pdo->prepare('INSERT INTO ' . $immunityTable . ' (group_id, other_id) VALUES (?, ?)');
            foreach ($data['immune_from'] as $otherId)
            {
                $insert->execute([$groupId, $otherId]);
            }
        });
        Flash::set('success', $lang->t($isCreate ? 'sm_groups.created' : 'sm_groups.saved', ['name' => $data['name']]));
        // Nach dem Anlegen weiter zur Bearbeitung, dort gibt es die Overrides.
        $redirect($isCreate ? $editUrl($groupId) : 'index.php?section=sm_groups');
    }
    $action = $isCreate ? 'create' : 'edit';
}

// --- Löschen -------------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['delete_group']) && is_int($groupId))
{
    $target = $findGroup($groupId);
    if ($target === null)
    {
        Flash::set('error', $lang->t('sm_groups.not_found'));
    }
    else
    {
        $sourcemod->deleteGroup($groupId);
        Flash::set('success', $lang->t('sm_groups.deleted', ['name' => $target['name']]));
    }
    $redirect('index.php?section=sm_groups');
}

// --- Gruppen-Overrides ---------------------------------------------------------------------------------------------
if ($isPost && (isset($_POST['add_override']) || isset($_POST['delete_override'])) && is_int($groupId))
{
    if ($findGroup($groupId) === null)
    {
        Flash::set('error', $lang->t('sm_groups.not_found'));
        $redirect('index.php?section=sm_groups');
    }
    $type = (string) ($_POST['override_type'] ?? '');
    $name = trim((string) ($_POST['override_name'] ?? ''));
    $access = (string) ($_POST['override_access'] ?? '');

    if (isset($_POST['delete_override']))
    {
        $pdo->prepare('DELETE FROM ' . $groupOverridesTable . ' WHERE group_id = ? AND type = ? AND name = ?')
            ->execute([$groupId, $type, $name]);
        Flash::set('success', $lang->t('sm_groups.override_deleted', ['name' => $name]));
        $redirect($editUrl($groupId));
    }

    if (!in_array($type, SourceMod::OVERRIDE_TYPES, true))
    {
        $overrideErrors[] = $lang->t('sm.error_override_type');
    }
    if (!in_array($access, SourceMod::OVERRIDE_ACCESS, true))
    {
        $overrideErrors[] = $lang->t('sm_groups.error_override_access');
    }
    if (preg_match('/^\S{1,32}$/u', $name) !== 1)
    {
        $overrideErrors[] = $lang->t('sm.error_override_name');
    }
    elseif (!$sourcemod->fitsCharset('group_overrides', 'name', $name))
    {
        $overrideErrors[] = $lang->t('sm.error_charset', ['field' => $lang->t('sm.override_name')]);
    }
    if ($overrideErrors === [])
    {
        // Gibt es den Override schon, ändert sich nur der Zugang.
        $pdo->prepare(
            'INSERT INTO ' . $groupOverridesTable . ' (group_id, type, name, access) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE access = VALUES(access)'
        )->execute([$groupId, $type, $name, $access]);
        Flash::set('success', $lang->t('sm_groups.override_saved', ['name' => $name]));
        $redirect($editUrl($groupId));
    }
    $action = 'edit';
}

// --- Formular (anlegen / bearbeiten) -------------------------------------------------------------------------------
if ($action === 'create' || $action === 'edit')
{
    $isCreate = $action === 'create';
    if ($isCreate)
    {
        $target = ['id' => 0, 'flags' => '', 'name' => '', 'immunity_level' => 0];
        $immuneFromIds = [];
    }
    else
    {
        $target = is_int($groupId) ? $findGroup($groupId) : null;
        if ($target === null)
        {
            Flash::set('error', $lang->t('sm_groups.not_found'));
            $redirect('index.php?section=sm_groups');
        }
        $immuneFromIds = $immuneFrom($groupId);
    }
    $currentName = (string) $target['name'];

    $form = [
        'name' => $currentName,
        'immunity_level' => (string) $target['immunity_level'],
        'flags' => (string) $target['flags'],
    ];
    if (isset($data))
    {
        $form = ['name' => $data['name'], 'immunity_level' => $data['immunity_input'], 'flags' => $data['flags']];
        $immuneFromIds = $data['immune_from'];
    }

    $immunityRows = [];
    foreach ($groups as $group)
    {
        if ($group['id'] !== (int) $target['id'])
        {
            $immunityRows[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'immunity_level' => $group['immunity_level'],
                'checked' => in_array($group['id'], $immuneFromIds, true),
            ];
        }
    }

    // Overrides und Mitglieder gibt es erst bei einer gespeicherten Gruppe.
    $overrideRows = [];
    $memberRows = [];
    if (!$isCreate)
    {
        $stmt = $pdo->prepare('SELECT type, name, access FROM ' . $groupOverridesTable . ' WHERE group_id = ? ORDER BY type ASC, name ASC');
        $stmt->execute([$groupId]);
        foreach ($stmt->fetchAll() as $index => $row)
        {
            $overrideRows[] = [
                'index' => $index,
                'type' => $row['type'],
                'type_label' => $lang->t('sm.override_type_' . $row['type']),
                'name' => $row['name'],
                'access_label' => $lang->t('sm_groups.access_' . $row['access']),
                'is_allow' => $row['access'] === 'allow',
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT a.id, a.name, a.authtype, a.identity FROM ' . $sourcemod->table('admins') . ' a JOIN ' . $adminsGroupsTable
            . ' ag ON ag.admin_id = a.id WHERE ag.group_id = ? ORDER BY a.name ASC'
        );
        $stmt->execute([$groupId]);
        foreach ($stmt->fetchAll() as $row)
        {
            $memberRows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'authtype' => $lang->t('sm.authtype_' . $row['authtype']),
                'identity' => $row['identity'],
            ];
        }
    }

    $overrideForm = [
        'type' => (string) ($_POST['override_type'] ?? 'command'),
        'name' => $overrideErrors !== [] ? trim((string) ($_POST['override_name'] ?? '')) : '',
        'access' => (string) ($_POST['override_access'] ?? 'allow'),
    ];
    $overrideTypes = array_map(static fn (string $type): array => [
        'value' => $type,
        'label' => $lang->t('sm.override_type_' . $type),
        'selected' => $overrideForm['type'] === $type,
    ], SourceMod::OVERRIDE_TYPES);
    $accessOptions = array_map(static fn (string $access): array => [
        'value' => $access,
        'label' => $lang->t('sm_groups.access_' . $access),
        'selected' => $overrideForm['access'] === $access,
    ], SourceMod::OVERRIDE_ACCESS);

    $title = $isCreate ? $lang->t('sm_groups.create_title') : $lang->t('sm_groups.edit_title');

    return [
        'title' => $lang->t('nav.sm_groups') . ' - ' . $title,
        'title_path' => [
            ['label' => $lang->t('nav.sm_groups'), 'url' => 'index.php?section=sm_groups'],
            ['label' => $title],
        ],
        'content' => $template->render('pages/sm_groups.html', [
            'show_form' => true,
            'is_edit' => !$isCreate,
            'form_title' => $isCreate ? $title : $lang->t('sm_groups.edit_title_name', ['name' => $currentName]),
            'form_action' => $isCreate ? 'index.php?section=sm_groups&action=create' : $editUrl((int) $target['id']),
            'submit_name' => $isCreate ? 'create_group' : 'save_group',
            'group_id' => (int) $target['id'],
            'has_errors' => $errors !== [],
            'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
            'form' => $form,
            'flag_rows' => SourceMod::flagRows($lang, $form['flags']),
            'immunity_rows' => $immunityRows,
            'has_immunity_rows' => $immunityRows !== [],
            'has_no_immunity_rows' => $immunityRows === [],
            'has_override_errors' => $overrideErrors !== [],
            'override_errors' => array_map(static fn (string $error): array => ['text' => $error], $overrideErrors),
            'overrides' => $overrideRows,
            'has_overrides' => $overrideRows !== [],
            'has_no_overrides' => $overrideRows === [],
            'override_form' => $overrideForm,
            'override_types' => $overrideTypes,
            'access_options' => $accessOptions,
            'members' => $memberRows,
            'has_members' => $memberRows !== [],
            'has_no_members' => $memberRows === [],
            'members_title' => $lang->t('sm_groups.members_title', ['count' => count($memberRows)]),
        ]),
    ];
}

// --- Liste ---------------------------------------------------------------------------------------------------------
$search = trim((string) ($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '')
{
    $where = ' WHERE g.name LIKE ?';
    $params = ['%' . addcslashes($search, '%_\\') . '%'];
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $groupsTable . ' g' . $where);
$countStmt->execute($params);
$pager = new Pager((int) $countStmt->fetchColumn(), $settings->int('sm_per_page', 5, 200), (int) ($_GET['page'] ?? 1));

$listStmt = $pdo->prepare(
    'SELECT g.id, g.name, g.flags, g.immunity_level,'
    . ' (SELECT COUNT(*) FROM ' . $adminsGroupsTable . ' ag WHERE ag.group_id = g.id) AS members,'
    . ' (SELECT COUNT(*) FROM ' . $groupOverridesTable . ' gov WHERE gov.group_id = g.id) AS overrides'
    . ' FROM ' . $groupsTable . ' g' . $where
    . ' ORDER BY g.immunity_level DESC, g.name ASC LIMIT ' . $pager->perPage . ' OFFSET ' . $pager->offset
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

// Immunitäten aller angezeigten Gruppen mit einer Abfrage holen.
$groupNames = array_column($groups, 'name', 'id');
$immunityByGroup = [];
if ($rows !== [])
{
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $stmt = $pdo->prepare(
        'SELECT group_id, other_id FROM ' . $immunityTable . ' WHERE group_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row)
    {
        $otherId = (int) $row['other_id'];
        if (isset($groupNames[$otherId]))
        {
            $immunityByGroup[(int) $row['group_id']][] = ['name' => $groupNames[$otherId]];
        }
    }
}

$groupRows = [];
foreach ($rows as $row)
{
    $id = (int) $row['id'];
    $flags = SourceMod::flagPills((string) $row['flags']);
    $immune = $immunityByGroup[$id] ?? [];
    $groupRows[] = [
        'id' => $id,
        'name' => $row['name'],
        'immunity_level' => (int) $row['immunity_level'],
        'flags' => $flags,
        'has_no_flags' => $flags === [],
        'members' => (int) $row['members'],
        'overrides' => (int) $row['overrides'],
        'immune_from' => $immune,
        'has_no_immune_from' => $immune === [],
        'delete_question' => $lang->t('sm_groups.delete_question', ['name' => $row['name']]),
    ];
}

$pagerView = $pager->view('index.php?section=sm_groups' . ($search !== '' ? '&q=' . rawurlencode($search) : ''));

return [
    'title' => $lang->t('nav.sm_groups'),
    'content' => $template->render('pages/sm_groups.html', [
        'show_list' => true,
        'search' => $search,
        'has_search' => $search !== '',
        'groups' => $groupRows,
        'has_groups' => $groupRows !== [],
        'has_no_groups' => $groupRows === [],
        'pager' => $pagerView,
        'count_text' => $lang->t('common.showing', [
            'from' => $pagerView['from'],
            'to' => $pagerView['to'],
            'total' => $pagerView['total'],
        ]),
    ]),
];
