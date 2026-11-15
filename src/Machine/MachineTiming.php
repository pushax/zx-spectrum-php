<?php

namespace ZX\Spectrum\Machine;

/**
 * Frame timing of a machine model, in CPU T-states.
 * Values from libspectrum timings.c: the 48K draws the top-left paper pixel at 14336 = 64 * 224;
 * the Pentagon has 16 lines of vertical retrace + 64 border lines, first pixel at 17988 = 80 * 224 + 68.
 */
final readonly class MachineTiming
{
    public function __construct(
        public string $name,
        public int $clockHz,
        public int $tstatesPerLine,
        public int $linesPerFrame,
        public int $frameTstates,
        public int $interruptTstates,
        public int $topBorderLines,
        public int $paperLines,
        public int $bottomBorderLines,
        public bool $memoryContention,
        /** T-states from the start of a paper line to its first paper pixel. */
        public int $paperLineOffset = 0
    ) {
        if (
            $clockHz <= 0 || $tstatesPerLine <= 0 || $linesPerFrame <= 0 || $interruptTstates <= 0
            || $frameTstates !== $tstatesPerLine * $linesPerFrame
        ) {
            throw new \InvalidArgumentException('Invalid machine timing profile.');
        }
    }

    public static function named(string $name): self
    {
        return match (strtolower($name)) {
            '48k', 'spectrum48' => new self(
                name: 'Spectrum 48K',
                clockHz: 3500000,
                tstatesPerLine: 224,
                linesPerFrame: 312,
                frameTstates: 69888,
                interruptTstates: 32,
                topBorderLines: 64,
                paperLines: 192,
                bottomBorderLines: 56,
                memoryContention: true,
                paperLineOffset: 0,
            ),
            'pentagon', 'pentagon128' => new self(
                name: 'Pentagon 128',
                clockHz: 3500000,
                tstatesPerLine: 224,
                linesPerFrame: 320,
                frameTstates: 71680,
                interruptTstates: 36,
                topBorderLines: 80,
                paperLines: 192,
                bottomBorderLines: 48,
                memoryContention: false,
                paperLineOffset: 68,
            ),
            default => throw new \InvalidArgumentException("Unknown machine profile '$name'. Use 48k or pentagon."),
        };
    }

    public function withOverrides(?int $clockHz = null, ?int $frameTstates = null): self
    {
        if ($clockHz === null && $frameTstates === null) {
            return $this;
        }
        $clock = $clockHz ?? $this->clockHz;
        $frame = $frameTstates ?? $this->frameTstates;
        if ($frame % $this->tstatesPerLine !== 0) {
            throw new \InvalidArgumentException('Frame T-states must be divisible by T-states per line.');
        }
        $lines = intdiv($frame, $this->tstatesPerLine);
        return new self(
            $this->name . ' (custom)',
            $clock,
            $this->tstatesPerLine,
            $lines,
            $frame,
            $this->interruptTstates,
            $this->topBorderLines,
            $this->paperLines,
            $this->bottomBorderLines,
            $this->memoryContention,
            $this->paperLineOffset
        );
    }

    /** T-state (from the interrupt) at which the top-left paper pixel is drawn. */
    public function topLeftPixel(): int
    {
        return $this->topBorderLines * $this->tstatesPerLine + $this->paperLineOffset;
    }

    public function frameRateHz(): float
    {
        return $this->clockHz / $this->frameTstates;
    }
}
