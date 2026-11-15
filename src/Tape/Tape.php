<?php

namespace ZX\Spectrum\Tape;

use ZX\Spectrum\Media\FormatRegistry;
use ZX\Spectrum\Tape\Block\DataBlock;
use ZX\Spectrum\Tape\Block\LoopEndBlock;
use ZX\Spectrum\Tape\Block\LoopStartBlock;
use ZX\Spectrum\Tape\Block\PauseBlock;
use ZX\Spectrum\Tape\Block\SetLevelBlock;
use ZX\Spectrum\Tape\Block\TapeBlock;

/**
 * The tape player: plays tape blocks as the EAR signal (port FE bit 6) and hands standard blocks to
 * the ROM loader trap (see TapeTrap). The machine starts and pauses it (Spectrum48::detectLoader()).
 */
final class Tape
{
    /** EAR input level (0 or 1). */
    public int $level = 0;
    public bool $playing = false;

    /** Index of the current block; -1 before the first one. */
    private int $index = -1;
    /** @var list<int> signal of the current block (see TapeBlock::pulses()) */
    private array $pulses = [];
    /** Next pulse of $pulses. */
    private int $pulse = 0;
    /** T-states left in the current pulse. */
    private int $remaining = 0;
    /** @var list<array{int,int}> loop stack: [index of the loop start block, repetitions left] */
    private array $loops = [];

    /** @param list<TapeBlock> $blocks */
    public function __construct(private readonly array $blocks)
    {
    }

    /** The supported tape image formats. */
    public static function formats(): FormatRegistry
    {
        return new FormatRegistry('tape image', [new TzxFormat(), new TapFormat()]);
    }

    /** Opens a .tap or .tzx file. */
    public static function open(string $path): self
    {
        $formats = self::formats();
        $data = $formats->read($path);
        $blocks = $formats->select($path, $data)->parse($data);
        if ($blocks === []) {
            throw new \RuntimeException("'$path' contains no tape blocks.");
        }
        return new self($blocks);
    }

    /** Advances the tape by $cycles T-states while it plays. */
    public function advance(int $cycles): void
    {
        $this->remaining -= $cycles;
        while ($this->remaining <= 0) {
            if ($this->pulse >= count($this->pulses) && !$this->nextBlock()) {
                $this->playing = false;
                return;
            }
            $pulse = $this->pulses[$this->pulse++];
            if ($pulse > 0) {
                $this->remaining += $pulse;
                $this->level ^= 1;
            } else {
                $this->remaining -= $pulse;
                $this->level = 0;
            }
        }
    }

    /**
     * Instant load for the ROM LD-BYTES routine: returns the next data block the ROM loader can read
     * (standard timing, or turbo timing within its tolerance) and moves past it. Blocks the ROM cannot
     * read are skipped, as the ROM itself would let them pass. Null when no such block is left.
     */
    public function takeStandardBlock(): ?string
    {
        // Still in the pilot tone of the current data block: load that one; otherwise look further on.
        $index = $this->index;
        $current = $this->blocks[$index] ?? null;
        if (
            $index < 0
            || $this->pulse >= count($this->pulses)
            || !$current instanceof DataBlock
            || $this->pulse > $current->pilotCount
        ) {
            $index++;
        }
        for ($count = count($this->blocks); $index < $count; $index++) {
            $block = $this->blocks[$index];
            if ($block instanceof DataBlock && $block->romReadable()) {
                $this->index = $index;
                $this->pulses = [];
                $this->pulse = 0;
                $this->remaining = 0;
                $this->level = 0;
                return $block->data;
            }
        }
        return null;
    }

    public function atEnd(): bool
    {
        return $this->index >= count($this->blocks) - 1 && $this->pulse >= count($this->pulses);
    }

    /** Moves to the next block with a signal; false at the end of the tape or at a "stop the tape" pause. */
    private function nextBlock(): bool
    {
        while (true) {
            $this->index++;
            $this->pulses = [];
            $this->pulse = 0;
            if ($this->index >= count($this->blocks)) {
                return false;
            }
            $block = $this->blocks[$this->index];
            if ($block instanceof LoopStartBlock) {
                $this->loops[] = [$this->index, $block->repetitions];
                continue;
            }
            if ($block instanceof LoopEndBlock) {
                $this->endLoopIteration();
                continue;
            }
            if ($block instanceof SetLevelBlock) {
                $this->level = $block->level;
                continue;
            }
            if ($block instanceof PauseBlock && $block->stopsTape()) {
                $this->playing = false;
                return false;
            }
            $this->pulses = $block->pulses($this->level);
            if ($this->pulses !== []) {
                return true;
            }
        }
    }

    private function endLoopIteration(): void
    {
        if ($this->loops === []) {
            return;
        }
        $top = count($this->loops) - 1;
        if (--$this->loops[$top][1] > 0) {
            $this->index = $this->loops[$top][0]; // the next block is the first one of the loop again
        } else {
            array_pop($this->loops);
        }
    }
}
