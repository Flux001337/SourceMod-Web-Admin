<?php
declare(strict_types=1);

/**
 * Meldungen über eine Weiterleitung hinweg (Post/Redirect/Get): set() vor dem Redirect, pull() auf der nächsten Seite.
 */
final class Flash
{
    private const KEY = 'flash';

    public static function set(string $type, string $message): void
    {
        $_SESSION[self::KEY] = ['type' => $type === 'error' ? 'error' : 'success', 'message' => $message];
    }

    /** @return array{type: string, message: string}|null */
    public static function pull(): ?array
    {
        $flash = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        return is_array($flash) ? $flash : null;
    }
}
