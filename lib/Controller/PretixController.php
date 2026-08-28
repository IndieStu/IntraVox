<?php
declare(strict_types=1);

namespace OCA\IntraVox\Controller;

use OCA\IntraVox\Service\PretixService;
use OCA\IntraVox\Service\PermissionService;
use OCA\IntraVox\Service\PageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

final class PretixController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private PretixService $pretix,
        private PermissionService $permissions,
        private PageService $pages,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private ?string $userId = null,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function getWidgetData(): DataResponse {
        if ($this->userId === null || !$this->permissions->hasAccess($this->userId)) {
            return new DataResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $pageId = (string)$this->request->getParam('pageId', '');
            if ($pageId === '') {
                return new DataResponse(['error' => 'Page is required'], Http::STATUS_BAD_REQUEST);
            }
            $page = $this->pages->getPage($pageId);
            if (!($page['permissions']['canRead'] ?? false)
                || ($this->pages->isHiddenFromReaders($page) && !($page['permissions']['canWrite'] ?? false))) {
                return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
            }
            return new DataResponse($this->pretix->getWidgetData(
                (string)$this->request->getParam('organizer', ''),
                (string)$this->request->getParam('event', ''),
                (int)$this->request->getParam('quotaId', 0),
                (int)$this->request->getParam('newOrdersHours', 24),
                (bool)$this->request->getParam('showBackendLink', false),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('IntraVox: Pretix widget data unavailable', ['exceptionClass' => $e::class]);
            return new DataResponse(['status' => 'error', 'message' => 'Pretix data is currently unavailable'], Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getOptions(): DataResponse {
        if ($this->userId === null || !$this->permissions->hasAccess($this->userId)) {
            return new DataResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $organizer = (string)$this->request->getParam('organizer', '');
            return new DataResponse($organizer === ''
                ? ['organizers' => $this->pretix->listOrganizers()]
                : ['events' => $this->pretix->listEvents($organizer)]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Pretix options are unavailable'], Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoCSRFRequired]
    public function getSettings(): DataResponse {
        if ($this->userId === null || !$this->groupManager->isAdmin($this->userId)) {
            return new DataResponse(['error' => 'Administrator privileges required'], Http::STATUS_FORBIDDEN);
        }
        return new DataResponse($this->pretix->getPublicConfig());
    }

    public function setSettings(): DataResponse {
        if ($this->userId === null || !$this->groupManager->isAdmin($this->userId)) {
            return new DataResponse(['error' => 'Administrator privileges required'], Http::STATUS_FORBIDDEN);
        }
        try {
            $this->pretix->saveConfig(
                (string)$this->request->getParam('baseUrl', ''),
                (string)$this->request->getParam('token', ''),
            );
            return new DataResponse($this->pretix->getPublicConfig());
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Pretix settings could not be saved'], Http::STATUS_BAD_REQUEST);
        }
    }
}
