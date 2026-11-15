<?php

namespace ZX\Spectrum\Cpu;

/**
 * Zilog Z80 CPU.
 *
 * Implements every documented and undocumented instruction (including IXH/IXL/IYH/IYL forms, SLL,
 * DDCB/FDCB register copies and the undocumented X/Y flags), interrupt modes 0-2, HALT and the
 * one-instruction interrupt delay after EI. Each step() returns the T-states the instruction took.
 * Memory contention is not modelled.
 */
final class Z80
{
    private const int S = 0x80;
    private const int Z = 0x40;
    private const int Y = 0x20;
    private const int H = 0x10;
    private const int X = 0x08;
    private const int PV = 0x04;
    private const int N = 0x02;
    private const int C = 0x01;

    /** Register indexes used by the opcode encoding: 0 B, 1 C, 2 D, 3 E, 4 H, 5 L, 6 (HL), 7 A. */
    private const int REG_H = 4;
    private const int REG_L = 5;
    private const int REG_HL_INDIRECT = 6;

    public int $a = 0;
    public int $f = 0;
    public int $b = 0;
    public int $c = 0;
    public int $d = 0;
    public int $e = 0;
    public int $h = 0;
    public int $l = 0;

    public int $aAlt = 0;
    public int $fAlt = 0;
    public int $bAlt = 0;
    public int $cAlt = 0;
    public int $dAlt = 0;
    public int $eAlt = 0;
    public int $hAlt = 0;
    public int $lAlt = 0;

    public int $sp = 0xFFFF;
    public int $pc = 0;
    public int $ix = 0;
    public int $iy = 0;
    public int $i = 0;
    public int $r = 0;
    public int $im = 1;

    public bool $halted = false;
    public bool $iff1 = false;
    public bool $iff2 = false;

    /** Total T-states executed. */
    public int $cycles = 0;

    /** True for one instruction after EI: an interrupt is accepted only after the next instruction. */
    private bool $afterEi = false;

    private ?\Closure $ioRead = null;
    private ?\Closure $ioWrite = null;

    /** @var array<int,int> S, Z, X, Y and parity flags of every 8-bit value */
    private static array $szpTable = [];

    public function __construct(private MemoryInterface $mem)
    {
        if (self::$szpTable === []) {
            for ($v = 0; $v < 256; $v++) {
                $evenParity = substr_count(decbin($v), '1') % 2 === 0;
                self::$szpTable[$v] = ($v & (self::S | self::Y | self::X))
                    | ($v === 0 ? self::Z : 0)
                    | ($evenParity ? self::PV : 0);
            }
        }
    }

    /**
     * @param callable(int $port): int $read
     * @param callable(int $port, int $value): void $write
     */
    public function setIo(callable $read, callable $write): void
    {
        $this->ioRead = $read(...);
        $this->ioWrite = $write(...);
    }

    public function canAcceptInterrupt(): bool
    {
        return $this->iff1 && !$this->afterEi;
    }

    /** Accepts a maskable interrupt; returns the T-states it took (0 if interrupts are disabled). */
    public function interrupt(): int
    {
        if (!$this->canAcceptInterrupt()) {
            return 0;
        }
        $this->incrementR();
        $this->halted = false;
        $this->iff1 = $this->iff2 = false;
        $this->push($this->pc);
        if ($this->im === 2) {
            // The vector is read from I * 256 + the byte on the data bus (FF on a Spectrum).
            $this->pc = $this->mem->readWord(($this->i << 8) | 0xFF);
            $t = 19;
        } else {
            $this->pc = 0x0038;
            $t = 13;
        }
        $this->cycles += $t;
        return $t;
    }

    /** Executes one instruction; returns its T-states. */
    public function step(): int
    {
        $this->afterEi = false;
        if ($this->halted) {
            // HALT executes NOPs until an interrupt arrives.
            $this->incrementR();
            $this->cycles += 4;
            return 4;
        }
        $t = $this->execute($this->fetchOpcode());
        $this->cycles += $t;
        return $t;
    }

    // ---------------------------------------------------------------------------------------------
    // Unprefixed instructions
    // ---------------------------------------------------------------------------------------------

    private function execute(int $op): int
    {
        if ($op >= 0x40 && $op < 0x80) {
            return $this->loadRegisterOrHalt($op);
        }
        if ($op >= 0x80 && $op < 0xC0) {
            // ADD/ADC/SUB/SBC/AND/XOR/OR/CP A,r
            $src = $op & 7;
            $this->alu(($op >> 3) & 7, $this->getR($src));
            return $src === self::REG_HL_INDIRECT ? 7 : 4;
        }

        switch ($op) {
            case 0x00: // NOP
                return 4;

            case 0x01: case 0x11: case 0x21: case 0x31: // LD rr,nn
                $this->setPair(($op >> 4) & 3, $this->fetchWord());
                return 10;

            case 0x02: // LD (BC),A
                $this->mem->write($this->bc(), $this->a);
                return 7;
            case 0x12: // LD (DE),A
                $this->mem->write($this->de(), $this->a);
                return 7;
            case 0x0A: // LD A,(BC)
                $this->a = $this->mem->read($this->bc());
                return 7;
            case 0x1A: // LD A,(DE)
                $this->a = $this->mem->read($this->de());
                return 7;
            case 0x22: // LD (nn),HL
                $this->mem->writeWord($this->fetchWord(), $this->hl());
                return 16;
            case 0x2A: // LD HL,(nn)
                $this->setHl($this->mem->readWord($this->fetchWord()));
                return 16;
            case 0x32: // LD (nn),A
                $this->mem->write($this->fetchWord(), $this->a);
                return 13;
            case 0x3A: // LD A,(nn)
                $this->a = $this->mem->read($this->fetchWord());
                return 13;

            case 0x03: case 0x13: case 0x23: case 0x33: // INC rr
            case 0x0B: case 0x1B: case 0x2B: case 0x3B: // DEC rr
                $pair = ($op >> 4) & 3;
                $this->setPair($pair, $this->pair($pair) + (($op & 8) ? -1 : 1));
                return 6;

            case 0x04: case 0x0C: case 0x14: case 0x1C: case 0x24: case 0x2C: case 0x34: case 0x3C: // INC r
            case 0x05: case 0x0D: case 0x15: case 0x1D: case 0x25: case 0x2D: case 0x35: case 0x3D: // DEC r
                $reg = ($op >> 3) & 7;
                $this->setR($reg, $this->incDec($this->getR($reg), ($op & 1) === 1));
                return $reg === self::REG_HL_INDIRECT ? 11 : 4;

            case 0x06: case 0x0E: case 0x16: case 0x1E: case 0x26: case 0x2E: case 0x36: case 0x3E: // LD r,n
                $reg = ($op >> 3) & 7;
                $this->setR($reg, $this->fetch());
                return $reg === self::REG_HL_INDIRECT ? 10 : 7;

            case 0x09: case 0x19: case 0x29: case 0x39: // ADD HL,rr
                $this->setHl($this->add16($this->hl(), $this->pair(($op >> 4) & 3)));
                return 11;

            case 0x07: // RLCA
                $carry = $this->a >> 7;
                $this->a = (($this->a << 1) | $carry) & 0xFF;
                $this->setAccumulatorRotateFlags($carry);
                return 4;
            case 0x0F: // RRCA
                $carry = $this->a & 1;
                $this->a = ($this->a >> 1) | ($carry << 7);
                $this->setAccumulatorRotateFlags($carry);
                return 4;
            case 0x17: // RLA
                $carry = $this->a >> 7;
                $this->a = (($this->a << 1) | ($this->f & self::C)) & 0xFF;
                $this->setAccumulatorRotateFlags($carry);
                return 4;
            case 0x1F: // RRA
                $carry = $this->a & 1;
                $this->a = ($this->a >> 1) | (($this->f & self::C) << 7);
                $this->setAccumulatorRotateFlags($carry);
                return 4;

            case 0x08: // EX AF,AF'
                [$this->a, $this->f, $this->aAlt, $this->fAlt] = [$this->aAlt, $this->fAlt, $this->a, $this->f];
                return 4;
            case 0xD9: // EXX
                [$this->b, $this->c, $this->d, $this->e, $this->h, $this->l,
                    $this->bAlt, $this->cAlt, $this->dAlt, $this->eAlt, $this->hAlt, $this->lAlt] =
                [$this->bAlt, $this->cAlt, $this->dAlt, $this->eAlt, $this->hAlt, $this->lAlt,
                    $this->b, $this->c, $this->d, $this->e, $this->h, $this->l];
                return 4;
            case 0xEB: // EX DE,HL
                [$this->d, $this->e, $this->h, $this->l] = [$this->h, $this->l, $this->d, $this->e];
                return 4;
            case 0xE3: // EX (SP),HL
                $value = $this->mem->readWord($this->sp);
                $this->mem->writeWord($this->sp, $this->hl());
                $this->setHl($value);
                return 19;

            case 0x10: // DJNZ e
                $offset = $this->fetch();
                $this->b = ($this->b - 1) & 0xFF;
                if ($this->b !== 0) {
                    $this->jumpRelative($offset);
                    return 13;
                }
                return 8;
            case 0x18: // JR e
                $this->jumpRelative($this->fetch());
                return 12;
            case 0x20: case 0x28: case 0x30: case 0x38: // JR NZ/Z/NC/C,e
                $offset = $this->fetch();
                if ($this->condition(($op >> 3) & 3)) {
                    $this->jumpRelative($offset);
                    return 12;
                }
                return 7;

            case 0x27: // DAA
                $this->decimalAdjust();
                return 4;
            case 0x2F: // CPL
                $this->a ^= 0xFF;
                $this->f = ($this->f & (self::S | self::Z | self::PV | self::C)) | self::H | self::N
                    | ($this->a & (self::Y | self::X));
                return 4;
            case 0x37: // SCF
                $this->f = ($this->f & (self::S | self::Z | self::PV)) | ($this->a & (self::Y | self::X)) | self::C;
                return 4;
            case 0x3F: // CCF: H takes the old carry
                $carry = $this->f & self::C;
                $this->f = ($this->f & (self::S | self::Z | self::PV)) | ($this->a & (self::Y | self::X))
                    | ($carry ? self::H : self::C);
                return 4;

            case 0xC0: case 0xC8: case 0xD0: case 0xD8: case 0xE0: case 0xE8: case 0xF0: case 0xF8: // RET cc
                if ($this->condition(($op >> 3) & 7)) {
                    $this->pc = $this->pop();
                    return 11;
                }
                return 5;
            case 0xC2: case 0xCA: case 0xD2: case 0xDA: case 0xE2: case 0xEA: case 0xF2: case 0xFA: // JP cc,nn
                $address = $this->fetchWord();
                if ($this->condition(($op >> 3) & 7)) {
                    $this->pc = $address;
                }
                return 10;
            case 0xC4: case 0xCC: case 0xD4: case 0xDC: case 0xE4: case 0xEC: case 0xF4: case 0xFC: // CALL cc,nn
                $address = $this->fetchWord();
                if ($this->condition(($op >> 3) & 7)) {
                    $this->push($this->pc);
                    $this->pc = $address;
                    return 17;
                }
                return 10;

            case 0xC3: // JP nn
                $this->pc = $this->fetchWord();
                return 10;
            case 0xE9: // JP (HL)
                $this->pc = $this->hl();
                return 4;
            case 0xCD: // CALL nn
                $address = $this->fetchWord();
                $this->push($this->pc);
                $this->pc = $address;
                return 17;
            case 0xC9: // RET
                $this->pc = $this->pop();
                return 10;
            case 0xC7: case 0xCF: case 0xD7: case 0xDF: case 0xE7: case 0xEF: case 0xF7: case 0xFF: // RST p
                $this->push($this->pc);
                $this->pc = $op & 0x38;
                return 11;

            case 0xC5: case 0xD5: case 0xE5: // PUSH BC/DE/HL
                $this->push($this->pair(($op >> 4) & 3));
                return 11;
            case 0xF5: // PUSH AF
                $this->push(($this->a << 8) | $this->f);
                return 11;
            case 0xC1: case 0xD1: case 0xE1: // POP BC/DE/HL
                $this->setPair(($op >> 4) & 3, $this->pop());
                return 10;
            case 0xF1: // POP AF
                $value = $this->pop();
                $this->a = $value >> 8;
                $this->f = $value & 0xFF;
                return 10;
            case 0xF9: // LD SP,HL
                $this->sp = $this->hl();
                return 6;

            case 0xC6: case 0xCE: case 0xD6: case 0xDE: case 0xE6: case 0xEE: case 0xF6: case 0xFE: // ALU A,n
                $this->alu(($op >> 3) & 7, $this->fetch());
                return 7;

            case 0xD3: // OUT (n),A
                $this->ioOut(($this->a << 8) | $this->fetch(), $this->a);
                return 11;
            case 0xDB: // IN A,(n)
                $this->a = $this->ioIn(($this->a << 8) | $this->fetch());
                return 11;

            case 0xF3: // DI
                $this->iff1 = $this->iff2 = false;
                return 4;
            case 0xFB: // EI
                $this->iff1 = $this->iff2 = true;
                $this->afterEi = true;
                return 4;

            case 0xCB:
                return $this->executeCb();
            case 0xED:
                return $this->executeEd();
            case 0xDD:
                return $this->executeIndexed(true);
            case 0xFD:
                return $this->executeIndexed(false);
        }
        $address = ($this->pc - 1) & 0xFFFF;
        throw new \RuntimeException(sprintf('Unimplemented Z80 opcode 0x%02X at 0x%04X', $op, $address));
    }

    /** LD r,r' (0x40-0x7F) and HALT (0x76). */
    private function loadRegisterOrHalt(int $op): int
    {
        if ($op === 0x76) {
            $this->halted = true;
            return 4;
        }
        $dst = ($op >> 3) & 7;
        $src = $op & 7;
        $this->setR($dst, $this->getR($src));
        return ($dst === self::REG_HL_INDIRECT || $src === self::REG_HL_INDIRECT) ? 7 : 4;
    }

    private function decimalAdjust(): void
    {
        $a = $this->a;
        $subtract = $this->f & self::N;
        $carry = $this->f & self::C;
        $correction = 0;
        if (($this->f & self::H) || ($a & 0x0F) > 9) {
            $correction |= 0x06;
        }
        if ($carry || $a > 0x99) {
            $correction |= 0x60;
            $carry = self::C;
        }
        $result = ($subtract ? $a - $correction : $a + $correction) & 0xFF;
        if ($subtract) {
            $halfCarry = (($this->f & self::H) && ($a & 0x0F) < 6) ? self::H : 0;
        } else {
            $halfCarry = ($a & 0x0F) > 9 ? self::H : 0;
        }
        $this->a = $result;
        $this->f = self::$szpTable[$result] | $halfCarry | $subtract | $carry;
    }

    // ---------------------------------------------------------------------------------------------
    // CB prefix: rotates, shifts, BIT, RES, SET
    // ---------------------------------------------------------------------------------------------

    /** @return int T-states: 8 for registers, 12 for BIT n,(HL), 15 for the other (HL) forms */
    private function executeCb(): int
    {
        $op = $this->fetchOpcode();
        $reg = $op & 7;
        $bit = ($op >> 3) & 7;
        $value = $this->getR($reg);
        switch ($op >> 6) {
            case 0:
                $this->setR($reg, $this->rotateShift($bit, $value));
                break;
            case 1:
                $this->bitTest($bit, $value, $value);
                break;
            case 2:
                $this->setR($reg, $value & ~(1 << $bit));
                break;
            default:
                $this->setR($reg, $value | (1 << $bit));
        }
        if ($reg !== self::REG_HL_INDIRECT) {
            return 8;
        }
        return ($op >> 6) === 1 ? 12 : 15;
    }

    // ---------------------------------------------------------------------------------------------
    // ED prefix
    // ---------------------------------------------------------------------------------------------

    private function executeEd(): int
    {
        $op = $this->fetchOpcode();
        switch ($op) {
            case 0x40: case 0x48: case 0x50: case 0x58: case 0x60: case 0x68: case 0x70: case 0x78: // IN r,(C)
                $value = $this->ioIn($this->bc());
                $reg = ($op >> 3) & 7;
                if ($reg !== self::REG_HL_INDIRECT) { // IN (C) / IN F,(C) only sets the flags
                    $this->setR($reg, $value);
                }
                $this->f = ($this->f & self::C) | self::$szpTable[$value];
                return 12;
            case 0x41: case 0x49: case 0x51: case 0x59: case 0x61: case 0x69: case 0x71: case 0x79: // OUT (C),r
                $reg = ($op >> 3) & 7;
                $this->ioOut($this->bc(), $reg === self::REG_HL_INDIRECT ? 0 : $this->getR($reg));
                return 12;

            case 0x42: case 0x52: case 0x62: case 0x72: // SBC HL,rr
                $this->setHl($this->subtractWithCarry16($this->hl(), $this->pair(($op >> 4) & 3)));
                return 15;
            case 0x4A: case 0x5A: case 0x6A: case 0x7A: // ADC HL,rr
                $this->setHl($this->addWithCarry16($this->hl(), $this->pair(($op >> 4) & 3)));
                return 15;

            case 0x43: case 0x53: case 0x63: case 0x73: // LD (nn),rr
                $this->mem->writeWord($this->fetchWord(), $this->pair(($op >> 4) & 3));
                return 20;
            case 0x4B: case 0x5B: case 0x6B: case 0x7B: // LD rr,(nn)
                $this->setPair(($op >> 4) & 3, $this->mem->readWord($this->fetchWord()));
                return 20;

            case 0x44: case 0x4C: case 0x54: case 0x5C: case 0x64: case 0x6C: case 0x74: case 0x7C: // NEG
                $value = $this->a;
                $this->a = 0;
                $this->alu(2, $value);
                return 8;
            case 0x45: case 0x4D: case 0x55: case 0x5D: case 0x65: case 0x6D: case 0x75: case 0x7D: // RETN/RETI
                $this->pc = $this->pop();
                $this->iff1 = $this->iff2;
                return 14;

            case 0x46: case 0x4E: case 0x66: case 0x6E: // IM 0
                $this->im = 0;
                return 8;
            case 0x56: case 0x76: // IM 1
                $this->im = 1;
                return 8;
            case 0x5E: case 0x7E: // IM 2
                $this->im = 2;
                return 8;

            case 0x47: // LD I,A
                $this->i = $this->a;
                return 9;
            case 0x4F: // LD R,A
                $this->r = $this->a;
                return 9;
            case 0x57: // LD A,I
                $this->a = $this->i;
                $this->setInterruptRegisterFlags();
                return 9;
            case 0x5F: // LD A,R
                $this->a = $this->r;
                $this->setInterruptRegisterFlags();
                return 9;

            case 0x67: // RRD
                $address = $this->hl();
                $memory = $this->mem->read($address);
                $this->mem->write($address, (($this->a & 0x0F) << 4) | ($memory >> 4));
                $this->a = ($this->a & 0xF0) | ($memory & 0x0F);
                $this->f = ($this->f & self::C) | self::$szpTable[$this->a];
                return 18;
            case 0x6F: // RLD
                $address = $this->hl();
                $memory = $this->mem->read($address);
                $this->mem->write($address, (($memory << 4) | ($this->a & 0x0F)) & 0xFF);
                $this->a = ($this->a & 0xF0) | ($memory >> 4);
                $this->f = ($this->f & self::C) | self::$szpTable[$this->a];
                return 18;

            case 0xA0: case 0xA8: case 0xB0: case 0xB8: // LDI, LDD, LDIR, LDDR
                return $this->blockLoad($op);
            case 0xA1: case 0xA9: case 0xB1: case 0xB9: // CPI, CPD, CPIR, CPDR
                return $this->blockCompare($op);
            case 0xA2: case 0xAA: case 0xB2: case 0xBA: // INI, IND, INIR, INDR
                return $this->blockIn($op);
            case 0xA3: case 0xAB: case 0xB3: case 0xBB: // OUTI, OUTD, OTIR, OTDR
                return $this->blockOut($op);
        }
        return 8; // the other ED opcodes are NOPs on a real Z80
    }

    private function setInterruptRegisterFlags(): void
    {
        $this->f = ($this->f & self::C) | ($this->a & (self::S | self::Y | self::X))
            | ($this->a === 0 ? self::Z : 0) | ($this->iff2 ? self::PV : 0);
    }

    /** Bit 3 of a block opcode selects decrementing, bit 4 the repeating form. */
    private static function blockStep(int $op): int
    {
        return ($op & 0x08) ? -1 : 1;
    }

    /** Repeats a block instruction (by running it again) while $continue; returns its T-states. */
    private function blockRepeat(int $op, bool $continue): int
    {
        if (($op & 0x10) && $continue) {
            $this->pc = ($this->pc - 2) & 0xFFFF;
            return 21;
        }
        return 16;
    }

    private function blockLoad(int $op): int
    {
        $step = self::blockStep($op);
        $value = $this->mem->read($this->hl());
        $this->mem->write($this->de(), $value);
        $this->setHl($this->hl() + $step);
        $this->setDe($this->de() + $step);
        $this->setBc($this->bc() - 1);
        $n = ($this->a + $value) & 0xFF;
        $this->f = ($this->f & (self::S | self::Z | self::C)) | ($this->bc() !== 0 ? self::PV : 0)
            | (($n & 0x02) << 4) | ($n & self::X);
        return $this->blockRepeat($op, $this->bc() !== 0);
    }

    private function blockCompare(int $op): int
    {
        $step = self::blockStep($op);
        $value = $this->mem->read($this->hl());
        $result = ($this->a - $value) & 0xFF;
        $this->setHl($this->hl() + $step);
        $this->setBc($this->bc() - 1);
        $halfCarry = ($this->a & 15) < ($value & 15);
        $n = ($result - ($halfCarry ? 1 : 0)) & 0xFF;
        $this->f = ($this->f & self::C) | self::N | ($result & self::S) | (($n & 0x02) << 4) | ($n & self::X)
            | ($result === 0 ? self::Z : 0) | ($halfCarry ? self::H : 0) | ($this->bc() !== 0 ? self::PV : 0);
        return $this->blockRepeat($op, $this->bc() !== 0 && $result !== 0);
    }

    private function blockIn(int $op): int
    {
        $address = $this->hl();
        $this->mem->write($address, $this->ioIn($this->bc()));
        $this->setHl($address + self::blockStep($op));
        $this->b = ($this->b - 1) & 0xFF;
        $this->setBlockIoFlags();
        return $this->blockRepeat($op, $this->b !== 0);
    }

    private function blockOut(int $op): int
    {
        $address = $this->hl();
        $value = $this->mem->read($address);
        $this->b = ($this->b - 1) & 0xFF; // B is decremented before it is put on the address bus
        $this->ioOut($this->bc(), $value);
        $this->setHl($address + self::blockStep($op));
        $this->setBlockIoFlags();
        return $this->blockRepeat($op, $this->b !== 0);
    }

    private function setBlockIoFlags(): void
    {
        $this->f = ($this->f & self::C) | self::N | ($this->b & (self::S | self::Y | self::X))
            | ($this->b === 0 ? self::Z : 0);
    }

    // ---------------------------------------------------------------------------------------------
    // DD / FD prefixes: IX and IY
    // ---------------------------------------------------------------------------------------------

    private function executeIndexed(bool $useIx): int
    {
        $op = $this->fetchOpcode();
        $index = $useIx ? $this->ix : $this->iy;

        switch ($op) {
            case 0xCB:
                return $this->executeIndexedCb($index);

            case 0x21: // LD IX,nn
                $this->setIndex($useIx, $this->fetchWord());
                return 14;
            case 0x22: // LD (nn),IX
                $this->mem->writeWord($this->fetchWord(), $index);
                return 20;
            case 0x2A: // LD IX,(nn)
                $this->setIndex($useIx, $this->mem->readWord($this->fetchWord()));
                return 20;
            case 0x23: // INC IX
                $this->setIndex($useIx, $index + 1);
                return 10;
            case 0x2B: // DEC IX
                $this->setIndex($useIx, $index - 1);
                return 10;
            case 0x09: case 0x19: case 0x29: case 0x39: // ADD IX,rr (rr = BC, DE, IX, SP)
                $pair = ($op >> 4) & 3;
                $this->setIndex($useIx, $this->add16($index, $pair === 2 ? $index : $this->pair($pair)));
                return 15;
            case 0xE5: // PUSH IX
                $this->push($index);
                return 15;
            case 0xE1: // POP IX
                $this->setIndex($useIx, $this->pop());
                return 14;
            case 0xE9: // JP (IX)
                $this->pc = $index;
                return 8;
            case 0xF9: // LD SP,IX
                $this->sp = $index;
                return 10;
            case 0xE3: // EX (SP),IX
                $value = $this->mem->readWord($this->sp);
                $this->mem->writeWord($this->sp, $index);
                $this->setIndex($useIx, $value);
                return 23;

            case 0x24: case 0x25: case 0x2C: case 0x2D: // INC/DEC IXH, IXL
                $reg = ($op >> 3) & 7;
                $this->setIndexR($useIx, $reg, $this->incDec($this->getIndexR($useIx, $reg), ($op & 1) === 1));
                return 8;
            case 0x26: case 0x2E: // LD IXH,n / LD IXL,n
                $this->setIndexR($useIx, ($op >> 3) & 7, $this->fetch());
                return 11;

            case 0x34: case 0x35: // INC/DEC (IX+d)
                $address = $this->indexedAddress($index);
                $this->mem->write($address, $this->incDec($this->mem->read($address), $op === 0x35));
                return 23;
            case 0x36: // LD (IX+d),n
                $address = $this->indexedAddress($index);
                $this->mem->write($address, $this->fetch());
                return 19;
        }

        if ($op >= 0x40 && $op < 0x80 && $op !== 0x76) {
            $dst = ($op >> 3) & 7;
            $src = $op & 7;
            if ($dst !== self::REG_HL_INDIRECT && $src !== self::REG_HL_INDIRECT) {
                // LD r,r' where H and L mean IXH and IXL
                $this->setIndexR($useIx, $dst, $this->getIndexR($useIx, $src));
                return 8;
            }
            // LD r,(IX+d) and LD (IX+d),r use the real H and L
            $address = $this->indexedAddress($index);
            if ($src === self::REG_HL_INDIRECT) {
                $this->setR($dst, $this->mem->read($address));
            } else {
                $this->mem->write($address, $this->getR($src));
            }
            return 19;
        }

        if ($op >= 0x80 && $op < 0xC0) {
            $src = $op & 7;
            if ($src === self::REG_H || $src === self::REG_L) { // ALU A,IXH / A,IXL
                $this->alu(($op >> 3) & 7, $this->getIndexR($useIx, $src));
                return 8;
            }
            if ($src === self::REG_HL_INDIRECT) { // ALU A,(IX+d)
                $this->alu(($op >> 3) & 7, $this->mem->read($this->indexedAddress($index)));
                return 19;
            }
        }

        // Any other instruction ignores the prefix: run it unprefixed, plus 4 T-states for the prefix.
        // step() adds its own T-states to $cycles; take them back, the caller counts the total.
        $this->pc = ($this->pc - 1) & 0xFFFF;
        $inner = $this->step();
        $this->cycles -= $inner;
        return $inner + 4;
    }

    /** DD CB d op / FD CB d op. The non-BIT results are also copied to register r (undocumented). */
    private function executeIndexedCb(int $index): int
    {
        $address = $this->indexedAddress($index);
        $op = $this->fetch();
        $reg = $op & 7;
        $bit = ($op >> 3) & 7;
        $value = $this->mem->read($address);
        switch ($op >> 6) {
            case 0:
                $value = $this->rotateShift($bit, $value);
                break;
            case 1:
                // BIT n,(IX+d): X and Y come from the high byte of the address.
                $this->bitTest($bit, $value, $address >> 8);
                return 20;
            case 2:
                $value &= ~(1 << $bit);
                break;
            default:
                $value |= 1 << $bit;
        }
        $this->mem->write($address, $value);
        if ($reg !== self::REG_HL_INDIRECT) {
            $this->setR($reg, $value);
        }
        return 23;
    }

    /** Reads the displacement byte and returns IX/IY + d. */
    private function indexedAddress(int $index): int
    {
        $d = $this->fetch();
        return ($index + ($d & 0x80 ? $d - 256 : $d)) & 0xFFFF;
    }

    private function setIndex(bool $useIx, int $value): void
    {
        if ($useIx) {
            $this->ix = $value & 0xFFFF;
        } else {
            $this->iy = $value & 0xFFFF;
        }
    }

    /** Register r with H and L replaced by the high and low byte of IX/IY. */
    private function getIndexR(bool $useIx, int $reg): int
    {
        $index = $useIx ? $this->ix : $this->iy;
        return match ($reg) {
            self::REG_H => $index >> 8,
            self::REG_L => $index & 0xFF,
            default => $this->getR($reg),
        };
    }

    private function setIndexR(bool $useIx, int $reg, int $value): void
    {
        $index = $useIx ? $this->ix : $this->iy;
        match ($reg) {
            self::REG_H => $this->setIndex($useIx, ($value << 8) | ($index & 0xFF)),
            self::REG_L => $this->setIndex($useIx, ($index & 0xFF00) | $value),
            default => $this->setR($reg, $value),
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Arithmetic and flags
    // ---------------------------------------------------------------------------------------------

    /** The 8 accumulator operations: 0 ADD, 1 ADC, 2 SUB, 3 SBC, 4 AND, 5 XOR, 6 OR, 7 CP. */
    private function alu(int $operation, int $value): void
    {
        switch ($operation) {
            case 0:
                $this->add8($value, 0);
                break;
            case 1:
                $this->add8($value, $this->f & self::C);
                break;
            case 2:
                $this->subtract8($value, 0);
                break;
            case 3:
                $this->subtract8($value, $this->f & self::C);
                break;
            case 4:
                $this->a &= $value;
                $this->f = self::$szpTable[$this->a] | self::H;
                break;
            case 5:
                $this->a ^= $value;
                $this->f = self::$szpTable[$this->a];
                break;
            case 6:
                $this->a |= $value;
                $this->f = self::$szpTable[$this->a];
                break;
            default:
                $this->compare8($value);
        }
    }

    private function add8(int $value, int $carry): void
    {
        $a = $this->a;
        $sum = $a + $value + $carry;
        $result = $sum & 0xFF;
        $this->f = ($result & (self::S | self::Y | self::X))
            | ($result === 0 ? self::Z : 0)
            | ((($a & 15) + ($value & 15) + $carry > 15) ? self::H : 0)
            | ((~($a ^ $value) & ($a ^ $result) & 0x80) ? self::PV : 0)
            | ($sum > 0xFF ? self::C : 0);
        $this->a = $result;
    }

    private function subtract8(int $value, int $carry): void
    {
        $a = $this->a;
        $difference = $a - $value - $carry;
        $result = $difference & 0xFF;
        $this->f = self::N
            | ($result & (self::S | self::Y | self::X))
            | ($result === 0 ? self::Z : 0)
            | ((($a & 15) - ($value & 15) - $carry < 0) ? self::H : 0)
            | ((($a ^ $value) & ($a ^ $result) & 0x80) ? self::PV : 0)
            | ($difference < 0 ? self::C : 0);
        $this->a = $result;
    }

    /** CP: like SUB without storing the result; X and Y come from the operand. */
    private function compare8(int $value): void
    {
        $a = $this->a;
        $difference = $a - $value;
        $result = $difference & 0xFF;
        $this->f = self::N
            | ($result & self::S)
            | ($value & (self::Y | self::X))
            | ($result === 0 ? self::Z : 0)
            | ((($a & 15) - ($value & 15) < 0) ? self::H : 0)
            | ((($a ^ $value) & ($a ^ $result) & 0x80) ? self::PV : 0)
            | ($difference < 0 ? self::C : 0);
    }

    /** INC/DEC of an 8-bit value: returns the result and sets the flags (carry is kept). */
    private function incDec(int $old, bool $decrement): int
    {
        $result = ($old + ($decrement ? -1 : 1)) & 0xFF;
        if ($decrement) {
            $halfCarry = ($old & 15) === 0;
            $overflow = $old === 0x80;
        } else {
            $halfCarry = ($old & 15) === 15;
            $overflow = $old === 0x7F;
        }
        $this->f = ($this->f & self::C)
            | ($result & (self::S | self::Y | self::X))
            | ($result === 0 ? self::Z : 0)
            | ($decrement ? self::N : 0)
            | ($halfCarry ? self::H : 0)
            | ($overflow ? self::PV : 0);
        return $result;
    }

    /** ADD HL,rr / ADD IX,rr: returns the 16-bit sum; S, Z and P/V are kept. */
    private function add16(int $a, int $b): int
    {
        $sum = $a + $b;
        $result = $sum & 0xFFFF;
        $this->f = ($this->f & (self::S | self::Z | self::PV))
            | (($result >> 8) & (self::Y | self::X))
            | ((($a & 0xFFF) + ($b & 0xFFF) > 0xFFF) ? self::H : 0)
            | ($sum > 0xFFFF ? self::C : 0);
        return $result;
    }

    private function addWithCarry16(int $a, int $b): int
    {
        $carry = $this->f & self::C;
        $sum = $a + $b + $carry;
        $result = $sum & 0xFFFF;
        $this->f = ($result & 0x8000 ? self::S : 0)
            | ($result === 0 ? self::Z : 0)
            | (($result >> 8) & (self::Y | self::X))
            | ((($a & 0xFFF) + ($b & 0xFFF) + $carry > 0xFFF) ? self::H : 0)
            | ((~($a ^ $b) & ($a ^ $result) & 0x8000) ? self::PV : 0)
            | ($sum > 0xFFFF ? self::C : 0);
        return $result;
    }

    private function subtractWithCarry16(int $a, int $b): int
    {
        $carry = $this->f & self::C;
        $difference = $a - $b - $carry;
        $result = $difference & 0xFFFF;
        $this->f = self::N
            | ($result & 0x8000 ? self::S : 0)
            | ($result === 0 ? self::Z : 0)
            | (($result >> 8) & (self::Y | self::X))
            | ((($a & 0xFFF) - ($b & 0xFFF) - $carry < 0) ? self::H : 0)
            | ((($a ^ $b) & ($a ^ $result) & 0x8000) ? self::PV : 0)
            | ($difference < 0 ? self::C : 0);
        return $result;
    }

    /** Flags after RLCA/RRCA/RLA/RRA: S, Z and P/V kept, X/Y from A. */
    private function setAccumulatorRotateFlags(int $carry): void
    {
        $this->f = ($this->f & (self::S | self::Z | self::PV)) | ($this->a & (self::Y | self::X)) | $carry;
    }

    /**
     * CB rotate/shift group: 0 RLC, 1 RRC, 2 RL, 3 RR, 4 SLA, 5 SRA, 6 SLL (undocumented), 7 SRL.
     * Returns the result and sets the flags.
     */
    private function rotateShift(int $operation, int $value): int
    {
        switch ($operation) {
            case 0:
                $carry = $value >> 7;
                $value = (($value << 1) | $carry) & 0xFF;
                break;
            case 1:
                $carry = $value & 1;
                $value = ($value >> 1) | ($carry << 7);
                break;
            case 2:
                $carry = $value >> 7;
                $value = (($value << 1) | ($this->f & self::C)) & 0xFF;
                break;
            case 3:
                $carry = $value & 1;
                $value = ($value >> 1) | (($this->f & self::C) << 7);
                break;
            case 4:
                $carry = $value >> 7;
                $value = ($value << 1) & 0xFF;
                break;
            case 5:
                $carry = $value & 1;
                $value = ($value >> 1) | ($value & 0x80);
                break;
            case 6:
                $carry = $value >> 7;
                $value = (($value << 1) | 1) & 0xFF;
                break;
            default:
                $carry = $value & 1;
                $value >>= 1;
        }
        $this->f = self::$szpTable[$value] | $carry;
        return $value;
    }

    /** BIT n: Z and P/V set when the bit is 0; X and Y are copied from $xySource. */
    private function bitTest(int $bit, int $value, int $xySource): void
    {
        $this->f = ($this->f & self::C) | self::H
            | (($value & (1 << $bit)) ? 0 : self::Z | self::PV)
            | ($bit === 7 ? ($value & self::S) : 0)
            | ($xySource & (self::Y | self::X));
    }

    /** Condition codes: 0 NZ, 1 Z, 2 NC, 3 C, 4 PO, 5 PE, 6 P, 7 M. */
    private function condition(int $cc): bool
    {
        return match ($cc) {
            0 => ($this->f & self::Z) === 0,
            1 => ($this->f & self::Z) !== 0,
            2 => ($this->f & self::C) === 0,
            3 => ($this->f & self::C) !== 0,
            4 => ($this->f & self::PV) === 0,
            5 => ($this->f & self::PV) !== 0,
            6 => ($this->f & self::S) === 0,
            default => ($this->f & self::S) !== 0,
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Fetching, registers, stack and I/O
    // ---------------------------------------------------------------------------------------------

    /** The refresh register counts opcode fetches in its low seven bits. */
    private function incrementR(): void
    {
        $this->r = ($this->r & 0x80) | (($this->r + 1) & 0x7F);
    }

    /** Reads an operand byte at PC (R is not changed). */
    private function fetch(): int
    {
        $value = $this->mem->read($this->pc);
        $this->pc = ($this->pc + 1) & 0xFFFF;
        return $value;
    }

    /** M1 opcode fetch: also increments R. */
    private function fetchOpcode(): int
    {
        $value = $this->fetch();
        $this->incrementR();
        return $value;
    }

    private function fetchWord(): int
    {
        $low = $this->fetch();
        return $low | ($this->fetch() << 8);
    }

    private function jumpRelative(int $offset): void
    {
        $this->pc = ($this->pc + ($offset & 0x80 ? $offset - 256 : $offset)) & 0xFFFF;
    }

    private function bc(): int
    {
        return ($this->b << 8) | $this->c;
    }

    private function de(): int
    {
        return ($this->d << 8) | $this->e;
    }

    private function hl(): int
    {
        return ($this->h << 8) | $this->l;
    }

    private function setBc(int $value): void
    {
        $this->b = ($value >> 8) & 0xFF;
        $this->c = $value & 0xFF;
    }

    private function setDe(int $value): void
    {
        $this->d = ($value >> 8) & 0xFF;
        $this->e = $value & 0xFF;
    }

    private function setHl(int $value): void
    {
        $this->h = ($value >> 8) & 0xFF;
        $this->l = $value & 0xFF;
    }

    /** Register pairs as encoded in opcodes: 0 BC, 1 DE, 2 HL, 3 SP. */
    private function pair(int $pair): int
    {
        return match ($pair) {
            0 => $this->bc(),
            1 => $this->de(),
            2 => $this->hl(),
            default => $this->sp,
        };
    }

    private function setPair(int $pair, int $value): void
    {
        match ($pair) {
            0 => $this->setBc($value),
            1 => $this->setDe($value),
            2 => $this->setHl($value),
            default => $this->sp = $value & 0xFFFF,
        };
    }

    /** 8-bit registers as encoded in opcodes: 0 B, 1 C, 2 D, 3 E, 4 H, 5 L, 6 (HL), 7 A. */
    private function getR(int $reg): int
    {
        return match ($reg) {
            0 => $this->b,
            1 => $this->c,
            2 => $this->d,
            3 => $this->e,
            4 => $this->h,
            5 => $this->l,
            6 => $this->mem->read($this->hl()),
            default => $this->a,
        };
    }

    private function setR(int $reg, int $value): void
    {
        $value &= 0xFF;
        match ($reg) {
            0 => $this->b = $value,
            1 => $this->c = $value,
            2 => $this->d = $value,
            3 => $this->e = $value,
            4 => $this->h = $value,
            5 => $this->l = $value,
            6 => $this->mem->write($this->hl(), $value),
            default => $this->a = $value,
        };
    }

    private function push(int $value): void
    {
        $this->sp = ($this->sp - 1) & 0xFFFF;
        $this->mem->write($this->sp, $value >> 8);
        $this->sp = ($this->sp - 1) & 0xFFFF;
        $this->mem->write($this->sp, $value & 0xFF);
    }

    private function pop(): int
    {
        $low = $this->mem->read($this->sp);
        $this->sp = ($this->sp + 1) & 0xFFFF;
        $high = $this->mem->read($this->sp);
        $this->sp = ($this->sp + 1) & 0xFFFF;
        return ($high << 8) | $low;
    }

    private function ioIn(int $port): int
    {
        return $this->ioRead !== null ? ($this->ioRead)($port) : 0xFF;
    }

    private function ioOut(int $port, int $value): void
    {
        if ($this->ioWrite !== null) {
            ($this->ioWrite)($port, $value);
        }
    }
}
