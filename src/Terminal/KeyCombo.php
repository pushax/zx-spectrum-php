<?php

namespace ZX\Spectrum\Terminal;

use ZX\Spectrum\Machine\ULA;

/** A Spectrum key, optionally together with a shift key (CAPS SHIFT or SYMBOL SHIFT). */
final readonly class KeyCombo
{
    public const array CAPS_SHIFT = [0, 0];
    public const array SYMBOL_SHIFT = [7, 1];
    private const array ENTER = [6, 0];

    public function __construct(
        public int $row,
        public int $bit,
        public ?int $shiftRow = null,
        public ?int $shiftBit = null,
    ) {
    }

    /** @param array{int,int} $key @param array{int,int}|null $shift */
    public static function of(array $key, ?array $shift = null): self
    {
        return new self($key[0], $key[1], $shift[0] ?? null, $shift[1] ?? null);
    }

    public function equals(self $other): bool
    {
        // Strict comparison: with == a missing shift (null) would equal CAPS SHIFT (row 0, bit 0).
        return $this->row === $other->row && $this->bit === $other->bit
            && $this->shiftRow === $other->shiftRow && $this->shiftBit === $other->shiftBit;
    }

    /** The same physical key, with or without a shift. */
    public function sameKeyAs(self $other): bool
    {
        return $this->row === $other->row && $this->bit === $other->bit;
    }

    public function isEnter(): bool
    {
        return [$this->row, $this->bit] === self::ENTER;
    }

    public function press(ULA $ula): void
    {
        if ($this->shiftRow !== null) {
            $ula->setKey($this->shiftRow, $this->shiftBit, true);
        }
        $ula->setKey($this->row, $this->bit, true);
    }

    public function release(ULA $ula): void
    {
        $ula->setKey($this->row, $this->bit, false);
        if ($this->shiftRow !== null) {
            $ula->setKey($this->shiftRow, $this->shiftBit, false);
        }
    }
}
