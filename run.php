<?php

/**
 * ZX Spectrum emulator, command-line front end. Run without arguments for the usage summary.
 */

require __DIR__ . '/src/autoload.php';

use ZX\Spectrum\Cli\CliError;
use ZX\Spectrum\Cli\Launcher;

$launcher = new Launcher(getopt('', Launcher::OPTIONS), __DIR__ . '/roms');
try {
    exit($launcher->run());
} catch (CliError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit($e->getCode());
}
