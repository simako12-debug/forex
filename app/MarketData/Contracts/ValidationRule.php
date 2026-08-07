<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

use App\MarketData\Data\ValidationFindingData;
use App\MarketData\Enums\FindingSeverityEnum;
use Generator;

interface ValidationRule
{
    /**
     * Strop je součástí kontraktu, protože rozbitý soubor by jinak vyrobil miliony
     * nálezů. Při jeho dosažení musí pravidlo vydat souhrnný nález — tiché zahození
     * by vypadalo jako „našlo se přesně tisíc problémů".
     */
    public const int FINDING_CAP = 1000;

    public function name(): string;

    public function severity(): FindingSeverityEnum;

    /** @return Generator<int,ValidationFindingData> */
    public function findings(string $stagingTable): Generator;
}
