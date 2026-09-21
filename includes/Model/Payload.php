<?php
/**
 * A normalised import request.
 *
 * Whatever dialect arrived - our schema, a native ACF export, or an AI's loose
 * approximation - the Normalizer produces exactly this. Nothing downstream of here
 * ever sees raw JSON again.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class Payload implements \JsonSerializable {

	/**
	 * @param FieldGroup|null                   $group       Declared group, for create/replace/sync.
	 * @param list<Field>                       $add         Fields to insert.
	 * @param array<string,array<string,mixed>> $changes     field reference => settings to merge.
	 * @param list<string>                      $delete      Field references to remove.
	 * @param list<MoveSpec>                    $moves       Relocations.
	 * @param list<Layout>                      $addLayouts  Flexible-content layouts to insert.
	 * @param array<string,mixed>               $groupChanges Group-level settings to merge.
	 * @param array<string,mixed>               $options     confirm flags, conflict resolutions.
	 * @param string                            $dialect     Which input dialect this came from.
	 */
	public function __construct(
		public readonly string $version,
		public readonly Operation $operation,
		public readonly ?Target $target = null,
		public readonly ?FieldGroup $group = null,
		public readonly array $add = array(),
		public readonly array $changes = array(),
		public readonly array $delete = array(),
		public readonly array $moves = array(),
		public readonly array $addLayouts = array(),
		public readonly array $groupChanges = array(),
		public readonly array $options = array(),
		public readonly string $dialect = 'acfjp',
	) {}

	/**
	 * True when this payload asks for nothing. Validation rejects these rather than
	 * letting a developer wonder why their preview is empty.
	 */
	public function isEmpty(): bool {
		return array() === $this->add
			&& array() === $this->changes
			&& array() === $this->delete
			&& array() === $this->moves
			&& array() === $this->addLayouts
			&& array() === $this->groupChanges
			&& null === $this->group;
	}

	/**
	 * Conflict resolutions supplied by the caller, keyed by conflict id.
	 *
	 * @return array<string,string>
	 */
	public function resolutions(): array {
		$resolutions = $this->options['resolutions'] ?? array();

		return is_array( $resolutions ) ? array_map( 'strval', $resolutions ) : array();
	}

	public function isConfirmed( string $what ): bool {
		$confirm = $this->options['confirm'] ?? array();

		if ( is_bool( $confirm ) ) {
			return $confirm;
		}

		return is_array( $confirm ) && ! empty( $confirm[ $what ] );
	}

	/**
	 * Whether the caller asked to skip the (potentially slow) content probe.
	 */
	public function skipsDataProbe(): bool {
		return ! empty( $this->options['skip_data_probe'] );
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function withOptions( array $options ): self {
		return new self(
			$this->version,
			$this->operation,
			$this->target,
			$this->group,
			$this->add,
			$this->changes,
			$this->delete,
			$this->moves,
			$this->addLayouts,
			$this->groupChanges,
			array_merge( $this->options, $options ),
			$this->dialect,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array_filter(
			array(
				'version'       => $this->version,
				'operation'     => $this->operation->value,
				'dialect'       => $this->dialect,
				'target'        => $this->target?->jsonSerialize(),
				'group'         => $this->group?->jsonSerialize(),
				'add'           => array_map( static fn( Field $f ): array => $f->toAcfArray(), $this->add ),
				'changes'       => $this->changes,
				'delete'        => $this->delete,
				'moves'         => array_map( static fn( MoveSpec $m ): array => $m->jsonSerialize(), $this->moves ),
				'add_layouts'   => array_map( static fn( Layout $l ): array => $l->toAcfArray(), $this->addLayouts ),
				'group_changes' => $this->groupChanges,
			),
			static fn( $v ): bool => null !== $v && array() !== $v
		);
	}
}
