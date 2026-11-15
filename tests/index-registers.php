<?php

require __DIR__ . '/../src/autoload.php';

use ZX\Spectrum\Cpu\Z80;
use ZX\Spectrum\Machine\Memory;

$m = new Memory();
$cpu = new Z80($m);
// LD IY,5000; INC IY; LD A,IYH; LD (IY+1),A; LD B,(IY+1); HALT
$program = [0xFD, 0x21, 0x00, 0x50, 0xFD, 0x23, 0xFD, 0x7C, 0xFD, 0x77, 0x01, 0xFD, 0x46, 0x01, 0x76];
$cpu->pc = 0x4000;
foreach ($program as $i => $v) {
    $m->write(0x4000 + $i, $v);
}
for ($i = 0;$i < 8 && !$cpu->halted;$i++) {
    $cpu->step();
}
if ($cpu->iy !== 0x5001 || $cpu->a !== 0x50 || $cpu->b !== 0x50 || $m->read(0x5002) !== 0x50) {
    throw new RuntimeException('IY indexed instruction test failed');
}
echo "Index-register tests passed\n";
