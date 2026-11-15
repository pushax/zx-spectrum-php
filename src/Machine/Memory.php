<?php

namespace ZX\Spectrum\Machine;

use ZX\Spectrum\Cpu\MemoryInterface;

/**
 * Spectrum memory map. 48K: 16K ROM + 48K RAM. 128K/Pentagon: two ROMs and eight 16K RAM banks paged
 * through port 7FFD (0000 ROM 0/1, 4000 bank 5, 8000 bank 2, C000 any bank), screen in bank 5 or 7.
 * The Beta 128 TR-DOS ROM can be paged over 0000-3FFF.
 */
final class Memory implements MemoryInterface
{
    private const int ROM_SIZE = 16384;
    private const int BANK_SIZE = 16384;
    private const int SCREEN_SIZE = 6912;

    /** Called before a write to the displayed screen (bitmap or attributes), so the beam can catch up first. */
    public ?\Closure $onScreenWrite = null;
    /** The TR-DOS ROM is paged in (see Spectrum48::pageTrdos()). */
    public bool $trdosActive = false;

    /** @var array<int,int> 48K: the whole address space (the ROM part is unused) */
    private array $ram;
    /** @var array<int,int> the ROM used by the 48K memory map (= ROM 0) */
    private array $rom;
    /** @var array<int,array<int,int>> ROM 0 (128 menu) and ROM 1 (48 BASIC) */
    private array $romBanks = [];
    /** @var array<int,array<int,int>> 128K RAM banks 0-7 */
    private array $banks = [];
    /** @var array<int,int> Beta 128 TR-DOS ROM */
    private array $trdosRom = [];
    private bool $banked128 = false;
    private int $romSelect = 0;
    private int $pagedBank = 0;
    private int $screenBank = 5;
    private bool $pagingLocked = false;
    /** True when both ROM banks are the same 16K image (a 48K ROM on a 128K machine). */
    private bool $singleRom = false;

    public function __construct(?string $romFile = null, bool $banked128 = false)
    {
        $this->banked128 = $banked128;
        if ($banked128) {
            for ($bank = 0; $bank < 8; $bank++) {
                $this->banks[$bank] = array_fill(0, self::BANK_SIZE, 0);
            }
        }
        $this->ram = array_fill(0, 0x10000, 0);
        $romBytes = $romFile !== null ? @file_get_contents($romFile) : false;
        if ($romBytes === false) {
            $this->rom = array_fill(0, self::ROM_SIZE, 0xFF); // no ROM: the bus reads FF
            $this->romBanks = [0 => $this->rom, 1 => $this->rom];
        } else {
            if (strlen($romBytes) !== self::ROM_SIZE && strlen($romBytes) !== 2 * self::ROM_SIZE) {
                throw new \RuntimeException('ROM must be 16384 bytes (48K) or 32768 bytes (128K).');
            }
            $all = array_values(unpack('C*', $romBytes));
            $this->rom = array_slice($all, 0, self::ROM_SIZE);
            $this->romBanks = [$this->rom, array_slice($all, self::ROM_SIZE, self::ROM_SIZE)];
            if (count($this->romBanks[1]) !== self::ROM_SIZE) {
                $this->romBanks[1] = $this->romBanks[0]; // a 16K ROM serves as both ROMs
            }
        }
        $this->singleRom = $this->romBanks[0] === $this->romBanks[1];
    }

    public function read(int $address): int
    {
        $address &= 0xFFFF;
        if ($this->trdosActive && $address < 0x4000) {
            return $this->trdosRom[$address];
        }
        if (!$this->banked128) {
            return $address < 0x4000 ? $this->rom[$address] : $this->ram[$address];
        }
        if ($address < 0x4000) {
            return $this->romBanks[$this->romSelect][$address];
        }
        $bank = $this->bankAt($address);
        return $this->banks[$bank][$address & 0x3FFF];
    }

    public function write(int $address, int $value): void
    {
        $address &= 0xFFFF;
        $value &= 0xFF;
        if ($this->banked128) {
            if ($address < 0x4000) {
                return;
            }
            $slot = $address >> 14;
            $bank = match ($slot) {
                1 => 5,2 => 2,default => $this->pagedBank
            };
            $isScreen = $bank === $this->screenBank && ($address & 0x3FFF) < self::SCREEN_SIZE;
            if ($isScreen && $this->onScreenWrite !== null) {
                ($this->onScreenWrite)();
            }
            $this->banks[$bank][$address & 0x3FFF] = $value;
            return;
        }
        if ($address >= 0x4000 && $address < 0x5B00 && $this->onScreenWrite !== null) {
            ($this->onScreenWrite)();
        }
        if ($address >= 0x4000) {
            $this->ram[$address] = $value;
        }
    }

    /** RAM bank mapped at a 4000-FFFF address. */
    private function bankAt(int $address): int
    {
        return match ($address >> 14) {
            1 => 5,
            2 => 2,
            default => $this->pagedBank,
        };
    }

    public function is128K(): bool
    {
        return $this->banked128;
    }

    public function page7ffd(int $value): void
    {
        if (!$this->banked128 || $this->pagingLocked) {
            return;
        }
        $value &= 0xFF;
        $this->romSelect = ($value >> 4) & 1;
        $this->pagedBank = $value & 7;
        $this->screenBank = ($value & 8) ? 7 : 5;
        if (($value & 0x20) !== 0) {
            $this->pagingLocked = true;
        }
    }

    public function pagingValue(): int
    {
        return $this->pagedBank | ($this->screenBank === 7 ? 8 : 0) | ($this->pagingLocked ? 0x20 : 0);
    }

    /** @return array<int,int> */
    public function ramBank(int $bank): array
    {
        if ($bank < 0 || $bank > 7) {
            throw new \OutOfRangeException("No RAM bank $bank.");
        }
        return $this->banks[$bank];
    }

    public function loadBank(int $bank, string $bytes): void
    {
        if (!$this->banked128 || $bank < 0 || $bank > 7 || strlen($bytes) !== self::BANK_SIZE) {
            throw new \InvalidArgumentException('Expected a 16K RAM bank.');
        }
        $this->banks[$bank] = array_values(unpack('C*', $bytes));
    }

    public function screenBank(): int
    {
        return $this->screenBank;
    }

    /** One byte of the displayed screen (0-6143 bitmap, 6144-6911 attributes). */
    public function screenByte(int $offset): int
    {
        return $this->banked128 ? $this->banks[$this->screenBank][$offset] : $this->ram[0x4000 + $offset];
    }

    public function readWord(int $address): int
    {
        return $this->read($address) | ($this->read($address + 1) << 8);
    }

    public function writeWord(int $address, int $value): void
    {
        $this->write($address, $value & 0xFF);
        $this->write($address + 1, ($value >> 8) & 0xFF);
    }

    public function load(int $address, string $bytes): void
    {
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $this->write($address + $i, ord($bytes[$i]));
        }
    }

    /** @return array<int,int> */
    public function screenBytes(): array
    {
        if ($this->banked128) {
            return array_slice($this->banks[$this->screenBank], 0, self::SCREEN_SIZE);
        }
        $result = [];
        for ($i = 0x4000; $i < 0x5B00; $i++) {
            $result[] = $this->ram[$i];
        }
        return $result;
    }

    public function loadTrdosRom(string $romFile): void
    {
        $bytes = @file_get_contents($romFile);
        if ($bytes === false || strlen($bytes) !== self::ROM_SIZE) {
            throw new \RuntimeException('The TR-DOS ROM must be a 16384-byte file.');
        }
        $this->trdosRom = array_values(unpack('C*', $bytes));
    }

    /** The Beta interface pages TR-DOS in only while the 48 BASIC ROM is selected. */
    public function basic48Selected(): bool
    {
        return !$this->banked128 || $this->singleRom || $this->romSelect === 1;
    }
}
