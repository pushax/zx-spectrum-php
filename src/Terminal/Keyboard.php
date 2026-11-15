<?php

namespace ZX\Spectrum\Terminal;

use ZX\Spectrum\Machine\ULA;

/**
 * Reads the terminal in raw mode and turns what is typed into Spectrum key presses (see KeyMap and
 * KeyQueue). Ctrl+C stops the emulator.
 */
final class Keyboard
{
    private const string CTRL_C = "\x03";
    private const string ESC = "\033";
    /** A never-terminated escape sequence longer than this is garbage and is dropped. */
    private const int MAX_SEQUENCE = 65536;

    private bool $enabled = false;
    /** Input read but not decoded yet (an incomplete escape sequence). */
    private string $pending = '';
    private string $savedStty = '';
    private readonly KeyQueue $keys;

    /** @param int $holdFrames frames a key stays pressed after a key event (terminals send no key-up) */
    public function __construct(int $holdFrames = 30)
    {
        $this->keys = new KeyQueue($holdFrames);
    }

    /** Switches the terminal to raw input (no echo, no line editing, Ctrl+C as a character). */
    public function start(): void
    {
        if (!function_exists('shell_exec') || PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $this->savedStty = trim((string)@shell_exec('stty -g 2>/dev/null'));
        // -isig: Ctrl+C arrives as a character, so the emulator can restore the terminal before exiting.
        @shell_exec('stty -echo -icanon -isig min 0 time 0 2>/dev/null');
        stream_set_blocking(STDIN, false);
        $this->enabled = true;
    }

    public function stop(): void
    {
        if (!$this->enabled) {
            return;
        }
        $restore = $this->savedStty !== '' ? 'stty ' . escapeshellarg($this->savedStty) : 'stty sane';
        @shell_exec($restore . ' 2>/dev/null');
        $this->enabled = false;
    }

    /** Before each frame: reads the terminal and presses the keys that are due. */
    public function poll(ULA $ula): void
    {
        if (!$this->enabled) {
            return;
        }
        $chunk = @fread(STDIN, 4096);
        if ($chunk !== false && $chunk !== '') {
            $this->pending .= $chunk;
        }
        $this->decode();
        $this->keys->schedule($ula);
    }

    /** After each frame: releases keys that have been held long enough. */
    public function release(ULA $ula): void
    {
        $this->keys->frameDone($ula);
    }

    private function decode(): void
    {
        // A lone ESC in one read is the Esc key (escape sequences arrive in one piece).
        if ($this->pending === self::ESC) {
            $this->pending = '';
            $this->keys->add(KeyMap::escape());
        }
        while ($this->pending !== '') {
            if ($this->pending[0] === self::ESC) {
                // Escape sequences are never typed text: terminal replies (e.g. Kitty graphics "\033_G...\033\\")
                // must not turn into key presses. Only arrow keys are mapped.
                $length = self::escapeLength($this->pending);
                if ($length === 0) {
                    break; // incomplete: wait for the rest
                }
                $key = KeyMap::forEscapeSequence(substr($this->pending, 0, $length));
                $this->pending = substr($this->pending, $length);
            } else {
                $char = $this->pending[0];
                $this->pending = substr($this->pending, 1);
                if ($char === self::CTRL_C) {
                    throw new StopRequested();
                }
                $key = KeyMap::forCharacter($char);
            }
            if ($key !== null) {
                $this->keys->add($key);
            }
        }
    }

    /** Length of the escape sequence at the start of $s, or 0 if it is not complete yet. */
    private static function escapeLength(string $s): int
    {
        $length = strlen($s);
        if ($length < 2) {
            return 0;
        }
        switch ($s[1]) {
            case '[': // CSI: parameters, then a final byte 40-7E
                for ($i = 2; $i < $length; $i++) {
                    $byte = ord($s[$i]);
                    if ($byte >= 0x40 && $byte <= 0x7E) {
                        return $i + 1;
                    }
                }
                return 0;
            case ']': // OSC
            case '_': // APC
            case 'P': // DCS
            case '^': // PM: all end with BEL or ST (ESC \)
                for ($i = 2; $i < $length; $i++) {
                    if ($s[$i] === "\007") {
                        return $i + 1;
                    }
                    if ($s[$i] === self::ESC && $i + 1 < $length && $s[$i + 1] === '\\') {
                        return $i + 2;
                    }
                }
                return $length > self::MAX_SEQUENCE ? $length : 0;
            case 'O': // SS3, e.g. arrow keys in application mode
                return $length >= 3 ? 3 : 0;
            default: // Alt+key, or a lone ESC followed by a key: both bytes are ignored
                return 2;
        }
    }
}
