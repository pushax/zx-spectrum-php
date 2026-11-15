<?php

namespace ZX\Spectrum\Tape\Block;

/** TZX 24: the blocks up to the next LoopEndBlock are played $repetitions times. */
final readonly class LoopStartBlock implements TapeBlock
{
    public function __construct(public int $repetitions)
    {
    }

    public function pulses(int $level): array
    {
        return [];
    }
}
