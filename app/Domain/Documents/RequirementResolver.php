<?php

namespace App\Domain\Documents;

use App\Models\Order;
use LogicException;

/** Resolves the requirements an order's document must satisfy. (Owned by the document engine.) */
class RequirementResolver
{
    /**
     * @param  list<array{field:string,value:mixed,source_url:?string,source_type:string,quote:?string,verified:bool}>  $researched
     *         verified findings from the research stage
     * @param  array<string, mixed>  $customerStated  limits stated in the customer's prompt/uploads (e.g. ['max_words' => 500])
     */
    public function resolve(Order $order, array $researched = [], array $customerStated = []): ResolvedRequirements
    {
        throw new LogicException('Not implemented yet.');
    }
}
