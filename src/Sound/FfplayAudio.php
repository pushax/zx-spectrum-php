<?php

namespace ZX\Spectrum\Sound;

/** Plays the sound through ffplay (FFmpeg), fed with raw PCM through a pipe. Optional: start() may fail. */
final class FfplayAudio implements AudioOutput
{
    private const string COMMAND = 'ffplay -nodisp -autoexit -loglevel quiet -probesize 32 -analyzeduration 0'
        . ' -fflags nobuffer -f s16le -ar 44100 -ch_layout stereo -i pipe:0';
    /** Write in chunks of about 12 ms instead of one tiny write per sound change. */
    private const int WRITE_FRAMES = 512;

    private readonly Mixer $mixer;
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $pipe = null;
    private bool $available = false;
    private string $buffer = '';

    public function __construct()
    {
        $this->mixer = new Mixer();
    }

    public function setClockHz(int $clockHz): void
    {
        $this->mixer->setClockHz($clockHz);
    }

    public function setAy(?Ay8912 $ay): void
    {
        $this->mixer->setAy($ay);
    }

    /**
     * Starts ffplay. -ch_layout replaces -ac (removed in FFmpeg 7); the tiny probe size and no buffering
     * make playback start at once instead of after megabytes of input have been analysed.
     */
    public function start(): bool
    {
        if (!function_exists('proc_open') || trim((string)@shell_exec('command -v ffplay 2>/dev/null')) === '') {
            return false;
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $this->process = @proc_open(self::COMMAND, $descriptors, $pipes);
        if (!is_resource($this->process)) {
            return false;
        }
        $this->pipe = $pipes[0];
        stream_set_blocking($this->pipe, false);
        $this->available = true;
        // Sound is produced exactly in real time, so anything queued before ffplay starts playing stays as
        // permanent latency. Give ffplay a few samples to probe the stream, let it open the audio device
        // before emulation starts, then queue only a small (~40 ms) cushion against timing jitter.
        $this->buffer = str_repeat("\0", 16 * Mixer::FRAME_BYTES);
        $this->flush();
        usleep(500000);
        $this->buffer = str_repeat("\0", intdiv(Mixer::SAMPLE_RATE, 25) * Mixer::FRAME_BYTES);
        $this->flush();
        return true;
    }

    public function render(int $cycles, int $beeper): void
    {
        if (!$this->available || !is_resource($this->pipe)) {
            return;
        }
        $this->buffer .= $this->mixer->mix($cycles, $beeper);
        if (strlen($this->buffer) >= self::WRITE_FRAMES * Mixer::FRAME_BYTES) {
            $this->flush();
        }
    }

    public function stop(): void
    {
        if (is_resource($this->pipe)) {
            @fclose($this->pipe);
            $this->pipe = null;
        }
        if (is_resource($this->process)) {
            @proc_close($this->process);
            $this->process = null;
        }
        $this->available = false;
    }

    public function available(): bool
    {
        return $this->available;
    }

    private function flush(): void
    {
        if ($this->buffer === '') {
            return;
        }
        $written = @fwrite($this->pipe, $this->buffer);
        if ($written === false) {
            $this->available = false;
            $this->buffer = '';
            return;
        }
        // A non-blocking pipe may take only part of the data: keep the rest for the next write instead of
        // dropping it (dropped samples are heard as crackle).
        $this->buffer = (string)substr($this->buffer, $written);
        // If ffplay stopped reading, keep at most ~0.5 s. Drop whole sample frames so that the left/right
        // byte alignment of the stream is preserved.
        $excess = strlen($this->buffer) - intdiv(Mixer::SAMPLE_RATE, 2) * Mixer::FRAME_BYTES;
        if ($excess > 0) {
            $this->buffer = substr($this->buffer, (int)(ceil($excess / Mixer::FRAME_BYTES) * Mixer::FRAME_BYTES));
        }
    }
}
