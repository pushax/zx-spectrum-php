<?php

namespace ZX\Spectrum\Video;

/**
 * The Spectrum screen memory: 6144 bytes of bitmap (in the ULA's interleaved line order) and
 * 768 bytes of attributes (ink, paper, BRIGHT, FLASH for each 8×8 cell).
 */
final class SpectrumScreen
{
    public const int WIDTH = 256;
    public const int HEIGHT = 192;
    public const int BITMAP_BYTES = 6144;
    public const int ATTRIBUTE_BYTES = 768;
    public const int SCREEN_BYTES = 6912;

    /** Offset of the bitmap byte for pixel line $y (0-191), byte column $column (0-31). */
    public static function bitmapOffset(int $y, int $column): int
    {
        return (($y & 0xC0) << 5) | (($y & 7) << 8) | (($y & 0x38) << 2) | $column;
    }

    /** @param array<int,int> $memory */
    public function __construct(private array $memory)
    {
        if (count($memory) !== self::SCREEN_BYTES) {
            throw new \InvalidArgumentException('Screen memory must contain 6912 bytes.');
        }
    }

    /** @return array{0:array{0:int,1:int,2:int},1:array{0:int,1:int,2:int}} */
    public function terminalCell(int $x, int $terminalY, bool $flashPhase = false): array
    {
        if ($x < 0 || $x >= 256 || $terminalY < 0 || $terminalY >= 96) {
            throw new \OutOfRangeException('Terminal cell outside screen.');
        }
        return [
            $this->pixelRgb($x, $terminalY * 2, $flashPhase),
            $this->pixelRgb($x, $terminalY * 2 + 1, $flashPhase),
        ];
    }

    /** @return array{0:int,1:int,2:int} */
    public function pixelRgb(int $x, int $y, bool $flashPhase = false): array
    {
        if ($x < 0 || $x >= 256 || $y < 0 || $y >= 192) {
            throw new \OutOfRangeException('Pixel outside Spectrum screen.');
        }
        $offset = self::bitmapOffset($y, $x >> 3);
        $set = (($this->memory[$offset] >> (7 - ($x & 7))) & 1) === 1;
        $attr = $this->memory[6144 + intdiv($y, 8) * 32 + intdiv($x, 8)];
        $ink = $attr & 7;
        $paper = ($attr >> 3) & 7;
        $bright = (($attr >> 6) & 1) !== 0;
        if ((($attr >> 7) & 1) !== 0 && $flashPhase) {
            [$ink, $paper] = [$paper, $ink];
        }
        return Palette::rgb(($set ? $ink : $paper) + ($bright ? 8 : 0));
    }
}
