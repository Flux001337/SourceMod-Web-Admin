<?php
declare(strict_types=1);

/**
 * Themes der Seite: jede CSS-Datei in assets/css/themes ist ein Theme, der Dateiname (ohne .css) sein Name. Gespeichert
 * wird der Name in settings.site_theme (Standard der Seite) und users.theme (eigene Wahl im Profil, NULL = Standard der
 * Seite); neue Dateien erscheinen ohne weiteres in beiden Auswahlboxen.
 */
final class Themes
{
    public const DIRECTORY = __DIR__ . '/../assets/css/themes';
    public const DEFAULT = 'Midnight';

    /**
     * Namen aller Themes, natürlich sortiert. Nur Buchstaben, Ziffern, - und _ -- der Name landet in der URL des Stylesheets.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $names = [];
        foreach (glob(self::DIRECTORY . '/*.css') ?: [] as $file)
        {
            $name = basename($file, '.css');
            if (preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1)
            {
                $names[] = $name;
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Das Theme für die aktuelle Seite: die eigene Wahl des Benutzers, solange es die Datei gibt, sonst das der Seite,
     * sonst "Midnight" bzw. das erste vorhandene ("" ohne Themes).
     */
    public static function active(string $siteStored, ?string $userTheme = null): string
    {
        $available = self::available();
        foreach ([$userTheme, $siteStored, self::DEFAULT] as $candidate)
        {
            if (is_string($candidate) && in_array($candidate, $available, true))
            {
                return $candidate;
            }
        }

        return $available[0] ?? '';
    }

    /**
     * Auswahlliste für Templates. Mit $withDefault zuerst der Eintrag "Standard" (Wert "").
     *
     * @return list<array{name: string, label: string, selected: bool}>
     */
    public static function options(?string $selected, string $defaultLabel = ''): array
    {
        $options = [];
        if ($defaultLabel !== '')
        {
            $options[] = ['name' => '', 'label' => $defaultLabel, 'selected' => $selected === null || $selected === ''];
        }
        foreach (self::available() as $name)
        {
            $options[] = ['name' => $name, 'label' => self::label($name), 'selected' => $selected === $name];
        }

        return $options;
    }

    /**
     * Anzeigename für die Auswahl: "cool-blue" wird "Cool Blue".
     */
    public static function label(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
