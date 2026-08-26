<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\FeedReaderService;
use PHPUnit\Framework\TestCase;

/**
 * validateUrl() must fail CLOSED.
 *
 * The SSRF check resolved a host with gethostbynamel() and only inspected the
 * result `if (is_array($ips))`. That function returns A records and nothing else:
 * false when a name does not resolve, and false again when a host publishes AAAA
 * records only. Both landed in the else-that-was-not-there, so the guard skipped
 * precisely the hosts most worth guarding — `http://[::1]/` went straight through
 * to the fetcher.
 *
 * The endpoint behind this is #[NoAdminRequired] and fetches on the server's
 * behalf, so any logged-in user could aim it at the internal network.
 *
 * Reflection because the method is private and pure. Making it public to test it
 * would widen a security-relevant surface for the convenience of the test.
 */
class FeedUrlValidationTest extends TestCase {
    private \ReflectionMethod $validate;
    private FeedReaderService $service;

    protected function setUp(): void {
        parent::setUp();
        $class = new \ReflectionClass(FeedReaderService::class);
        // The constructor wants a dozen collaborators and validateUrl touches none
        // of them.
        $this->service = $class->newInstanceWithoutConstructor();
        $this->validate = $class->getMethod('validateUrl');
    }

    private function reject(string $url): ?string {
        try {
            $this->validate->invoke($this->service, $url);
            return null;
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    public function testIpv6LoopbackLiteralIsRefused(): void {
        $this->assertNotNull(
            $this->reject('http://[::1]/feed.xml'),
            'http://[::1]/ used to pass: gethostbynamel() returned false and the check was skipped'
        );
    }

    public function testIpv4LoopbackLiteralIsRefused(): void {
        $this->assertNotNull($this->reject('http://127.0.0.1/feed.xml'));
    }

    public function testPrivateRangeLiteralIsRefused(): void {
        $this->assertNotNull($this->reject('http://192.168.1.1/feed.xml'));
        $this->assertNotNull($this->reject('http://10.0.0.5/feed.xml'));
    }

    public function testUnresolvableHostIsRefusedRatherThanWavedThrough(): void {
        $message = $this->reject('http://intravox-nonexistent-host-for-tests.invalid/feed.xml');
        $this->assertNotNull(
            $message,
            'A name we cannot resolve is a name we cannot vouch for; it must not be fetched'
        );
    }

    public function testNonHttpSchemesAreRefused(): void {
        $this->assertNotNull($this->reject('file:///etc/passwd'));
        $this->assertNotNull($this->reject('gopher://example.com/'));
    }

    public function testAPublicAddressIsStillAccepted(): void {
        // A literal public address takes the no-DNS path, so this case stays
        // deterministic in CI with no network.
        $this->assertNull(
            $this->reject('https://93.184.216.34/feed.xml'),
            'The guard must not become so strict that ordinary feeds stop working'
        );
    }
}
