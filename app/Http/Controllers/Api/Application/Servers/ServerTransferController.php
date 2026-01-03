<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Servers;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Allocation;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\ServerTransfer;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Repositories\Eloquent\NodeRepository;
use Pterodactyl\Repositories\Wings\DaemonTransferRepository;
use Pterodactyl\Contracts\Repository\AllocationRepositoryInterface;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Pterodactyl\Http\Requests\Api\Application\Servers\ServerWriteRequest;
use Pterodactyl\Transformers\Api\Application\ServerTransferTransformer;

class ServerTransferController extends ApplicationApiController
{
    /**
     * ServerTransferController constructor.
     */
    public function __construct(
        private AllocationRepositoryInterface $allocationRepository,
        private ConnectionInterface $connection,
        private DaemonTransferRepository $daemonTransferRepository,
        private NodeJWTService $nodeJWTService,
        private NodeRepository $nodeRepository,
    ) {
        parent::__construct();
    }

    /**
     * Starts a transfer of a server to a new node.
     *
     * @throws \Throwable
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    public function store(ServerWriteRequest $request, Server $server): JsonResponse
    {
        $validatedData = $request->validate([
            'node_id' => 'required|exists:nodes,id',
            'allocation_additional' => 'nullable|array',
            'allocation_additional.*' => 'integer',
        ]);

        $node_id = $validatedData['node_id'];
        $additional_allocations = array_map('intval', $validatedData['allocation_additional'] ?? []);

        // Check if the node is viable for the transfer.
        $node = $this->nodeRepository->getNodeWithResourceUsage($node_id);
        if (!$node->isViable($server->memory, $server->disk)) {
            throw new \Pterodactyl\Exceptions\DisplayException('The selected node does not have enough resources to host this server.');
        }

        // Auto-select a random allocation on the new node
        $allocation = $this->allocationRepository->getRandomAllocation([$node_id], []);
        if (is_null($allocation)) {
            throw new \Pterodactyl\Exceptions\DisplayException('No allocations are available on the selected node wihout ports.');
        }
        $allocation_id = $allocation->id;

        $server->validateTransferState();

        $transfer = $this->connection->transaction(function () use ($server, $node_id, $allocation_id, $additional_allocations) {
            // Create a new ServerTransfer entry.
            $transfer = new ServerTransfer();

            $transfer->server_id = $server->id;
            $transfer->old_node = $server->node_id;
            $transfer->new_node = $node_id;
            $transfer->old_allocation = $server->allocation_id;
            $transfer->new_allocation = $allocation_id;
            $transfer->old_additional_allocations = $server->allocations->where('id', '!=', $server->allocation_id)->pluck('id')->values()->toArray();
            $transfer->new_additional_allocations = $additional_allocations;

            $transfer->save();

            // Add the allocations to the server, so they cannot be automatically assigned while the transfer is in progress.
            $this->assignAllocationsToServer($server, $node_id, $allocation_id, $additional_allocations);

            // Generate a token for the destination node that the source node can use to authenticate with.
            $token = $this->nodeJWTService
                ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
                ->setSubject($server->uuid)
                ->handle($transfer->newNode, $server->uuid, 'sha256');

            // Notify the source node of the pending outgoing transfer.
            $this->daemonTransferRepository->setServer($server)->notify($transfer->newNode, $token);

            return $transfer;
        });

        return $this->fractal->item($transfer)
            ->transformWith($this->getTransformer(ServerTransferTransformer::class))
            ->respond(201);
    }

    /**
     * Assigns the specified allocations to the specified server.
     *
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    private function assignAllocationsToServer(Server $server, int $node_id, int $allocation_id, array $additional_allocations)
    {
        $allocations = $additional_allocations;

        // Use optimistic locking to ensure the primary allocation is still available.
        // We update where id = allocation_id AND server_id IS NULL.
        // If 0 rows are updated, it means it's already taken.
        $updated = Allocation::where('id', $allocation_id)
            ->whereNull('server_id')
            ->update(['server_id' => $server->id]);

        if ($updated !== 1) {
            throw new \Pterodactyl\Exceptions\DisplayException('The selected allocation is no longer available. Please try again.');
        }

        $unassigned = $this->allocationRepository->getUnassignedAllocationIds($node_id);

        $updateIds = [];
        foreach ($allocations as $allocation) {
            if (!in_array($allocation, $unassigned)) {
                continue;
            }

            $updateIds[] = $allocation;
        }

        if (!empty($updateIds)) {
            $this->allocationRepository->updateWhereIn('id', $updateIds, ['server_id' => $server->id]);
        }
    }
}
