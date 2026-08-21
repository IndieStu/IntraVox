<?php

declare(strict_types=1);

namespace OCA\IntraVox\Controller;

use OCA\IntraVox\Controller\Shared\CalendarRequestTrait;
use OCA\IntraVox\Service\CalendarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class CalendarController extends Controller {
    use CalendarRequestTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private CalendarService $calendarService,
        private LoggerInterface $logger,
        private ?string $userId = null,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get available calendars for the current user
     *
     *
     * @return DataResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getCalendars(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $calendars = $this->calendarService->getCalendarsForUser($this->userId);

            return new DataResponse([
                'calendars' => $calendars,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Error getting calendars', [
                'error' => $e->getMessage(),
            ]);
            return new DataResponse(
                ['error' => 'Failed to get calendars'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get events from one or more calendars
     *
     *
     * @return DataResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getEvents(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $calendarIdsParam = $this->request->getParam('calendarIds', '');
            $rangeStart = $this->request->getParam('rangeStart', '');
            $rangeEnd = $this->request->getParam('rangeEnd', '');
            $limit = (int) $this->request->getParam('limit', 5);

            // Parse external ICS URLs
            $externalIcsUrls = $this->parseExternalIcsUrls($this->request->getParam('externalIcsUrls', ''));

            if (empty($calendarIdsParam) && empty($externalIcsUrls)) {
                return new DataResponse(['events' => []]);
            }

            // Parse calendar keys (string identifiers)
            $calendarIds = array_filter(explode(',', (string) $calendarIdsParam), fn($s) => $s !== '');

            $limit = min(max($limit, 1), 20);

            // Validate date parameters
            try {
                $start = new \DateTimeImmutable($rangeStart ?: 'now');
                $end = new \DateTimeImmutable($rangeEnd ?: '+30 days');
            } catch (\Exception $e) {
                return new DataResponse(
                    ['error' => 'Invalid date format'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Cap date range to 1 year max to prevent excessive recurring event expansion
            $maxEnd = $start->modify('+1 year');
            if ($end > $maxEnd) {
                $end = $maxEnd;
            }

            $events = $this->calendarService->getEvents($this->userId, $calendarIds, $start, $end, $limit, $externalIcsUrls);

            return new DataResponse([
                'events' => $events,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Error getting calendar events', [
                'error' => $e->getMessage(),
            ]);
            return new DataResponse(
                ['error' => 'Failed to get calendar events'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
