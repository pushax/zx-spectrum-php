<?php

namespace ZX\Spectrum\Terminal;

/**
 * Terminal characters → Spectrum keys. Terminals send characters, not key presses, so every character
 * becomes the key combination that types it on a Spectrum.
 */
final class KeyMap
{
    /** Keyboard matrix position [half-row, bit] of every key. */
    private const array KEYS = [
        'z' => [0, 1], 'x' => [0, 2], 'c' => [0, 3], 'v' => [0, 4],
        'a' => [1, 0], 's' => [1, 1], 'd' => [1, 2], 'f' => [1, 3], 'g' => [1, 4],
        'q' => [2, 0], 'w' => [2, 1], 'e' => [2, 2], 'r' => [2, 3], 't' => [2, 4],
        '1' => [3, 0], '2' => [3, 1], '3' => [3, 2], '4' => [3, 3], '5' => [3, 4],
        '0' => [4, 0], '9' => [4, 1], '8' => [4, 2], '7' => [4, 3], '6' => [4, 4],
        'p' => [5, 0], 'o' => [5, 1], 'i' => [5, 2], 'u' => [5, 3], 'y' => [5, 4],
        "\n" => [6, 0], "\r" => [6, 0], 'l' => [6, 1], 'k' => [6, 2], 'j' => [6, 3], 'h' => [6, 4],
        ' ' => [7, 0], 'm' => [7, 2], 'n' => [7, 3], 'b' => [7, 4],
    ];

    /** Symbols typed with SYMBOL SHIFT, and the key that carries them. */
    private const array SYMBOLS = [
        '!' => '1', '@' => '2', '#' => '3', '$' => '4', '%' => '5', '&' => '6', "'" => '7', '(' => '8',
        ')' => '9', '_' => '0', '<' => 'r', '>' => 't', ';' => 'o', '"' => 'p', '^' => 'h', '-' => 'j',
        '+' => 'k', '=' => 'l', ':' => 'z', '?' => 'c', '/' => 'v', '*' => 'b', ',' => 'n', '.' => 'm',
    ];

    /** Arrow keys (final byte of the escape sequence) → CAPS SHIFT + 5/6/7/8. */
    private const array ARROWS = ['D' => '5', 'B' => '6', 'A' => '7', 'C' => '8'];

    public static function forCharacter(string $char): ?KeyCombo
    {
        return match (true) {
            isset(self::KEYS[$char]) => KeyCombo::of(self::KEYS[$char]),
            ctype_upper($char) => KeyCombo::of(self::KEYS[strtolower($char)], KeyCombo::CAPS_SHIFT),
            isset(self::SYMBOLS[$char]) => KeyCombo::of(self::KEYS[self::SYMBOLS[$char]], KeyCombo::SYMBOL_SHIFT),
            $char === "\x7F", $char === "\x08" => KeyCombo::of(self::KEYS['0'], KeyCombo::CAPS_SHIFT), // DELETE
            $char === "\t" => KeyCombo::of(KeyCombo::CAPS_SHIFT, KeyCombo::SYMBOL_SHIFT), // extended mode
            default => null,
        };
    }

    /** The Esc key: BREAK (CAPS SHIFT + SPACE). */
    public static function escape(): KeyCombo
    {
        return KeyCombo::of(self::KEYS[' '], KeyCombo::CAPS_SHIFT);
    }

    /** Arrow keys in CSI or SS3 form (with any modifiers); other escape sequences are ignored. */
    public static function forEscapeSequence(string $sequence): ?KeyCombo
    {
        $final = $sequence[strlen($sequence) - 1];
        if (($sequence[1] === '[' || $sequence[1] === 'O') && isset(self::ARROWS[$final])) {
            return KeyCombo::of(self::KEYS[self::ARROWS[$final]], KeyCombo::CAPS_SHIFT);
        }
        return null;
    }
}
