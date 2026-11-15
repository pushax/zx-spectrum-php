<?php

namespace ZX\Spectrum\Tape\Block;

/** TZX 15: the signal sampled at a fixed rate, one bit per sample. */
final readonly class DirectRecordingBlock implements TapeBlock
{
    public function __construct(
        public string $samples,
        public int $tStatesPerSample,
        public int $lastByteBits,
        public int $pauseMs,
    ) {
    }

    /** A pulse ends wherever the sampled level changes. */
    public function pulses(int $level): array
    {
        $out = [];
        $run = 0;
        $length = strlen($this->samples);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($this->samples[$i]);
            $bits = $i === $length - 1 ? $this->lastByteBits : 8;
            for ($k = 7; $k > 7 - $bits; $k--) {
                $bit = ($byte >> $k) & 1;
                if ($bit !== $level && $run > 0) {
                    $out[] = $run;
                    $run = 0;
                    $level = $bit;
                }
                $run += $this->tStatesPerSample;
            }
        }
        if ($run > 0) {
            $out[] = $run;
        }
        if ($this->pauseMs > 0) {
            $out[] = -$this->pauseMs * self::T_STATES_PER_MS;
        }
        return $out;
    }
}
