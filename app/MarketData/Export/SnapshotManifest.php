<?php

declare(strict_types=1);

namespace App\MarketData\Export;

final readonly class SnapshotManifest
{
    /**
     * @param array<int,int> $years
     * @param array<string,int> $rowCounts
     */
    public function __construct(
        public int $adjustmentLogicVersion,
        public string $exportedAt,
        public array $years,
        public array $rowCounts,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'adjustment_logic_version' => $this->adjustmentLogicVersion,
            'exported_at' => $this->exportedAt,
            'years' => $this->years,
            'row_counts' => $this->rowCounts,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var array<int,int> $years */
        $years = $payload['years'];
        /** @var array<string,int> $rowCounts */
        $rowCounts = $payload['row_counts'];

        $adjustmentLogicVersion = $payload['adjustment_logic_version'];
        $exportedAt = $payload['exported_at'];

        return new self(
            adjustmentLogicVersion: (int) $adjustmentLogicVersion, // @phpstan-ignore-line
            exportedAt: (string) $exportedAt, // @phpstan-ignore-line
            years: $years,
            rowCounts: $rowCounts,
        );
    }
}
