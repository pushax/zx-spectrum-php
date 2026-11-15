<?php

namespace ZX\Spectrum;

use ZX\Spectrum\Machine\MachineTiming;
use ZX\Spectrum\Machine\Spectrum48;
use ZX\Spectrum\Machine\ULA;
use ZX\Spectrum\Terminal\Keyboard;

require __DIR__ . '/../src/autoload.php';

stream_set_blocking(STDIN, false); // the keyboard also reads the real terminal; never wait for it here

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

/** Keyboard that reads from a string instead of the terminal. */
function keyboard(): array
{
    $k = new Keyboard();
    (new \ReflectionProperty($k, 'enabled'))->setValue($k, true);
    $pending = new \ReflectionProperty($k, 'pending');
    return [$k, fn (string $s) => $pending->setValue($k, $pending->getValue($k) . $s)];
}

// Character → Spectrum keys (row, bit): uppercase = CAPS SHIFT + letter, symbols = SYMBOL SHIFT + key,
// Backspace = CAPS SHIFT + 0, Esc = CAPS SHIFT + SPACE.
$cases = [
    'A' => [[0, 0], [1, 0]],
    '"' => [[7, 1], [5, 0]],
    ':' => [[7, 1], [0, 1]],
    "\x7F" => [[0, 0], [4, 0]],
    "\033" => [[0, 0], [7, 0]],
    'q' => [[2, 0]],
];
foreach ($cases as $ch => $keys) {
    $ula = new ULA(MachineTiming::named('48k'));
    [$k, $type] = keyboard();
    $type($ch);
    $k->poll($ula);
    $down = [];
    foreach ($ula->keyRows() as $row => $bits) {
        for ($bit = 0; $bit < 5; $bit++) {
            if ((($bits >> $bit) & 1) === 0) {
                $down[] = [$row, $bit];
            }
        }
    }
    sort($keys);
    sort($down);
    check(
        $down === $keys,
        sprintf('Key %s: expected %s, got %s', json_encode($ch), json_encode($keys), json_encode($down))
    );
}

// Terminal auto-repeat keeps the key held without gaps.
$ula = new ULA(MachineTiming::named('48k'));
[$k, $type] = keyboard();
$trace = '';
for ($f = 0; $f < 80; $f++) {
    if ($f === 0 || ($f >= 18 && $f < 60 && $f % 2 === 0)) {
        $type('q');
    }
    $k->poll($ula);
    $trace .= ($ula->keyRows()[2] & 1) === 0 ? '#' : '.';
    $k->release($ula);
}
check(!str_contains(substr($trace, 0, 60), '.'), "Auto-repeat: key must stay held, got $trace");

// A key and the same key with CAPS SHIFT typed shortly after each other are two presses
// ("0" then Backspace = CAPS SHIFT + 0), not one held key.
$ula = new ULA(MachineTiming::named('48k'));
[$k, $type] = keyboard();
$type('0');
$capsSeen = false;
for ($f = 0; $f < 40; $f++) {
    if ($f === 15) {
        $type("\x7F");
    }
    $k->poll($ula);
    $rows = $ula->keyRows();
    $capsSeen = $capsSeen || (($rows[0] & 1) === 0 && ($rows[4] & 1) === 0);
    $k->release($ula);
}
check($capsSeen, 'Backspace after "0" must press CAPS SHIFT + 0');

// Typing a BASIC line fast (as when pasting): repeated keys, shifted keys and Enter all arrive.
$rom = __DIR__ . '/../48.rom';
if (is_file($rom)) {
    $m = new Spectrum48($rom);
    for ($f = 0; $f < 100; $f++) {
        $m->runFrame();
    }
    [$k, $type] = keyboard();
    $type("10p\"Hi ll\"x\x7F\n");
    for ($f = 0; $f < 300; $f++) {
        $k->poll($m->ula);
        $m->runFrame();
        $k->release($m->ula);
    }
    $prog = $m->memory->readWord(23635);
    $line = '';
    for ($a = $prog + 4; $a < $prog + 13; $a++) {
        $line .= chr($m->memory->read($a));
    }
    check($line === "\xF5\"Hi ll\"\x0D", 'BASIC line 10 should be PRINT "Hi ll", got ' . bin2hex($line));
} else {
    echo "BASIC typing test skipped (48.rom not found)\n";
}
echo "Keyboard tests passed\n";
