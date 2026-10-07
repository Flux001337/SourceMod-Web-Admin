<?php
declare(strict_types=1);

// Games (Recht "games"): Spielordner mit Name und Icon. Die Server-Liste erkennt das Game am Ordner, den der Server
// bei der Abfrage meldet. Altes SMWA: mods.php ("Server Mods"; die Spalte advert gehörte zum entfernten
// Advertisements-Plugin und entfällt).

if (!$auth->hasPermission('games'))
{
    return $forbidden();
}

$gamesTable = $db->table('games');
$action = (string) ($_GET['action'] ?? '');
$gameId = filter_var($_GET['id'] ?? $_POST['game_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$errors = [];

$findGame = static function (int $id) use ($pdo, $gamesTable): ?array {
    $stmt = $pdo->prepare('SELECT id, name, folder, icon FROM ' . $gamesTable . ' WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
};

// --- Anlegen / Speichern -------------------------------------------------------------------------------------------
if ($isPost && (isset($_POST['create_game']) || isset($_POST['save_game'])))
{
    $isCreate = isset($_POST['create_game']);
    $current = null;
    if (!$isCreate)
    {
        $current = is_int($gameId) ? $findGame($gameId) : null;
        if ($current === null)
        {
            Flash::set('error', $lang->t('games.not_found'));
            $redirect('index.php?section=games');
        }
    }

    $data = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'folder' => trim((string) ($_POST['folder'] ?? '')),
        'remove_icon' => isset($_POST['remove_icon']),
    ];
    if ($data['name'] === '' || mb_strlen($data['name']) > 100)
    {
        $errors[] = $lang->t('games.error_name');
    }
    if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $data['folder']) !== 1)
    {
        $errors[] = $lang->t('games.error_folder');
    }
    else
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $gamesTable . ' WHERE folder = ? AND id <> ?');
        $stmt->execute([$data['folder'], (int) ($current['id'] ?? 0)]);
        if ((int) $stmt->fetchColumn() > 0)
        {
            $errors[] = $lang->t('games.error_folder_taken');
        }
    }

    // Das Bild erst speichern, wenn alles andere stimmt (sonst bliebe eine verwaiste Datei).
    $upload = $_FILES['icon'] ?? null;
    $hasUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $icon = $current['icon'] ?? null;
    if ($errors === [] && $hasUpload)
    {
        try
        {
            $icon = GameIcon::storeUpload($upload);
        }
        catch (GameIconException $exception)
        {
            $errors[] = $lang->t($exception->getMessage(), ['size' => GameIcon::MAX_SIZE]);
        }
    }
    elseif ($data['remove_icon'])
    {
        $icon = null;
    }

    if ($errors === [])
    {
        if ($isCreate)
        {
            $pdo->prepare('INSERT INTO ' . $gamesTable . ' (name, folder, icon) VALUES (?, ?, ?)')
                ->execute([$data['name'], $data['folder'], $icon]);
        }
        else
        {
            $pdo->prepare('UPDATE ' . $gamesTable . ' SET name = ?, folder = ?, icon = ? WHERE id = ?')
                ->execute([$data['name'], $data['folder'], $icon, $gameId]);
            if ($icon !== $current['icon'])
            {
                GameIcon::delete($current['icon']);
            }
        }
        Flash::set('success', $lang->t($isCreate ? 'games.created' : 'games.saved', ['name' => $data['name']]));
        $redirect('index.php?section=games');
    }
    $action = $isCreate ? 'create' : 'edit';
}

// --- Löschen -------------------------------------------------------------------------------------------------------
if ($isPost && isset($_POST['delete_game']) && is_int($gameId))
{
    $target = $findGame($gameId);
    if ($target === null)
    {
        Flash::set('error', $lang->t('games.not_found'));
    }
    else
    {
        $pdo->prepare('DELETE FROM ' . $gamesTable . ' WHERE id = ?')->execute([$gameId]);
        GameIcon::delete($target['icon']);
        Flash::set('success', $lang->t('games.deleted', ['name' => $target['name']]));
    }
    $redirect('index.php?section=games');
}

// --- Formular (anlegen / bearbeiten) -------------------------------------------------------------------------------
if ($action === 'create' || $action === 'edit')
{
    $isCreate = $action === 'create';
    if ($isCreate)
    {
        $target = ['id' => 0, 'name' => '', 'folder' => '', 'icon' => null];
    }
    else
    {
        $target = is_int($gameId) ? $findGame($gameId) : null;
        if ($target === null)
        {
            Flash::set('error', $lang->t('games.not_found'));
            $redirect('index.php?section=games');
        }
    }
    $form = isset($data) ? $data : ['name' => $target['name'], 'folder' => $target['folder'], 'remove_icon' => false];
    $iconUrl = GameIcon::url($target['icon']);
    $title = $isCreate ? $lang->t('games.create_title') : $lang->t('games.edit_title');

    return [
        'title' => $lang->t('nav.games') . ' - ' . $title,
        'title_path' => [
            ['label' => $lang->t('nav.games'), 'url' => 'index.php?section=games'],
            ['label' => $title],
        ],
        'content' => $template->render('pages/games.html', [
            'show_form' => true,
            'form_title' => $isCreate ? $title : $lang->t('games.edit_title_name', ['name' => $target['name']]),
            'form_action' => $isCreate ? 'index.php?section=games&action=create' : 'index.php?section=games&action=edit&id=' . (int) $target['id'],
            'submit_name' => $isCreate ? 'create_game' : 'save_game',
            'game_id' => (int) $target['id'],
            'has_errors' => $errors !== [],
            'errors' => array_map(static fn (string $error): array => ['text' => $error], $errors),
            'form' => $form,
            'icon_url' => $iconUrl,
            'has_icon' => $iconUrl !== '',
            'icon_hint' => $lang->t('games.icon_hint', ['size' => GameIcon::MAX_SIZE]),
        ]),
    ];
}

// --- Liste ---------------------------------------------------------------------------------------------------------
$gameRows = [];
foreach ($pdo->query('SELECT id, name, folder, icon FROM ' . $gamesTable . ' ORDER BY name ASC') as $row)
{
    $iconUrl = GameIcon::url($row['icon']);
    $gameRows[] = [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'folder' => $row['folder'],
        'icon_url' => $iconUrl,
        'has_icon' => $iconUrl !== '',
        'has_no_icon' => $iconUrl === '',
        'delete_question' => $lang->t('games.delete_question', ['name' => $row['name']]),
    ];
}

return [
    'title' => $lang->t('nav.games'),
    'content' => $template->render('pages/games.html', [
        'show_list' => true,
        'games' => $gameRows,
        'has_games' => $gameRows !== [],
        'has_no_games' => $gameRows === [],
        'count_text' => $lang->t('games.count', ['count' => count($gameRows)]),
    ]),
];
