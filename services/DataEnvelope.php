<?php

declare(strict_types=1);

/**
 * Every provider (weather, flood, routing, geocoding) returns data wrapped
 * in one of these envelopes so the frontend can always tell the user
 * exactly what kind of data they're looking at. This is the single most
 * important contract in the app: nothing about the data's provenance
 * should ever depend on the caller "just knowing" which provider ran.
 */
final class DataEnvelope
{
    public const REAL_TIME = 'REAL_TIME';
    public const DEMO = 'DEMO';
    public const STALE = 'STALE';
    public const UNAVAILABLE = 'UNAVAILABLE';

    /**
     * @param mixed $data null when status is UNAVAILABLE
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $sourceName,
        public readonly ?string $sourceUrl,
        public readonly ?string $retrievedAt,
        public readonly mixed $data,
        public readonly ?string $note = null,
    ) {
    }

    public static function realTime(string $sourceName, ?string $sourceUrl, mixed $data, ?string $note = null): self
    {
        return new self(self::REAL_TIME, $sourceName, $sourceUrl, date('c'), $data, $note);
    }

    public static function demo(string $sourceName, mixed $data, ?string $note = null): self
    {
        return new self(
            self::DEMO,
            $sourceName,
            null,
            date('c'),
            $data,
            $note ?? 'This is illustrative demo data, not a live feed. Do not use it to make real safety decisions.'
        );
    }

    public static function stale(string $sourceName, ?string $sourceUrl, mixed $data, string $retrievedAt, ?string $note = null): self
    {
        return new self($sourceStatus = self::STALE, $sourceName, $sourceUrl, $retrievedAt, $data, $note ?? 'Last successful update was longer ago than usual. Treat this as outdated.');
    }

    public static function unavailable(string $sourceName, ?string $reason = null): self
    {
        return new self(self::UNAVAILABLE, $sourceName, null, null, null, $reason ?? 'Data source did not respond.');
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'source_name' => $this->sourceName,
            'source_url' => $this->sourceUrl,
            'retrieved_at' => $this->retrievedAt,
            'note' => $this->note,
            'data' => $this->data,
        ];
    }
}
