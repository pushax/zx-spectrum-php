<?php

namespace ZX\Spectrum\Terminal;

/** Thrown when the user presses Ctrl+C in the emulator window. */
final class StopRequested extends \RuntimeException
{
}
