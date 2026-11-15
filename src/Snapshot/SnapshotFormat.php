<?php

namespace ZX\Spectrum\Snapshot;

use ZX\Spectrum\Machine\Spectrum48;
use ZX\Spectrum\Media\MediaFormat;

/** A snapshot format: restores memory, CPU registers and hardware state of the machine. */
interface SnapshotFormat extends MediaFormat
{
    public function load(string $data, Spectrum48 $machine): void;
}
