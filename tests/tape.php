<?php

namespace ZX\Spectrum;

use ZX\Spectrum\Machine\Spectrum48;
use ZX\Spectrum\Tape\Tape;

require __DIR__ . '/../src/autoload.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}
function block(string $d): string
{
    $x = 0;
    foreach (str_split($d) as $c) {
        $x ^= ord($c);
    } return $d . chr($x);
}

// BASIC program "test", autostart 10: 10 POKE 32768,42: BORDER 2 / 20 GO TO 20
$num = fn (int $n) => $n . "\x0E\x00\x00" . pack('v', $n) . "\x00";
$line = function (int $no, string $body): string {
    $body .= "\x0D";
    return pack('n', $no) . pack('v', strlen($body)) . $body;
};
$prog = $line(10, "\xF4" . $num(32768) . ',' . $num(42) . ":\xE7" . $num(2)) . $line(20, "\xEC" . $num(20));
$header = block("\x00\x00" . str_pad('test', 10) . pack('v', strlen($prog)) . pack('v', 10) . pack('v', strlen($prog)));
$data = block("\xFF" . $prog);
$dir = sys_get_temp_dir() . '/zx-tape-test-' . getmypid();
@mkdir($dir);
file_put_contents("$dir/t.tap", pack('v', strlen($header)) . $header . pack('v', strlen($data)) . $data);
// TZX: text, archive info, a turbo block the ROM cannot read, then the two blocks as 0x11 turbo blocks
// with ROM timings, and finally a code block with its own (non-ROM) timings.
$turbo = fn(string $d, int $pilots, int $zero = 855, int $one = 1710) => "\x11"
    . pack('vvvvvv', 2168, 667, 735, $zero, $one, $pilots) . "\x08" . pack('v', 1000)
    . substr(pack('V', strlen($d)), 0, 3) . $d;
$code = block("\xFF\x01\x02\x03\x04");
file_put_contents("$dir/t.tzx", "ZXTape!\x1A\x01\x14" . "\x30\x04note" . "\x32\x05\x00\x01\x00\x02ab"
    . $turbo("\x55", 100, 300, 600) . $turbo($header, 8063) . $turbo($data, 3223) . $turbo($code, 3223, 640, 1280));

$rom = __DIR__ . '/../48.rom';
if (!is_file($rom)) {
    echo "Tape tests skipped (48.rom not found)\n";
    exit(0);
}

/** Types LOAD "" and runs until the program has poked 42 into 32768. */
function loadWithBasic(string $tape, string $rom, int $maxFrames): int
{
    $m = new Spectrum48($rom);
    $m->tape = Tape::open($tape);
    $keys = [150 => [[6, 3]], 170 => [[7, 1], [5, 0]], 190 => [[7, 1], [5, 0]], 210 => [[6, 0]]];
    for ($i = 0; $i < $maxFrames; $i++) {
        $down = [];
        foreach ($keys as $f => $ks) {
            if ($i >= $f && $i < $f + 8) {
                foreach ($ks as [$r, $b]) {
                    $down["$r:$b"] = true;
                }
            }
        }
        foreach ([[6, 3], [7, 1], [5, 0], [6, 0]] as [$r, $b]) {
            $m->ula->setKey($r, $b, isset($down["$r:$b"]));
        }
        $m->runFrame();
        if ($m->memory->read(0x8000) === 42) {
            return $i;
        }
    }
    return -1;
}

// TAP: LOAD "" is answered instantly by the LD-BYTES trap.
$f = loadWithBasic("$dir/t.tap", $rom, 400);
check($f > 0 && $f < 240, "TAP: LOAD \"\" should load instantly (frame $f)");
// TZX: unreadable turbo block skipped, ROM-timed turbo blocks loaded through the trap.
$f = loadWithBasic("$dir/t.tzx", $rom, 400);
check($f > 0 && $f < 240, "TZX: ROM-readable blocks should load instantly (frame $f)");

// Real signal: run the ROM loader past the trap (entry 055A) and let it read the custom-timed code block
// (the ROM accepts these timings within its tolerance window, the trap does not, so pulses are used).
$m = new Spectrum48($rom);
$m->tape = Tape::open("$dir/t.tzx");
check(
    $m->tape->takeStandardBlock() !== null && $m->tape->takeStandardBlock() !== null,
    'TZX: two trap-loadable blocks'
);
$m->tape->playing = true;
$c = $m->cpu;
$m->memory->writeWord(0xEFFE, 0x1234);
$c->sp = 0xEFFE;
$c->aAlt = 0xFF;
$c->fAlt = 0x01;       // expected flag FF, LOAD (carry), NZ — what 0556-0559 would have set
$c->ix = 0x9000;
$c->d = 0;
$c->e = 4;
$c->pc = 0x055A;
for ($n = 0; $c->pc !== 0x1234 && $n < 20000000; $n++) {
    $m->runInstructions(1);
}
$loaded = '';
for ($a = 0x9000; $a < 0x9004; $a++) {
    $loaded .= chr($m->memory->read($a));
}
check(
    $c->pc === 0x1234 && ($c->f & 1) === 1 && $loaded === "\x01\x02\x03\x04",
    'Real signal: the ROM loader should read the code block from the pulses'
);

@unlink("$dir/t.tap");
@unlink("$dir/t.tzx");
@rmdir($dir);
echo "Tape tests passed\n";
