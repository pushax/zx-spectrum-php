<?php

namespace ZX\Spectrum\Tape;

use ZX\Spectrum\Cpu\Z80;
use ZX\Spectrum\Machine\Memory;

/**
 * Instant tape loading through the ROM routine LD-BYTES (address 0556).
 *
 * On entry the ROM expects A = flag byte, IX = address, DE = length and carry set for LOAD (reset for
 * VERIFY). The trap does the whole job at once and leaves through SA/LD-RET (053F), which restores the
 * border and enables interrupts, exactly as the ROM would.
 */
final class TapeTrap
{
    public const int LD_BYTES = 0x0556;
    private const int SA_LD_RET = 0x053F;

    /** True when the 48 BASIC ROM routine is really there (and not, for example, TR-DOS). */
    public static function romHasLoader(Memory $memory): bool
    {
        return !$memory->trdosActive
            && $memory->basic48Selected()
            && $memory->read(self::LD_BYTES) === 0x14       // INC D
            && $memory->read(self::LD_BYTES + 1) === 0x08;  // EX AF,AF'
    }

    /** Loads (or verifies) one tape block (flag byte, data, parity byte) as LD-BYTES would. */
    public static function load(string $block, Z80 $cpu, Memory $memory): void
    {
        $ok = false;
        $length = strlen($block);
        $requested = ($cpu->d << 8) | $cpu->e;
        if ($length > 0 && ord($block[0]) === $cpu->a) {
            $load = ($cpu->f & 1) !== 0;
            $count = min($requested, $length - 1);
            $parity = ord($block[0]);
            $ok = true;
            for ($i = 0; $i < $count; $i++) {
                $byte = ord($block[$i + 1]);
                $parity ^= $byte;
                $address = ($cpu->ix + $i) & 0xFFFF;
                if ($load) {
                    $memory->write($address, $byte);
                } elseif ($memory->read($address) !== $byte) {
                    $ok = false;
                }
            }
            $requested -= $count;
            if ($requested > 0 || $count + 1 >= $length) {
                $ok = false; // the block is shorter than requested
            } elseif ((($parity ^ ord($block[$count + 1])) & 0xFF) !== 0) {
                $ok = false; // parity error
            }
            $cpu->ix = ($cpu->ix + $count) & 0xFFFF;
            $cpu->d = ($requested >> 8) & 0xFF;
            $cpu->e = $requested & 0xFF;
        }
        $cpu->f = $ok ? ($cpu->f | 1) : ($cpu->f & 0xFE);
        $cpu->pc = self::SA_LD_RET;
    }
}
