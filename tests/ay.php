<?php

namespace ZX\Spectrum;

use ZX\Spectrum\Sound\Ay8912;

require __DIR__ . '/../src/autoload.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$rate = 44100;
$ticks = 1750000 / 8 / $rate;
/** @return array{0:list<float>,1:list<float>} */
function run(Ay8912 $ay, int $samples, float $ticks): array
{
    $l = [];
    $r = [];
    $ay->render($samples, $ticks, $l, $r);
    return [$l, $r];
}

// Tone A, period 100: 1750000 / (16 * 100) = 1093.75 Hz.
$ay = new Ay8912();
$ay->write(0, 100);
$ay->write(1, 0);
$ay->write(7, 0x3E);
$ay->write(8, 15);
[$l] = run($ay, $rate, $ticks);
$mid = (max($l) + min($l)) / 2;
$rises = 0;
for ($i = 1; $i < count($l); $i++) {
    if ($l[$i - 1] < $mid && $l[$i] >= $mid) {
        $rises++;
    }
}
check(abs($rises - 1093.75) <= 2, "Tone frequency: expected ~1094 Hz, got $rises");

// Channel A is panned left: left louder than right.
[$l, $r] = run($ay, 2000, $ticks);
check(max($l) > max($r) * 2, 'Channel A should be panned to the left');

// All tone and noise disabled (R7 = 3F): the output is a constant level (silence after DC removal).
$ay = new Ay8912();
$ay->write(0, 50);
$ay->write(7, 0x3F);
$ay->write(8, 15);
[$l] = run($ay, 2000, $ticks);
check(max($l) - min($l) < 1e-9, 'Mixer off must give a constant output');

// Volume 0 is silent.
$ay = new Ay8912();
$ay->write(0, 50);
$ay->write(7, 0x3E);
$ay->write(8, 0);
[$l] = run($ay, 2000, $ticks);
check(max($l) < 1e-9, 'Volume 0 must be silent');

// Envelope shape 13 (attack, then hold at top) on a channel with tone and noise disabled.
$ay = new Ay8912();
$ay->write(7, 0x3F);
$ay->write(8, 0x10);
$ay->write(11, 10);
$ay->write(12, 0);
$ay->write(13, 13);
[$l] = run($ay, 4000, $ticks);
check(
    $l[1] < $l[60] && abs($l[3000] - $l[3999]) < 1e-9 && $l[3999] >= max($l) - 1e-9,
    'Envelope shape 13 must rise and hold at the top'
);

// Envelope shape 8 (repeating decay) keeps changing.
$ay = new Ay8912();
$ay->write(7, 0x3F);
$ay->write(8, 0x10);
$ay->write(11, 5);
$ay->write(13, 8);
[$l] = run($ay, 4000, $ticks);
check(max(array_slice($l, 3000)) - min(array_slice($l, 3000)) > 0.1, 'Envelope shape 8 must keep repeating');

// Noise on channel A only: both levels appear, and the result is not a regular square wave.
$ay = new Ay8912();
$ay->write(6, 1);
$ay->write(7, 0x37);
$ay->write(8, 15);
[$l] = run($ay, 4000, $ticks);
check(max($l) - min($l) > 0.1, 'Noise must produce sound');

// Register read-back is masked to the implemented bits.
$ay = new Ay8912();
$ay->write(1, 0xFF);
$ay->write(6, 0xFF);
$ay->write(8, 0xFF);
check($ay->read(1) === 0x0F && $ay->read(6) === 0x1F && $ay->read(8) === 0x1F, 'Register masks');

echo "AY tests passed\n";
