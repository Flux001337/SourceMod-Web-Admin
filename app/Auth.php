<?php
declare(strict_types=1);

/**
 * Anmeldung, Sitzung und "Passwort merken". Die Rechte des angemeldeten Benutzers liefert hasPermission() (Owner: alle).
 */
final class Auth
{
    private const SESSION_KEY = 'user_id';
    private const REMEMBER_COOKIE = 'smwa_remember';
    private const REMEMBER_DAYS = 30;
    // Ändert sich der Passwort-Hash, endet jede ältere Sitzung dieses Kontos.
    private const STAMP_KEY = 'password_stamp';
    // Gültiger Hash eines Zufallspassworts: Bei unbekannten Benutzern wird trotzdem ein Passwort geprüft, damit die
    // Antwortzeit keinen Rückschluss auf das Konto zulässt (auch beim Update in install/).
    public const DUMMY_HASH = '$2y$12$Z.grsAJ2irKnX.bxBfj10eJF/4cvTrB3MEjiDA4bOFz8VIjuL3qVC';
    private const USER_COLUMNS = 'id, username, email, password_hash, language, theme, is_owner, created_at, last_login_at';
    // Aus SMWA 2.x übernommene Passwörter: dort MD5 des Passworts, hier "md5:" + password_hash() dieses MD5. Bei der
    // nächsten Anmeldung wird daraus ein normaler Hash.
    private const LEGACY_PREFIX = 'md5:';

    /** Angemeldeter Benutzer dieses Aufrufs (false = noch nicht geladen). */
    private array|null|false $user = false;
    /** @var list<string>|null */
    private ?array $permissions = null;

    public function __construct(private readonly Database $db, private readonly Permissions $permissionStore)
    {
    }

    public function login(string $username, string $password, bool $remember = false): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, password_hash FROM ' . $this->db->table('users') . ' WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        $passwordMatches = self::verifyHash($password, $user ? (string) $user['password_hash'] : self::DUMMY_HASH);
        if (!$user || !$passwordMatches)
        {
            return false;
        }
        if (self::needsRehash((string) $user['password_hash']))
        {
            $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->db->pdo()->prepare('UPDATE ' . $this->db->table('users') . ' SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => $user['password_hash'], 'id' => $user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $_SESSION[self::STAMP_KEY] = self::stamp((string) $user['password_hash']);
        $this->forget();

        if ($remember)
        {
            $this->createRememberToken((int) $user['id']);
        }

        $this->db->pdo()->prepare('UPDATE ' . $this->db->table('users') . ' SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);

        return true;
    }

    public function restoreRememberedLogin(): void
    {
        if ($this->check() || !isset($_COOKIE[self::REMEMBER_COOKIE]))
        {
            return;
        }

        $parts = explode(':', (string) $_COOKIE[self::REMEMBER_COOKIE], 2);
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{24}$/', $parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1]))
        {
            $this->clearRememberCookie();
            return;
        }

        [$selector, $validator] = $parts;
        $stmt = $this->db->pdo()->prepare(
            'SELECT rt.user_id, rt.token_hash, u.password_hash
             FROM ' . $this->db->table('remember_tokens') . ' rt
             INNER JOIN ' . $this->db->table('users') . ' u ON u.id = rt.user_id
             WHERE rt.selector = :selector AND rt.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['selector' => $selector]);
        $token = $stmt->fetch();

        if (!$token || !hash_equals($token['token_hash'], hash('sha256', $validator)))
        {
            $this->deleteRememberToken($selector);
            $this->clearRememberCookie();
            return;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $token['user_id'];
        $_SESSION[self::STAMP_KEY] = self::stamp((string) $token['password_hash']);
        $this->forget();
        // Jeder Token gilt nur einmal und wird beim Einlösen ersetzt.
        $this->deleteRememberToken($selector);
        $this->createRememberToken((int) $token['user_id']);
    }

    public function logout(): void
    {
        $this->deleteRememberTokenFromCookie();
        $this->clearRememberCookie();
        $_SESSION = [];
        $this->forget();

        if (ini_get('session.use_cookies'))
        {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $user = $this->user();

        return $user !== null ? (int) $user['id'] : null;
    }

    /**
     * Der angemeldete Benutzer (ohne Passwort-Hash) oder null. Einmal je Aufruf geladen.
     */
    public function user(): ?array
    {
        if ($this->user !== false)
        {
            return $this->user;
        }

        $this->user = null;
        $userId = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_int($userId))
        {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::USER_COLUMNS . ' FROM ' . $this->db->table('users') . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user)
        {
            unset($_SESSION[self::SESSION_KEY], $_SESSION[self::STAMP_KEY]);
            return null;
        }

        $stamp = self::stamp((string) $user['password_hash']);
        if (!isset($_SESSION[self::STAMP_KEY]) || !is_string($_SESSION[self::STAMP_KEY])
            || !hash_equals($_SESSION[self::STAMP_KEY], $stamp))
        {
            // Das Passwort wurde inzwischen geändert: ältere Sitzungen enden.
            unset($_SESSION[self::SESSION_KEY], $_SESSION[self::STAMP_KEY]);
            return null;
        }

        unset($user['password_hash']);
        $user['is_owner'] = (bool) $user['is_owner'];

        return $this->user = $user;
    }

    public function isOwner(): bool
    {
        return (bool) ($this->user()['is_owner'] ?? false);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $user = $this->user();
        if ($user === null)
        {
            return [];
        }

        return $this->permissions ??= $user['is_owner'] ? Permissions::ALL : $this->permissionStore->ofUser((int) $user['id']);
    }

    public function hasPermission(string $permission): bool
    {
        // Abgeschaltete Bereiche gibt es für niemanden, auch wenn der Benutzer das Recht hat.
        return in_array($permission, $this->permissions(), true) && !$this->permissionStore->isDisabled($permission);
    }

    /**
     * Prüft das Passwort des angemeldeten Benutzers (z. B. vor einer Passwortänderung im Profil).
     */
    public function verifyPassword(string $password): bool
    {
        $userId = $this->id();
        if ($userId === null)
        {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM ' . $this->db->table('users') . ' WHERE id = :id');
        $stmt->execute(['id' => $userId]);

        return self::verifyHash($password, (string) $stmt->fetchColumn());
    }

    /**
     * Prüft ein Passwort gegen einen gespeicherten Hash, auch gegen aus SMWA 2.x übernommene. Das alte SMWA bildete den
     * MD5 in MySQL über die Bytes, die der Browser schickte (Seiten in UTF-8 oder ISO-8859-1), daher beide Varianten.
     */
    public static function verifyHash(string $password, string $hash): bool
    {
        if (!str_starts_with($hash, self::LEGACY_PREFIX))
        {
            return password_verify($password, $hash);
        }
        $legacy = substr($hash, strlen(self::LEGACY_PREFIX));
        $latin1 = mb_convert_encoding($password, 'ISO-8859-1', 'UTF-8');

        return password_verify(md5($password), $legacy) || ($latin1 !== $password && password_verify(md5($latin1), $legacy));
    }

    /** Gespeicherter Wert für einen MD5-Hash aus SMWA 2.x (32 Hex-Zeichen). */
    public static function legacyHash(string $md5): string
    {
        return self::LEGACY_PREFIX . password_hash(strtolower($md5), PASSWORD_DEFAULT);
    }

    private static function needsRehash(string $hash): bool
    {
        return str_starts_with($hash, self::LEGACY_PREFIX) || password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Nach einer Passwortänderung im eigenen Konto aufrufen: Diese Sitzung bleibt gültig (mit neuer Session-ID), alle
     * anderen Sitzungen und gemerkten Anmeldungen enden.
     */
    public function refreshSession(): void
    {
        $userId = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_int($userId))
        {
            return;
        }

        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM ' . $this->db->table('users') . ' WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $hash = $stmt->fetchColumn();
        if (is_string($hash))
        {
            $_SESSION[self::STAMP_KEY] = self::stamp($hash);
            session_regenerate_id(true);
        }
        $this->revokeRememberedLogins($userId);
        $this->forget();
    }

    /**
     * Gemerkte Anmeldungen eines Kontos beenden (z. B. nachdem ein Admin sein Passwort geändert hat).
     */
    public function revokeRememberedLogins(int $userId): void
    {
        $this->db->pdo()->prepare('DELETE FROM ' . $this->db->table('remember_tokens') . ' WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    /**
     * Zwischengespeicherte Benutzerdaten verwerfen, z. B. nachdem das eigene Konto geändert wurde.
     */
    public function forget(): void
    {
        $this->user = false;
        $this->permissions = null;
    }

    private function createRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = time() + (self::REMEMBER_DAYS * 86400);
        $table = $this->db->table('remember_tokens');

        $this->db->pdo()->exec('DELETE FROM ' . $table . ' WHERE expires_at <= NOW()');
        $this->db->pdo()->prepare(
            'INSERT INTO ' . $table . ' (user_id, selector, token_hash, expires_at)
             VALUES (:user_id, :selector, :token_hash, FROM_UNIXTIME(:expires_at))'
        )->execute([
            'user_id' => $userId,
            'selector' => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => $expiresAt,
        ]);

        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, $this->rememberCookieOptions($expiresAt));
    }

    private function deleteRememberTokenFromCookie(): void
    {
        $parts = explode(':', (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? ''), 2);
        if (count($parts) === 2 && preg_match('/^[a-f0-9]{24}$/', $parts[0]))
        {
            $this->deleteRememberToken($parts[0]);
        }
    }

    private function deleteRememberToken(string $selector): void
    {
        $this->db->pdo()->prepare('DELETE FROM ' . $this->db->table('remember_tokens') . ' WHERE selector = :selector')
            ->execute(['selector' => $selector]);
    }

    private function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', $this->rememberCookieOptions(time() - 3600));
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private function rememberCookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function stamp(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }
}
