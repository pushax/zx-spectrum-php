<?php

namespace ZX\Spectrum\Terminal;

use ZX\Spectrum\Video\SpectrumScreen;

/**
 * Text output for terminals without an image protocol: every character cell shows two pixels with the
 * upper half block (foreground = upper pixel, background = lower pixel), so 256×192 needs 256×96 cells.
 */
final class TextRenderer
{
    public function render(SpectrumScreen $screen, bool $flashPhase = false): string
    {
        $out = '';
        for ($ty = 0; $ty < 96; $ty++) {
            $lastFg = $lastBg = null;
            for ($x = 0; $x < 256; $x++) {
                [$top, $bottom] = $screen->terminalCell($x, $ty, $flashPhase);
                if ($top !== $lastFg) {
                    $out .= sprintf("\033[38;2;%d;%d;%dm", ...$top);
                    $lastFg = $top;
                }
                if ($bottom !== $lastBg) {
                    $out .= sprintf("\033[48;2;%d;%d;%dm", ...$bottom);
                    $lastBg = $bottom;
                }
                $out .= '▀';
            }
            // No newline after the last row, so the terminal does not scroll the picture up.
            $out .= $ty < 95 ? "\033[0m\n" : "\033[0m";
        }
        return $out;
    }
}
