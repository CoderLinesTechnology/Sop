<?php

namespace App\Domain\Documents;

use App\Models\DocumentVersion;
use LogicException;

/** Validates rendered files before delivery. (Owned by the document engine.) */
class DocumentQa
{
    public function validate(DocumentVersion $version): QaResult
    {
        throw new LogicException('Not implemented yet.');
    }
}
