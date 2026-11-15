<?php

namespace ZX\Spectrum\Sound;

/**
 * Mixes the beeper and the AY chip into 16-bit little-endian stereo PCM at a fixed sample rate.
 * A DC-blocking filter centres the signal (beeper and AY outputs are unipolar).
 */
final class Mixer
{
    public const int SAMPLE_RATE = 44100;
    /** Bytes per stereo sample frame (s16le × 2). */
    public const int FRAME_BYTES = 4;

    // Levels after DC removal: the beeper swings ±0.25 (about ±14500); the AY at full volume on all three
    // channels adds up to ±0.3 per side; together they stay below 32767.
    private const float BEEPER_LEVEL = 0.5;
    private const float AY_GAIN = 0.3;
    private const float OUTPUT_GAIN = 58000.0;
    private const float DC_FILTER_POLE = 0.995;

    private int $clockHz = 3500000;
    private float $sampleRemainder = 0.0;
    private ?Ay8912 $ay = null;
    private float $ayTicksPerSample = 0.0;
    private float $dcInLeft = 0.0;
    private float $dcOutLeft = 0.0;
    private float $dcInRight = 0.0;
    private float $dcOutRight = 0.0;

    public function setClockHz(int $clockHz): void
    {
        if ($clockHz <= 0) {
            throw new \InvalidArgumentException('Clock must be positive');
        }
        $this->clockHz = $clockHz;
    }

    public function setAy(?Ay8912 $ay): void
    {
        $this->ay = $ay;
        if ($ay !== null) {
            $this->ayTicksPerSample = $ay->clockHz() / 8 / self::SAMPLE_RATE; // the generators tick at clock/8
        }
    }

    /** PCM for $cycles CPU T-states with the beeper at $beeper (0/1). */
    public function mix(int $cycles, int $beeper): string
    {
        $exact = $cycles * self::SAMPLE_RATE / $this->clockHz + $this->sampleRemainder;
        $count = (int)$exact;
        $this->sampleRemainder = $exact - $count;
        if ($count <= 0) {
            return '';
        }
        $beeperLevel = $beeper ? self::BEEPER_LEVEL : 0.0;
        $left = [];
        $right = [];
        $this->ay?->render($count, $this->ayTicksPerSample, $left, $right);
        $samples = [];
        for ($i = 0; $i < $count; $i++) {
            $l = $beeperLevel + ($left[$i] ?? 0.0) * self::AY_GAIN;
            $r = $beeperLevel + ($right[$i] ?? 0.0) * self::AY_GAIN;
            $this->dcOutLeft = $l - $this->dcInLeft + self::DC_FILTER_POLE * $this->dcOutLeft;
            $this->dcInLeft = $l;
            $this->dcOutRight = $r - $this->dcInRight + self::DC_FILTER_POLE * $this->dcOutRight;
            $this->dcInRight = $r;
            $samples[] = self::toInt16($this->dcOutLeft);
            $samples[] = self::toInt16($this->dcOutRight);
        }
        return pack('v*', ...$samples); // the low 16 bits of two's complement = s16le
    }

    private static function toInt16(float $value): int
    {
        return max(-32768, min(32767, (int)($value * self::OUTPUT_GAIN)));
    }
}
