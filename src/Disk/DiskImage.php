<?php

namespace ZX\Spectrum\Disk;

/**
 * A TR-DOS disk in TRD layout: 80 cylinders, 16 sectors of 256 bytes per track; on double-sided disks
 * the tracks of both sides alternate (cylinder * 2 + side). Changes stay in memory.
 */
final class DiskImage
{
    public const int SECTOR_SIZE = 256;
    public const int SECTORS_PER_TRACK = 16;
    public const int TRACK_SIZE = self::SECTOR_SIZE * self::SECTORS_PER_TRACK;
    /** 80 cylinders × 2 sides. */
    public const int SIZE = 160 * self::TRACK_SIZE;
    /** The TR-DOS system sector: track 0, sector 9. */
    public const int SYSTEM_SECTOR = 8 * self::SECTOR_SIZE;

    private function __construct(private string $bytes, public readonly bool $doubleSided)
    {
    }

    /** A TRD image; truncated images (unused tracks cut off) are padded to a full disk. */
    public static function fromTrd(string $trd): self
    {
        if (strlen($trd) > self::SIZE || strlen($trd) < self::SYSTEM_SECTOR + self::SECTOR_SIZE) {
            throw new \RuntimeException('Not a valid TRD image.');
        }
        // Disk type in the system sector: 16h/17h double-sided, 18h/19h single-sided.
        $type = ord($trd[self::SYSTEM_SECTOR + 0xE3]);
        return new self(str_pad($trd, self::SIZE, "\0"), !($type === 0x18 || $type === 0x19));
    }

    /** Image offset of a physical track, null when the disk has no such track. */
    public function trackOffset(int $cylinder, int $side): ?int
    {
        if ($this->doubleSided) {
            $track = $cylinder * 2 + $side;
        } elseif ($side === 0) {
            $track = $cylinder;
        } else {
            return null;
        }
        $offset = $track * self::TRACK_SIZE;
        return $offset < self::SIZE ? $offset : null;
    }

    public function read(int $offset, int $length): string
    {
        return substr($this->bytes, $offset, $length);
    }

    public function write(int $offset, string $bytes): void
    {
        $this->bytes = substr_replace($this->bytes, $bytes, $offset, strlen($bytes));
    }
}
