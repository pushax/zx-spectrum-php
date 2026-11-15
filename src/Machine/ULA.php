<?php

namespace ZX\Spectrum\Machine;

/**
 * The ULA: keyboard matrix, border colour, beeper and the frame clock (it raises the interrupt and
 * toggles the FLASH phase every 16 frames).
 */
final class ULA
{
    private const int FLASH_FRAMES = 16;

    public int $border = 0;
    public int $beeper = 0;
    /** T-states since the start of the current frame (the interrupt). */
    public int $frameCycles = 0;
    public bool $flashPhase = false;
    private int $flashFrames = 0;
    /** @var array<int,int> keyboard half-rows, active low, bits 0-4 */
    private array $keys;

    public function __construct(private MachineTiming $timing)
    {
        $this->keys = array_fill(0, 8, 0x1F);
    }

    public function timing(): MachineTiming
    {
        return $this->timing;
    }

    /** Presses or releases a key: half-row 0-7 (port bits A8-A15), key bit 0-4. */
    public function setKey(int $row, int $bit, bool $pressed): void
    {
        if ($row < 0 || $row > 7 || $bit < 0 || $bit > 4) {
            return;
        }
        if ($pressed) {
            $this->keys[$row] &= ~(1 << $bit);
        } else {
            $this->keys[$row] |= 1 << $bit;
        }
    }

    public function readPort(int $port): int
    {
        // Kempston joystick interface (port 0x1F): active-high, nothing pressed.
        if (($port & 0xFF) === 0x1F) {
            return 0x00;
        }
        $value = 0xFF;
        if (($port & 1) === 0) {
            // Every half-row whose address line (A8-A15) is low is read; pressed keys pull bits low.
            $high = ($port >> 8) & 0xFF;
            for ($row = 0; $row < 8; $row++) {
                if ((($high >> $row) & 1) === 0) {
                    $value &= ($this->keys[$row] | 0xE0);
                }
            }
        }
        return $value;
    }

    public function writePort(int $port, int $value): void
    {
        if (($port & 1) === 0) {
            $this->border = $value & 7;
            $this->beeper = ($value >> 4) & 1;
        }
    }

    /** Advances the frame clock; returns true when a new frame (and interrupt) starts. */
    public function tick(int $cycles): bool
    {
        $this->frameCycles += $cycles;
        if ($this->frameCycles >= $this->timing->frameTstates) {
            $this->frameCycles %= $this->timing->frameTstates;
            if (++$this->flashFrames >= self::FLASH_FRAMES) {
                $this->flashFrames = 0;
                $this->flashPhase = !$this->flashPhase;
            }
            return true;
        }
        return false;
    }

    /** @return array<int,int> */
    public function keyRows(): array
    {
        return $this->keys;
    }
}
