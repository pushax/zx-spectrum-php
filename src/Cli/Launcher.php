<?php

namespace ZX\Spectrum\Cli;

use ZX\Spectrum\Cpu\Z80;
use ZX\Spectrum\Machine\MachineTiming;
use ZX\Spectrum\Machine\Spectrum48;
use ZX\Spectrum\Sound\FfplayAudio;
use ZX\Spectrum\Tape\Tape;
use ZX\Spectrum\Terminal\Display;
use ZX\Spectrum\Terminal\Keyboard;
use ZX\Spectrum\Terminal\Session;
use ZX\Spectrum\Terminal\TextRenderer;
use ZX\Spectrum\Video\DemoScreen;
use ZX\Spectrum\Video\SpectrumScreen;

/**
 * The command-line program (run.php): reads the options, builds the machine, loads the software and
 * starts the terminal session.
 */
final class Launcher
{
    /** Options for getopt(). */
    public const array OPTIONS = [
        'demo', 'screen:', 'snapshot:', 'boot', 'rom:', 'cpu-test', 'no-audio', 'machine:', 'clock-hz:',
        'frame-tstates:', 'debug', 'gfx:', 'scale:', 'disk:', 'trdos:', 'tape:', 'ay',
    ];

    private const string USAGE = <<<'TEXT'
        Usage:
         php run.php --demo
         php run.php --screen=file.scr
         php run.php --snapshot=game.sna [--rom=roms/48.rom] [--machine=48k|pentagon] [--no-audio]
                     [--gfx=auto|kitty|iterm|text] [--scale=2]
         php run.php --boot --rom=roms/48.rom [--machine=48k] [--no-audio] [--debug]
         php run.php --disk=demo.trd|demo.scl [--rom=roms/pentagon.rom] [--trdos=roms/trdos.rom] [--boot]
         php run.php --tape=game.tap|game.tzx [--machine=48k|pentagon] [--rom=roms/48.rom] [--ay]
         php run.php --cpu-test

        TEXT;

    private const string KEYBOARD_HELP = 'Keyboard: capitals = CAPS SHIFT, symbols (" : ; , . + - = * / < > ...) = '
        . 'SYMBOL SHIFT, Backspace = DELETE, Esc = BREAK, Tab = extended mode, arrows = cursor. Ctrl+C exits.';

    /** Keys typed to start a tape: LOAD "" in 48 BASIC (J, SS+P, SS+P, Enter), frame => [[row, bit], ...]. */
    private const array LOAD_48K = [150 => [[6, 3]], 170 => [[7, 1], [5, 0]], 190 => [[7, 1], [5, 0]], 210 => [[6, 0]]];
    /** On a 128K ROM: Enter on the first menu item ("Tape Loader"). */
    private const array LOAD_128K = [150 => [[6, 0]]];

    /**
     * @param array<string, string|false> $options result of getopt()
     * @param string $romDir folder with the default ROM files
     */
    public function __construct(private array $options, private readonly string $romDir)
    {
    }

    /** Runs the program; returns the exit code. */
    public function run(): int
    {
        $o = $this->options;
        return match (true) {
            isset($o['cpu-test']) => $this->cpuTest(),
            isset($o['demo']) => $this->showStaticScreen(DemoScreen::make()),
            isset($o['screen']) => $this->showStaticScreen($this->readScreenFile((string)$o['screen'])),
            isset($o['snapshot']) || isset($o['boot']) || isset($o['disk']) || isset($o['tape']) => $this->runMachine(),
            default => $this->usage(),
        };
    }

    private function usage(): int
    {
        fwrite(STDERR, self::USAGE);
        return 2;
    }

    /** A tiny program on the CPU, for checking that PHP runs the core at all. */
    private function cpuTest(): int
    {
        $machine = new Spectrum48();
        $machine->memory->load(0x8000, "\x3E\x2A\x06\x18\x80\x76"); // LD A,42; LD B,24; ADD A,B; HALT
        $machine->cpu->pc = 0x8000;
        $machine->runInstructions(10);
        $cpu = $machine->cpu;
        printf(
            "A=%02X F=%02X PC=%04X cycles=%d halted=%s\n",
            $cpu->a,
            $cpu->f,
            $cpu->pc,
            $cpu->cycles,
            $cpu->halted ? 'yes' : 'no'
        );
        return 0;
    }

    /** @return array<int,int> */
    private function readScreenFile(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || strlen($raw) !== SpectrumScreen::SCREEN_BYTES) {
            throw new CliError('Raw screen must be 6912 bytes', 1);
        }
        return array_values(unpack('C*', $raw));
    }

    /** Shows a fixed screen (with FLASH) in text mode until the program is interrupted. */
    private function showStaticScreen(array $screenBytes): never
    {
        $renderer = new TextRenderer();
        $screen = new SpectrumScreen($screenBytes);
        echo "\033[?25l";
        try {
            $flash = false;
            while (true) {
                echo "\033[H" . $renderer->render($screen, $flash) . "\033[0m";
                $flash = !$flash;
                usleep(500000);
            }
        } finally {
            echo "\033[0m\033[?25h\n";
        }
    }

    private function runMachine(): int
    {
        $o = &$this->options;
        $rom = $this->romFile();
        if (isset($o['boot']) && ($rom === null || !is_file($rom) || !in_array(filesize($rom), [16384, 32768], true))) {
            throw new CliError(
                'Boot requires a 16K (48K) or 32K (128K/Pentagon) ROM file. Use --boot --rom=roms/48.rom',
                2
            );
        }
        // Disk images are Pentagon software: default to the Pentagon when a disk is given.
        if (isset($o['disk']) && !isset($o['machine'])) {
            $o['machine'] = 'pentagon';
        }
        $timing = $this->timing();
        $machine = new Spectrum48($rom, $timing, $this->isPentagon(), isset($o['ay']));
        if ($rom === null) {
            fwrite(STDERR, "Warning: no --rom given, the ROM area is empty (0xFF). "
                . "Most snapshots need the ROM: add --rom=roms/48.rom\n");
        }

        if (isset($o['disk'])) {
            $this->insertDisk($machine, (string)$o['disk']);
        }
        $autoKeys = [];
        if (isset($o['tape'])) {
            $this->insertTape($machine, (string)$o['tape']);
            if (!isset($o['snapshot'])) {
                $autoKeys = $rom !== null && filesize($rom) === 32768 ? self::LOAD_128K : self::LOAD_48K;
            }
        }
        if (isset($o['snapshot'])) {
            $this->loadSnapshot($machine, (string)$o['snapshot']);
        } elseif (isset($o['disk']) && !isset($o['boot'])) {
            $machine->resetToTrdos(); // TR-DOS starts and runs the disk's "boot" file
        } else {
            $this->powerOn($machine->cpu);
        }

        $this->startSession($machine, $timing, $autoKeys);
        return 0;
    }

    /** --rom, or the default ROM in the ROM folder for disks (pentagon.rom) and tapes (48.rom / 128.rom). */
    private function romFile(): ?string
    {
        $o = $this->options;
        if (isset($o['rom'])) {
            return (string)$o['rom'];
        }
        $default = match (true) {
            isset($o['disk']) => 'pentagon.rom',
            isset($o['tape']) => $this->isPentagon() ? '128.rom' : '48.rom', // 128.rom has "Tape Loader"
            default => null,
        };
        $path = $default !== null ? $this->romDir . '/' . $default : null;
        return $path !== null && is_file($path) ? $path : null;
    }

    private function isPentagon(): bool
    {
        return in_array(strtolower((string)($this->options['machine'] ?? '48k')), ['pentagon', 'pentagon128'], true);
    }

    private function timing(): MachineTiming
    {
        $o = $this->options;
        try {
            return MachineTiming::named((string)($o['machine'] ?? '48k'))->withOverrides(
                isset($o['clock-hz']) ? (int)$o['clock-hz'] : null,
                isset($o['frame-tstates']) ? (int)$o['frame-tstates'] : null
            );
        } catch (\InvalidArgumentException $e) {
            throw new CliError($e->getMessage(), 2);
        }
    }

    /** Adds the Beta 128 interface (TR-DOS ROM from --trdos or trdos.rom) and inserts the disk. */
    private function insertDisk(Spectrum48 $machine, string $disk): void
    {
        $trdos = (string)($this->options['trdos'] ?? $this->romDir . '/trdos.rom');
        try {
            $machine->attachBetaDisk($trdos);
        } catch (\RuntimeException $e) {
            throw new CliError($e->getMessage() . " (TR-DOS ROM: $trdos, use --trdos=file)", 1);
        }
        try {
            $machine->beta->insert($disk);
        } catch (\RuntimeException $e) {
            throw new CliError($e->getMessage(), 1);
        }
    }

    private function insertTape(Spectrum48 $machine, string $tape): void
    {
        try {
            $machine->tape = Tape::open($tape);
        } catch (\RuntimeException $e) {
            throw new CliError($e->getMessage(), 1);
        }
    }

    private function loadSnapshot(Spectrum48 $machine, string $snapshot): void
    {
        try {
            $machine->loadSnapshot($snapshot);
        } catch (\RuntimeException $e) {
            throw new CliError($e->getMessage(), 1);
        }
    }

    /** Power-on state: the Z80 starts at ROM address 0000 with interrupts disabled. */
    private function powerOn(Z80 $cpu): void
    {
        $cpu->pc = 0x0000;
        $cpu->sp = 0xFFFF;
        $cpu->halted = false;
        $cpu->iff1 = false;
        $cpu->iff2 = false;
        $cpu->im = 1;
    }

    /** @param array<int, list<array{int,int}>> $autoKeys */
    private function startSession(Spectrum48 $machine, MachineTiming $timing, array $autoKeys): void
    {
        $o = $this->options;
        $keyboard = new Keyboard();
        $keyboard->start();
        $audio = new FfplayAudio();
        if (!isset($o['no-audio'])) {
            fwrite(STDERR, $audio->start()
                ? 'Audio: ffplay enabled. '
                : 'Audio unavailable (install ffplay); continuing silently. ');
        }
        $audio->setClockHz($timing->clockHz);
        $machine->setAudio($audio);
        fwrite(STDERR, sprintf(
            "Machine: %s | clock: %.3f MHz | frame: %d T | %.3f Hz\n",
            $timing->name . ($machine->ay !== null && !$this->isPentagon() ? ' + AY' : ''),
            $timing->clockHz / 1000000,
            $timing->frameTstates,
            $timing->frameRateHz()
        ));
        fwrite(STDERR, self::KEYBOARD_HELP . "\n");

        try {
            $display = new Display((string)($o['gfx'] ?? 'auto'), isset($o['scale']) ? (int)$o['scale'] : 2);
        } catch (\InvalidArgumentException $e) {
            $keyboard->stop();
            $audio->stop();
            throw new CliError($e->getMessage(), 2);
        }

        $display->open();
        try {
            $session = new Session($machine, $timing, $display, $keyboard, $audio, $autoKeys, isset($o['debug']));
            $session->run();
        } finally {
            $keyboard->stop();
            $audio->stop();
            $display->close();
        }
    }
}
