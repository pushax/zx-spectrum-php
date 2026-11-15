<?php

namespace ZX\Spectrum\Terminal\Image;

/** A terminal image protocol: wraps a PNG in the escape sequence that shows it. */
interface ImageProtocol
{
    /** Escape sequence that shows (or replaces) the picture at the cursor. */
    public function show(string $png, int $width, int $height): string;

    /** Escape sequence that removes the picture when the emulator exits. */
    public function clear(): string;
}
