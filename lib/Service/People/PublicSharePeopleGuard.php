<?php

declare(strict_types=1);

namespace OCA\IntraVox\Service\People;

/**
 * Removes People widgets from pages served over a public share link.
 *
 * A public share is normally created to hand someone a set of documents. If
 * the page also carries a People widget, the act of sharing those documents
 * silently publishes a staff directory — names, photos, and whatever profile
 * fields the widget shows — to anyone holding the URL. Nobody on that list
 * consented to it, and the person sharing usually has not even noticed the
 * widget is there.
 *
 * Account-property scopes (AccountScopePolicy) already strip most fields for
 * anonymous visitors, but names and avatars survive by design: a People
 * widget without names is meaningless. For an organisation working with
 * vulnerable groups, a list of names and faces is itself the sensitive part.
 *
 * So the default is to remove the widget entirely on public shares. An admin
 * can allow it instance-wide when there is a genuine reason — an external
 * project page with a named contact, say — but that has to be a decision
 * someone took, not something that happens because a document was shared.
 *
 * Pure array surgery: no Nextcloud dependencies, so the rules are testable
 * on their own.
 */
final class PublicSharePeopleGuard {
	/** Widget types withheld from public shares by default. */
	private const GUARDED_TYPES = ['people'];

	/**
	 * Strip guarded widgets from a page's layout.
	 *
	 * Every place a widget can live is covered — rows, both side columns and
	 * the header row. A widget that survives in an overlooked container is
	 * the whole leak, so this walks all of them rather than only `rows`.
	 *
	 * @param array $pageData a page array with a `layout` key
	 * @return array{page: array, removed: int}
	 */
	public static function strip(array $pageData): array {
		$removed = 0;

		if (!isset($pageData['layout']) || !is_array($pageData['layout'])) {
			return ['page' => $pageData, 'removed' => 0];
		}

		$layout = $pageData['layout'];

		if (isset($layout['rows']) && is_array($layout['rows'])) {
			foreach ($layout['rows'] as $index => $row) {
				if (!is_array($row)) {
					continue;
				}
				$layout['rows'][$index]['widgets'] = self::filterWidgets($row['widgets'] ?? [], $removed);
			}
		}

		if (isset($layout['sideColumns']) && is_array($layout['sideColumns'])) {
			foreach (['left', 'right'] as $side) {
				if (!isset($layout['sideColumns'][$side]) || !is_array($layout['sideColumns'][$side])) {
					continue;
				}
				$layout['sideColumns'][$side]['widgets'] = self::filterWidgets(
					$layout['sideColumns'][$side]['widgets'] ?? [],
					$removed
				);
			}
		}

		if (isset($layout['headerRow']) && is_array($layout['headerRow'])) {
			$layout['headerRow']['widgets'] = self::filterWidgets($layout['headerRow']['widgets'] ?? [], $removed);
		}

		$pageData['layout'] = $layout;

		return ['page' => $pageData, 'removed' => $removed];
	}

	/**
	 * Whether a page carries a widget that a public share would strip.
	 *
	 * Used to warn an editor at sharing time rather than letting them find
	 * out from the rendered page.
	 */
	public static function countGuardedWidgets(array $pageData): int {
		return self::strip($pageData)['removed'];
	}

	/**
	 * @param mixed $widgets
	 * @param int $removed running total, by reference
	 */
	private static function filterWidgets($widgets, int &$removed): array {
		if (!is_array($widgets)) {
			return [];
		}

		$kept = [];
		foreach ($widgets as $widget) {
			if (is_array($widget) && in_array($widget['type'] ?? '', self::GUARDED_TYPES, true)) {
				$removed++;
				continue;
			}
			$kept[] = $widget;
		}

		// Reindex: a gap in the array would serialise to a JSON object
		// instead of a list and break the frontend's v-for.
		return array_values($kept);
	}
}
