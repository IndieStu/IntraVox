<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\HomepageService;
use OCA\IntraVox\Service\PageService;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

/**
 * getHomepageUniqueId() must name a page the frontend can actually find.
 *
 * Two homepage layouts exist side by side: the normalised `home/home.json`
 * folder page, which has a real uniqueId, and the legacy loose `home.json` in
 * the language root. For the legacy form this returned the bare string 'home',
 * which matches no entry in listPages() — so
 * `pages.find(p => p.uniqueId === homepageUniqueId)` came up empty and the
 * reader fell through to a slug/path heuristic that ends at pages[0], the
 * alphabetically first page.
 *
 * On dev that put every Dutch reader on "API Referentie" instead of "Welkom bij
 * IntraVox", while English — which uses the normalised layout — was fine. The
 * two layouts coexisting is exactly what hid it.
 */
class PageHomepageResolutionTest extends TestCase {

    private function makeFile(string $path, array $json): File {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn(basename($path));
        $file->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $file->method('getPath')->willReturn($path);
        $file->method('getContent')->willReturn(json_encode($json));
        $file->method('getId')->willReturn(abs(crc32($path)));
        return $file;
    }

    private function makeFolder(string $path, array $children): Folder {
        $folder = $this->createMock(Folder::class);
        $folder->method('getName')->willReturn(basename($path));
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getPath')->willReturn($path);
        $folder->method('getDirectoryListing')->willReturn(array_values($children));
        $folder->method('nodeExists')->willReturnCallback(fn($n) => isset($children[$n]));
        $folder->method('get')->willReturnCallback(function ($p) use ($children, $path) {
            if (isset($children[$p])) {
                return $children[$p];
            }
            throw new \OCP\Files\NotFoundException($path . '/' . $p);
        });
        return $folder;
    }

    /**
     * @param array|null $homeJson contents of nl/home.json, or null for none
     * @param string|null $pointer configured homepage pointer, if any
     */
    private function makeService(?array $homeJson, ?string $pointer = null): PageService {
        $children = [
            'about.json' => $this->makeFile(
                '/IntraVox/nl/about.json',
                ['uniqueId' => 'page-about', 'title' => 'API Referentie']
            ),
        ];
        if ($homeJson !== null) {
            $children['home.json'] = $this->makeFile('/IntraVox/nl/home.json', $homeJson);
        }
        $nl = $this->makeFolder('/IntraVox/nl', $children);
        $base = $this->makeFolder('/IntraVox', ['nl' => $nl]);

        $svc = new class($nl, $base) extends PageService {
            private Folder $langFolder;
            private Folder $baseFolder;
            public function __construct(Folder $langFolder, Folder $baseFolder) {
                $this->langFolder = $langFolder;
                $this->baseFolder = $baseFolder;
            }
            protected function getLanguageFolder() {
                return $this->langFolder;
            }
            protected function getReadLanguageFolder(): Folder {
                return $this->langFolder;
            }
            protected function getIntraVoxFolder() {
                return $this->baseFolder;
            }
            public function clearCache(): void {
            }
        };

        $homepageService = $this->createMock(HomepageService::class);
        $homepageService->method('getHomepageUniqueId')->willReturn($pointer);

        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getUserValue')->willReturn('nl');

        $languageService = $this->createMock(\OCA\IntraVox\Service\LanguageService::class);
        $languageService->method('isLanguageAvailable')->willReturn(true);
        $languageService->method('getPrimaryLanguage')->willReturn('nl');

        $explicit = [
            'userSession' => $this->createMock(\OCP\IUserSession::class),
            'userId' => 'tester',
            'config' => $config,
            'logger' => $this->createMock(\Psr\Log\LoggerInterface::class),
            'languageService' => $languageService,
            'homepageService' => $homepageService,
        ];
        foreach ($explicit as $name => $value) {
            (new \ReflectionProperty(PageService::class, $name))->setValue($svc, $value);
        }
        foreach ((new \ReflectionClass(PageService::class))->getProperties() as $prop) {
            if ($prop->isStatic() || isset($explicit[$prop->getName()])) {
                continue;
            }
            $type = $prop->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $lazySeamServices = [
                \OCA\IntraVox\Service\Locator\PageLocator::class,
                \OCA\IntraVox\Service\Translation\TranslationGroupService::class,
                \OCA\IntraVox\Service\Media\PageMediaService::class,
            ];
            if (in_array($type->getName(), $lazySeamServices, true)) {
                // Leave unset: PageService's lazy seam accessors build the
                // REAL service from the pageIndexService + logger this test
                // sets, reproducing the pre-split inline behaviour. An
                // auto-mock here would answer null to every lookup.
                continue;
            }
            if ($prop->isInitialized($svc)) {
                continue;
            }
            $class = $type->getName();
            if (!interface_exists($class) && !class_exists($class)) {
                continue;
            }
            try {
                $prop->setValue($svc, $this->createMock($class));
            } catch (\PHPUnit\Framework\MockObject\Generator\ClassIsFinalException $e) {
                $ctor = (new \ReflectionClass($class))->getConstructor();
                $args = [];
                foreach ($ctor?->getParameters() ?? [] as $param) {
                    $pType = $param->getType();
                    $args[] = $pType instanceof \ReflectionNamedType && !$pType->isBuiltin()
                        ? $this->createMock($pType->getName())
                        : null;
                }
                $prop->setValue($svc, new $class(...$args));
            }
        }
        return $svc;
    }

    /**
     * The regression: a legacy loose home.json must resolve to the uniqueId the
     * file really carries, so the frontend can match it against listPages().
     */
    public function testLooseHomeJsonResolvesToItsRealUniqueId(): void {
        $svc = $this->makeService([
            'uniqueId' => 'page-nl-home',
            'title' => 'Welkom bij IntraVox',
        ]);

        $this->assertSame('page-nl-home', $svc->getHomepageUniqueId('nl'));
    }

    /**
     * A home.json without a uniqueId keeps the legacy answer — that string is
     * what the rest of the legacy path still understands, and inventing an id
     * here would be worse than saying "the legacy default".
     */
    public function testHomeJsonWithoutUniqueIdKeepsTheLegacyAnswer(): void {
        $svc = $this->makeService(['title' => 'Welcome']);

        $this->assertSame('home', $svc->getHomepageUniqueId('nl'));
    }

    /** No loose home.json at all: unchanged legacy answer. */
    public function testMissingHomeJsonKeepsTheLegacyAnswer(): void {
        $svc = $this->makeService(null);

        $this->assertSame('home', $svc->getHomepageUniqueId('nl'));
    }

    /**
     * A configured pointer still wins over the loose file — this fix must not
     * quietly override an admin's explicit homepage choice.
     */
    public function testConfiguredPointerStillWins(): void {
        $svc = $this->makeService(
            ['uniqueId' => 'page-nl-home', 'title' => 'Welkom'],
            'page-about'
        );

        $this->assertSame('page-about', $svc->getHomepageUniqueId('nl'));
    }
}
