<?php

namespace ZX\Spectrum\Terminal\Image;

use ZX\Spectrum\Video\Palette;

/** Minimal PNG encoder for palette-indexed Spectrum pictures (8 bits per pixel, 16 colours). */
final class PngEncoder
{
    private const string SIGNATURE = "\x89PNG\r\n\x1A\n";
    /** Fast compression: the picture is sent 50 times per second. */
    private const int COMPRESSION_LEVEL = 1;

    /** @param list<string> $rows one string per image row, one byte (palette index) per pixel */
    public static function encode(array $rows, int $width, int $height): string
    {
        $palette = '';
        for ($index = 0; $index < 16; $index++) {
            $palette .= pack('C3', ...Palette::rgb($index));
        }
        $raw = "\0" . implode("\0", $rows); // filter type 0 (none) before every row
        return self::SIGNATURE
            . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 3, 0, 0, 0)) // 8-bit, palette colour
            . self::chunk('PLTE', $palette)
            . self::chunk('IDAT', (string)gzcompress($raw, self::COMPRESSION_LEVEL))
            . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
