<?php

namespace ZX\Spectrum\Terminal;

use ZX\Spectrum\Machine\Spectrum48;
use ZX\Spectrum\Terminal\Image\GraphicsRenderer;
use ZX\Spectrum\Video\SpectrumScreen;

/**
 * Shows the emulated screen in the terminal: pixel-exact through a graphics protocol (Kitty, iTerm2)
 * or as true-colour text. Uses the alternate screen buffer, so the terminal is restored on exit.
 */
final class Display
{
    private const string ENTER = "\033[?1049h\033[?25l\033[?7l\033[2J"; // alternate screen, no cursor, no wrap
    private const string LEAVE = "\033[0m\033[?7h\033[?25h\033[?1049l";
    private const int TEXT_COLUMNS = 256;
    private const int TEXT_ROWS = 96;

    private ?GraphicsRenderer $graphics = null;
    private TextRenderer $text;
    private ?string $lastFrame = null;

    /** @param string $mode auto, kitty, iterm or text */
    public function __construct(string $mode, int $scale)
    {
        $mode = strtolower($mode);
        if ($mode === 'auto') {
            $mode = self::detectMode();
        }
        if ($mode !== 'text') {
            $this->graphics = GraphicsRenderer::forProtocol($mode, $scale); // throws on an unknown mode
        }
        $this->text = new TextRenderer();
    }

    /** Picks the graphics protocol from the terminal program: iTerm2 images, Kitty graphics, or text. */
    public static function detectMode(): string
    {
        $program = strtolower((string)getenv('TERM_PROGRAM'));
        return match (true) {
            $program === 'iterm.app' => 'iterm',
            in_array($program, ['warpterminal', 'wezterm', 'ghostty'], true),
            getenv('TERM') === 'xterm-kitty' => 'kitty',
            default => 'text',
        };
    }

    public function open(): void
    {
        echo self::ENTER;
        if ($this->graphics === null) {
            $this->warnIfTooSmallForText();
        }
    }

    public function close(): void
    {
        if ($this->graphics !== null) {
            echo $this->graphics->clear();
        }
        echo self::LEAVE;
    }

    /** Draws the last complete frame, if it differs from the one on screen. */
    public function show(Spectrum48 $machine): void
    {
        if ($this->graphics !== null) {
            $frame = $machine->beam->frame();
            $key = md5($frame);
            if ($key !== $this->lastFrame) {
                $this->lastFrame = $key;
                echo "\033[H" . $this->graphics->renderFrame($frame);
            }
            return;
        }
        $screenBytes = $machine->memory->screenBytes();
        $key = md5(pack('C*', ...$screenBytes)) . ($machine->ula->flashPhase ? '1' : '0');
        if ($key !== $this->lastFrame) {
            $this->lastFrame = $key;
            $picture = $this->text->render(new SpectrumScreen($screenBytes), $machine->ula->flashPhase);
            // Synchronized output: the terminal shows the frame only when it is complete (no flicker).
            echo "\033[?2026h\033[H" . $picture . "\033[0m\033[?2026l";
        }
    }

    /** The text renderer needs 256×96 character cells: warn instead of silently cutting the picture. */
    private function warnIfTooSmallForText(): void
    {
        $size = trim((string)@shell_exec('stty size 2>/dev/null'));
        [$rows, $columns] = array_map('intval', explode(' ', $size . ' 0')) + [0, 0];
        if ($rows > 0 && ($rows < self::TEXT_ROWS || $columns < self::TEXT_COLUMNS)) {
            echo "\033[?1049l\033[?25h\033[?7h";
            fwrite(STDERR, sprintf(
                "Warning: text mode needs a %dx%d terminal, this one is %dx%d; the picture will be cut. "
                . "Enlarge the window / reduce the font, or use a terminal with --gfx=iterm|kitty.\n",
                self::TEXT_COLUMNS,
                self::TEXT_ROWS,
                $columns,
                $rows
            ));
            sleep(3);
            echo self::ENTER;
        }
    }
}
