<?php

namespace ZX\Spectrum\Tape\Block;

/** TZX 20: silence; a pause of 0 means "stop the tape". */
final readonly class PauseBlock implements TapeBlock
{
    public function __construct(public int $pauseMs)
    {
    }

    public function stopsTape(): bool
    {
        return $this->pauseMs === 0;
    }

    public function pulses(int $level): array
    {
        return $this->pauseMs > 0 ? [-$this->pauseMs * self::T_STATES_PER_MS] : [];
    }
}
