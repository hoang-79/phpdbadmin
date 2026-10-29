<?php

namespace App\Services\Transfer;

/**
 * Bytes für eine Binärspalte. Gebunden wird jedes Mal ein frischer Strom (PDO::PARAM_LOB) — als Text gebunden
 * landeten sie bei PostgreSQL gar nicht (Nullbyte) und bei SQLite als TEXT statt BLOB.
 */
final readonly class BinaryValue
{
    public function __construct(public string $bytes) {}

    /**
     * @return resource
     */
    public function stream()
    {
        $stream = fopen('php://memory', 'r+b');
        assert($stream !== false);
        fwrite($stream, $this->bytes);
        rewind($stream);

        return $stream;
    }
}
