<?php

/**
 * Downloads the ROM images the emulator uses into the project folder and checks their MD5 sums.
 *
 *   php fetch-roms.php           # download missing ROMs
 *   php fetch-roms.php --force   # download again and overwrite
 *
 * Sinclair/Amstrad ROMs come from the Fuse emulator, TR-DOS and the Pentagon ROM from ZEsarUX.
 */

const FUSE = 'https://sourceforge.net/p/fuse-emulator/fuse/ci/master/tree/roms/%s?format=raw';
const ZESARUX = 'https://raw.githubusercontent.com/chernandezba/zesarux/master/src/%s';

$roms = [
    '48.rom' => [
        sprintf(FUSE, '48.rom'),
        '4c42a2f075212361c3117015b107ff68',
        'Spectrum 48K ROM (Amstrad)',
    ],
    '128-0.rom' => [
        sprintf(FUSE, '128-0.rom'),
        'b4d2692115a9f2924df92a3cbfb358fb',
        'Spectrum 128 ROM 0, 128 menu/BASIC (Amstrad)',
    ],
    '128-1.rom' => [
        sprintf(FUSE, '128-1.rom'),
        '6e09e5d3c4aef166601669feaaadc01c',
        'Spectrum 128 ROM 1, 48 BASIC (Amstrad)',
    ],
    'trdos.rom' => [
        sprintf(ZESARUX, 'trdos.rom'),
        '0c42e3b9ab8dd91ea096f1d0c07c55e5',
        'TR-DOS 5.03 (Technology Research)',
    ],
    'pentagon.rom' => [
        sprintf(ZESARUX, 'pentagon.rom'),
        '178d780c1347cf6634471805bd10e52c',
        'Pentagon 128 ROM with TR-DOS menu item',
    ],
];

$force = in_array('--force', $argv, true);
$dir = __DIR__;
$failed = false;

foreach ($roms as $name => [$url, $md5, $title]) {
    $path = "$dir/$name";
    if (!$force && is_file($path) && md5_file($path) === $md5) {
        printf("%-13s ok (already present)\n", $name);
        continue;
    }
    if (!$force && is_file($path)) {
        // A different file with the same name (e.g. another ROM version): keep it, the user put it there.
        printf("%-13s kept: the existing file differs from the expected one (use --force to replace)\n", $name);
        continue;
    }
    $context = stream_context_create([
        'http' => ['timeout' => 30, 'follow_location' => 1, 'user_agent' => 'zx-spectrum-php-fetch-roms'],
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false || md5($data) !== $md5) {
        printf("%-13s FAILED: %s from %s\n", $name, $data === false ? 'download error' : 'checksum mismatch', $url);
        $failed = true;
        continue;
    }
    file_put_contents($path, $data);
    printf("%-13s downloaded, %s\n", $name, $title);
}

// The original Spectrum 128 as one 32K image: ROM 0 followed by ROM 1.
$rom0 = "$dir/128-0.rom";
$rom1 = "$dir/128-1.rom";
if (($force || !is_file("$dir/128.rom")) && is_file($rom0) && is_file($rom1)) {
    file_put_contents("$dir/128.rom", file_get_contents($rom0) . file_get_contents($rom1));
    printf("%-13s built from 128-0.rom + 128-1.rom\n", '128.rom');
}

exit($failed ? 1 : 0);
