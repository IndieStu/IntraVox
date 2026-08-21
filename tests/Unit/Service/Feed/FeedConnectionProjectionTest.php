<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Feed;

use PHPUnit\Framework\TestCase;

/**
 * Credential-adjacent fields leave getConnections() only for admins. (FEED-CRED)
 *
 * /api/settings/feed-connections is @NoAdminRequired on purpose — the feed widget
 * editor needs the connection NAMES to build its dropdown. But the projection
 * returned the whole configuration to every logged-in user, including
 * customHeaders, which is exactly where an admin types an API key when a service
 * wants one in a header. clientId, tenantId, apiKeyHeader and the service-account
 * address (jiraEmail) went out the same way.
 *
 * The token itself was already reduced to a hasToken boolean; this brings the
 * rest of the credential surface in line with that.
 *
 * The projection is pure array work, so it is tested through the same source the
 * service uses rather than by standing up FeedReaderService with its 15
 * dependencies.
 */
class FeedConnectionProjectionTest extends TestCase {

	/** Fields no non-admin may ever receive. */
	private const ADMIN_ONLY = [
		'customHeaders',
		'apiKeyHeader',
		'clientId',
		'tenantId',
		'jiraEmail',
	];

	/** Fields the widget editor genuinely needs. */
	private const ALWAYS_PRESENT = [
		'id',
		'name',
		'type',
		'active',
	];

	private function source(): string {
		$path = \dirname(__DIR__, 4) . '/lib/Service/FeedReaderService.php';
		$this->assertFileExists($path);

		return (string)file_get_contents($path);
	}

	/** The projection body, so assertions cannot drift onto other methods. */
	private function projection(): string {
		$source = $this->source();
		$start = strpos($source, 'public function getConnections(');
		$this->assertNotFalse($start, 'getConnections() moved or was renamed');

		$end = strpos($source, "\n    }", $start);

		return substr($source, $start, $end - $start);
	}

	public function testProjectionTakesAnAdminFlagThatDefaultsToClosed(): void {
		$this->assertStringContainsString(
			'getConnections(bool $includeAdminFields = false)',
			$this->source(),
			'the admin flag must default to false, so a new caller cannot leak these '
			. 'fields by forgetting the argument'
		);
	}

	/** The closure must actually see the flag, or PHP fatals on an undefined var. */
	public function testProjectionClosureCapturesTheFlag(): void {
		$this->assertStringContainsString(
			'use ($includeAdminFields)',
			$this->projection(),
			'the array_map closure must capture $includeAdminFields'
		);
	}

	/**
	 * The regression: each sensitive field must sit behind the flag. Fails on the
	 * pre-fix code, where all five were assigned unconditionally.
	 */
	public function testEverySensitiveFieldIsGuarded(): void {
		$projection = $this->projection();
		$unguarded = [];

		foreach (self::ADMIN_ONLY as $field) {
			$pattern = "/\\\$result\['" . preg_quote($field, '/') . "'\]/";
			if (preg_match($pattern, $projection) !== 1) {
				continue; // field no longer projected at all: also fine
			}

			// Everything after the guard opens is admin-only territory.
			$guardPos = strpos($projection, 'if ($includeAdminFields)');
			$fieldPos = strpos($projection, "\$result['" . $field . "']");

			if ($guardPos === false || $fieldPos < $guardPos) {
				$unguarded[] = $field;
			}
		}

		$this->assertSame(
			[],
			$unguarded,
			'these fields reach every logged-in user: ' . implode(', ', $unguarded)
		);
	}

	/** The widget editor must keep working: names and ids stay unconditional. */
	public function testTheFieldsTheWidgetEditorNeedsAreNotGuarded(): void {
		$projection = $this->projection();
		$guardPos = strpos($projection, 'if ($includeAdminFields)');
		$this->assertNotFalse($guardPos);

		$beforeGuard = substr($projection, 0, $guardPos);

		foreach (self::ALWAYS_PRESENT as $field) {
			$this->assertStringContainsString(
				"'" . $field . "' =>",
				$beforeGuard,
				"$field is needed to render the connection dropdown and must stay unconditional"
			);
		}
	}

	/** The raw token must never be projected, guarded or not. */
	public function testTheTokenItselfIsNeverProjected(): void {
		$projection = $this->projection();

		$this->assertStringContainsString("'hasToken' => !empty(", $projection);
		$this->assertStringNotContainsString("'token' => \$conn['token']", $projection);
		$this->assertStringNotContainsString("'clientSecret' =>", $projection);
	}
}
