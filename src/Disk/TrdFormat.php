<?php

namespace ZX\Spectrum\Disk;

/** .trd: a plain sector dump of a TR-DOS disk. */
final class TrdFormat implements DiskFormat
{
    public function extensions(): array
    {
        return ['trd'];
    }

    public function recognizes(string $data): bool
    {
        return false; // no signature
    }

    public function toImage(string $data): DiskImage
    {
        return DiskImage::fromTrd($data);
    }
}
