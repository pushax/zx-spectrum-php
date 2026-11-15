<?php

namespace ZX\Spectrum\Snapshot;

use ZX\Spectrum\Machine\Spectrum48;

/**
 * .sna: a 27-byte register header and the 48K RAM; the 128K variant adds PC, port 7FFD and the
 * other RAM banks. https://worldofspectrum.org/faq/reference/formats.htm
 */
final class SnaFormat implements SnapshotFormat
{
    private const int SIZE_48K = 49179;
    private const int SIZE_128K = 131103;
    private const int SIZE_128K_TRDOS = 147487;
    private const int HEADER = 27;
    private const int RAM_48K = 49152;
    private const int BANK = 16384;
    /** Port 7FFD for a 48K program on a 128K machine: 48 BASIC ROM, bank 0, paging locked. */
    private const int LOCKED_48K = 0x30;

    public function extensions(): array
    {
        return ['sna'];
    }

    public function recognizes(string $data): bool
    {
        return false; // no signature
    }

    public function load(string $data, Spectrum48 $machine): void
    {
        $size = strlen($data);
        if (!in_array($size, [self::SIZE_48K, self::SIZE_128K, self::SIZE_128K_TRDOS], true)) {
            throw new \RuntimeException('.sna must be 49179 bytes (48K) or 131103/147487 bytes (128K).');
        }
        $is128 = $size !== self::SIZE_48K;
        $memory = $machine->memory;
        if ($is128 && !$memory->is128K()) {
            throw new \RuntimeException('This is a 128K snapshot: run it with --machine=pentagon and a 32K ROM.');
        }

        if ($is128) {
            // Banks 5, 2 and the paged bank n (like the 48K dump), then PC, 7FFD, TR-DOS flag,
            // then the remaining banks in ascending order.
            $port7ffd = ord($data[self::SIZE_48K + 2]);
            $memory->page7ffd($port7ffd); // before loading, so that C000 goes to bank n
            $memory->load(0x4000, substr($data, self::HEADER, self::RAM_48K));
            $paged = $port7ffd & 7;
            $offset = self::SIZE_48K + 4;
            for ($bank = 0; $bank < 8; $bank++) {
                if ($bank === 5 || $bank === 2 || $bank === $paged) {
                    continue;
                }
                $memory->loadBank($bank, substr($data, $offset, self::BANK));
                $offset += self::BANK;
            }
        } else {
            if ($memory->is128K()) {
                $memory->page7ffd(self::LOCKED_48K);
            }
            $memory->load(0x4000, substr($data, self::HEADER));
        }

        // Header: I, HL', DE', BC', AF', HL, DE, BC, IY, IX, IFF2, R, AF, SP, IM, border.
        $h = array_values(unpack('C*', substr($data, 0, self::HEADER)));
        $cpu = $machine->cpu;
        $cpu->i = $h[0];
        [$cpu->lAlt, $cpu->hAlt, $cpu->eAlt, $cpu->dAlt] = [$h[1], $h[2], $h[3], $h[4]];
        [$cpu->cAlt, $cpu->bAlt, $cpu->fAlt, $cpu->aAlt] = [$h[5], $h[6], $h[7], $h[8]];
        [$cpu->l, $cpu->h, $cpu->e, $cpu->d, $cpu->c, $cpu->b] = [$h[9], $h[10], $h[11], $h[12], $h[13], $h[14]];
        $cpu->iy = SnapshotLoader::word($h, 15);
        $cpu->ix = SnapshotLoader::word($h, 17);
        $cpu->iff2 = ($h[19] & 4) !== 0;
        $cpu->iff1 = $cpu->iff2;
        $cpu->r = $h[20];
        $cpu->f = $h[21];
        $cpu->a = $h[22];
        $cpu->sp = SnapshotLoader::word($h, 23);
        $cpu->im = $h[25] & 3;
        $machine->ula->border = $h[26] & 7;

        if ($is128) {
            // The 128K format stores PC in the file.
            $cpu->pc = ord($data[self::SIZE_48K]) | (ord($data[self::SIZE_48K + 1]) << 8);
        } else {
            // The 48K format keeps PC on the stack, as if an interrupt had been taken (a RETN follows).
            $cpu->pc = $memory->readWord($cpu->sp);
            $cpu->sp = ($cpu->sp + 2) & 0xFFFF;
        }
    }
}
