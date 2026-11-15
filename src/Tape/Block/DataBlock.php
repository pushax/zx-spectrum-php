<?php

namespace ZX\Spectrum\Tape\Block;

/**
 * Data with pilot tone and sync pulses: standard ROM timing (TAP, TZX 10), turbo timing (TZX 11) or
 * pure data without pilot (TZX 14).
 */
final readonly class DataBlock implements TapeBlock
{
    public const int PILOT = 2168;
    public const int SYNC1 = 667;
    public const int SYNC2 = 735;
    public const int ZERO = 855;
    public const int ONE = 1710;
    public const int HEADER_PILOTS = 8063;
    public const int DATA_PILOTS = 3223;

    public function __construct(
        public string $data,
        public int $pilot,
        public int $pilotCount,
        public int $sync1,
        public int $sync2,
        public int $zero,
        public int $one,
        /** Bits used in the last byte. */
        public int $lastByteBits,
        public int $pauseMs,
        /** Recorded with the ROM's own timing. */
        public bool $standard,
    ) {
    }

    /** A block saved by the ROM: header blocks (flag < 128) have a longer pilot tone. */
    public static function standard(string $data, int $pauseMs): self
    {
        $flag = $data === '' ? 0 : ord($data[0]);
        return new self(
            data: $data,
            pilot: self::PILOT,
            pilotCount: $flag < 128 ? self::HEADER_PILOTS : self::DATA_PILOTS,
            sync1: self::SYNC1,
            sync2: self::SYNC2,
            zero: self::ZERO,
            one: self::ONE,
            lastByteBits: 8,
            pauseMs: $pauseMs,
            standard: true,
        );
    }

    /** True when the ROM loader can read it: standard timing, or turbo timing within its tolerance. */
    public function romReadable(): bool
    {
        if ($this->standard) {
            return true;
        }
        return $this->lastByteBits === 8 && $this->pilotCount > 0
            && abs($this->zero - self::ZERO) <= self::ZERO / 4
            && abs($this->one - self::ONE) <= self::ONE / 4;
    }

    public function pulses(int $level): array
    {
        $out = [];
        if ($this->pilotCount > 0) {
            array_push($out, ...array_fill(0, $this->pilotCount, $this->pilot));
        }
        if ($this->sync1 > 0) {
            $out[] = $this->sync1;
        }
        if ($this->sync2 > 0) {
            $out[] = $this->sync2;
        }
        // Every bit is two equal pulses.
        $zero = [$this->zero, $this->zero];
        $one = [$this->one, $this->one];
        $length = strlen($this->data);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($this->data[$i]);
            $bits = $i === $length - 1 ? $this->lastByteBits : 8;
            for ($bit = 7; $bit > 7 - $bits; $bit--) {
                array_push($out, ...((($byte >> $bit) & 1) ? $one : $zero));
            }
        }
        if ($this->pauseMs > 0) {
            $out[] = -$this->pauseMs * self::T_STATES_PER_MS;
        }
        return $out;
    }
}
