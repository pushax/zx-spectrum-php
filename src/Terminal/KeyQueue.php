<?php

namespace ZX\Spectrum\Terminal;

use ZX\Spectrum\Machine\ULA;

/**
 * Turns key events into key presses the ROM can see. Terminals send key-down characters only, so each
 * key is held for a while; keys typed quickly (or pasted) are queued and pressed one after another,
 * with the pauses the ROM keyboard routine needs.
 */
final class KeyQueue
{
    /** A key must be down at one interrupt at least before the next one replaces it. */
    private const int MIN_PRESS = 2;
    /** The ROM keeps a released key "in use" for 5 interrupts: pressing it again sooner is not a new key. */
    private const int SAME_KEY_GAP = 6;
    /** After Enter BASIC stores/lists the line and reads no keys for a while. */
    private const int ENTER_GAP = 25;
    /** Terminal auto-repeat starts after about 0.3 s; a second press of the key before that is a new press. */
    private const int AUTO_REPEAT_FRAMES = 10;
    /** Long enough for pasted text. */
    private const int MAX_QUEUE = 1024;

    /** @var list<KeyCombo> */
    private array $queue = [];
    private ?KeyCombo $current = null;
    /** Frames the current key has been held. */
    private int $heldFor = 0;
    /** Frames to wait before the next key is pressed. */
    private int $gap = 0;
    /** The current key is being auto-repeated by the terminal. */
    private bool $repeating = false;

    /** @param int $holdFrames frames a key stays pressed when nothing else is typed */
    public function __construct(private readonly int $holdFrames = 30)
    {
    }

    public function add(KeyCombo $key): void
    {
        // Auto-repeat of the held key: keep holding it, so games see a steadily pressed key.
        if (
            $this->current !== null && $key->equals($this->current) && $this->queue === []
            && ($this->repeating || $this->heldFor >= self::AUTO_REPEAT_FRAMES)
        ) {
            $this->heldFor = self::MIN_PRESS;
            $this->repeating = true;
            return;
        }
        if (count($this->queue) < self::MAX_QUEUE) {
            $this->queue[] = $key;
        }
    }

    /** Before a frame: releases the held key when the next one is waiting, presses the next one when due. */
    public function schedule(ULA $ula): void
    {
        if ($this->current !== null && $this->queue !== [] && $this->heldFor >= self::MIN_PRESS) {
            $released = $this->current;
            $released->release($ula);
            $this->current = null;
            $this->gap = match (true) {
                $released->isEnter() => self::ENTER_GAP,
                $this->queue[0]->sameKeyAs($released) => self::SAME_KEY_GAP,
                default => 1,
            };
        }
        if ($this->current === null && $this->gap <= 0 && $this->queue !== []) {
            $this->current = array_shift($this->queue);
            $this->heldFor = 0;
            $this->repeating = false;
            $this->current->press($ula);
        }
    }

    /** After a frame: counts the hold time and releases the key when it has been held long enough. */
    public function frameDone(ULA $ula): void
    {
        if ($this->gap > 0) {
            $this->gap--;
        }
        if ($this->current === null) {
            return;
        }
        $this->heldFor++;
        if ($this->heldFor >= $this->holdFrames) {
            $this->current->release($ula);
            $this->current = null;
            $this->gap = 1;
        }
    }
}
