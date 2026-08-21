<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\GroupFolders;

use OCA\IntraVox\Service\GroupFolders\GroupFoldersGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One chokepoint for groupfolder resolution. (SE-1, GFG-0)
 *
 * Four call sites walked getAllFolders() looking for a mount point and keeping
 * the highest id — SetupService three times, PermissionService once — in code
 * that was identical apart from its error handling, with two separate inline
 * copies of the mount-point extraction. getAllFolders() is three unbounded
 * queries plus an object per row, and getSharedFolder() has 41 call sites,
 * several on the page-render path, so on an instance with thousands of team
 * folders this dominated page load.
 *
 * The gateway is exercised through a subclass that replaces the FolderManager
 * lookup: the real class lives in an optional app that need not be installed,
 * which is also why it cannot simply be constructor-injected.
 */
class GroupFoldersGatewayTest extends TestCase {

	/** @param array<int|string,mixed> $folders */
	private function gateway(array $folders, bool $available = true, ?int &$calls = null): GroupFoldersGateway {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($available);

		return new class ($appManager, $this->createMock(LoggerInterface::class), $folders, $calls)
			extends GroupFoldersGateway {
			public function __construct(
				IAppManager $appManager,
				LoggerInterface $logger,
				private array $folders,
				private ?int &$calls,
			) {
				parent::__construct($appManager, $logger);
			}

			public function folderManager(): object {
				$this->calls = ($this->calls ?? 0) + 1;

				return new class ($this->folders) {
					public function __construct(private array $folders) {
					}

					public function getAllFolders(): array {
						return $this->folders;
					}

					public function getFolder(int $id): mixed {
						return $this->folders[$id] ?? null;
					}

					public function createFolder(string $mountPoint): int {
						return 99;
					}
				};
			}
		};
	}

	public function testResolvesTheFolderIdByMountPoint(): void {
		$gateway = $this->gateway([
			3 => ['mount_point' => 'Other'],
			7 => ['mount_point' => 'IntraVox'],
		]);

		$this->assertSame(7, $gateway->findFolderIdByMountPoint('IntraVox'));
	}

	public function testUnknownMountPointResolvesToNull(): void {
		$gateway = $this->gateway([3 => ['mount_point' => 'Other']]);

		$this->assertNull($gateway->findFolderIdByMountPoint('IntraVox'));
	}

	/** The behaviour all four original loops shared: highest id wins. */
	public function testHighestIdWinsWhenSeveralFoldersShareAMountPoint(): void {
		$gateway = $this->gateway([
			2 => ['mount_point' => 'IntraVox'],
			9 => ['mount_point' => 'IntraVox'],
			5 => ['mount_point' => 'IntraVox'],
		]);

		$this->assertSame(9, $gateway->findFolderIdByMountPoint('IntraVox'));
	}

	/**
	 * The SE-1 property: however often a request asks, the walk happens once.
	 * Four services used to ask independently, each walking every groupfolder.
	 */
	public function testResolutionIsMemoisedPerRequest(): void {
		$calls = 0;
		$gateway = $this->gateway([7 => ['mount_point' => 'IntraVox']], true, $calls);

		for ($i = 0; $i < 10; $i++) {
			$gateway->findFolderIdByMountPoint('IntraVox');
		}

		$this->assertSame(1, $gateway->resolveCallCount(), 'the walk must happen once per request');
	}

	/** A negative answer is memoised too, or setup re-walks on every check. */
	public function testANegativeResultIsAlsoMemoised(): void {
		$gateway = $this->gateway([3 => ['mount_point' => 'Other']]);

		$gateway->findFolderIdByMountPoint('IntraVox');
		$gateway->findFolderIdByMountPoint('IntraVox');

		$this->assertSame(1, $gateway->resolveCallCount());
	}

	/** ...but setup must be able to drop it after creating the folder. */
	public function testForgetAllowsRelookupAfterCreation(): void {
		$gateway = $this->gateway([3 => ['mount_point' => 'Other']]);

		$this->assertNull($gateway->findFolderIdByMountPoint('IntraVox'));
		$gateway->forget('IntraVox');
		$gateway->findFolderIdByMountPoint('IntraVox');

		$this->assertSame(2, $gateway->resolveCallCount());
	}

	/** Both row shapes groupfolders has used across releases. */
	public function testHandlesObjectRowsAsWellAsArrayRows(): void {
		$row = new class {
			public string $mountPoint = 'IntraVox';
		};

		$this->assertSame(4, $this->gateway([4 => $row])->findFolderIdByMountPoint('IntraVox'));
	}

	public function testHandlesRowsExposingAGetter(): void {
		$row = new class {
			public function getMountPoint(): string {
				return 'IntraVox';
			}
		};

		$this->assertSame(6, $this->gateway([6 => $row])->findFolderIdByMountPoint('IntraVox'));
	}

	/** With the app disabled nothing resolves, and nothing is walked. */
	public function testDisabledAppResolvesToNullWithoutWalking(): void {
		$calls = 0;
		$gateway = $this->gateway([7 => ['mount_point' => 'IntraVox']], false, $calls);

		$this->assertNull($gateway->findFolderIdByMountPoint('IntraVox'));
		$this->assertSame(0, $gateway->resolveCallCount());
		$this->assertSame([], $gateway->allFolderIds());
	}

	public function testCreateFolderMakesTheNewIdVisibleImmediately(): void {
		$gateway = $this->gateway([]);

		$this->assertNull($gateway->findFolderIdByMountPoint('IntraVox'));
		$this->assertSame(99, $gateway->createFolder('IntraVox'));
		$this->assertSame(99, $gateway->findFolderIdByMountPoint('IntraVox'));
	}

	public function testAllFolderIdsReturnsIntegers(): void {
		$gateway = $this->gateway(['3' => ['mount_point' => 'a'], '8' => ['mount_point' => 'b']]);

		$this->assertSame([3, 8], $gateway->allFolderIds());
	}
}
