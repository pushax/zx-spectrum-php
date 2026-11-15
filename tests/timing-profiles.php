<?php

namespace ZX\Spectrum;

use ZX\Spectrum\Machine\MachineTiming;
use ZX\Spectrum\Machine\ULA;

require __DIR__ . '/../src/autoload.php';

foreach ([
    ['48k', 69888, 312, 50.080128],
    ['pentagon', 71680, 320, 48.828125],
] as [$name,$frame,$lines,$rate]) {
    $p = MachineTiming::named($name);
    if ($p->frameTstates !== $frame || $p->linesPerFrame !== $lines || abs($p->frameRateHz() - $rate) > 0.001) {
        throw new \RuntimeException("Bad timing profile: $name");
    }
    $ula = new ULA($p);
    if ($ula->tick($frame - 1) !== false || $ula->tick(1) !== true || $ula->frameCycles !== 0) {
        throw new \RuntimeException("Frame boundary failure: $name");
    }
}
echo "Machine timing tests passed\n";
