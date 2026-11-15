<?php

namespace ZX\Spectrum\Tape\Block;

/** TZX 13: pulses of individual lengths. */
final readonly class PulseSequenceBlock implements TapeBlock
{
    /** @param list<int> $lengths */
    public function __construct(public array $lengths)
    {
    }

    public function pulses(int $level): array
    {
        return $this->lengths;
    }
}
