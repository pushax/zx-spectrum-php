<?php

namespace ZX\Spectrum\Sound;

/** Where the machine's sound goes. The machine calls render() whenever the sound state changes. */
interface AudioOutput
{
    /** The AY chip to mix in (null on a 48K). */
    public function setAy(?Ay8912 $ay): void;

    /** Produces $cycles CPU T-states of sound with the beeper at $beeper (0/1) and the AY as it is now. */
    public function render(int $cycles, int $beeper): void;
}
