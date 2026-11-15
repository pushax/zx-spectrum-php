<?php

namespace ZX\Spectrum\Video;

/** A test picture (colour pattern and stripes) for checking the display without a ROM. */
final class DemoScreen
{
    /** @return array<int,int> */
    public static function make(): array
    {
        $memory = array_fill(0, SpectrumScreen::SCREEN_BYTES, 0);

        // Fill the attributes with a colourful, changing 8x8 pattern.
        for ($ay = 0; $ay < 24; $ay++) {
            for ($ax = 0; $ax < 32; $ax++) {
                $ink = ($ax + $ay) % 8;
                $paper = (($ax * 3) + $ay) % 8;
                $bright = (($ax + $ay) % 3) === 0 ? 1 : 0;
                $memory[SpectrumScreen::BITMAP_BYTES + $ay * 32 + $ax] = $ink | ($paper << 3) | ($bright << 6);
            }
        }

        // Draw a simple diagonal/checker pattern into bitmap memory.
        for ($y = 0; $y < 192; $y++) {
            for ($xb = 0; $xb < 32; $xb++) {
                $value = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $xb * 8 + $bit;
                    if ((intdiv($x + $y, 8) % 2) === 0 || abs($x - 128) < 3) {
                        $value |= 1 << (7 - $bit);
                    }
                }
                $memory[SpectrumScreen::bitmapOffset($y, $xb)] = $value;
            }
        }

        return $memory;
    }
}
