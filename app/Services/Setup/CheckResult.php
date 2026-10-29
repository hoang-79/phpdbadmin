<?php

namespace App\Services\Setup;

/**
 * Ein Punkt der Systemprüfung (B-05): erfüllt, Hinweis oder Hindernis — mit dem nächsten Schritt, falls etwas fehlt.
 */
final readonly class CheckResult
{
    public function __construct(
        public CheckLevel $level,
        public string $title,
        public ?string $hint = null,
    ) {}

    public static function ok(string $title): self
    {
        return new self(CheckLevel::Ok, $title);
    }

    public static function warn(string $title, string $hint): self
    {
        return new self(CheckLevel::Warn, $title, $hint);
    }

    public static function fail(string $title, string $hint): self
    {
        return new self(CheckLevel::Fail, $title, $hint);
    }
}
