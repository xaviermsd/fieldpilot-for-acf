<?php
/**
 * The operations this engine understands.
 *
 * Unknown operations are rejected, never guessed. Guessing intent is precisely the
 * failure mode this product exists to eliminate.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

enum Operation: string {

	/** Create a new field group. Fails if the target already exists. */
	case Create = 'create';

	/** Insert new fields into an existing target. Touches nothing else. */
	case Add = 'add';

	/** Change only the named settings on the named fields. */
	case Update = 'update';

	/** Remove the named fields. */
	case Delete = 'delete';

	/** Relocate an existing field to a new parent and/or position. */
	case Move = 'move';

	/** Add what is missing, update what is named, delete nothing. */
	case Merge = 'merge';

	/** Make the target match the payload exactly, including deletions. */
	case Sync = 'sync';

	/** Discard the target's configuration and rebuild it from the payload. */
	case Replace = 'replace';

	/**
	 * Whether this operation can remove configuration. Destructive operations
	 * require explicit confirmation and always take a snapshot first.
	 */
	public function isDestructive(): bool {
		return match ( $this ) {
			self::Delete, self::Sync, self::Replace => true,
			default                                 => false,
		};
	}

	/**
	 * Whether the payload declares a whole desired state (rather than a patch).
	 * These are the only operations permitted to call acf_import_field_group().
	 * See docs/ARCHITECTURE-REVIEW.md B.5.
	 */
	public function isDeclarative(): bool {
		return match ( $this ) {
			self::Sync, self::Replace => true,
			default                   => false,
		};
	}

	/** Operations that require an existing target. */
	public function requiresExistingTarget(): bool {
		return self::Create !== $this;
	}

	public function riskLabel(): string {
		return match ( $this ) {
			self::Create, self::Add, self::Update, self::Merge => 'normal',
			self::Move                                          => 'medium',
			self::Delete, self::Sync                            => 'high',
			self::Replace                                       => 'critical',
		};
	}

	/**
	 * @throws \ValueError When the string is not a known operation.
	 */
	public static function fromString( string $value ): self {
		return self::from( strtolower( trim( $value ) ) );
	}

	/** @return list<string> */
	public static function names(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}
}
