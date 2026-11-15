<?php

namespace ZX\Spectrum\Cpu;

/** Memory bus as seen by the Z80 core. */
interface MemoryInterface
{
    public function read(int $address): int;

    public function write(int $address, int $value): void;

    public function readWord(int $address): int;

    public function writeWord(int $address, int $value): void;
}
