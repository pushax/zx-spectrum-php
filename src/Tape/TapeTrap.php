<?php

namespace ZX\Spectrum\Tape;

use ZX\Spectrum\Cpu\Z80;
use ZX\Spectrum\Machine\Memory;

/**
 * Instant tape loading through the ROM routine LD-BYTES (address 0556).
 *
 * On entry the ROM expects A = flag byte, IX = address, DE = length and carry set for LOAD (reset for
 * VERIFY). The trap does the whole job at once; the machine then returns to the caller the way the
 * ROM's SA/LD-RET (053F) would: border restored, interrupts enabled, but without its BREAK test. A real
 * load takes seconds, so the key that started it (often SPACE, which is also BREAK) has long been
 * released; after an instant load it is usually still held.
 */
final class TapeTrap
{
    public const int LD_BYTES = 0x0556;
    /** System variable BORDCR: border colour in bits 3-5. */
    private const int BORDCR = 0x5C48;

    /** True when the 48 BASIC ROM routine is really there (and not, for example, TR-DOS). */
    public static function romHasLoader(Memory $memory): bool
    {
        return !$memory->trdosActive
            && $memory->basic48Selected()
            && $memory->read(self::LD_BYTES) === 0x14       // INC D
            && $memory->read(self::LD_BYTES + 1) === 0x08;  // EX AF,AF'
    }

    /** The border colour the ROM restores after loading. */
    public static function borderColour(Memory $memory): int
    {
        return ($memory->read(self::BORDCR) & 0x38) >> 3;
    }

    /** Loads (or verifies) one tape block (flag byte, data, parity byte) as LD-BYTES would; carry = success. */
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
    }
}
