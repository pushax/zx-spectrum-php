<?php

namespace ZX\Spectrum;

use ZX\Spectrum\Cpu\Z80;
use ZX\Spectrum\Machine\Memory;

require __DIR__ . '/../src/autoload.php';

$mem = new Memory();
$program = [0x3E, 0x10, 0x06, 0x02, 0x80, 0xFE, 0x12, 0x20, 0x02, 0x3E, 0x2A, 0x76];
foreach ($program as $i => $byte) {
    $mem->write(0x8000 + $i, $byte);
}
$cpu = new Z80($mem);
$cpu->pc = 0x8000;
for ($i = 0;$i < 20 && !$cpu->halted;$i++) {
    $cpu->step();
}
if (!$cpu->halted || $cpu->a !== 0x2A) {
    fwrite(STDERR, "CPU regression test failed\n");
    exit(1);
}
echo "CPU regression test passed\n";
