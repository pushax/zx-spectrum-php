<?php

namespace ZX\Spectrum\Video;

use ZX\Spectrum\Machine\MachineTiming;
use ZX\Spectrum\Machine\Memory;
use ZX\Spectrum\Machine\ULA;

/**
 * Beam-accurate video: builds a 320×240 palette-indexed frame (256×192 paper, 32 px side border,
 * 24 lines top/bottom border) in raster order, in 8-pixel cells. Each cell is drawn with the
 * screen memory, attributes and border colour as they are at the T-state the beam reaches it,
 * so multicolour and border effects show up. Rendering is lazy: update($t) catches the beam up
 * to frame T-state $t; it is called before anything that changes the picture.
 */
final class BeamRenderer
{
    public const int WIDTH = 320;
    public const int HEIGHT = 240;
    /** 8-pixel cells per row. */
    private const int COLS = self::WIDTH / 8;
    private const int CELLS = self::COLS * self::HEIGHT;
    private const int BORDER_LEFT_PX = 32;
    private const int BORDER_TOP_LINES = 24;

    /** @var array<int,int> frame T-state at which the beam reaches each cell */
    private array $cellT = [];
    /** @var array<int,int> bitmap offset of a paper cell, -1 for border */
    private array $cellBitmap = [];
    /** @var array<int,int> attribute offset of a paper cell */
    private array $cellAttr = [];
    /** @var array<int,string> rendered 8-byte cells of the current frame */
    private array $cells = [];
    private int $next = 0;
    private string $frame;
    /** @var array<int,string> 8 palette indices for each (FLASH-swap, attribute, bitmap byte) combination */
    private array $lut = [];
    /** @var array<int,string> an 8-pixel border cell of each colour */
    private array $borderCell = [];

    public function __construct(MachineTiming $timing, private Memory $memory, private ULA $ula)
    {
        $topLeft = $timing->topLeftPixel();
        for ($i = 0; $i < self::CELLS; $i++) {
            $row = intdiv($i, self::COLS);
            $col = $i % self::COLS;
            $y = $row - self::BORDER_TOP_LINES;
            $x = $col * 8 - self::BORDER_LEFT_PX; // relative to the paper
            $this->cellT[$i] = $topLeft + $y * $timing->tstatesPerLine + intdiv($x, 2); // 2 pixels per T-state
            if ($y >= 0 && $y < 192 && $x >= 0 && $x < 256) {
                $c = $x >> 3;
                $this->cellBitmap[$i] = SpectrumScreen::bitmapOffset($y, $c);
                $this->cellAttr[$i] = 6144 + ($y >> 3) * 32 + $c;
            } else {
                $this->cellBitmap[$i] = -1;
                $this->cellAttr[$i] = 0;
            }
        }
        for ($c = 0; $c < 8; $c++) {
            $this->borderCell[$c] = str_repeat(chr($c), 8);
        }
        $this->frame = str_repeat("\0", self::WIDTH * self::HEIGHT);
    }

    /** Draws every cell the beam reaches before frame T-state $t. */
    public function update(int $t): void
    {
        $i = $this->next;
        if ($i >= self::CELLS || $this->cellT[$i] >= $t) {
            return;
        }
        $mem = $this->memory;
        $border = $this->borderCell[$this->ula->border & 7];
        $flash = $this->ula->flashPhase;
        while ($i < self::CELLS && $this->cellT[$i] < $t) {
            $b = $this->cellBitmap[$i];
            if ($b < 0) {
                $this->cells[$i] = $border;
            } else {
                $attr = $mem->screenByte($this->cellAttr[$i]);
                $key = (($flash && ($attr & 0x80)) ? 0x100 : 0) | $attr;
                $this->cells[$i] = $this->paperCell($key, $mem->screenByte($b));
            }
            $i++;
        }
        $this->next = $i;
    }

    /** Finishes the frame (called at the interrupt) and starts the next one. */
    public function endFrame(): void
    {
        $this->update(PHP_INT_MAX);
        $this->frame = implode('', $this->cells);
        $this->next = 0;
    }

    /** The last complete frame: 320×240 bytes, palette index 0-15 (colour + 8 for BRIGHT). */
    public function frame(): string
    {
        return $this->frame;
    }

    /** 8 palette indices for one attribute (bit 8 set = FLASH swap active) and bitmap byte. */
    private function paperCell(int $key, int $bits): string
    {
        $k = ($key << 8) | $bits;
        if (isset($this->lut[$k])) {
            return $this->lut[$k];
        }
        $attr = $key & 0xFF;
        $ink = $attr & 7;
        $paper = ($attr >> 3) & 7;
        if ($key & 0x100) {
            [$ink, $paper] = [$paper, $ink];
        }
        if ($attr & 0x40) {
            $ink += 8;
            $paper += 8;
        }
        $s = '';
        for ($b = 7; $b >= 0; $b--) {
            $s .= chr((($bits >> $b) & 1) ? $ink : $paper);
        }
        return $this->lut[$k] = $s;
    }
}
