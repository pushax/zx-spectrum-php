<?php

/**
 * Runs the ZEXDOC Z80 instruction exerciser (CP/M .com, not bundled) on the Z80 core.
 *
 *   php tests/zexdoc.php path/to/zexdoc.com            # all tests (slow: hours in PHP)
 *   php tests/zexdoc.php path/to/zexdoc.com --list     # list test names with their numbers
 *   php tests/zexdoc.php path/to/zexdoc.com --test=N   # run only test N (0-based), for parallel runs
 */

namespace ZX\Spectrum;

use ZX\Spectrum\Cpu\MemoryInterface;
use ZX\Spectrum\Cpu\Z80;

require __DIR__ . '/../src/autoload.php';

/** Flat 64K RAM, as CP/M programs expect. */
final class FlatRam implements MemoryInterface
{
    /** @var array<int,int> */
    public array $bytes;

    public function __construct()
    {
        $this->bytes = array_fill(0, 65536, 0);
    }

    public function read(int $address): int
    {
        return $this->bytes[$address & 0xFFFF];
    }

    public function write(int $address, int $value): void
    {
        $this->bytes[$address & 0xFFFF] = $value & 0xFF;
    }

    public function readWord(int $address): int
    {
        return $this->read($address) | ($this->read($address + 1) << 8);
    }

    public function writeWord(int $address, int $value): void
    {
        $this->write($address, $value & 0xFF);
        $this->write($address + 1, $value >> 8);
    }
}

$file = $argv[1] ?? '';
$image = $file !== '' ? @file_get_contents($file) : false;
if ($image === false) {
    fwrite(STDERR, "Usage: php tests/zexdoc.php zexdoc.com [--list|--test=N]\n");
    exit(2);
}

const TEST_TABLE = 0x013A; // zexdoc's list of test descriptors, terminated by 0x0000

$mem = new FlatRam();
foreach (str_split($image) as $i => $ch) {
    $mem->bytes[0x100 + $i] = ord($ch);
}
$mem->bytes[0x0005] = 0xC9;          // BDOS entry: RET (calls are trapped below)
$mem->writeWord(0x0006, 0xF000);     // top of TPA, zexdoc sets SP from here

$tests = [];
for ($a = TEST_TABLE; ($p = $mem->readWord($a)) !== 0; $a += 2) {
    $name = '';
    for ($q = $p + 65; ($c = $mem->read($q)) !== 0x24; $q++) {
        $name .= chr($c);
    } // descriptor: mask, base, increment, shift, CRC (65 bytes), then '$'-terminated name
    $tests[] = [$a, $p, rtrim($name, '.')];
}
// getopt() stops at the first positional argument, so parse the options after the file name by hand.
$opts = [];
foreach (array_slice($argv, 2) as $arg) {
    if ($arg === '--list') {
        $opts['list'] = true;
    } elseif (str_starts_with($arg, '--test=')) {
        $opts['test'] = substr($arg, 7);
    }
}
if (isset($opts['list'])) {
    foreach ($tests as $i => [, , $n]) {
        echo "$i $n\n";
    } exit(0);
}
if (isset($opts['test'])) {
    $n = (int)$opts['test'];
    if (!isset($tests[$n])) {
        fwrite(STDERR, "No test $n\n");
        exit(2);
    }
    $mem->writeWord(TEST_TABLE, $tests[$n][1]);
    $mem->writeWord(TEST_TABLE + 2, 0);
}

$cpu = new Z80($mem);
$cpu->pc = 0x0100;
$cpu->sp = 0xF000;
$output = '';
while (true) {
    if ($cpu->pc === 0x0005) {
        if ($cpu->c === 2) {
            $output .= chr($cpu->e);
        } elseif ($cpu->c === 9) {
            for ($q = ($cpu->d << 8) | $cpu->e; ($ch = $mem->read($q)) !== 0x24; $q++) {
                $output .= chr($ch);
            }
        }
        echo substr($output, 0);
        $output = '';
    } elseif ($cpu->pc === 0x0000) {
        break;
    }
    $cpu->step();
}
echo "\n";
