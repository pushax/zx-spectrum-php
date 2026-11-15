<?php

namespace ZX\Spectrum\Tape\Block;

/** TZX 12: $count pulses of the same length. */
final readonly class ToneBlock implements TapeBlock
{
    public function __construct(public int $pulseLength, public int $count)
    {
    }

    public function pulses(int $level): array
    {
        return array_fill(0, $this->count, $this->pulseLength);
    }
}
