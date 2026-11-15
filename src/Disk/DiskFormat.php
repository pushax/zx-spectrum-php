<?php

namespace ZX\Spectrum\Disk;

use ZX\Spectrum\Media\MediaFormat;

/** A disk image format: turns the file into a TR-DOS disk. */
interface DiskFormat extends MediaFormat
{
    public function toImage(string $data): DiskImage;
}
