<?php

namespace ZX\Spectrum\Tape;

use ZX\Spectrum\Tape\Block\DataBlock;
use ZX\Spectrum\Tape\Block\DirectRecordingBlock;
use ZX\Spectrum\Tape\Block\LoopEndBlock;
use ZX\Spectrum\Tape\Block\LoopStartBlock;
use ZX\Spectrum\Tape\Block\PauseBlock;
use ZX\Spectrum\Tape\Block\PulseSequenceBlock;
use ZX\Spectrum\Tape\Block\SetLevelBlock;
use ZX\Spectrum\Tape\Block\ToneBlock;

/**
 * .tzx (https://worldofspectrum.net/TZXformat.html). Blocks with sound are converted; informational
 * blocks and the ones not supported (CSW 18, generalized data 19) are skipped by their length fields.
 */
final class TzxFormat implements TapeFormat
{
    private const string SIGNATURE = "ZXTape!\x1A";
    private const int HEADER_LENGTH = 10;

    private string $data = '';

    public function extensions(): array
    {
        return ['tzx'];
    }

    public function recognizes(string $data): bool
    {
        return str_starts_with($data, self::SIGNATURE);
    }

    public function parse(string $data): array
    {
        $this->data = $data;
        $blocks = [];
        $end = strlen($data);
        for ($p = self::HEADER_LENGTH; $p < $end;) {
            $id = ord($data[$p++]);
            switch ($id) {
                case 0x10: // standard speed data
                    $length = $this->word($p + 2);
                    $blocks[] = DataBlock::standard(substr($data, $p + 4, $length), $this->word($p));
                    $p += 4 + $length;
                    break;
                case 0x11: // turbo speed data
                    $length = $this->word24($p + 15);
                    $blocks[] = new DataBlock(
                        data: substr($data, $p + 18, $length),
                        pilot: $this->word($p),
                        pilotCount: $this->word($p + 10),
                        sync1: $this->word($p + 2),
                        sync2: $this->word($p + 4),
                        zero: $this->word($p + 6),
                        one: $this->word($p + 8),
                        lastByteBits: ord($data[$p + 12]),
                        pauseMs: $this->word($p + 13),
                        standard: false,
                    );
                    $p += 18 + $length;
                    break;
                case 0x12: // pure tone
                    $blocks[] = new ToneBlock($this->word($p), $this->word($p + 2));
                    $p += 4;
                    break;
                case 0x13: // pulse sequence
                    $count = ord($data[$p]);
                    $lengths = $count > 0 ? array_values(unpack('v*', substr($data, $p + 1, 2 * $count))) : [];
                    $blocks[] = new PulseSequenceBlock($lengths);
                    $p += 1 + 2 * $count;
                    break;
                case 0x14: // pure data
                    $length = $this->word24($p + 7);
                    $blocks[] = new DataBlock(
                        data: substr($data, $p + 10, $length),
                        pilot: 0,
                        pilotCount: 0,
                        sync1: 0,
                        sync2: 0,
                        zero: $this->word($p),
                        one: $this->word($p + 2),
                        lastByteBits: ord($data[$p + 4]),
                        pauseMs: $this->word($p + 5),
                        standard: false,
                    );
                    $p += 10 + $length;
                    break;
                case 0x15: // direct recording
                    $length = $this->word24($p + 5);
                    $blocks[] = new DirectRecordingBlock(
                        samples: substr($data, $p + 8, $length),
                        tStatesPerSample: $this->word($p),
                        lastByteBits: ord($data[$p + 4]),
                        pauseMs: $this->word($p + 2),
                    );
                    $p += 8 + $length;
                    break;
                case 0x20: // pause or "stop the tape"
                    $blocks[] = new PauseBlock($this->word($p));
                    $p += 2;
                    break;
                case 0x24: // loop start
                    $blocks[] = new LoopStartBlock($this->word($p));
                    $p += 2;
                    break;
                case 0x25: // loop end
                    $blocks[] = new LoopEndBlock();
                    break;
                case 0x2B: // set signal level
                    $blocks[] = new SetLevelBlock(ord($data[$p + 4]) & 1);
                    $p += 5;
                    break;

                    // Blocks without sound
                case 0x21: // group start
                case 0x30: // text description
                    $p += 1 + ord($data[$p]);
                    break;
                case 0x22: // group end
                case 0x27: // return from sequence
                    break;
                case 0x23: // jump
                    $p += 2;
                    break;
                case 0x26: // call sequence
                    $p += 2 + 2 * $this->word($p);
                    break;
                case 0x28: // select block
                case 0x32: // archive info
                    $p += 2 + $this->word($p);
                    break;
                case 0x31: // message
                    $p += 2 + ord($data[$p + 1]);
                    break;
                case 0x33: // hardware type
                    $p += 1 + 3 * ord($data[$p]);
                    break;
                case 0x35: // custom info
                    $p += 20 + $this->dword($p + 16);
                    break;
                case 0x5A: // glue
                    $p += 9;
                    break;
                default:
                    // 18 (CSW), 19 (generalized data), 2A and unknown blocks start with a 32-bit length.
                    if ($p + 4 > $end) {
                        break 2;
                    }
                    $p += 4 + $this->dword($p);
            }
        }
        return $blocks;
    }

    private function word(int $offset): int
    {
        return unpack('v', substr($this->data, $offset, 2))[1];
    }

    private function word24(int $offset): int
    {
        return $this->word($offset) | (ord($this->data[$offset + 2]) << 16);
    }

    private function dword(int $offset): int
    {
        return unpack('V', substr($this->data, $offset, 4))[1];
    }
}
