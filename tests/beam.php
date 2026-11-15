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

/** Pentagon machine without ROM; IM 2 handler in RAM (EI; RET) so the test does not depend on a ROM image. */
function machine(): Spectrum48
{
    $m = new Spectrum48(null, MachineTiming::named('pentagon'), true);
    $m->memory->load(0x90FF, "\x00\x92");   // IM 2 vector (I=90, bus FF) → 9200
    $m->memory->load(0x9200, "\xFB\xC9");   // EI; RET
    $m->cpu->i = 0x90;
    $m->cpu->im = 2;
    $m->cpu->sp = 0x7F00;
    $m->cpu->pc = 0x8000;
    return $m;
}

// Multicolour: the attribute of cell (0,0) changes while the beam is inside the cell.
// Pixel lines drawn before the write keep the old paper colour, later ones show the new one.
$m = machine();
for ($a = 0x5800; $a < 0x5B00; $a++) {
    $m->memory->write($a, 0x08);
} // blue paper
// EI; HALT; LD A,08; LD (5800),A; LD HL,700; loop: DEC HL; LD A,H; OR L; JR NZ,loop; LD A,10; LD (5800),A; JR → HALT
$m->memory->load(
    0x8000,
    "\xFB\x76\x3E\x08\x32\x00\x58\x21" . pack('v', 700) . "\x2B\x7C\xB5\x20\xFB\x3E\x10\x32\x00\x58\x18\xEB"
);
$writeT = null;
$orig = $m->memory->onScreenWrite;
$m->memory->onScreenWrite = function () use ($m, $orig, &$writeT) {
    if ($m->cpu->a === 0x10) {
        $writeT = $m->ula->frameCycles;
    }
    ($orig)();
};
for ($f = 0; $f < 3; $f++) {
    $m->runFrame();
}
$frame = $m->beam->frame();
$firstNew = (int)ceil(($writeT - $m->ula->timing()->topLeftPixel()) / 224);
check($firstNew > 0 && $firstNew < 8, "Test setup: the write should land inside the cell (line $firstNew)");
for ($y = 0; $y < 8; $y++) {
    $paper = ord($frame[(24 + $y) * 320 + 32]);
    check($paper === ($y < $firstNew ? 1 : 2), "Multicolour: pixel line $y of cell (0,0) has colour $paper");
}

// Border effects: OUT (FE) in a loop paints horizontal stripes into the border.
$m = machine();
// EI; HALT; LD C,0; LD D,64; loop: LD A,C; AND 7; OUT (FE),A; LD B,64; DJNZ $; INC C; DEC D; JR NZ,loop; JR → HALT
$m->memory->load(0x8000, "\xFB\x76\x0E\x00\x16\x40\x79\xE6\x07\xD3\xFE\x06\x40\x10\xFE\x0C\x15\x20\xF2\x18\xEA");
for ($f = 0; $f < 3; $f++) {
    $m->runFrame();
}
$frame = $m->beam->frame();
$changes = 0;
for ($y = 1; $y < 240; $y++) {
    if ($frame[$y * 320 + 5] !== $frame[($y - 1) * 320 + 5]) {
        $changes++;
    }
}
check($changes >= 40, "Border stripes: expected many colour changes down the left border, got $changes");

echo "Beam renderer tests passed\n";
