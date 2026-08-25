<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\LanguageService;
use OCA\IntraVox\Service\LicenseService;
use OCA\IntraVox\Service\SetupService;
use OCA\IntraVox\Service\UserCountService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The licence server distinguishes four refusals, and each needs a different
 * response from the admin. Before this, validateLicense() received the reason
 * and dropped it, so all four surfaced as "invalid or expired" — sending an
 * admin whose key merely moved instance chasing a healthy subscription.
 */
class LicenseRefusalReasonTest extends TestCase {
    /** @var array<string,string> */
    private array $appConfig = [];

    private IConfig $config;

    protected function setUp(): void {
        parent::setUp();
        $this->appConfig = [];

        $this->config = $this->createMock(IConfig::class);
        $this->config->method('setAppValue')
            ->willReturnCallback(function (string $app, string $key, string $value): void {
                $this->appConfig[$key] = $value;
            });
        $this->config->method('getAppValue')
            ->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->appConfig[$key] ?? $default);
        $this->config->method('deleteAppValue')
            ->willReturnCallback(function (string $app, string $key): void {
                unset($this->appConfig[$key]);
            });
    }

    public function testRefusalReasonIsPersistedForGetStats(): void {
        $this->appConfig['license_key'] = 'IVX-0000-1111-2222';

        $service = $this->serviceRespondingWith([
            'valid' => false,
            'reason' => 'License has expired',
            'validUntil' => '2026-07-01T00:00:00+00:00',
        ]);

        $result = $service->validateLicense();

        $this->assertFalse($result['valid']);
        $this->assertSame('License has expired', $this->appConfig['license_reason']);
        $this->assertSame('false', $this->appConfig['license_valid']);
    }

    public function testExpiredRefusalKeepsTheDateItLapsed(): void {
        $this->appConfig['license_key'] = 'IVX-0000-1111-2222';

        $service = $this->serviceRespondingWith([
            'valid' => false,
            'reason' => 'License has expired',
            'validUntil' => '2026-07-01T00:00:00+00:00',
        ]);
        $service->validateLicense();

        // A refused response carries validUntil flat, not nested under 'license'.
        $info = json_decode($this->appConfig['license_info'], true);
        $this->assertSame('2026-07-01T00:00:00+00:00', $info['validUntil']);
    }

    public function testSuccessfulValidationClearsAStaleReason(): void {
        $this->appConfig['license_key'] = 'IVX-0000-1111-2222';
        $this->appConfig['license_reason'] = 'License has expired';

        $service = $this->serviceRespondingWith([
            'valid' => true,
            'license' => ['validUntil' => '2027-01-01T00:00:00+00:00'],
        ]);
        $service->validateLicense();

        $this->assertArrayNotHasKey(
            'license_reason',
            $this->appConfig,
            'A renewed licence must not keep showing the old refusal message'
        );
        $this->assertSame('true', $this->appConfig['license_valid']);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function serviceRespondingWith(array $payload): LicenseService {
        $response = $this->createMock(\OCP\Http\Client\IResponse::class);
        $response->method('getBody')->willReturn(json_encode($payload));

        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $client->method('post')->willReturn($response);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return new LicenseService(
            $this->createMock(SetupService::class),
            $this->config,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LanguageService::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(UserCountService::class)
        );
    }
}
