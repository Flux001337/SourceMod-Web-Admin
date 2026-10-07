<?php
declare(strict_types=1);

/**
 * Einfaches Ereignis-Limit in der Datenbank (Tabelle rate_limits), z. B. für Anmeldeversuche.
 *
 * Gespeichert wird nur ein HMAC des Merkmals (IP, Benutzername), nie der Klartext. Fällt die Tabelle aus oder fehlt sie
 * noch, wird der Fehler protokolliert und die Anfrage nicht blockiert, damit die Seite erreichbar bleibt.
 */
final class RateLimiter
{
    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly Database $db, private readonly string $pepper)
    {
    }

    public function tooMany(string $bucket, string $subject, int $maxAttempts, int $windowSeconds): bool
    {
        try
        {
            $stmt = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM ' . $this->db->table('rate_limits')
                . ' WHERE bucket = :bucket AND subject = :subject AND created_at > :since'
            );
            $stmt->execute([
                'bucket' => $bucket,
                'subject' => $this->fingerprint($subject),
                'since' => time() - $windowSeconds,
            ]);

            return (int) $stmt->fetchColumn() >= $maxAttempts;
        }
        catch (PDOException $exception)
        {
            error_log('RateLimiter (Prüfung): ' . $exception->getMessage());
            return false;
        }
    }

    public function hit(string $bucket, string $subject): void
    {
        try
        {
            $this->db->pdo()->prepare(
                'INSERT INTO ' . $this->db->table('rate_limits') . ' (bucket, subject, created_at) VALUES (:bucket, :subject, :now)'
            )->execute(['bucket' => $bucket, 'subject' => $this->fingerprint($subject), 'now' => time()]);

            if (random_int(1, 50) === 1)
            {
                $this->db->pdo()->prepare('DELETE FROM ' . $this->db->table('rate_limits') . ' WHERE created_at < :cutoff')
                    ->execute(['cutoff' => time() - self::RETENTION_SECONDS]);
            }
        }
        catch (PDOException $exception)
        {
            error_log('RateLimiter (Eintrag): ' . $exception->getMessage());
        }
    }

    public function clear(string $bucket, string $subject): void
    {
        try
        {
            $this->db->pdo()->prepare(
                'DELETE FROM ' . $this->db->table('rate_limits') . ' WHERE bucket = :bucket AND subject = :subject'
            )->execute(['bucket' => $bucket, 'subject' => $this->fingerprint($subject)]);
        }
        catch (PDOException $exception)
        {
            error_log('RateLimiter (Zurücksetzen): ' . $exception->getMessage());
        }
    }

    private function fingerprint(string $subject): string
    {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($subject) : strtolower($subject);

        return hash_hmac('sha256', $normalized, $this->pepper !== '' ? $this->pepper : 'smwa-rate-limit');
    }
}
