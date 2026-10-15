<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown;

use Normalizer;

/**
 * A heading's fragment id, the same one the help article's table of contents
 * builds in the browser (useKinetixHelpToc), so a link to `#configuracion`
 * works whichever side assigned it.
 *
 * Letters and digits of any script are kept: an ASCII-only rule would erase
 * a Greek, Cyrillic, Arabic or CJK heading entirely. Accents are folded on
 * Latin letters only (`Configuración` → `configuracion`); in other scripts a
 * combining mark can change the letter itself, so it stays.
 */
final class HeadingSlug
{
    public static function from(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
            $folded     = preg_replace('/(\p{Latin})\p{M}+/u', '$1', is_string($decomposed) ? $decomposed : $text);
            $text       = (string) Normalizer::normalize((string) $folded, Normalizer::FORM_C);
        }

        $slug = mb_strtolower(trim($text));
        $slug = (string) preg_replace('/[^\p{L}\p{N}\s-]/u', '', $slug);
        $slug = (string) preg_replace('/\s+/u', '-', $slug);

        return (string) preg_replace('/-+/', '-', $slug);
    }

    /**
     * The slug, made unique among the ones already taken (`-2`, `-3`, …).
     *
     * @param array<string, true> $taken updated with the returned id
     */
    public static function unique(string $text, array &$taken): string
    {
        $base = self::from($text);
        $base = $base === '' ? 'section' : $base;
        $id   = $base;

        for ($suffix = 2; isset($taken[$id]); $suffix++) {
            $id = "{$base}-{$suffix}";
        }

        $taken[$id] = true;

        return $id;
    }
}
