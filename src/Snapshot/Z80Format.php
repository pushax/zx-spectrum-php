<?php

namespace ZX\Spectrum\Snapshot;

use ZX\Spectrum\Machine\Spectrum48;

/**
 * .z80 versions 1, 2 and 3: a register header, then the RAM (v1) or 16K memory pages (v2/v3), all
 * optionally compressed. https://worldofspectrum.org/faq/reference/z80format.htm
 */
final class Z80Format implements SnapshotFormat
{
    private const int HEADER = 30;
    private const int RAM_48K = 49152;
    private const int PAGE = 16384;
    /** Port 7FFD for a 48K program on a 128K machine: 48 BASIC ROM, bank 0, paging locked. */
    private const int LOCKED_48K = 0x30;

    public function extensions(): array
    {
        return ['z80'];
    }

    public function recognizes(string $data): bool
    {
        return false; // no signature
    }

    public function load(string $data, Spectrum48 $machine): void
    {
        if (strlen($data) < self::HEADER) {
            throw new \RuntimeException('Invalid .z80 snapshot.');
        }
        $h = array_values(unpack('C*', substr($data, 0, self::HEADER)));
        $pc = SnapshotLoader::word($h, 6);
        // Byte 12: bit 0 = R bit 7, bits 1-3 = border, bit 5 = compressed; 255 must be read as 1.
        $flags = $h[12] === 255 ? 1 : $h[12];

        if ($pc === 0) {
            $pc = $this->loadPages($data, $machine); // version 2 or 3: PC is in the additional header
        } else {
            $ram = substr($data, self::HEADER);
            if ($h[12] !== 255 && ($h[12] & 0x20) !== 0) {
                $ram = self::decompress($ram, self::RAM_48K);
            }
            if (strlen($ram) !== self::RAM_48K) {
                throw new \RuntimeException('Unexpected RAM size in .z80 v1 snapshot.');
            }
            if ($machine->memory->is128K()) {
                $machine->memory->page7ffd(self::LOCKED_48K);
            }
            $machine->memory->load(0x4000, $ram);
        }

        $cpu = $machine->cpu;
        [$cpu->a, $cpu->f, $cpu->c, $cpu->b, $cpu->l, $cpu->h] = [$h[0], $h[1], $h[2], $h[3], $h[4], $h[5]];
        $cpu->pc = $pc;
        $cpu->sp = SnapshotLoader::word($h, 8);
        $cpu->i = $h[10];
        $cpu->r = ($h[11] & 0x7F) | (($flags & 1) << 7);
        $machine->ula->border = ($flags >> 1) & 7;
        [$cpu->e, $cpu->d, $cpu->cAlt, $cpu->bAlt] = [$h[13], $h[14], $h[15], $h[16]];
        [$cpu->eAlt, $cpu->dAlt, $cpu->lAlt, $cpu->hAlt] = [$h[17], $h[18], $h[19], $h[20]];
        [$cpu->aAlt, $cpu->fAlt] = [$h[21], $h[22]];
        $cpu->iy = SnapshotLoader::word($h, 23);
        $cpu->ix = SnapshotLoader::word($h, 25);
        $cpu->iff1 = $h[27] !== 0;
        $cpu->iff2 = $h[28] !== 0;
        $cpu->im = $h[29] & 3;
    }

    /**
     * Versions 2/3: an additional header (23 bytes = v2, 54/55 = v3), then 16K memory pages.
     * Loads memory, paging and the AY state; returns PC.
     */
    private function loadPages(string $data, Spectrum48 $machine): int
    {
        $headerLength = ord($data[30]) | (ord($data[31]) << 8);
        if (!in_array($headerLength, [23, 54, 55], true)) {
            throw new \RuntimeException(sprintf('Unknown .z80 additional header length %d.', $headerLength));
        }
        $pc = ord($data[32]) | (ord($data[33]) << 8);
        $mode = ord($data[34]);
        // Hardware modes: v2 3/4 = 128K; v3 4/5/6 = 128K, 7/9/12/13 = +3, Pentagon, +2, +2A (all 128K-paged).
        $is128 = $headerLength === 23
            ? in_array($mode, [3, 4], true)
            : in_array($mode, [4, 5, 6, 7, 9, 12, 13], true);
        if (!$is128 && !in_array($mode, [0, 1, 3], true)) {
            throw new \RuntimeException(sprintf('Unsupported .z80 hardware mode %d.', $mode));
        }
        $memory = $machine->memory;
        if ($is128 && !$memory->is128K()) {
            throw new \RuntimeException('This is a 128K snapshot: run it with --machine=pentagon and a 32K ROM.');
        }
        if ($is128) {
            $memory->page7ffd(ord($data[35]));
            $machine->aySelected = ord($data[38]) & 15;
            for ($reg = 0; $reg < 16; $reg++) {
                $machine->ay?->write($reg, ord($data[39 + $reg]));
            }
        } elseif ($memory->is128K()) {
            $memory->page7ffd(self::LOCKED_48K);
        }

        $offset = self::HEADER + 2 + $headerLength;
        $end = strlen($data);
        while ($offset + 3 <= $end) {
            $length = ord($data[$offset]) | (ord($data[$offset + 1]) << 8);
            $page = ord($data[$offset + 2]);
            $offset += 3;
            if ($length === 0xFFFF) { // stored uncompressed
                $block = substr($data, $offset, self::PAGE);
                $offset += self::PAGE;
            } else {
                $block = self::decompress(substr($data, $offset, $length), self::PAGE);
                $offset += $length;
            }
            if (strlen($block) !== self::PAGE) {
                throw new \RuntimeException(sprintf('Truncated .z80 memory block for page %d.', $page));
            }
            if ($is128) {
                if ($page >= 3 && $page <= 10) {
                    $memory->loadBank($page - 3, $block); // pages 3-10 are RAM banks 0-7
                }
                continue;
            }
            $address = match ($page) {
                8 => 0x4000,
                4 => 0x8000,
                5 => 0xC000,
                default => null,
            };
            if ($address !== null) {
                $memory->load($address, $block);
            }
        }
        return $pc;
    }

    /** ED ED count byte = byte repeated count times (count 0 = 256). */
    private static function decompress(string $src, int $expected): string
    {
        $out = '';
        $length = strlen($src);
        for ($i = 0; $i < $length && strlen($out) < $expected;) {
            $value = ord($src[$i++]);
            if ($value === 0xED && $i < $length && ord($src[$i]) === 0xED) {
                $i++;
                if ($i + 1 >= $length) {
                    break;
                }
                $count = ord($src[$i++]) ?: 256;
                $out .= str_repeat($src[$i++], $count);
            } else {
                $out .= chr($value);
            }
        }
        return substr($out, 0, $expected);
    }
}
