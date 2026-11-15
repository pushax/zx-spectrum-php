<?php

namespace ZX\Spectrum\Media;

/**
 * A file format for software media (snapshots, tapes, disks). Each media kind extends this with its
 * own loading method; a FormatRegistry picks the format for a file.
 */
interface MediaFormat
{
    /** @return list<string> file extensions, lower case, without the dot */
    public function extensions(): array;

    /** True when the data starts with this format's signature (formats without one return false). */
    public function recognizes(string $data): bool;
}
