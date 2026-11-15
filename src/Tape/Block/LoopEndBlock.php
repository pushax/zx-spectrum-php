<?php

namespace ZX\Spectrum\Tape\Block;

/** TZX 25: end of a loop. */
final readonly class LoopEndBlock implements TapeBlock
{
    public function pulses(int $level): array
    {
        return [];
    }
}
