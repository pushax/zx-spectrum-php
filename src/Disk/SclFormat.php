<?php

namespace ZX\Spectrum\Disk;

/**
 * .scl: a TR-DOS file archive ("SINCLAIR", file count, 14-byte catalogue entries, file data).
 * It is converted into a disk with the files one after another from track 1 and a TR-DOS catalogue.
 */
final class SclFormat implements DiskFormat
{
    private const string SIGNATURE = 'SINCLAIR';
    private const int ENTRY_SIZE = 14;
    private const int CATALOGUE_ENTRY_SIZE = 16;
    private const int TOTAL_SECTORS = 2560;

    public function extensions(): array
    {
        return ['scl'];
    }

    public function recognizes(string $data): bool
    {
        return str_starts_with($data, self::SIGNATURE);
    }

    public function toImage(string $data): DiskImage
    {
        return DiskImage::fromTrd(self::toTrd($data));
    }

    public static function toTrd(string $scl): string
    {
        if (!str_starts_with($scl, self::SIGNATURE) || strlen($scl) < 9) {
            throw new \RuntimeException('Not an SCL image.');
        }
        $sectorSize = DiskImage::SECTOR_SIZE;
        $perTrack = DiskImage::SECTORS_PER_TRACK;
        $count = ord($scl[8]);
        $trd = str_repeat("\0", DiskImage::SIZE);
        $dataOffset = 9 + $count * self::ENTRY_SIZE;
        $track = 1; // files start at logical track 1, sector 0
        $sector = 0;
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($scl, 9 + $i * self::ENTRY_SIZE, self::ENTRY_SIZE);
            if (strlen($entry) < self::ENTRY_SIZE) {
                throw new \RuntimeException('Truncated SCL image.');
            }
            $sectors = ord($entry[13]);
            $size = $sectors * $sectorSize;
            $fileData = substr($scl, $dataOffset, $size);
            $dataOffset += $size;
            $offset = ($track * $perTrack + $sector) * $sectorSize;
            if ($offset + $size > DiskImage::SIZE) {
                throw new \RuntimeException('SCL image does not fit on a disk.');
            }
            $trd = substr_replace($trd, str_pad($fileData, $size, "\0"), $offset, $size);
            // Catalogue entry: the 14 SCL bytes plus the first sector and track of the file.
            $trd = substr_replace(
                $trd,
                $entry . chr($sector) . chr($track),
                $i * self::CATALOGUE_ENTRY_SIZE,
                self::CATALOGUE_ENTRY_SIZE
            );
            $next = $track * $perTrack + $sector + $sectors;
            $track = intdiv($next, $perTrack);
            $sector = $next % $perTrack;
        }
        return substr_replace($trd, self::systemSector($track, $sector, $count), DiskImage::SYSTEM_SECTOR, $sectorSize);
    }

    /** TR-DOS system sector: first free sector, disk type, file count, free sectors, TR-DOS id, label. */
    private static function systemSector(int $freeTrack, int $freeSector, int $files): string
    {
        $free = self::TOTAL_SECTORS - ($freeTrack * DiskImage::SECTORS_PER_TRACK + $freeSector);
        $sector = str_repeat("\0", DiskImage::SECTOR_SIZE);
        $sector[0xE1] = chr($freeSector);
        $sector[0xE2] = chr($freeTrack);
        $sector[0xE3] = "\x16"; // 80 cylinders, double-sided
        $sector[0xE4] = chr($files);
        $sector[0xE5] = chr($free & 0xFF);
        $sector[0xE6] = chr($free >> 8);
        $sector[0xE7] = "\x10"; // TR-DOS id
        $sector = substr_replace($sector, str_repeat(' ', 9), 0xEA, 9);
        return substr_replace($sector, 'SCL     ', 0xF5, 8);
    }
}
