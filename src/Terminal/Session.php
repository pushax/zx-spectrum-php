<?php

namespace ZX\Spectrum\Terminal;

use ZX\Spectrum\Machine\MachineTiming;
use ZX\Spectrum\Machine\Spectrum48;
use ZX\Spectrum\Sound\AudioOutput;

/**
 * The interactive main loop: keyboard in, one emulated frame, picture and sound out, then wait for the
 * next frame time. While a tape loader is running the loop fast-forwards instead.
 */
final class Session
{
    /** Length of one fast-forward burst while the tape plays (frames run flat out, sound off). */
    private const float FAST_FORWARD_SECONDS = 0.04;
    /** Frames an automatic key press is held. */
    private const int AUTO_KEY_FRAMES = 8;
    /** When this far behind (e.g. a slow terminal) the schedule restarts instead of racing to catch up. */
    private const float MAX_LAG_SECONDS = 0.25;

    private int $frame = 0;
    private float $nextFrameTime = 0.0;

    /**
     * @param array<int, list<array{int,int}>> $autoKeys keys pressed automatically: frame => [[row, bit], ...]
     */
    public function __construct(
        private readonly Spectrum48 $machine,
        private readonly MachineTiming $timing,
        private readonly Display $display,
        private readonly Keyboard $keyboard,
        private readonly AudioOutput $audio,
        private readonly array $autoKeys = [],
        private readonly bool $debug = false,
    ) {
    }

    /** Runs until the user presses Ctrl+C. */
    public function run(): void
    {
        $frameSeconds = 1 / $this->timing->frameRateHz();
        $this->nextFrameTime = microtime(true) + $frameSeconds;
        try {
            while (true) {
                $this->keyboard->poll($this->machine->ula);
                // One full frame up to the next interrupt: the ROM keyboard scan sees the keys,
                // and the beam-rendered picture is complete.
                $pressFrame = $this->frame;
                $this->holdAutoKeys($pressFrame, true);
                $this->runFrames();
                $this->holdAutoKeys($pressFrame, false);
                $this->keyboard->release($this->machine->ula);

                if ($this->debug) {
                    $this->printRegisters();
                }
                $this->display->show($this->machine);
                $this->waitForNextFrame($frameSeconds);
            }
        } catch (StopRequested) {
            return;
        }
    }

    /** Runs one frame, or a burst of frames while a loader reads the tape. */
    private function runFrames(): void
    {
        $machine = $this->machine;
        if ($machine->tape === null || !$machine->tape->playing) {
            $machine->runFrame();
            $this->frame++;
            return;
        }
        $machine->setAudio(null);
        $until = microtime(true) + self::FAST_FORWARD_SECONDS;
        do {
            $machine->runFrame();
            $this->frame++;
        } while ($machine->tape->playing && microtime(true) < $until);
        $machine->setAudio($this->audio);
        $this->nextFrameTime = microtime(true);
    }

    private function holdAutoKeys(int $frame, bool $down): void
    {
        foreach ($this->autoKeys as $at => $keys) {
            if ($frame >= $at && $frame < $at + self::AUTO_KEY_FRAMES) {
                foreach ($keys as [$row, $bit]) {
                    $this->machine->ula->setKey($row, $bit, $down);
                }
            }
        }
    }

    /**
     * Paces against an absolute schedule: usleep() oversleeps by 1-2 ms on macOS, and sleeping a relative
     * amount every frame would accumulate that error (about 45 instead of 50 frames per second).
     */
    private function waitForNextFrame(float $frameSeconds): void
    {
        $left = $this->nextFrameTime - microtime(true);
        if ($left > 0) {
            usleep((int)($left * 1000000));
        } elseif ($left < -self::MAX_LAG_SECONDS) {
            $this->nextFrameTime = microtime(true);
        }
        $this->nextFrameTime += $frameSeconds;
    }

    private function printRegisters(): void
    {
        $cpu = $this->machine->cpu;
        $screenBytes = $this->machine->memory->screenBytes();
        $nonZero = count(array_filter($screenBytes));
        $sum = array_sum($screenBytes) & 0xFFFFFFFF;
        fwrite(STDERR, sprintf(
            "\rPC=%04X SP=%04X AF=%02X%02X BC=%02X%02X DE=%02X%02X HL=%02X%02X cycles=%d halted=%d "
            . "screenNZ=%d/6912 sum=%08X\n",
            $cpu->pc,
            $cpu->sp,
            $cpu->a,
            $cpu->f,
            $cpu->b,
            $cpu->c,
            $cpu->d,
            $cpu->e,
            $cpu->h,
            $cpu->l,
            $cpu->cycles,
            $cpu->halted ? 1 : 0,
            $nonZero,
            $sum
        ));
    }
}
