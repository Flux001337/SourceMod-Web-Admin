<?php
declare(strict_types=1);

/**
 * Rechte der Oberfläche. Wie im alten SMWA hängen die Rechte direkt am Benutzer (Tabelle user_permissions), nicht an
 * Gruppen. Der Owner (users.is_owner) hat immer alle Rechte und ist geschützt.
 *
 * Grundsatz für die Verwaltung: Wer kein Owner ist, darf nur Rechte vergeben oder entziehen, die er selbst besitzt. So
 * kann niemand über die Benutzerverwaltung mehr Rechte erlangen, als er schon hat.
 */
final class Permissions
{
    /**
     * Alle Rechte in Anzeigereihenfolge. Die Beschriftungen stehen in lang/*.json unter "permissions".
     * Altes SMWA: UserEditUsers, UserEditPermissions, UserEditInterfacesettings, UserSQLAdmins, UserServersettings,
     * UserEditMods, UserPlugincontrol. Neu: console (RCON-Konsole).
     */
    public const ALL = ['users', 'permissions', 'settings', 'sqladmins', 'servers', 'games', 'plugincontrol', 'console'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<string> */
    public function ofUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT permission FROM ' . $this->db->table('user_permissions') . ' WHERE user_id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $stored = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Reihenfolge wie ALL, unbekannte Einträge fallen weg.
        return array_values(array_intersect(self::ALL, $stored));
    }

    /**
     * Rechte, die $actor vergeben oder entziehen darf: der Owner alle, sonst die eigenen.
     *
     * @return list<string>
     */
    public function grantableBy(array $actor, array $actorPermissions): array
    {
        return (bool) $actor['is_owner'] ? self::ALL : array_values(array_intersect(self::ALL, $actorPermissions));
    }

    /**
     * Speichert die Rechte von $userId. Rechte außerhalb von $grantable bleiben unverändert, auch wenn das Formular sie
     * (manipuliert) mitschickt oder weglässt.
     *
     * @param list<string> $submitted
     * @param list<string> $grantable
     */
    public function save(int $userId, array $submitted, array $grantable): void
    {
        $current = $this->ofUser($userId);
        $kept = array_diff($current, $grantable);
        $granted = array_intersect($grantable, $submitted);
        $new = array_values(array_intersect(self::ALL, array_unique(array_merge($kept, $granted))));

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try
        {
            $pdo->prepare('DELETE FROM ' . $this->db->table('user_permissions') . ' WHERE user_id = :id')
                ->execute(['id' => $userId]);
            $insert = $pdo->prepare(
                'INSERT INTO ' . $this->db->table('user_permissions') . ' (user_id, permission) VALUES (:id, :permission)'
            );
            foreach ($new as $permission)
            {
                $insert->execute(['id' => $userId, 'permission' => $permission]);
            }
            $pdo->commit();
        }
        catch (Throwable $exception)
        {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Darf $actor das Konto $target bearbeiten (Name, E-Mail, Passwort, Sprache)? Den Owner bearbeitet nur er selbst
     * (über das Profil). Wer kein Owner ist, nur Konten ohne Rechte, die er selbst nicht hat: Sonst könnte er deren
     * Passwort setzen, sich damit anmelden und so mehr Rechte erlangen.
     *
     * @param list<string> $targetPermissions
     */
    public static function canEditUser(array $actor, array $actorPermissions, array $target, array $targetPermissions): bool
    {
        if ((bool) $target['is_owner'])
        {
            return (bool) $actor['is_owner'];
        }
        if ((bool) $actor['is_owner'])
        {
            return true;
        }
        if ((int) $actor['id'] === (int) $target['id'])
        {
            return in_array('users', $actorPermissions, true);
        }

        return in_array('users', $actorPermissions, true) && array_diff($targetPermissions, $actorPermissions) === [];
    }

    /**
     * Darf $actor die Rechte von $target ändern? Nie die des Owners (hat ohnehin alle) und nie die eigenen.
     */
    public static function canEditPermissions(array $actor, array $actorPermissions, array $target): bool
    {
        if ((bool) $target['is_owner'] || (int) $actor['id'] === (int) $target['id'])
        {
            return false;
        }

        return (bool) $actor['is_owner'] || in_array('permissions', $actorPermissions, true);
    }

    /**
     * Darf $actor das Konto $target löschen? Nie den Owner und nie sich selbst; wer kein Owner ist, nur Konten ohne
     * Rechte, die er selbst nicht hat.
     *
     * @param list<string> $targetPermissions
     */
    public static function canDeleteUser(array $actor, array $actorPermissions, array $target, array $targetPermissions): bool
    {
        if ((bool) $target['is_owner'] || (int) $actor['id'] === (int) $target['id'])
        {
            return false;
        }

        return (bool) $actor['is_owner']
            || (in_array('users', $actorPermissions, true) && array_diff($targetPermissions, $actorPermissions) === []);
    }
}
