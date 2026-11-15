<?php

namespace ZX\Spectrum\Tape\Block;

/** One block of a tape image. Durations are T-states of a 3.5 MHz Spectrum, as in the TZX specification. */
interface TapeBlock
{
    public const int T_STATES_PER_MS = 3500;

    /**
     * The signal of the block: a positive value toggles the EAR level after that many T-states,
     * a negative value is silence (level low) for that many T-states. Control blocks return [].
     *
     * @param int $level EAR level before the block (direct recordings start from it)
     * @return list<int>
     */
    public function pulses(int $level): array;
}
