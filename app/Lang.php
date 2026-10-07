<?php
declare(strict_types=1);

/**
 * Sprachen der Oberfläche. Jede Datei lang/<code>.json ist eine Sprache und erscheint ohne Codeänderung in der Auswahl:
 *
 *   { "meta": { "name": "Deutsch", "flag": "de" }, "nav": { ... }, ... }
 *
 * "flag" ist der Dateiname in assets/flags/<flag>.svg. Englisch ist die Grundsprache: Fehlt ein Text in einer anderen
 * Sprache, wird der englische verwendet.
 */
final class Lang
{
    public const FALLBACK = 'en';

    /** @var array<string, array{name: string, flag: string}> */
    private array $available = [];
    private string $code = self::FALLBACK;
    /** @var array<string, mixed> */
    private array $texts = [];

    public function __construct(private readonly string $directory)
    {
        foreach (glob(rtrim($directory, '/') . '/*.json') ?: [] as $file)
        {
            $code = basename($file, '.json');
            if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) !== 1)
            {
                continue;
            }
            $meta = $this->read($code)['meta'] ?? [];
            $this->available[$code] = [
                'name' => (string) ($meta['name'] ?? $code),
                'flag' => preg_match('/^[a-z0-9-]+$/', (string) ($meta['flag'] ?? '')) === 1 ? (string) $meta['flag'] : '00',
            ];
        }
        uasort($this->available, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    }

    /** @return array<string, array{name: string, flag: string}> */
    public function available(): array
    {
        return $this->available;
    }

    public function isAvailable(string $code): bool
    {
        return isset($this->available[$code]);
    }

    /**
     * Wählt die erste vorhandene Sprache aus den Kandidaten (z. B. Benutzer, Cookie, Standard der Seite).
     */
    public function select(?string ...$candidates): void
    {
        $this->code = self::FALLBACK;
        foreach ($candidates as $candidate)
        {
            if (is_string($candidate) && $this->isAvailable($candidate))
            {
                $this->code = $candidate;
                break;
            }
        }

        $texts = $this->read(self::FALLBACK);
        if ($this->code !== self::FALLBACK)
        {
            $texts = array_replace_recursive($texts, $this->read($this->code));
        }
        $this->texts = $texts;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function flag(): string
    {
        return $this->available[$this->code]['flag'] ?? '00';
    }

    /** Alle Texte der aktiven Sprache (für die Templates als "t"). */
    public function all(): array
    {
        return $this->texts;
    }

    /**
     * Ein Text über seinen Pfad, z. B. t('users.saved', ['user' => 'Max']) ersetzt {user}. Fehlt er, kommt der Pfad.
     */
    public function t(string $path, array $replace = []): string
    {
        $current = $this->texts;
        foreach (explode('.', $path) as $part)
        {
            if (!is_array($current) || !array_key_exists($part, $current))
            {
                return $path;
            }
            $current = $current[$part];
        }
        if (!is_string($current))
        {
            return $path;
        }

        foreach ($replace as $key => $value)
        {
            $current = str_replace('{' . $key . '}', (string) $value, $current);
        }

        return $current;
    }

    /**
     * Auswahlliste der Sprachen für Templates. Mit $withDefault zuerst der Eintrag "Standard" (Wert "").
     *
     * @return list<array{code: string, name: string, selected: bool}>
     */
    public function options(?string $selected, bool $withDefault = false): array
    {
        $options = [];
        if ($withDefault)
        {
            $options[] = ['code' => '', 'name' => $this->t('common.default_option'), 'selected' => $selected === null || $selected === ''];
        }
        foreach ($this->available as $code => $info)
        {
            $options[] = ['code' => $code, 'name' => $info['name'], 'selected' => $selected === $code];
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function read(string $code): array
    {
        $file = rtrim($this->directory, '/') . '/' . $code . '.json';
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($json) ? $json : [];
    }
}
