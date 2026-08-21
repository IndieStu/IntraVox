<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\People;

use OCA\IntraVox\Service\UserService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use Psr\Log\LoggerInterface;

/**
 * The people query a public share is allowed to run. (F6d)
 *
 * getPeopleByShare() used to call PeopleController::getPeople() — a sibling
 * controller method — passing nulls for every viewer-facing facet parameter.
 * That worked, but it made the share endpoint's safety a property of an
 * argument list: widen getPeople()'s signature, or reorder it, and the share
 * path silently gains the facet surface it was written to withhold.
 *
 * Here the two modes a share may use are the whole API. There is no faceted
 * branch to accidentally reach, because it is not in this class. A facet panel
 * on a public share is a browsable directory of the organisation — roles,
 * buildings, departments and their headcounts — handed to anyone holding the
 * link, so "cannot be reached" is worth more than "is not passed".
 */
class PeopleQuery {

    public function __construct(
        private UserService $userService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Manual mode (an explicit id list) or filter mode (an editor-configured
     * cohort). Both are what the widget itself was configured to show.
     *
     * Body is the manual/filter half of PeopleController::getPeople(),
     * verbatim, so responses are unchanged.
     */
    public function forPublicShare(
        ?string $userIds = null,
        ?string $filters = null,
        string $filterOperator = 'AND',
        string $sortBy = 'displayName',
        string $sortOrder = 'asc',
        int $limit = 50,
        int $offset = 0
    ): DataResponse {
        try {
            $users = [];
            $total = 0;
            $hasMore = false;

            // Manual mode: get specific users
            if ($userIds !== null && $userIds !== '') {
                $ids = array_filter(explode(',', $userIds));
                $allUsers = $this->userService->getUserProfiles($ids);
                $total = count($allUsers);

                // Apply sorting for manual mode
                usort($allUsers, function ($a, $b) use ($sortBy, $sortOrder) {
                    $valueA = $a[$sortBy] ?? '';
                    $valueB = $b[$sortBy] ?? '';
                    $result = strcasecmp($valueA, $valueB);
                    return $sortOrder === 'desc' ? -$result : $result;
                });

                // Apply offset and limit
                $users = array_slice($allUsers, $offset, $limit);
                $hasMore = ($offset + count($users)) < $total;
            }
            // Filter mode: get users matching filters
            elseif ($filters !== null && $filters !== '') {
                $filterArray = json_decode($filters, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return new DataResponse(
                        ['error' => 'Invalid filters JSON'],
                        Http::STATUS_BAD_REQUEST
                    );
                }

                $result = $this->userService->getUsersByFilters(
                    $filterArray,
                    $filterOperator,
                    min($limit, 100),
                    $sortBy,
                    $sortOrder,
                    $offset,
                    true // returnTotal
                );

                $users = $result['users'];
                $total = $result['total'];
                $hasMore = ($offset + count($users)) < $total;
            }

            return new DataResponse([
                'users' => $users,
                'total' => $total,
                'hasMore' => $hasMore,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Error getting people', [
                'error' => $e->getMessage()
            ]);
            return new DataResponse(
                ['error' => 'Failed to get people'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
