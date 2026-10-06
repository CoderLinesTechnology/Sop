<?php

namespace App\Domain\Documents;

use App\Models\DocumentVersion;
use LogicException;

/** Renders the PDF and DOCX of a version from its DocumentModel and template snapshot. (Owned by the document engine.) */
class DocumentRenderer
{
    public function render(DocumentVersion $version): DocumentVersion
    {
        throw new LogicException('Not implemented yet.');
    }
}
