<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Jeder benutzersichtbare Text (Formular, Translate(), Kachel) braucht eine deutsche Übersetzung in locale.json.
class LocaleTest extends TestCase
{
    // Konstanten in module.html, deren Werte übersetzt angezeigt werden
    private const TILE_TEXT_CONSTANTS = ['WEEKDAYS', 'WEEKDAY_NAMES', 'GROUPS', 'STATUS', 'HINTS', 'STYLES'];

    public function testWakeUpManagerTextsAreTranslated(): void
    {
        $this->assertTranslated('WakeUpManager');
    }

    public function testWakeUpActionsTextsAreTranslated(): void
    {
        $this->assertTranslated('WakeUpActions');
    }

    private function assertTranslated(string $module): void
    {
        $folder = __DIR__ . '/../' . $module;
        $translations = json_decode(file_get_contents($folder . '/locale.json'), true)['translations']['de'];

        $missing = array_diff(array_unique($this->collectTexts($folder)), array_keys($translations));
        $this->assertSame([], array_values($missing), 'Missing German translations in ' . $module . '/locale.json');
    }

    private function collectTexts(string $folder): array
    {
        $texts = [];

        $form = json_decode(file_get_contents($folder . '/form.json'), true);
        array_walk_recursive($form, function ($value, $key) use (&$texts): void
        {
            if (in_array($key, ['caption', 'label'], true) && is_string($value) && $value !== '') {
                $texts[] = $value;
            }
        });
        // Captions in den Bearbeitungsformularen der Listen (PHP-Code als Zeilen)
        foreach ($form['elements'] ?? [] as $element) {
            foreach ($element['form'] ?? [] as $line) {
                preg_match_all("/'caption' => '([^']+)'/", $line, $matches);
                $texts = [...$texts, ...$matches[1]];
            }
        }

        $php = file_get_contents($folder . '/module.php');
        preg_match_all("/Translate\\('([^']+)'\\)/", $php, $matches);
        $texts = [...$texts, ...$matches[1]];
        preg_match_all("/\\['caption'\\] = '([^']+)'/", $php, $matches);
        $texts = [...$texts, ...$matches[1]];
        preg_match_all("/SetErrorStatus\\([^,]+, '([^']+)'/", $php, $matches);
        $texts = [...$texts, ...$matches[1]];

        if (file_exists($folder . '/module.html')) {
            $html = file_get_contents($folder . '/module.html');
            preg_match_all("/translate\\('([^']+)'\\)/", $html, $matches);
            $texts = [...$texts, ...$matches[1]];
            foreach (self::TILE_TEXT_CONSTANTS as $name) {
                $this->assertSame(1, preg_match('/const ' . $name . ' = \{(.*?)\};/s', $html, $block), 'Constant ' . $name . ' not found in module.html');
                preg_match_all("/: '([^']+)'/", $block[1], $matches);
                $texts = [...$texts, ...$matches[1]];
            }
            // Statische Texte, die translateHTML beim Laden übersetzt
            preg_match_all('/<(?:span|div)[^>]*>([^<{}]*[A-Za-z][^<{}]*)<\/(?:span|div)>/', $html, $matches);
            $texts = [...$texts, ...array_map('trim', $matches[1])];
            preg_match_all('/title="([^"]+)"/', $html, $matches);
            $texts = [...$texts, ...$matches[1]];
        }

        return array_values(array_filter($texts, static fn (string $text): bool => trim($text) !== '' && $text !== '--:--'));
    }
}
