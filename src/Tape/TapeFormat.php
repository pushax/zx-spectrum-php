<?php

namespace ZX\Spectrum\Tape;

use ZX\Spectrum\Media\MediaFormat;
use ZX\Spectrum\Tape\Block\TapeBlock;

/** A tape image format: turns the file into tape blocks. */
interface TapeFormat extends MediaFormat
{
    /** @return list<TapeBlock> */
    public function parse(string $data): array;
}
