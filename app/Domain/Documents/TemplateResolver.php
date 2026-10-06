<?php

namespace App\Domain\Documents;

use App\Models\DocumentTemplate;
use App\Models\Order;
use LogicException;

/** Picks the most appropriate formatting template for an order. (Owned by the document engine.) */
class TemplateResolver
{
    public function resolve(Order $order, ResolvedRequirements $requirements): DocumentTemplate
    {
        throw new LogicException('Not implemented yet.');
    }
}
