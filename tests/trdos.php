<?php

namespace ZX\Spectrum;

use ZX\Spectrum\Disk\BetaDisk;
use ZX\Spectrum\Disk\SclFormat;
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

/** SCL with a BASIC "boot": 10 POKE 32768,42 / 20 BORDER 2 / 30 GO TO 30, autostart at line 10. */
function bootScl(): string
{
    $num = fn (int $n) => $n . "\x0E\x00\x00" . pack('v', $n) . "\x00";
    $line = function (int $no, string $body): string {
        $body .= "\x0D";
        return pack('n', $no) . pack('v', strlen($body)) . $body;
    };
    $prog = $line(10, "\xF4" . $num(32768) . ',' . $num(42))
        . $line(20, "\xE7" . $num(2))
        . $line(30, "\xEC" . $num(30));
    $data = $prog . "\x80\xAA" . pack('v', 10);
    $sectors = (int)ceil(strlen($data) / 256);
    $entry = str_pad('boot', 8) . 'B' . pack('v', strlen($prog)) . pack('v', strlen($prog)) . chr($sectors);
    $scl = 'SINCLAIR' . chr(1) . $entry . str_pad($data, $sectors * 256, "\0");
    return $scl . pack('V', array_sum(array_map('ord', str_split($scl))));
}

$tmp = sys_get_temp_dir() . '/zx-trdos-test-' . getmypid() . '.scl';
file_put_contents($tmp, bootScl());

// SCL → TRD: catalogue entry, system sector and file data.
$trd = SclFormat::toTrd(bootScl());
check(substr($trd, 0, 9) === 'boot    B', 'SCL→TRD: catalogue entry');
check(ord($trd[14]) === 0 && ord($trd[15]) === 1, 'SCL→TRD: file starts at track 1, sector 0');
check(ord($trd[0x8E3]) === 0x16 && ord($trd[0x8E4]) === 1 && ord($trd[0x8E7]) === 0x10, 'SCL→TRD: system sector');
check(ord($trd[0x8E1]) === 1 && ord($trd[0x8E2]) === 1, 'SCL→TRD: first free sector');
check(substr($trd, 4096, 2) === "\x00\x0A", 'SCL→TRD: file data on track 1');

// WD1793: seek to cylinder 0, select side 1 (system register bit 4 = 0), read sector 1 = logical track 1.
$fdc = new BetaDisk();
$fdc->insert($tmp);
$fdc->write(0xFF, 0x2C, 0);          // drive A, side 1, not in reset
$fdc->write(0x1F, 0x08, 0);          // restore
check(($fdc->read(0xFF, 10) & 0x80) !== 0, 'WD1793: INTRQ after restore');
check(($fdc->read(0x1F, 10) & 0x04) !== 0, 'WD1793: track 0 status bit');
$fdc->write(0x5F, 1, 20);
$fdc->write(0x1F, 0x80, 20);         // read sector
$bytes = '';
while (($fdc->read(0xFF, 30) & 0x40) !== 0) {
    $bytes .= chr($fdc->read(0x7F, 30));
}
check($bytes === substr($trd, 4096, 256), 'WD1793: read sector returns the image data');
check(($fdc->read(0xFF, 40) & 0x80) !== 0, 'WD1793: INTRQ after the last byte');
$fdc->write(0x1F, 0xF0, 50);         // write track, never fed: must end with lost data after one revolution
check(($fdc->read(0xFF, 60) & 0x80) === 0, 'WD1793: write track waits for data');
check(
    ($fdc->read(0xFF, 800000) & 0x80) !== 0 && ($fdc->read(0x1F, 800000) & 0x04) !== 0,
    'WD1793: unfed write track ends with lost data'
);

// Full boot with the real ROMs (skipped when they are not present).
$rom48 = __DIR__ . '/../roms/48.rom';
$trdosRom = __DIR__ . '/../roms/trdos.rom';
if (is_file($rom48) && is_file($trdosRom)) {
    $m = new Spectrum48($rom48, MachineTiming::named('pentagon'), true);
    $m->attachBetaDisk($trdosRom);
    $m->beta->insert($tmp);
    $m->resetToTrdos();
    for ($f = 0; $f < 200; $f++) {
        $m->runFrame();
    }
    check(
        $m->memory->read(0x8000) === 42 && $m->ula->border === 2,
        'TR-DOS: reset should load and run the "boot" file from the disk'
    );
    check(!$m->memory->trdosActive, 'TR-DOS ROM should be paged out while BASIC runs');
} else {
    echo "TR-DOS boot test skipped (roms/48.rom / roms/trdos.rom not found)\n";
}
@unlink($tmp);
echo "TR-DOS tests passed\n";
