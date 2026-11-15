<?php

namespace ZX\Spectrum\Media;

/**
 * Picks the format of a file: first by signature, then by extension.
 *
 * @template T of MediaFormat
 */
final readonly class FormatRegistry
{
    /**
     * @param string $kind what the files are, for error messages ("snapshot", "tape image", ...)
     * @param list<T> $formats
     */
    public function __construct(private string $kind, private array $formats)
    {
    }

    /** Reads a file of this kind. */
    public function read(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Cannot read {$this->kind} '$path'.");
        }
        return $data;
    }

    /** @return T */
    public function select(string $path, string $data): MediaFormat
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $format = array_find($this->formats, fn (MediaFormat $f) => $f->recognizes($data))
            ?? array_find($this->formats, fn (MediaFormat $f) => in_array($extension, $f->extensions(), true));
        if ($format === null) {
            $extensions = array_merge(...array_map(fn (MediaFormat $f) => $f->extensions(), $this->formats));
            throw new \RuntimeException(sprintf(
                'Unsupported %s: use %s.',
                $this->kind,
                implode(' or ', array_map(fn (string $e) => ".$e", $extensions))
            ));
        }
        return $format;
    }
}
