<?php

namespace ZX\Spectrum\Tape\Block;

/** TZX 2B: sets the EAR level. */
final readonly class SetLevelBlock implements TapeBlock
{
    public function __construct(public int $level)
    {
    }

    public function pulses(int $level): array
    {
        return [];
    }
}
