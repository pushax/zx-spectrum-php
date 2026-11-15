<?php

namespace ZX\Spectrum\Terminal\Image;

/**
 * Kitty graphics protocol (kitty, Ghostty, WezTerm, Warp): image id 1, placement 1, replaced in place
 * on every frame; the data is sent in 4096-byte chunks.
 */
final class KittyProtocol implements ImageProtocol
{
    private const int CHUNK = 4096;

    public function show(string $png, int $width, int $height): string
    {
        $chunks = str_split(base64_encode($png), self::CHUNK);
        $last = count($chunks) - 1;
        $out = '';
        foreach ($chunks as $n => $chunk) {
            $more = $n < $last ? 1 : 0;
            $head = $n === 0 ? "a=T,f=100,i=1,p=1,q=2,C=1,m=$more" : "m=$more";
            $out .= "\033_G$head;$chunk\033\\";
        }
        return $out;
    }

    public function clear(): string
    {
        return "\033_Ga=d,d=I,i=1,q=2\033\\";
    }
}
