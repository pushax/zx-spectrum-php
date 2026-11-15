<?php

namespace ZX\Spectrum\Terminal\Image;

/** iTerm2 inline image protocol (OSC 1337). */
final class ItermProtocol implements ImageProtocol
{
    public function show(string $png, int $width, int $height): string
    {
        return "\033]1337;File=inline=1;size=" . strlen($png)
            . ";width={$width}px;height={$height}px;preserveAspectRatio=1:"
            . base64_encode($png) . "\007";
    }

    public function clear(): string
    {
        return '';
    }
}
