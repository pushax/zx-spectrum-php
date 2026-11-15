<?php

require __DIR__ . '/../src/autoload.php';

use ZX\Spectrum\Machine\Memory;

$m = new Memory(null, true);
$m->write(0xC000, 0x11);
if ($m->read(0xC000) !== 0x11) {
    throw new RuntimeException('bank 0 read/write failed');
}
$m->page7ffd(3);
$m->write(0xC000, 0x33);
if ($m->read(0xC000) !== 0x33) {
    throw new RuntimeException('bank 3 mapping failed');
}
$m->page7ffd(0);
if ($m->read(0xC000) !== 0x11) {
    throw new RuntimeException('bank switching failed');
}
$m->page7ffd(8);
$m->write(0x4000, 0x55);
if ($m->screenBank() !== 7) {
    throw new RuntimeException('screen page select failed');
}
$m->page7ffd(0);
if ($m->screenBank() !== 5) {
    throw new RuntimeException('screen bank 5 select failed');
}
$m->page7ffd(0x20);
$m->page7ffd(1);
if ($m->pagingValue() !== 0x20) {
    throw new RuntimeException('paging lock failed');
}
echo "128K memory banking tests passed\n";
