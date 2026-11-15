<?php

namespace ZX\Spectrum;

use ZX\Spectrum\Cpu\Z80;
use ZX\Spectrum\Machine\Memory;

require __DIR__ . '/../src/autoload.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

// RET Z: taken when Z is set, with the return address popped from the stack.
$mem = new Memory();
$cpu = new Z80($mem);
$mem->write(0x8000, 0xC8);
$mem->write(0x9000, 0x34);
$mem->write(0x9001, 0x12);
$cpu->pc = 0x8000;
$cpu->sp = 0x9000;
$cpu->f = 0x40;
$cpu->step();
check($cpu->pc === 0x1234 && $cpu->sp === 0x9002, 'RET Z taken case failed');

// RET Z: not taken when Z is clear.
$mem = new Memory();
$cpu = new Z80($mem);
$mem->write(0x8000, 0xC8);
$cpu->pc = 0x8000;
$cpu->sp = 0x9000;
$cpu->f = 0;
$cpu->step();
check($cpu->pc === 0x8001 && $cpu->sp === 0x9000, 'RET Z not-taken case failed');

// Exercise all condition-code encodings through the same decoder.
$opcodes = [0xC0, 0xC8, 0xD0, 0xD8, 0xE0, 0xE8, 0xF0, 0xF8];
$falseFlags = [0x40, 0x00, 0x01, 0x00, 0x04, 0x00, 0x80, 0x00];
foreach ($opcodes as $i => $op) {
    $mem = new Memory();
    $cpu = new Z80($mem);
    $mem->write(0x8000, $op);
    $cpu->pc = 0x8000;
    $cpu->sp = 0x9000;
    $cpu->f = $falseFlags[$i];
    $cpu->step();
    check($cpu->pc === 0x8001, "Conditional RET opcode " . sprintf('%02X', $op) . ' failed');
}
echo "Conditional control-flow tests passed\n";
