<?php
declare(strict_types=1);

namespace OCA\IntraVox\Controller;

use OCA\IntraVox\Service\Filter\FacetCalculator;
use OCA\IntraVox\Service\Filter\FilterSpec;
use OCA\IntraVox\Service\PublicShareService;
use OCA\IntraVox\Service\UserService;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\AnonRateThrottle;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Controller for the People widget API
 *
 * Provides endpoints for:
 * - Searching users for manual selection
 * - Getting user profiles
 * - Filtering users by groups/attributes
 * - Getting available groups and fields
 */
class PeopleController extends Controller {
    /** Reject oversized filter payloads before json_decode, as FileStoryController does. */
    private const MAX_FILTER_JSON_BYTES = 16384;

    /** Operators a viewer is allowed to send. Deliberately narrower than the
     *  editor's vocabulary: a facet panel only ever needs set membership and
     *  presence, and a smaller surface is a smaller thing to get wrong. */
    private const ALLOWED_REFINE_OPS = ['equals', 'in', 'contains', 'not_empty', 'empty'];

    private const MAX_REFINEMENTS = 12;
    private const MAX_REFINE_VALUES = 64;
    private const MAX_VALUE_LENGTH = 200;
    private const MAX_FACETS = 12;
    private const MAX_SEARCH_FIELDS = 8;
    private const MAX_QUERY_LENGTH = 128;

    /** Field names must look like field names. */
    private const FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/i';

    public function __construct(
        string $appName,
        IRequest $request,
        private UserService $userService,
        private PublicShareService $publicShareService,
        private LoggerInterface $logger,
        private ?IActivityManager $activityManager = null,
        private ?IURLGenerator $urlGenerator = null,
        private ?IConfig $config = null
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Search users by name or email
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $query Search query
     * @param int $limit Maximum results (default 20)
     * @return DataResponse
     */
    public function searchUsers(string $query = '', int $limit = 20): DataResponse {
        try {
            if (strlen($query) < 2) {
                return new DataResponse([
                    'users' => [],
                    'message' => 'Query must be at least 2 characters'
                ]);
            }

            $users = $this->userService->searchUsers($query, min($limit, 50));

            return new DataResponse([
                'users' => $users
            ]);
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Error searching users', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            return new DataResponse(
                ['error' => 'Failed to search users'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get user profiles by IDs
     *
     * @NoAdminRequired
     *
     * @param array $userIds Array of user IDs
     * @return DataResponse
     */
    public function getUsers(array $userIds = []): DataResponse {
        try {
            if (empty($userIds)) {
                return new DataResponse([
                    'users' => []
                ]);
            }

            // Limit to prevent abuse
            $userIds = array_slice($userIds, 0, 100);
            $users = $this->userService->getUserProfiles($userIds);

            return new DataResponse([
                'users' => $users
            ]);
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Error getting users', [
                'userIds' => $userIds,
                'error' => $e->getMessage()
            ]);
            return new DataResponse(
                ['error' => 'Failed to get users'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get available groups for filtering
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return DataResponse
     */
    public function getGroups(): DataResponse {
        try {
            $groups = $this->userService->getGroups();

            return new DataResponse([
                'groups' => $groups
            ]);
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Error getting groups', [
                'error' => $e->getMessage()
            ]);
            return new DataResponse(
                ['error' => 'Failed to get groups'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get available user profile fields for filtering/display configuration
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return DataResponse
     */
    public function getUserFields(): DataResponse {
        try {
            $fields = $this->userService->getAvailableFields();

            return new DataResponse([
                'fields' => $fields
            ]);
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Error getting user fields', [
                'error' => $e->getMessage()
            ]);
            return new DataResponse(
                ['error' => 'Failed to get user fields'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get people for widget display (supports both manual and filter modes)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string|null $userIds Comma-separated user IDs for manual mode
     * @param string|null $filters JSON-encoded filters for filter mode
     * @param string $filterOperator 'AND' or 'OR'
     * @param string $sortBy Field to sort by
     * @param string $sortOrder 'asc' or 'desc'
     * @param int $limit Maximum results
     * @param int $offset Offset for pagination
     * @return DataResponse
     */
    public function getPeople(
        ?string $userIds = null,
        ?string $filters = null,
        string $filterOperator = 'AND',
        string $sortBy = 'displayName',
        string $sortOrder = 'asc',
        int $limit = 50,
        int $offset = 0,
        ?string $refine = null,
        ?string $facets = null,
        string $q = '',
        ?string $searchFields = null,
        int $facetLimit = FacetCalculator::DEFAULT_FACET_LIMIT
    ): DataResponse {
        try {
            $users = [];
            $total = 0;
            $hasMore = false;

            // Faceted mode: the viewer is narrowing an editor-configured
            // cohort. Distinct from filter mode below, which is the legacy
            // editor-only path and stays untouched.
            $wantsFacets = ($refine !== null && $refine !== '')
                || ($facets !== null && $facets !== '')
                || $q !== '';

            if ($wantsFacets && ($userIds === null || $userIds === '')) {
                $editorFilters = [];
                if ($filters !== null && $filters !== '') {
                    $editorFilters = $this->decodeFilterJson($filters);
                    if ($editorFilters === null) {
                        return new DataResponse(['error' => 'Invalid filters JSON'], Http::STATUS_BAD_REQUEST);
                    }
                }

                $refinements = [];
                if ($refine !== null && $refine !== '') {
                    $refinements = $this->decodeFilterJson($refine);
                    if ($refinements === null) {
                        return new DataResponse(['error' => 'Invalid refine JSON'], Http::STATUS_BAD_REQUEST);
                    }
                    $refinements = $this->sanitizeRefinements($refinements);
                }

                $result = $this->userService->queryFaceted(
                    $editorFilters,
                    $filterOperator,
                    $refinements,
                    $this->parseFieldList($facets, self::MAX_FACETS),
                    mb_substr(trim($q), 0, self::MAX_QUERY_LENGTH),
                    $this->parseFieldList($searchFields, self::MAX_SEARCH_FIELDS),
                    min($limit, 100),
                    $offset,
                    $sortBy,
                    $sortOrder,
                    max(1, min($facetLimit, 100))
                );

                return new DataResponse($result);
            }

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

    /**
     * Get people for widget display via public share link
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $token Share token
     * @param string|null $userIds Comma-separated user IDs for manual mode
     * @param string|null $filters JSON-encoded filters for filter mode
     * @param string $filterOperator 'AND' or 'OR'
     * @param string $sortBy Field to sort by
     * @param string $sortOrder 'asc' or 'desc'
     * @param int $limit Maximum results
     * @param int $offset Offset for pagination
     * @return DataResponse
     */
    #[AnonRateThrottle(limit: 30, period: 60)]
    public function getPeopleByShare(
        string $token,
        ?string $userIds = null,
        ?string $filters = null,
        string $filterOperator = 'AND',
        string $sortBy = 'displayName',
        string $sortOrder = 'asc',
        int $limit = 50,
        int $offset = 0
    ): DataResponse {
        try {
            // Validate share token
            // NOTE: this previously called validateShareToken(), which does
            // not exist on PublicShareService — every request to this
            // endpoint died with a 500. getShareByToken() is the real method
            // and matches the null check below.
            $shareInfo = $this->publicShareService->getShareByToken($token);
            if ($shareInfo === null) {
                return new DataResponse(
                    ['error' => 'Invalid or expired share token'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Removing the widget from the shared page is the primary
            // guard, but this endpoint is reachable on its own, so refuse
            // here too rather than trusting the page to be the only route.
            if (!$this->peopleAllowedOnPublicShares()) {
                return new DataResponse(['users' => [], 'total' => 0, 'hasMore' => false]);
            }

            // Use the same logic as getPeople, with the viewer-facing
            // parameters explicitly nulled.
            //
            // This is deliberate rather than incidental: a facet panel on a
            // public share is a browsable directory of the organisation —
            // roles, buildings, departments and their headcounts — handed to
            // anyone holding the link. Relying on the delegate to "just not
            // pass them on" would make that a one-refactor-away accident.
            return $this->getPeople(
                $userIds,
                $filters,
                $filterOperator,
                $sortBy,
                $sortOrder,
                $limit,
                $offset,
                null,   // refine
                null,   // facets
                '',     // q
                null,   // searchFields
                FacetCalculator::DEFAULT_FACET_LIMIT
            );
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Error getting people by share', [
                'token' => substr($token, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
            return new DataResponse(
                ['error' => 'Failed to get people'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Tell the editor whether facet counts will be exact on this instance.
     *
     * Worth answering while a widget is being configured rather than letting
     * an editor discover approximate counts in production.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function facetPreflight(): DataResponse {
        try {
            $stats = $this->userService->facetPreflight();
            return new DataResponse($stats);
        } catch (\Exception $e) {
            $this->logger->warning('IntraVox: facet preflight failed', ['error' => $e->getMessage()]);
            return new DataResponse(['userCount' => 0, 'cap' => 0, 'approximate' => false]);
        }
    }

    /**
     * Whether an admin has allowed People data on public share links.
     *
     * Same default as ApiController: withheld unless deliberately enabled.
     */
    private function peopleAllowedOnPublicShares(): bool {
        return $this->config !== null
            && $this->config->getAppValue('intravox', 'public_share_allow_people', 'no') === 'yes';
    }

    /**
     * Decode a filter JSON payload, rejecting oversized or malformed input.
     *
     * Size is checked before decoding so a pathological payload cannot cost
     * us a parse. Same approach as FileStoryController::files().
     *
     * @return array|null null when the payload is unusable
     */
    private function decodeFilterJson(string $json): ?array {
        if (strlen($json) > self::MAX_FILTER_JSON_BYTES) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Clamp viewer refinements to something safe.
     *
     * Rows with an unknown operator are dropped rather than coerced: an
     * operator we do not understand must never widen the result set.
     */
    private function sanitizeRefinements(array $rows): array {
        $out = [];

        foreach (FilterSpec::normalizeList($rows) as $row) {
            if (count($out) >= self::MAX_REFINEMENTS) {
                break;
            }
            if (!preg_match(self::FIELD_PATTERN, $row['field'])) {
                continue;
            }
            if (!in_array($row['op'], self::ALLOWED_REFINE_OPS, true)) {
                continue;
            }

            $value = $row['value'];
            if (is_array($value)) {
                $value = array_slice(array_values(array_filter(
                    array_map(
                        static fn($v): ?string => is_scalar($v)
                            ? mb_substr(trim((string)$v), 0, self::MAX_VALUE_LENGTH)
                            : null,
                        $value
                    ),
                    static fn(?string $v): bool => $v !== null && $v !== ''
                )), 0, self::MAX_REFINE_VALUES);

                if ($value === []) {
                    continue;
                }
            } elseif (is_scalar($value)) {
                $value = mb_substr(trim((string)$value), 0, self::MAX_VALUE_LENGTH);
            } elseif ($value !== null) {
                continue;
            }

            $out[] = ['field' => $row['field'], 'op' => $row['op'], 'value' => $value];
        }

        return $out;
    }

    /**
     * Parse a comma-separated field list.
     *
     * @return array<int, string>
     */
    private function parseFieldList(?string $raw, int $max): array {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $fields = [];
        foreach (explode(',', $raw) as $candidate) {
            $field = trim($candidate);
            if ($field === '' || !preg_match(self::FIELD_PATTERN, $field)) {
                continue;
            }
            $fields[] = FilterSpec::aliasField($field);
            if (count($fields) >= $max) {
                break;
            }
        }

        return array_values(array_unique($fields));
    }
}
