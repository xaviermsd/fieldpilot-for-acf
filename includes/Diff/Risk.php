<?php
/**
 * How dangerous a single change is.
 *
 * Risk drives the UI (colour, grouping, whether a confirmation is demanded) and the
 * Guard (whether the batch may proceed unattended). It is computed from what the
 * change does to *content*, not from how large it looks.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diff;

defined( 'ABSPATH' ) || exit;

enum Risk: string {

	/** No stored content can be affected. */
	case Safe = 'safe';

	/** Configuration changes that could surprise, but do not orphan content. */
	case Caution = 'caution';

	/** Content becomes unreachable or is removed. Always requires confirmation. */
	case Destructive = 'destructive';

	public function requiresConfirmation(): bool {
		return self::Destructive === $this;
	}

	public function weight(): int {
		return match ( $this ) {
			self::Safe        => 0,
			self::Caution     => 1,
			self::Destructive => 2,
		};
	}

	public function label(): string {
		return match ( $this ) {
			self::Safe        => __( 'Safe', 'wp-acf-json-pro' ),
			self::Caution     => __( 'Caution', 'wp-acf-json-pro' ),
			self::Destructive => __( 'Destructive', 'wp-acf-json-pro' ),
		};
	}

	/**
	 * @param list<self> $risks
	 */
	public static function highest( array $risks ): self {
		$highest = self::Safe;

		foreach ( $risks as $risk ) {
			if ( $risk->weight() > $highest->weight() ) {
				$highest = $risk;
			}
		}

		return $highest;
	}
}
