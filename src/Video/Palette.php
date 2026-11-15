<?php

namespace ZX\Spectrum\Video;

/** The 16 Spectrum colours: 0-7 normal (black, blue, red, magenta, green, cyan, yellow, white), 8-15 BRIGHT. */
final class Palette
{
    private const int NORMAL = 205;
    private const int BRIGHT = 255;

    /** @return array{int,int,int} red, green, blue of palette index 0-15 */
    public static function rgb(int $index): array
    {
        $level = $index >= 8 ? self::BRIGHT : self::NORMAL;
        // Colour bits: 1 = blue, 2 = red, 4 = green.
        return [($index & 2) ? $level : 0, ($index & 4) ? $level : 0, ($index & 1) ? $level : 0];
    }
}
