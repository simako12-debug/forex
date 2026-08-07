<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Bulk;

use App\MarketData\Models\IngestRun;

class BulkFileRegistry
{
    /** hash_file čte streamovaně, takže ani u několikagigového dumpu nenaroste paměť. */
    public function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw InvalidCsvHeaderException::forHeader($path, []);
        }

        return $hash;
    }

    public function alreadyImported(string $hash): bool
    {
        return IngestRun::completedForFileHash($hash);
    }
}
