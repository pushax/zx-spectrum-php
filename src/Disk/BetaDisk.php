<?php

namespace ZX\Spectrum\Disk;

use ZX\Spectrum\Media\FormatRegistry;

/**
 * Beta 128 disk interface: WD1793 floppy controller (ports 1F command/status, 3F track, 5F sector,
 * 7F data) plus the Beta system register (port FF: drive, side, reset; reads INTRQ and DRQ).
 *
 * Commands complete instantly and data bytes are always ready (DRQ), which is what TR-DOS and disk
 * loaders need. The index pulse is simulated from the CPU clock (5 revolutions per second) because
 * TR-DOS uses it to detect a disk.
 */
final class BetaDisk
{
    // Status register bits
    private const int BUSY = 0x01;
    private const int INDEX_OR_DRQ = 0x02; // type I: index pulse, type II/III: data request
    private const int TRACK0_OR_LOST_DATA = 0x04;
    private const int NOT_FOUND = 0x10;     // seek error / record not found
    private const int HEAD_LOADED = 0x20;
    private const int NOT_READY = 0x80;

    private const int MAX_CYLINDER = 85;
    private const int RAW_TRACK_SIZE = 6250;

    /** @var array<int,?DiskImage> disks in drives A-D */
    private array $disks = [null, null, null, null];
    /** @var array<int,int> head position per drive */
    private array $headCylinder = [0, 0, 0, 0];

    private int $status = 0;
    private int $track = 0;
    private int $sector = 1;
    private int $data = 0;
    private int $drive = 0;
    private int $side = 0;
    private bool $intrq = false;
    private bool $drq = false;
    /** The last command was a type I command (restore/seek/step): the status shows index and track 0. */
    private bool $typeOne = true;
    private int $stepDirection = 1;

    private Transfer $transfer = Transfer::None;
    private string $buffer = '';
    private int $bufferPosition = 0;
    private int $writeOffset = 0;
    private bool $multipleSectors = false;
    /** Sector number returned by the next READ ADDRESS (the disk keeps turning). */
    private int $addressSector = 0;
    /** CPU T-state of the last command or data byte, for the lost-data timeout. */
    private int $lastActivity = 0;
    private int $now = 0;

    public function __construct(private readonly int $clockHz = 3500000)
    {
    }

    /** The supported disk image formats. */
    public static function formats(): FormatRegistry
    {
        return new FormatRegistry('disk image', [new SclFormat(), new TrdFormat()]);
    }

    /** Inserts a .trd or .scl image into drive 0-3. The file itself is never modified. */
    public function insert(string $path, int $drive = 0): void
    {
        $formats = self::formats();
        $data = $formats->read($path);
        $this->disks[$drive] = $formats->select($path, $data)->toImage($data);
    }

    /** True when the port belongs to the interface (decoded only while the TR-DOS ROM is paged in). */
    public static function ownsPort(int $port): bool
    {
        return in_array($port & 0xFF, [0x1F, 0x3F, 0x5F, 0x7F, 0xFF], true);
    }

    public function read(int $port, int $tstates): int
    {
        $this->clock($tstates);
        return match ($port & 0xFF) {
            0x1F => $this->readStatus($tstates),
            0x3F => $this->track,
            0x5F => $this->sector,
            0x7F => $this->readData(),
            default => ($this->intrq ? 0x80 : 0) | ($this->drq ? 0x40 : 0) | 0x3F,
        };
    }

    public function write(int $port, int $value, int $tstates): void
    {
        $this->clock($tstates);
        match ($port & 0xFF) {
            0x1F => $this->command($value),
            0x3F => $this->track = $value,
            0x5F => $this->sector = $value,
            0x7F => $this->writeData($value),
            default => $this->writeSystemRegister($value),
        };
    }

    private function readStatus(int $tstates): int
    {
        $this->intrq = false;
        $status = $this->status;
        $disk = $this->disks[$this->drive];
        if ($this->typeOne) {
            $status &= ~self::INDEX_OR_DRQ;
            if ($disk !== null && $this->indexPulse($tstates)) {
                $status |= self::INDEX_OR_DRQ;
            }
            if ($this->headCylinder[$this->drive] === 0) {
                $status |= self::TRACK0_OR_LOST_DATA;
            }
        }
        if ($disk === null) {
            $status |= self::NOT_READY;
        }
        return $status;
    }

    /** Bits 0-1 drive, bit 2 = 0 resets the controller, bit 4 = 0 selects the upper side. */
    private function writeSystemRegister(int $value): void
    {
        $this->drive = $value & 3;
        $this->side = ($value & 0x10) ? 0 : 1;
        if (($value & 0x04) === 0) {
            $this->reset();
        }
    }

    private function reset(): void
    {
        $this->status = 0;
        $this->transfer = Transfer::None;
        $this->drq = false;
        $this->intrq = false;
        $this->typeOne = true;
        $this->sector = 1;
        $this->track = 0;
    }

    /**
     * A transfer the CPU does not service within one disk revolution ends with "lost data", as on the
     * real WD1793 (TR-DOS relies on this: it sometimes issues a write-track command and never feeds it).
     */
    private function clock(int $tstates): void
    {
        $this->now = $tstates;
        if ($this->transfer !== Transfer::None && $tstates - $this->lastActivity > $this->revolution()) {
            $this->finish();
            $this->status |= self::TRACK0_OR_LOST_DATA;
        }
    }

    /** T-states per disk revolution (300 rpm). */
    private function revolution(): int
    {
        return intdiv($this->clockHz, 5);
    }

    /** The index hole passes the sensor once per revolution, for about 4 ms. */
    private function indexPulse(int $tstates): bool
    {
        return ($tstates % $this->revolution()) < intdiv($this->revolution(), 50);
    }

    private function command(int $command): void
    {
        if (($command & 0xF0) === 0xD0) {
            $this->forceInterrupt($command);
            return;
        }
        // No "busy" check: a transfer the CPU abandoned would already have ended on a real drive (lost data),
        // even if clock() has not noticed yet, so a new command is always accepted.
        $this->lastActivity = $this->now;
        $this->intrq = false;
        $this->drq = false;
        $this->transfer = Transfer::None;
        if (($command & 0x80) === 0) {
            $this->typeOneCommand($command);
            return;
        }
        $this->typeOne = false;
        if ($this->disks[$this->drive] === null) {
            $this->status = self::NOT_READY;
            $this->intrq = true;
            return;
        }
        match ($command >> 4) {
            0x8, 0x9 => $this->readSectors(($command & 0x10) !== 0),
            0xA, 0xB => $this->writeSectors(($command & 0x10) !== 0),
            0xC => $this->readAddress(),
            0xE => $this->readTrack(),
            default => $this->writeTrack(),
        };
    }

    private function forceInterrupt(int $command): void
    {
        $this->transfer = Transfer::None;
        $this->drq = false;
        $this->status &= ~self::BUSY;
        $this->typeOne = true;
        $this->intrq = ($command & 0x0F) !== 0;
    }

    /** RESTORE, SEEK, STEP, STEP IN, STEP OUT; bit 4 updates the track register, bit 2 verifies. */
    private function typeOneCommand(int $command): void
    {
        $this->typeOne = true;
        $updateTrack = ($command & 0x10) !== 0;
        $cylinder = $this->headCylinder[$this->drive];
        switch ($command >> 5) {
            case 0:
                if (!$updateTrack) { // RESTORE
                    $this->track = 0;
                    $cylinder = 0;
                } else { // SEEK to the track in the data register
                    $cylinder = max(0, min(self::MAX_CYLINDER, $cylinder + $this->data - $this->track));
                    $this->track = $this->data;
                }
                break;
            case 1: // STEP in the last direction
                $cylinder += $this->stepDirection;
                if ($updateTrack) {
                    $this->track = ($this->track + $this->stepDirection) & 0xFF;
                }
                break;
            case 2: // STEP IN
                $this->stepDirection = 1;
                $cylinder++;
                if ($updateTrack) {
                    $this->track = ($this->track + 1) & 0xFF;
                }
                break;
            default: // STEP OUT
                $this->stepDirection = -1;
                $cylinder--;
                if ($updateTrack) {
                    $this->track = ($this->track - 1) & 0xFF;
                }
        }
        $this->headCylinder[$this->drive] = max(0, min(self::MAX_CYLINDER, $cylinder));
        $this->status = self::HEAD_LOADED;
        $verify = ($command & 0x04) !== 0;
        if ($verify && $this->disks[$this->drive] !== null && $this->track !== $this->headCylinder[$this->drive]) {
            $this->status |= self::NOT_FOUND;
        }
        $this->intrq = true;
    }

    private function readSectors(bool $multiple): void
    {
        $this->multipleSectors = $multiple;
        $this->startSectorRead();
    }

    private function startSectorRead(): void
    {
        $offset = $this->sectorOffset($this->sector);
        if ($offset === null) {
            $this->status = self::NOT_FOUND;
            $this->intrq = true;
            $this->drq = false;
            $this->transfer = Transfer::None;
            return;
        }
        $this->startTransfer(Transfer::Read, $this->disks[$this->drive]->read($offset, DiskImage::SECTOR_SIZE));
    }

    private function writeSectors(bool $multiple): void
    {
        $this->multipleSectors = $multiple;
        $offset = $this->sectorOffset($this->sector);
        if ($offset === null) {
            $this->status = self::NOT_FOUND;
            $this->intrq = true;
            return;
        }
        $this->writeOffset = $offset;
        $this->startTransfer(Transfer::WriteSector, '');
    }

    /** READ ADDRESS: the ID field of the next sector under the head (track, side, sector, size, CRC). */
    private function readAddress(): void
    {
        $cylinder = $this->headCylinder[$this->drive];
        $this->addressSector = $this->addressSector % DiskImage::SECTORS_PER_TRACK + 1;
        $this->sector = $cylinder; // the WD1793 copies the track number into the sector register
        $this->multipleSectors = false;
        $this->startTransfer(
            Transfer::Read,
            chr($cylinder) . chr($this->side) . chr($this->addressSector) . "\x01\x00\x00"
        );
    }

    /** READ TRACK: the sector data of the track (gaps and ID fields are not modelled). */
    private function readTrack(): void
    {
        $offset = $this->currentTrackOffset();
        $this->multipleSectors = false;
        $this->startTransfer(
            Transfer::Read,
            $offset === null
                ? str_repeat("\x4E", self::RAW_TRACK_SIZE)
                : $this->disks[$this->drive]->read($offset, DiskImage::TRACK_SIZE)
        );
    }

    /** WRITE TRACK (format): the raw track bytes are accepted and the track is cleared. */
    private function writeTrack(): void
    {
        $this->startTransfer(Transfer::WriteTrack, '');
    }

    private function startTransfer(Transfer $transfer, string $buffer): void
    {
        $this->transfer = $transfer;
        $this->buffer = $buffer;
        $this->bufferPosition = 0;
        $this->drq = true;
        $this->status = self::BUSY | self::INDEX_OR_DRQ;
    }

    private function readData(): int
    {
        if ($this->transfer !== Transfer::Read) {
            return $this->data;
        }
        $this->lastActivity = $this->now;
        $this->data = ord($this->buffer[$this->bufferPosition++]);
        if ($this->bufferPosition >= strlen($this->buffer)) {
            if ($this->multipleSectors && $this->sector < DiskImage::SECTORS_PER_TRACK) {
                $this->sector++;
                $this->startSectorRead();
            } else {
                $this->finish();
            }
        }
        return $this->data;
    }

    private function writeData(int $value): void
    {
        $this->data = $value;
        $this->lastActivity = $this->now;
        if ($this->transfer === Transfer::WriteSector) {
            $this->buffer .= chr($value);
            if (strlen($this->buffer) >= DiskImage::SECTOR_SIZE) {
                $this->disks[$this->drive]->write($this->writeOffset, $this->buffer);
                $next = $this->multipleSectors && $this->sector < DiskImage::SECTORS_PER_TRACK
                    ? $this->sectorOffset($this->sector + 1)
                    : null;
                if ($next !== null) {
                    $this->sector++;
                    $this->writeOffset = $next;
                    $this->buffer = '';
                } else {
                    $this->finish();
                }
            }
        } elseif ($this->transfer === Transfer::WriteTrack) {
            $this->buffer .= chr($value);
            if (strlen($this->buffer) >= self::RAW_TRACK_SIZE) {
                $offset = $this->currentTrackOffset();
                if ($offset !== null) {
                    $this->disks[$this->drive]->write($offset, str_repeat("\0", DiskImage::TRACK_SIZE));
                }
                $this->finish();
            }
        }
    }

    private function finish(): void
    {
        $this->transfer = Transfer::None;
        $this->drq = false;
        $this->status &= ~(self::BUSY | self::INDEX_OR_DRQ);
        $this->intrq = true;
    }

    private function currentTrackOffset(): ?int
    {
        return $this->disks[$this->drive]?->trackOffset($this->headCylinder[$this->drive], $this->side);
    }

    /** Image offset of sector 1-16 on the current track; the ID's track must match the track register. */
    private function sectorOffset(int $sector): ?int
    {
        $onTrack = $this->track === $this->headCylinder[$this->drive];
        if ($sector < 1 || $sector > DiskImage::SECTORS_PER_TRACK || !$onTrack) {
            return null;
        }
        $base = $this->currentTrackOffset();
        return $base === null ? null : $base + ($sector - 1) * DiskImage::SECTOR_SIZE;
    }
}
