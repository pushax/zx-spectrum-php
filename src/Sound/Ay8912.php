<?php

namespace ZX\Spectrum\Sound;

/**
 * AY-3-8912 sound chip. Tone, noise and envelope generators follow the ayumi reference
 * implementation (https://github.com/true-grue/ayumi): generators tick at clock/8,
 * a tone period P gives a square wave of clock/(16·P), 17-bit noise LFSR, AY DAC table.
 */
final class Ay8912
{
    /** AY DAC levels indexed 0..31 (a 4-bit volume v uses index 2v+1). From ayumi. */
    private const array DAC = [
        0.0, 0.0, 0.00999465934234, 0.00999465934234, 0.0144502937362, 0.0144502937362,
        0.0210574502174, 0.0210574502174, 0.0307011520562, 0.0307011520562,
        0.0455481803616, 0.0455481803616, 0.0644998855573, 0.0644998855573,
        0.107362478065, 0.107362478065, 0.126588845655, 0.126588845655,
        0.20498970016, 0.20498970016, 0.292210269322, 0.292210269322,
        0.372838941024, 0.372838941024, 0.492530708782, 0.492530708782,
        0.635324635691, 0.635324635691, 0.805584802014, 0.805584802014, 1.0, 1.0,
    ];
    /** Register masks: unused bits read back as 0. */
    private const array MASK = [
        0xFF, 0x0F, 0xFF, 0x0F, 0xFF, 0x0F, 0x1F, 0xFF, 0x1F, 0x1F, 0x1F, 0xFF, 0xFF, 0x0F, 0xFF, 0xFF];
    // Envelope segment kinds
    private const int SLIDE_DOWN = 0;
    private const int SLIDE_UP = 1;
    private const int HOLD_BOTTOM = 2;
    private const int HOLD_TOP = 3;
    /** Envelope shapes (register 13) as [first segment, following segment]. From ayumi. */
    private const array SHAPES = [
        [0, 2], [0, 2], [0, 2], [0, 2], [1, 2], [1, 2], [1, 2], [1, 2],
        [0, 0], [0, 2], [0, 1], [0, 3], [1, 1], [1, 3], [1, 0], [1, 2],
    ];

    /** @var array<int,int> */
    private array $regs;
    /** @var array<int,int> */
    private array $toneCounter = [0, 0, 0];
    /** @var array<int,int> */
    private array $tone = [0, 0, 0];
    private int $noiseCounter = 0;
    private int $noise = 1;
    private int $envCounter = 0;
    private int $env = 0;
    private int $envShape = 0;
    private int $envSegment = 0;
    private float $tickAccumulator = 0.0;
    /** @var array<int,float> */
    private array $panLeft;
    /** @var array<int,float> */
    private array $panRight;

    /** @param array<int,float> $pan stereo position of A, B, C (0 = left, 1 = right); default ABC stereo */
    public function __construct(private int $clockHz = 1750000, array $pan = [0.1, 0.5, 0.9])
    {
        $this->regs = array_fill(0, 16, 0);
        foreach ($pan as $i => $p) {
            $this->panLeft[$i] = sqrt(1 - $p);
            $this->panRight[$i] = sqrt($p);
        } // equal-power
        $this->resetEnvelopeSegment();
    }

    public function clockHz(): int
    {
        return $this->clockHz;
    }

    public function write(int $reg, int $value): void
    {
        $reg &= 15;
        $this->regs[$reg] = $value & self::MASK[$reg];
        if ($reg === 13) { // writing the shape restarts the envelope
            $this->envShape = $this->regs[13];
            $this->envCounter = 0;
            $this->envSegment = 0;
            $this->resetEnvelopeSegment();
        }
    }

    public function read(int $reg): int
    {
        return $this->regs[$reg & 15];
    }

    /**
     * Generates $count output samples, each the average of the generator ticks that fall into it.
     *
     * @param array<int,float> $left
     * @param array<int,float> $right
     */
    public function render(int $count, float $ticksPerSample, array &$left, array &$right): void
    {
        $r = $this->regs;
        $tp = [($r[0] | ($r[1] << 8)) ?: 1, ($r[2] | ($r[3] << 8)) ?: 1, ($r[4] | ($r[5] << 8)) ?: 1];
        $noisePeriod = (($r[6] & 0x1F) ?: 1) << 1;
        $envPeriod = ($r[11] | ($r[12] << 8)) ?: 1;
        $tOff = [$r[7] & 1, ($r[7] >> 1) & 1, ($r[7] >> 2) & 1];      // register 7 bits are active-low enables
        $nOff = [($r[7] >> 3) & 1, ($r[7] >> 4) & 1, ($r[7] >> 5) & 1];
        $eOn = [($r[8] & 0x10) !== 0, ($r[9] & 0x10) !== 0, ($r[10] & 0x10) !== 0];
        $vol = [($r[8] & 15) * 2 + 1, ($r[9] & 15) * 2 + 1, ($r[10] & 15) * 2 + 1];
        $dac = self::DAC;
        $pl = $this->panLeft;
        $pr = $this->panRight;
        [$c0, $c1, $c2] = $this->toneCounter;
        [$t0, $t1, $t2] = $this->tone;
        $nc = $this->noiseCounter;
        $noise = $this->noise;
        $ec = $this->envCounter;
        $acc = $this->tickAccumulator;

        for ($s = 0; $s < $count; $s++) {
            $acc += $ticksPerSample;
            $k = (int)$acc;
            $acc -= $k;
            $sumL = 0.0;
            $sumR = 0.0;
            for ($t = 0; $t < $k; $t++) {
                if (++$nc >= $noisePeriod) {
                    $nc = 0;
                    $noise = ($noise >> 1) | ((($noise ^ ($noise >> 3)) & 1) << 16);
                }
                if (++$ec >= $envPeriod) {
                    $ec = 0;
                    $this->envelopeStep();
                }
                if (++$c0 >= $tp[0]) {
                    $c0 = 0;
                    $t0 ^= 1;
                }
                if (++$c1 >= $tp[1]) {
                    $c1 = 0;
                    $t1 ^= 1;
                }
                if (++$c2 >= $tp[2]) {
                    $c2 = 0;
                    $t2 ^= 1;
                }
                $n = $noise & 1;
                $env = $this->env;
                $a = (($t0 | $tOff[0]) & ($n | $nOff[0])) ? $dac[$eOn[0] ? $env : $vol[0]] : 0.0;
                $b = (($t1 | $tOff[1]) & ($n | $nOff[1])) ? $dac[$eOn[1] ? $env : $vol[1]] : 0.0;
                $c = (($t2 | $tOff[2]) & ($n | $nOff[2])) ? $dac[$eOn[2] ? $env : $vol[2]] : 0.0;
                $sumL += $a * $pl[0] + $b * $pl[1] + $c * $pl[2];
                $sumR += $a * $pr[0] + $b * $pr[1] + $c * $pr[2];
            }
            $left[] = $k > 0 ? $sumL / $k : 0.0;
            $right[] = $k > 0 ? $sumR / $k : 0.0;
        }

        $this->toneCounter = [$c0, $c1, $c2];
        $this->tone = [$t0, $t1, $t2];
        $this->noiseCounter = $nc;
        $this->noise = $noise;
        $this->envCounter = $ec;
        $this->tickAccumulator = $acc;
    }

    private function envelopeStep(): void
    {
        $segment = self::SHAPES[$this->envShape][$this->envSegment];
        if ($segment === self::SLIDE_UP) {
            if (++$this->env > 31) {
                $this->envSegment ^= 1;
                $this->resetEnvelopeSegment();
            }
        } elseif ($segment === self::SLIDE_DOWN) {
            if (--$this->env < 0) {
                $this->envSegment ^= 1;
                $this->resetEnvelopeSegment();
            }
        }
    }

    private function resetEnvelopeSegment(): void
    {
        $segment = self::SHAPES[$this->envShape][$this->envSegment];
        $this->env = ($segment === self::SLIDE_DOWN || $segment === self::HOLD_TOP) ? 31 : 0;
    }
}
