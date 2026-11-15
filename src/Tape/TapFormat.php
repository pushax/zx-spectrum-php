<?php

namespace ZX\Spectrum\Tape;

use ZX\Spectrum\Tape\Block\DataBlock;

/** .tap: standard ROM blocks, each preceded by its 16-bit length. */
final class TapFormat implements TapeFormat
{
    private const int PAUSE_MS = 1000;

    public function extensions(): array
    {
        return ['tap'];
    }

    public function recognizes(string $data): bool
    {
        return false; // no signature
    }

    public function parse(string $data): array
    {
        $blocks = [];
        $end = strlen($data);
        for ($offset = 0; $offset + 2 <= $end;) {
            $length = unpack('v', substr($data, $offset, 2))[1];
            $block = substr($data, $offset + 2, $length);
            if (strlen($block) < $length) {
                throw new \RuntimeException('Truncated TAP image.');
            }
            $blocks[] = DataBlock::standard($block, self::PAUSE_MS);
            $offset += 2 + $length;
        }
        return $blocks;
    }
}
