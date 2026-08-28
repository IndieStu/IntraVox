<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service;

use OCA\IntraVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Security\ICrypto;

final class PretixService {
    private const CACHE_TTL = 180;
    private const HTTP_TIMEOUT = 8;

    private ?ICache $cache;

    public function __construct(
        private IClientService $httpClient,
        ICacheFactory $cacheFactory,
        private IConfig $config,
        private ICrypto $crypto,
    ) {
        $this->cache = $cacheFactory->createDistributed('intravox-pretix');
    }

    /** @return array{baseUrl:string,configured:bool,hasToken:bool} */
    public function getPublicConfig(): array {
        return [
            'baseUrl' => $this->config->getAppValue(Application::APP_ID, 'pretix_base_url', ''),
            'configured' => $this->isConfigured(),
            'hasToken' => $this->config->getAppValue(Application::APP_ID, 'pretix_token', '') !== '',
        ];
    }

    public function saveConfig(string $baseUrl, string $token): void {
        $baseUrl = $this->validateBaseUrl($baseUrl);
        $this->config->setAppValue(Application::APP_ID, 'pretix_base_url', $baseUrl);
        if ($token !== '') {
            $this->config->setAppValue(Application::APP_ID, 'pretix_token', $this->crypto->encrypt($token));
        }
        $this->cache?->clear();
    }

    public function isConfigured(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'pretix_base_url', '') !== ''
            && $this->config->getAppValue(Application::APP_ID, 'pretix_token', '') !== '';
    }

    /** @return array<int,array{slug:string,name:string}> */
    public function listOrganizers(): array {
        $items = $this->requestList('/api/v1/organizers/');
        return array_values(array_map(fn(array $item): array => [
            'slug' => (string)($item['slug'] ?? ''),
            'name' => $this->translated($item['name'] ?? $item['slug'] ?? ''),
        ], $items));
    }

    /** @return array<int,array{slug:string,name:string,hasSubevents:bool}> */
    public function listEvents(string $organizer): array {
        $organizer = $this->slug($organizer);
        $events = [];
        foreach ($this->requestList("/api/v1/organizers/{$organizer}/events/") as $item) {
            if (($item['live'] ?? false) !== true || ($item['testmode'] ?? false) === true) {
                continue;
            }
            $events[] = [
                'slug' => (string)($item['slug'] ?? ''),
                'name' => $this->translated($item['name'] ?? $item['slug'] ?? ''),
                'hasSubevents' => (bool)($item['has_subevents'] ?? false),
            ];
        }
        return $events;
    }

    /** @return array<string,mixed> */
    public function getWidgetData(string $organizer, string $event, int $quotaId, int $newOrdersHours, bool $showBackendLink = false): array {
        $organizer = $this->slug($organizer);
        $event = $this->slug($event);
        $newOrdersHours = max(1, min($newOrdersHours, 168));
        $cacheKey = 'widget_' . hash('sha256', implode('|', [$organizer, $event, $quotaId, $newOrdersHours, (int)$showBackendLink]));
        $cached = $this->cache?->get($cacheKey);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $eventData = $this->request("/api/v1/organizers/{$organizer}/events/{$event}/");
        $occurrence = $this->selectOccurrence($organizer, $event, $eventData);
        if ($occurrence === null) {
            $result = ['status' => 'empty'];
            $this->cacheResult($cacheKey, $result);
            return $result;
        }

        $subeventId = isset($occurrence['id']) ? (int)$occurrence['id'] : null;
        $quota = $this->selectQuota($organizer, $event, $quotaId, $subeventId);
        $metrics = self::capacityMetrics($quota);

        $since = (new \DateTimeImmutable("-{$newOrdersHours} hours"))->format(DATE_ATOM);
        $query = ['created_since' => $since];
        if ($subeventId !== null) {
            $query['subevent'] = (string)$subeventId;
        }
        $orders = $this->request("/api/v1/organizers/{$organizer}/events/{$event}/orders/", $query);

        $result = [
            'status' => 'ok',
            'name' => $this->translated($occurrence['name'] ?? $eventData['name'] ?? $event),
            'dateFrom' => $occurrence['date_from'] ?? null,
            'dateTo' => $occurrence['date_to'] ?? null,
            'location' => $this->translated($occurrence['location'] ?? $eventData['location'] ?? ''),
            'hasQuota' => $quota !== [],
            ...$metrics,
            'newOrders' => max(0, (int)($orders['count'] ?? 0)),
            'newOrdersHours' => $newOrdersHours,
        ];
        if ($showBackendLink) {
            $result['backendUrl'] = $this->config->getAppValue(Application::APP_ID, 'pretix_base_url', '')
                . "/control/event/{$organizer}/{$event}/orders/";
        }
        $this->cacheResult($cacheKey, $result);
        return $result;
    }

    private function selectOccurrence(string $organizer, string $event, array $eventData): ?array {
        $now = new \DateTimeImmutable();
        if (($eventData['has_subevents'] ?? false) !== true) {
            if (($eventData['live'] ?? false) !== true || ($eventData['testmode'] ?? false) === true) {
                return null;
            }
            $start = isset($eventData['date_from']) ? new \DateTimeImmutable($eventData['date_from']) : null;
            $end = isset($eventData['date_to']) && $eventData['date_to'] !== null
                ? new \DateTimeImmutable($eventData['date_to']) : $start;
            return $end !== null && $end >= $now ? $eventData : null;
        }

        $items = $this->requestList("/api/v1/organizers/{$organizer}/events/{$event}/subevents/");
        return self::selectNextOccurrence($items, $now);
    }

    /** @param array<int,array<string,mixed>> $occurrences */
    public static function selectNextOccurrence(array $occurrences, \DateTimeImmutable $now): ?array {
        $future = array_filter($occurrences, static function (array $item) use ($now): bool {
            if (($item['active'] ?? false) !== true || ($item['is_public'] ?? true) !== true || empty($item['date_from'])) {
                return false;
            }
            $end = !empty($item['date_to']) ? new \DateTimeImmutable($item['date_to']) : new \DateTimeImmutable($item['date_from']);
            return $end >= $now;
        });
        usort($future, static fn(array $a, array $b): int => strcmp((string)$a['date_from'], (string)$b['date_from']));
        return $future[0] ?? null;
    }

    /** @return array{capacity:?int,registered:?int,available:?int,soldOut:bool} */
    public static function capacityMetrics(array $quota): array {
        $capacity = array_key_exists('size', $quota) && $quota['size'] !== null ? (int)$quota['size'] : null;
        $available = array_key_exists('available_number', $quota) && $quota['available_number'] !== null
            ? max(0, (int)$quota['available_number']) : null;
        return [
            'capacity' => $capacity,
            'registered' => $capacity !== null && $available !== null ? max(0, $capacity - $available) : null,
            'available' => $available,
            'soldOut' => (($quota['closed'] ?? false) === true) || $available === 0,
        ];
    }

    private function selectQuota(string $organizer, string $event, int $quotaId, ?int $subeventId): array {
        $query = ['with_availability' => 'true'];
        if ($subeventId !== null) {
            $query['subevent'] = (string)$subeventId;
        }
        $quotas = array_values(array_filter($this->requestList("/api/v1/organizers/{$organizer}/events/{$event}/quotas/", $query), static fn(array $quota): bool =>
            ($quota['ignore_for_event_availability'] ?? false) !== true
        ));
        if ($quotaId > 0) {
            foreach ($quotas as $quota) {
                if ((int)($quota['id'] ?? 0) === $quotaId) {
                    return $quota;
                }
            }
        }
        return $quotas[0] ?? [];
    }

    private function cacheResult(string $key, array $result): void {
        $this->cache?->set($key, json_encode($result, JSON_THROW_ON_ERROR), self::CACHE_TTL);
    }

    private function request(string $path, array $query = []): array {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Pretix is not configured');
        }
        $baseUrl = $this->validateBaseUrl($this->config->getAppValue(Application::APP_ID, 'pretix_base_url', ''));
        $token = $this->crypto->decrypt($this->config->getAppValue(Application::APP_ID, 'pretix_token', ''));
        $url = $baseUrl . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $response = $this->httpClient->newClient()->get($url, [
            'timeout' => self::HTTP_TIMEOUT,
            'allow_redirects' => false,
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Token ' . $token],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Pretix request failed');
        }
        $data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid Pretix response');
        }
        return $data;
    }

    /** @return array<int,array<string,mixed>> */
    private function requestList(string $path, array $query = []): array {
        $items = [];
        for ($page = 1; $page <= 100; $page++) {
            $data = $this->request($path, [...$query, 'page' => (string)$page]);
            foreach ($data['results'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            if (empty($data['next'])) {
                break;
            }
        }
        return $items;
    }

    private function validateBaseUrl(string $url): string {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Pretix URL must be a plain HTTPS origin');
        }
        $records = dns_get_record((string)$parts['host'], DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new \InvalidArgumentException('Pretix host cannot be resolved');
        }
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException('Pretix host must resolve to a public address');
            }
        }
        return $url;
    }

    private function slug(string $value): string {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/i', $value)) {
            throw new \InvalidArgumentException('Invalid Pretix identifier');
        }
        return rawurlencode($value);
    }

    private function translated(mixed $value): string {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['en']) && is_string($value['en'])) {
            return $value['en'];
        }
        $first = reset($value);
        return is_string($first) ? $first : '';
    }
}
