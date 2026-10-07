<?php
declare(strict_types=1);

/**
 * Verschlüsselt gespeicherte Zugangsdaten (RCON- und FTP-Passwörter) mit libsodium, wie die Keks-Brigarde-Homepage:
 * Schlüssel ist security.master_key (32 Bytes, base64), gespeichert wird "v1:" + base64(Nonce + Geheimtext).
 * Wird der master_key geändert, lassen sich die gespeicherten Passwörter nicht mehr entschlüsseln und müssen neu
 * eingegeben werden.
 *
 * Schlüssel erzeugen:  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
 */
final class SecretCipher
{
    private ?string $key;

    public function __construct(string $encodedKey)
    {
        if (!function_exists('sodium_crypto_secretbox'))
        {
            $this->key = null;
            return;
        }

        $key = base64_decode(trim($encodedKey), true);
        $this->key = is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $key : null;
    }

    public function isConfigured(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->key === null)
        {
            throw new RuntimeException('security.master_key ist nicht korrekt konfiguriert.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * Entschlüsselt einen gespeicherten Wert. Werte ohne "v1:" (leer oder alter Klartext) kommen unverändert zurück.
     */
    public function decrypt(string $value): string
    {
        if ($value === '' || !str_starts_with($value, 'v1:'))
        {
            return $value;
        }
        if ($this->key === null)
        {
            throw new RuntimeException('security.master_key ist nicht korrekt konfiguriert.');
        }

        $payload = base64_decode(substr($value, 3), true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
        {
            throw new RuntimeException('Ein gespeichertes Geheimnis ist ungültig.');
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        if ($plaintext === false)
        {
            throw new RuntimeException('Ein gespeichertes Geheimnis kann nicht entschlüsselt werden (anderer master_key?).');
        }

        return $plaintext;
    }
}
