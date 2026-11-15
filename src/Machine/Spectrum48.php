<?php

namespace ZX\Spectrum\Machine;

use ZX\Spectrum\Cpu\Z80;
use ZX\Spectrum\Disk\BetaDisk;
use ZX\Spectrum\Snapshot\SnapshotLoader;
use ZX\Spectrum\Sound\AudioOutput;
use ZX\Spectrum\Sound\Ay8912;
use ZX\Spectrum\Tape\Tape;
use ZX\Spectrum\Tape\TapeTrap;
use ZX\Spectrum\Video\BeamRenderer;

/**
 * The whole machine: CPU, memory, ULA, beam renderer and the optional AY chip, Beta 128 disk interface
 * and tape player. Despite the name it is also the Pentagon 128 (pass $banked128 = true).
 */
final class Spectrum48
{
    /** A loader polls the EAR bit thousands of times per frame; the ROM keyboard scan only a few. */
    private const int LOADER_READS_PER_FRAME = 1000;
    private const int QUIET_READS_PER_FRAME = 100;
    /** Frames without polling before the tape pauses (the ROM loader itself waits ~1 s without polling). */
    private const int QUIET_FRAMES_TO_PAUSE = 150;

    public readonly string $machineName;
    public readonly Memory $memory;
    public readonly Z80 $cpu;
    public readonly ULA $ula;
    /** Beam-accurate picture of the last complete frame. */
    public readonly BeamRenderer $beam;
    /** AY-3-8912, present on 128K/Pentagon machines. */
    public readonly ?Ay8912 $ay;
    /** AY register selected through port FFFD. */
    public int $aySelected = 0;
    /** Beta 128 disk interface (TR-DOS), present after attachBetaDisk(). */
    public ?BetaDisk $beta = null;
    /** Cassette in the tape player, if any. */
    public ?Tape $tape = null;

    private ?AudioOutput $audio = null;
    /** T-states executed since sound was last generated (sound is produced on changes and at frame end). */
    private int $audioPending = 0;
    private bool $frameDone = false;
    private bool $interruptPending = false;
    /** Port FE reads during the current frame, used to detect a loader polling the tape. */
    private int $earReads = 0;
    private int $quietFrames = 0;

    public function __construct(?string $romFile = null, ?MachineTiming $timing = null, bool $banked128 = false)
    {
        $this->machineName = $banked128 ? 'Pentagon 128' : 'Spectrum 48K';
        $this->memory = new Memory($romFile, $banked128);
        $this->ula = new ULA($timing ?? MachineTiming::named('48k'));
        // The AY runs at half the CPU clock (1.75 MHz at 3.5 MHz), so --clock-hz keeps both consistent.
        $this->ay = $banked128 ? new Ay8912(intdiv($this->ula->timing()->clockHz, 2)) : null;
        $this->cpu = new Z80($this->memory);
        $this->beam = new BeamRenderer($this->ula->timing(), $this->memory, $this->ula);
        // Anything that changes the picture first lets the beam catch up to the current T-state.
        // (The write happens during the instruction; frameCycles is the T-state at its start.)
        $this->memory->onScreenWrite = fn () => $this->beam->update($this->ula->frameCycles);
        $this->cpu->setIo($this->readPort(...), $this->writePort(...));
    }

    public function setAudio(?AudioOutput $audio): void
    {
        $this->audio = $audio;
        $audio?->setAy($this->ay);
    }

    /** Adds a Beta 128 disk interface with the given TR-DOS ROM. */
    public function attachBetaDisk(string $trdosRom): void
    {
        $this->memory->loadTrdosRom($trdosRom);
        $this->beta = new BetaDisk($this->ula->timing()->clockHz);
    }

    /** Pentagon "reset to TR-DOS": a cold start with TR-DOS paged in; TR-DOS then runs the disk's "boot". */
    public function resetToTrdos(): void
    {
        if ($this->beta === null) {
            throw new \RuntimeException('No Beta disk interface attached.');
        }
        $this->memory->page7ffd(0x10); // 48 BASIC ROM, bank 0, normal screen
        $this->memory->trdosActive = true;
        $cpu = $this->cpu;
        $cpu->pc = 0;
        $cpu->sp = 0xFFFF;
        $cpu->halted = false;
        $cpu->iff1 = false;
        $cpu->iff2 = false;
        $cpu->im = 1;
    }

    public function loadSnapshot(string $path): void
    {
        SnapshotLoader::load($path, $this);
    }

    /** Runs until the end of the current frame (the next interrupt), so the beam frame is complete. */
    public function runFrame(): void
    {
        $this->frameDone = false;
        while (!$this->frameDone) {
            $this->runInstructions(1);
        }
    }

    public function runInstructions(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            if ($this->beta !== null) {
                $this->pageTrdos($this->cpu->pc);
            }
            if ($this->tape !== null && $this->cpu->pc === TapeTrap::LD_BYTES) {
                $this->loadFromTape();
            }

            $cycles = $this->cpu->step();
            if ($this->tape !== null && $this->tape->playing) {
                $this->tape->advance($cycles);
            }
            $this->audioPending += $cycles;
            if ($this->ula->tick($cycles)) {
                $this->endFrame();
            }
            if ($this->interruptPending) {
                $this->acceptInterrupt();
            }
        }
    }

    /**
     * Beta 128 paging: an opcode fetch from 3D00-3DFF with the 48 BASIC ROM selected pages TR-DOS in,
     * any fetch from RAM (4000 and up) pages it out again.
     */
    private function pageTrdos(int $pc): void
    {
        if ($this->memory->trdosActive) {
            if ($pc >= 0x4000) {
                $this->memory->trdosActive = false;
            }
        } elseif (($pc & 0xFF00) === 0x3D00 && $this->memory->basic48Selected()) {
            $this->memory->trdosActive = true;
        }
    }

    private function endFrame(): void
    {
        $this->interruptPending = true;
        $this->flushAudio();
        $this->beam->endFrame();
        $this->frameDone = true;
        $this->detectLoader();
    }

    /**
     * The ULA holds INT low for a short window at the start of the frame; the interrupt is accepted
     * at the first instruction boundary inside that window when interrupts are enabled.
     */
    private function acceptInterrupt(): void
    {
        if ($this->ula->frameCycles >= $this->ula->timing()->interruptTstates) {
            $this->interruptPending = false;
        } elseif ($this->cpu->canAcceptInterrupt()) {
            $this->interruptPending = false;
            $t = $this->cpu->interrupt();
            $this->audioPending += $t;
            $this->ula->tick($t);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // I/O ports
    // ---------------------------------------------------------------------------------------------

    private function readPort(int $port): int
    {
        // While TR-DOS is paged in, the WD1793 ports take priority (port 1F is Kempston otherwise).
        if ($this->beta !== null && $this->memory->trdosActive && BetaDisk::ownsPort($port)) {
            return $this->beta->read($port, $this->cpu->cycles);
        }
        // AY register read (port FFFD: A15 = 1, A14 = 1, A1 = 0).
        if ($this->ay !== null && ($port & 0xC002) === 0xC000) {
            return $this->ay->read($this->aySelected);
        }
        $value = $this->ula->readPort($port);
        if ($this->tape !== null && ($port & 1) === 0) {
            $this->earReads++;
            $value = ($value & 0xBF) | ($this->tape->level << 6); // EAR input on bit 6
        }
        return $value;
    }

    private function writePort(int $port, int $value): void
    {
        if ($this->beta !== null && $this->memory->trdosActive && BetaDisk::ownsPort($port)) {
            $this->beta->write($port, $value, $this->cpu->cycles);
            return;
        }
        if (($port & 1) === 0) {
            // Sound or picture is about to change: first produce them up to this instruction.
            if ((($value >> 4) & 1) !== $this->ula->beeper) {
                $this->flushAudio();
            }
            if (($value & 7) !== $this->ula->border) {
                $this->beam->update($this->ula->frameCycles);
            }
        }
        $this->ula->writePort($port, $value);

        // 128K/Pentagon decode port 7FFD by A15 = 0 and A1 = 0 only.
        if ($this->memory->is128K() && ($port & 0x8002) === 0) {
            if ((($value & 8) ? 7 : 5) !== $this->memory->screenBank()) {
                $this->beam->update($this->ula->frameCycles);
            }
            $this->memory->page7ffd($value);
        }
        if ($this->ay !== null) {
            if (($port & 0xC002) === 0xC000) {        // FFFD: select register
                $this->aySelected = $value & 15;
            } elseif (($port & 0xC002) === 0x8000) {  // BFFD: write register
                $this->flushAudio();
                $this->ay->write($this->aySelected, $value);
            }
        }
    }

    /** Generates sound for the T-states executed so far, with the sound state as it was during them. */
    private function flushAudio(): void
    {
        if ($this->audio !== null && $this->audioPending > 0) {
            $this->audio->render($this->audioPending, $this->ula->beeper);
        }
        $this->audioPending = 0;
    }

    // ---------------------------------------------------------------------------------------------
    // Tape
    // ---------------------------------------------------------------------------------------------

    /**
     * The ROM loader was called: load the next standard block instantly. Blocks the ROM cannot load
     * instantly are left to it (or to a custom loader) reading the real signal, so the tape starts playing.
     */
    private function loadFromTape(): void
    {
        if (!TapeTrap::romHasLoader($this->memory)) {
            return;
        }
        $block = $this->tape->takeStandardBlock();
        if ($block === null) {
            $this->tape->playing = true;
            return;
        }
        $this->tape->playing = false;
        TapeTrap::load($block, $this->cpu, $this->memory);
    }

    /** Starts the tape when a loader polls the EAR bit hard (custom loaders), pauses it when polling stops. */
    private function detectLoader(): void
    {
        if ($this->tape === null) {
            return;
        }
        if ($this->earReads > self::LOADER_READS_PER_FRAME && !$this->tape->atEnd()) {
            $this->tape->playing = true;
            $this->quietFrames = 0;
        } elseif ($this->earReads >= self::QUIET_READS_PER_FRAME) {
            $this->quietFrames = 0;
        } elseif ($this->tape->playing && ++$this->quietFrames >= self::QUIET_FRAMES_TO_PAUSE) {
            $this->tape->playing = false;
        }
        $this->earReads = 0;
    }
}
