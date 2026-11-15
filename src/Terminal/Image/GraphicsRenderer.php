<?php

namespace ZX\Spectrum\Terminal\Image;

use ZX\Spectrum\Video\BeamRenderer;

/**
 * Pixel-exact output: scales a 320×240 palette-indexed frame (from BeamRenderer), encodes it as PNG and
 * wraps it in a terminal image protocol.
 */
final class GraphicsRenderer
{
    private const int MAX_SCALE = 8;

    /** @var array<string,string> palette index byte => the same byte repeated $scale times */
    private array $scaledPixels = [];

    public function __construct(private readonly ImageProtocol $protocol, private readonly int $scale = 2)
    {
        if ($scale < 1 || $scale > self::MAX_SCALE) {
            throw new \InvalidArgumentException('Scale must be between 1 and 8.');
        }
        for ($index = 0; $index < 16; $index++) {
            $this->scaledPixels[chr($index)] = str_repeat(chr($index), $scale);
        }
    }

    /** @param string $protocol kitty or iterm */
    public static function forProtocol(string $protocol, int $scale = 2): self
    {
        $imageProtocol = match ($protocol) {
            'kitty' => new KittyProtocol(),
            'iterm' => new ItermProtocol(),
            default => throw new \InvalidArgumentException(
                "Unknown graphics protocol '$protocol'. Use kitty or iterm."
            ),
        };
        return new self($imageProtocol, $scale);
    }

    /** Escape sequence showing a 320×240 palette-indexed frame. */
    public function renderFrame(string $indexed): string
    {
        $rows = [];
        foreach (str_split($indexed, BeamRenderer::WIDTH) as $line) {
            $scaled = $this->scale === 1 ? $line : strtr($line, $this->scaledPixels);
            for ($i = 0; $i < $this->scale; $i++) {
                $rows[] = $scaled;
            }
        }
        $width = BeamRenderer::WIDTH * $this->scale;
        $height = BeamRenderer::HEIGHT * $this->scale;
        return $this->protocol->show(PngEncoder::encode($rows, $width, $height), $width, $height);
    }

    public function clear(): string
    {
        return $this->protocol->clear();
    }
}
