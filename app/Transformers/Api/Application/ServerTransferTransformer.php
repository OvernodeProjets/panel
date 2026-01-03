<?php

namespace Pterodactyl\Transformers\Api\Application;

use Pterodactyl\Models\ServerTransfer;

class ServerTransferTransformer extends BaseTransformer
{
    /**
     * Return the resource name for the JSONAPI output.
     */
    public function getResourceName(): string
    {
        return ServerTransfer::RESOURCE_NAME;
    }

    /**
     * Return a generic transformed server_transfer array.
     */
    public function transform(ServerTransfer $transfer): array
    {
        return [
            'id' => $transfer->getKey(),
            'server_id' => $transfer->server_id,
            'old_node' => $transfer->old_node,
            'new_node' => $transfer->new_node,
            'old_allocation' => $transfer->old_allocation,
            'new_allocation' => $transfer->new_allocation,
            'old_additional_allocations' => $transfer->old_additional_allocations,
            'new_additional_allocations' => $transfer->new_additional_allocations,
            'successful' => $transfer->successful,
            'archived' => $transfer->archived,
            'created_at' => $this->formatTimestamp($transfer->created_at),
            'updated_at' => $this->formatTimestamp($transfer->updated_at),
        ];
    }
}
