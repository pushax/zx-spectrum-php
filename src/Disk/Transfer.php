<?php

namespace ZX\Spectrum\Disk;

/** The data transfer in progress on the WD1793. */
enum Transfer
{
    case None;
    /** Sector, address or track data to the CPU. */
    case Read;
    case WriteSector;
    /** Formatting: the CPU sends a whole raw track. */
    case WriteTrack;
}
