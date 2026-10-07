<?php
declare(strict_types=1);

// Globale SourceMod-Overrides (Recht "sqladmins"): welche Flags ein Befehl oder eine Befehlsgruppe verlangt.
// Altes SMWA: overrides.php (section=overrides). Gruppen-Overrides stehen bei der Gruppe (sm_groups.php).

if (($blocked = $sourcemodGuard()) !== null)
{
    return $blocked;
}

$overridesTable = $sourcemod->table('overrides');
$action = (string) ($_GET['action'] ?? '');
$errors = [];

// Der Schlüssel eines Overrides ist Typ + Name.
$keyType = (string) ($_GET['type'] ?? $_POST['original_type'] ?? '');
$keyName = (string) ($_GET['name'] ?? $_POST['original_name'] ?? '');

$findOverride = static function (string $type, string $name) use ($pdo, $overridesTable): ?array {
    $stmt = $pdo->prepare('SELECT type, name, flags FROM ' . $overridesTable . ' WHERE type = ? AND name = ?');
    $stmt->execute([$type, $name]);
    $row = $stmt->fetch();

    return $row ?: null;
};

$editUrl = static fn (string $type, string $name): string => 'index.php?section=sm_overrides&action=edit&type='
    . rawurlencode($type) . '&name=' . rawurlencode($name);

// --- Anlegen / Speichern -------------------------------------------------------------------------------------------
if ($isPost && (isset($_POST['create_override']) || isset($_POST['save_override'])))
{
    $isCreate = isset($_POST['create_override']);
    $current = $isCreate ? null : $findOverride($keyType, $keyName);
    if (!$isCreate && $current === null)
    {
        Flash::set('error', $lang->t('sm_overrides.not_found'));
        $redirect('index.php?section=sm_overrides');
    }

    $data = [
        'type' => (string) ($_POST['type'] ?? ''),
        'name' => trim((string) ($_POST['name'] ?? '')),
        'flags' => SourceMod::normalizeFlags((array) ($_POST['flags'] ?? [])),
    ];
    if (!in_array($data['type'], SourceMod::OVERRIDE_TYPES, true))
    {
        $errors[] = $lang->t('sm.error_override_type');
    }
    if (preg_match('/^\S{1,32}$/u', $data['name']) !== 1)
    {
        $errors[] = $lang->t('sm.error_override_name');
    }
    elseif (!$sourcemod->fitsCharset('overrides', 'name', $data['name']))
    {
        $errors[] = $lang->t('sm.error_charset', ['field' => $lang->t('sm.override_name')]);
    }
    $keyChanged = $isCreate || $data['type'] !== $current['type'] || $data['name'] !== $current['name'];
    if ($errors === [] && $keyChanged)
    {
        $existing = $findOverride($data['type'], $data['name']);
        // Beim Bearbeiten darf sich nur die Schreibweise des eigenen Namens ändern (Vergleich ohne Groß-/Kleinschreibung).
        if ($existing !== null && ($isCreate || $existing['type'] !== $current['type'] || $existing['name'] !== $current['name']))
        {
            $errors[] = $lang->t('sm_overrides.error_exists');
        }
    }

    if ($errors === [])
    {
        if ($isCreate)
        {
            $pdo->prepare('INSERT INTO ' . $overridesTable . ' (type, name, flags) VALUES (?, ?, ?)')
                ->execute([$data['type'], $data['name'], $data['flags']]);
        }
        else
        {
            $pdo->prepare('UPDATE ' . $overridesTable . ' SET type = ?, name = ?, flags = ? WHERE type = ? AND name = ?')
                ->execute([$data['type'], $data['name'], $data['flags'], $current['type'], $current['name']]);
        }
        Flash::set('success', $lang->t($isCreate ? 'sm_overrides.created' : 'sm_overrides.saved', ['name' => $data['name']]));
        $redirect('index.php?section=sm_overrides');
    }
    $action = $isCreate ? 'create' : 'edit';
}

// --- Löschen -------------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['delete_override']))
{
    $target = $findOverride($keyType, $keyName);
    if ($target === null)
    {
        Flash::set('error', $lang->t('sm_overrides.not_found'));
    }
    else
    {
        $pdo->prepare('DELETE FROM ' . $overridesTable . ' WHERE type = ? AND name = ?')->execute([$target['type'], $target['name']]);
        Flash::set('success', $lang->t('sm_overrides.deleted', ['name' => $target['name']]));
    }
    $redirect('index.php?section=sm_overrides');
}

// --- Formular (anlegen / bearbeiten) -------------------------------------------------------------------------------
if ($action === 'create' || $action === 'edit')
{
    $isCreate = $action === 'create';
    if ($isCreate)
    {
        $target = ['type' => 'command', 'name' => '', 'flags' => ''];
    }
    else
    {
        $target = $findOverride($keyType, $keyName);
        if ($target === null)
        {
            Flash::set('error', $lang->t('sm_overrides.not_found'));
            $redirect('index.php?section=sm_overrides');
        }
    }
    $form = isset($data) ? $data : $target;

    $types = array_map(static fn (string $type): array => [
        'value' => $type,
        'label' => $lang->t('sm.override_type_' . $type),
        'selected' => $form['type'] === $type,
    ], SourceMod::OVERRIDE_TYPES);

    $title = $isCreate ? $lang->t('sm_overrides.create_title') : $lang->t('sm_overrides.edit_title');

    return [
        'title' => $lang->t('nav.sm_overrides') . ' - ' . $title,
        'title_path' => [
            ['label' => $lang->t('nav.sm_overrides'), 'url' => 'index.php?section=sm_overrides'],
            ['label' => $title],
        ],
        'content' => $template->render('pages/sm_overrides.html', [
            'show_form' => true,
            'form_title' => $isCreate ? $title : $lang->t('sm_overrides.edit_title_name', ['name' => $target['name']]),
            'form_action' => $isCreate ? 'index.php?section=sm_overrides&action=create' : $editUrl($target['type'], $target['name']),
            'submit_name' => $isCreate ? 'create_override' : 'save_override',
            'original_type' => $target['type'],
            'original_name' => $target['name'],
            'has_errors' => $errors !== [],
            'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
            'form' => $form,
            'types' => $types,
            'flag_rows' => SourceMod::flagRows($lang, (string) $form['flags']),
        ]),
    ];
}

// --- Liste ---------------------------------------------------------------------------------------------------------
$search = trim((string) ($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '')
{
    $where = ' WHERE name LIKE ?';
    $params = ['%' . addcslashes($search, '%_\\') . '%'];
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $overridesTable . $where);
$countStmt->execute($params);
$pager = new Pager((int) $countStmt->fetchColumn(), $settings->int('sm_per_page', 5, 200), (int) ($_GET['page'] ?? 1));

$listStmt = $pdo->prepare(
    'SELECT type, name, flags FROM ' . $overridesTable . $where
    . ' ORDER BY type ASC, name ASC LIMIT ' . $pager->perPage . ' OFFSET ' . $pager->offset
);
$listStmt->execute($params);

$overrideRows = [];
foreach ($listStmt->fetchAll() as $index => $row)
{
    $flags = SourceMod::flagPills((string) $row['flags']);
    $overrideRows[] = [
        'index' => $index,
        'type' => $row['type'],
        'type_label' => $lang->t('sm.override_type_' . $row['type']),
        'name' => $row['name'],
        'edit_url' => $editUrl((string) $row['type'], (string) $row['name']),
        'flags' => $flags,
        'has_no_flags' => $flags === [],
        'delete_question' => $lang->t('sm_overrides.delete_question', ['name' => $row['name']]),
    ];
}

$pagerView = $pager->view('index.php?section=sm_overrides' . ($search !== '' ? '&q=' . rawurlencode($search) : ''));

return [
    'title' => $lang->t('nav.sm_overrides'),
    'content' => $template->render('pages/sm_overrides.html', [
        'show_list' => true,
        'search' => $search,
        'has_search' => $search !== '',
        'overrides' => $overrideRows,
        'has_overrides' => $overrideRows !== [],
        'has_no_overrides' => $overrideRows === [],
        'pager' => $pagerView,
        'count_text' => $lang->t('common.showing', [
            'from' => $pagerView['from'],
            'to' => $pagerView['to'],
            'total' => $pagerView['total'],
        ]),
    ]),
];
