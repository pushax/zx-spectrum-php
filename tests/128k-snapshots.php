<?php

namespace ZX\Spectrum;

use ZX\Spectrum\Machine\MachineTiming;
use ZX\Spectrum\Machine\Spectrum48;

require __DIR__ . '/../src/autoload.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/zx-snap-test-' . getmypid();
@mkdir($tmp);
// 32K ROM: 128 menu ROM filled with 0x11, 48 BASIC ROM filled with 0x22.
file_put_contents("$tmp/128.rom", str_repeat("\x11", 16384) . str_repeat("\x22", 16384));
$pentagon = fn () => new Spectrum48("$tmp/128.rom", MachineTiming::named('pentagon'), true);
$bank = fn (int $b) => str_repeat(chr(0xB0 | $b), 16384);
// SNA header: I=3F, HL=1234, SP=8000, IM 2, border 4.
$snaHeader = "\x3F" . str_repeat("\0", 8) . "\x34\x12" . str_repeat("\0", 8) . "\x04\x00" . "\0\0"
    . "\x00\x80" . "\x02\x04";

// 128K SNA, paged bank 4 (131103 bytes).
$sna = $snaHeader . $bank(5) . $bank(2) . $bank(4) . "\xCD\xAB" . "\x14" . "\x00";
foreach ([0, 1, 3, 6, 7] as $b) {
    $sna .= $bank($b);
}
check(strlen($sna) === 131103, 'test SNA size');
file_put_contents("$tmp/a.sna", $sna);
$m = $pentagon();
$m->loadSnapshot("$tmp/a.sna");
check($m->cpu->pc === 0xABCD && $m->cpu->sp === 0x8000, '128K SNA: PC must come from the file, SP unchanged');
check($m->cpu->h === 0x12 && $m->cpu->l === 0x34 && $m->cpu->im === 2 && $m->ula->border === 4, '128K SNA: registers');
for ($b = 0; $b < 8; $b++) {
    check($m->memory->ramBank($b)[100] === (0xB0 | $b), "128K SNA: bank $b content");
}
check(
    $m->memory->read(0xC000) === 0xB4 && $m->memory->read(0x0000) === 0x22,
    '128K SNA: 7FFD=14 pages bank 4 and ROM 1'
);

// 128K SNA, paged bank 5 (147487 bytes: bank 5 stored twice).
$sna = $snaHeader . $bank(5) . $bank(2) . $bank(5) . "\x00\x60" . "\x05" . "\x00";
foreach ([0, 1, 3, 4, 6, 7] as $b) {
    $sna .= $bank($b);
}
check(strlen($sna) === 147487, 'test SNA size 2');
file_put_contents("$tmp/b.sna", $sna);
$m = $pentagon();
$m->loadSnapshot("$tmp/b.sna");
for ($b = 0; $b < 8; $b++) {
    check($m->memory->ramBank($b)[0] === (0xB0 | $b), "128K SNA (n=5): bank $b content");
}
check($m->cpu->pc === 0x6000 && $m->memory->read(0x0000) === 0x11, '128K SNA (n=5): PC and ROM 0');

// 48K SNA on a 128K machine: 48 BASIC ROM selected and paging locked.
$sna48 = $snaHeader . str_repeat("\0", 49152);
$sna48[27 + 0x8000 - 0x4000] = "\x78";
$sna48[27 + 0x8001 - 0x4000] = "\x56"; // PC on the stack at 8000
file_put_contents("$tmp/c.sna", $sna48);
$m = $pentagon();
$m->loadSnapshot("$tmp/c.sna");
check($m->cpu->pc === 0x5678 && $m->cpu->sp === 0x8002, '48K SNA on 128K: PC popped from stack');
check(
    $m->memory->read(0x0000) === 0x22 && ($m->memory->pagingValue() & 0x20) !== 0,
    '48K SNA on 128K: 48 ROM + locked'
);

// Z80 v3, 128K (hardware mode 4), one compressed block, the rest stored raw (length FFFF).
$hdr = str_repeat("\0", 30);
$hdr[0] = "\x42";
$hdr[8] = "\x00";
$hdr[9] = "\x90";
$hdr[29] = "\x01"; // A=42, SP=9000, IM 1, PC=0 → extended
$ext = pack('v', 54) . pack('v', 0x8123) . "\x04" . "\x13" . "\0\0" . "\x07"
    . implode('', array_map('chr', range(1, 16)));
$ext .= str_repeat("\0", 54 - strlen($ext) + 2);
$z80 = $hdr . $ext;
for ($b = 0; $b < 8; $b++) {
    if ($b === 6) {
        $c = "\xED\xED\x00" . chr(0xB6);
        $c = str_repeat($c, 64);
        $z80 .= pack('v', strlen($c)) . chr($b + 3) . $c;
    } // 64 × 256 bytes
    else {
        $z80 .= "\xFF\xFF" . chr($b + 3) . $bank($b);
    }
}
file_put_contents("$tmp/d.z80", $z80);
$m = $pentagon();
$m->loadSnapshot("$tmp/d.z80");
check($m->cpu->pc === 0x8123 && $m->cpu->a === 0x42 && $m->cpu->sp === 0x9000, 'Z80 v3: registers/PC');
for ($b = 0; $b < 8; $b++) {
    check($m->memory->ramBank($b)[16383] === (0xB0 | $b), "Z80 v3: bank $b content");
}
check($m->memory->read(0xC000) === 0xB3 && $m->memory->read(0) === 0x22, 'Z80 v3: 7FFD=13 pages bank 3 and ROM 1');
check(
    $m->aySelected === 7 && $m->ay->read(0) === 1 && $m->ay->read(1) === 2 && $m->ay->read(15) === 16,
    'Z80 v3: AY state'
);

// Z80 v1, compressed (this used to fail with a TypeError).
$v1 = str_repeat("\0", 30);
$v1[6] = "\x00";
$v1[7] = "\x80";
$v1[12] = "\x20"; // PC=8000, compressed
$v1 .= str_repeat("\xED\xED\x00\x99", 192) . "\x00\xED\xED\x00";                // 48K of 0x99 + end marker
file_put_contents("$tmp/e.z80", $v1);
$m = new Spectrum48();
$m->loadSnapshot("$tmp/e.z80");
check(
    $m->cpu->pc === 0x8000 && $m->memory->read(0x4000) === 0x99 && $m->memory->read(0xFFFF) === 0x99,
    'Z80 v1 compressed'
);

// AY register read-back through port FFFD.
$m = $pentagon();
$m->memory->load(0x8000, "\x01\xFD\xFF\x3E\x00\xED\x79\x01\xFD\xBF\x3E\x5A\xED\x79\x01\xFD\xFF\xED\x78\x76");
// LD BC,FFFD; LD A,0; OUT (C),A; LD BC,BFFD; LD A,5A; OUT (C),A; LD BC,FFFD; IN A,(C); HALT
$m->cpu->pc = 0x8000;
$m->runInstructions(10);
check($m->cpu->a === 0x5A, 'AY register read-back');

array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
echo "128K snapshot tests passed\n";
