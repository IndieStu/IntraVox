<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The documented Reactions shape is the shape the service actually returns.
 *
 * It was not. The schema described a map of emoji to {count, userReacted}
 * objects; CommentService returns {reactions: {emoji: int}, userReactions: [...]}
 * — a flat count map with the caller's own picks in a SEPARATE list. Nothing in
 * the response has ever had a userReacted field.
 *
 * This is the defect class that opened the 2.5 work, and this instance was the
 * worst of it by reach: Reactions is shared by six operations (page and comment,
 * GET/POST/DELETE each), so one wrong schema meant six wrong operations. A
 * generated client would have produced a type nothing could deserialise into,
 * and the failure surfaces at the call site rather than at the schema.
 *
 * Two things are pinned here, and the second matters more than it looks:
 *
 *  - the top-level keys, so the map-vs-list distinction cannot quietly revert;
 *  - that userReactions is documented as holding at most one entry. One reaction
 *    per user is enforced unconditionally — getSingleReactionPerUser() returns a
 *    hardcoded true — so ADDING a reaction silently withdraws the previous one.
 *    A client reading the array shape without that note will treat reacting as
 *    additive and render a count that never goes down.
 */
class ReactionsSchemaTest extends TestCase {
    private array $schema;

    protected function setUp(): void {
        parent::setUp();
        $spec = json_decode(
            file_get_contents(__DIR__ . '/../../../openapi.json'),
            true
        );
        $this->schema = $spec['components']['schemas']['Reactions'];
    }

    public function testTheTopLevelKeysMatchWhatTheServiceReturns(): void {
        $this->assertSame(
            ['reactions', 'userReactions'],
            array_keys($this->schema['properties']),
            'CommentService::getCommentReactions() and getPageReactions() both return exactly these two keys'
        );
        $this->assertSame(['reactions', 'userReactions'], $this->schema['required']);
    }

    public function testReactionsIsAFlatCountMapNotAMapOfObjects(): void {
        $counts = $this->schema['properties']['reactions'];

        $this->assertSame(
            'integer',
            $counts['additionalProperties']['type'],
            'Each emoji maps straight to a count; the old schema wrapped it in an object with a userReacted flag'
        );
    }

    public function testNoUserReactedFlagIsPromisedAnywhere(): void {
        $this->assertStringNotContainsString(
            'userReacted"',
            json_encode($this->schema),
            'No response has ever carried a per-emoji userReacted field'
        );
    }

    public function testUserReactionsIsAListOfEmoji(): void {
        $picked = $this->schema['properties']['userReactions'];

        $this->assertSame('array', $picked['type']);
        $this->assertSame('string', $picked['items']['type']);
    }

    /**
     * The at-most-one constraint is documented, not just implied by the code.
     *
     * An array type invites a client to expect several. The note is the only
     * thing standing between that assumption and a count that never decreases.
     */
    public function testTheSingleReactionRuleIsDocumented(): void {
        $this->assertStringContainsString(
            'AT MOST ONE',
            $this->schema['properties']['userReactions']['description'],
            'The array shape must carry the note that only one entry ever appears'
        );
    }

    /** And the code still enforces it, so the note stays true. */
    public function testOneReactionPerUserIsStillUnconditional(): void {
        $settings = file_get_contents(
            __DIR__ . '/../../../lib/Service/EngagementSettingsService.php'
        );

        $this->assertMatchesRegularExpression(
            '/function getSingleReactionPerUser\(\)\s*:\s*bool\s*\{\s*return true;/',
            $settings,
            'If this becomes configurable, the spec must stop claiming userReactions holds at most one entry'
        );
    }
}
