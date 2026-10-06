<?php

namespace App\Domain\Documents;

use App\Models\AdminUser;
use App\Models\AiJob;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\Revision;
use LogicException;

/** Creates (unrendered) document versions from an approved DocumentModel. (Owned by the document engine.) */
class DocumentFactory
{
    public function createVersion(
        Order $order,
        DocumentModel $model,
        DocumentTemplate $template,
        ResolvedRequirements $requirements,
        string $source = 'ai',
        ?AiJob $job = null,
        ?Revision $revision = null,
        ?AdminUser $admin = null,
        ?float $qualityScore = null,
    ): DocumentVersion {
        throw new LogicException('Not implemented yet.');
    }
}
