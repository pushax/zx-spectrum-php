<?php

namespace ZX\Spectrum\Snapshot;

use ZX\Spectrum\Machine\Spectrum48;
use ZX\Spectrum\Media\FormatRegistry;

/** Loads a snapshot file in any supported format. */
final class SnapshotLoader
{
    public static function formats(): FormatRegistry
    {
        return new FormatRegistry('snapshot', [new SnaFormat(), new Z80Format()]);
    }

    public static function load(string $path, Spectrum48 $machine): void
    {
        $formats = self::formats();
        $data = $formats->read($path);
        $formats->select($path, $data)->load($data, $machine);
    }

    /** Little-endian word from a byte array. */
    public static function word(array $bytes, int $offset): int
    {
        return $bytes[$offset] | ($bytes[$offset + 1] << 8);
    }
}
