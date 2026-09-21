<?php
/**
 * A decision the engine refuses to make on the user's behalf.
 *
 * A conflict is not an error. It is a fork in the road where both directions are
 * defensible and only the developer knows which they meant. The engine presents the
 * options and blocks until one is chosen; it never picks a default silently, and
 * `suggested` is a hint for the UI to preselect, never an auto-resolution.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diff;

defined( 'ABSPATH' ) || exit;

final class Conflict implements \JsonSerializable {

	public const TYPE_CHANGE      = 'type_change';
	public const RENAME_WITH_DATA = 'rename_with_data';
	public const DELETE_WITH_DATA = 'delete_with_data';
	public const NAME_COLLISION   = 'name_collision';
	public const KEY_IN_USE       = 'key_in_use';
	public const CONTAINER_CHANGE = 'container_change';

	// Resolution option ids.
	public const KEEP_EXISTING = 'keep_existing';
	public const APPLY_INCOMING = 'apply_incoming';
	public const CREATE_NEW     = 'create_new';
	public const LABEL_ONLY     = 'label_only';
	public const RENAME_ORPHAN  = 'rename_orphan';
	public const SKIP           = 'skip';

	/**
	 * @param list<array{id:string,label:string,description:string,destructive:bool}> $options
	 * @param array<string,mixed>                                                      $context
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $kind,
		public readonly string $title,
		public readonly string $message,
		public readonly array $options,
		public readonly string $suggested,
		public readonly array $context = array(),
		public readonly ?string $resolution = null,
	) {}

	public function isResolved(): bool {
		return null !== $this->resolution && $this->isValidResolution( $this->resolution );
	}

	public function isValidResolution( string $option ): bool {
		return in_array( $option, array_column( $this->options, 'id' ), true );
	}

	public function resolveWith( string $option ): self {
		return new self(
			$this->id,
			$this->kind,
			$this->title,
			$this->message,
			$this->options,
			$this->suggested,
			$this->context,
			$this->isValidResolution( $option ) ? $option : null,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'id'         => $this->id,
			'kind'       => $this->kind,
			'title'      => $this->title,
			'message'    => $this->message,
			'options'    => $this->options,
			'suggested'  => $this->suggested,
			'context'    => $this->context,
			'resolution' => $this->resolution,
			'resolved'   => $this->isResolved(),
		);
	}

	// ---- Builders ------------------------------------------------------------

	/**
	 * @param array<string,mixed> $context
	 */
	public static function typeChange( string $id, string $label, string $from, string $to, bool $hasContent, array $context = array() ): self {
		return new self(
			id: $id,
			kind: self::TYPE_CHANGE,
			title: sprintf(
				/* translators: %s: field label */
				__( 'Field type conflict: %s', 'wp-acf-json-pro' ),
				$label
			),
			message: $hasContent
				? sprintf(
					/* translators: 1: current type, 2: incoming type */
					__( 'This field is currently %1$s and holds content. Changing it to %2$s may make that content unreadable.', 'wp-acf-json-pro' ),
					$from,
					$to
				)
				: sprintf(
					/* translators: 1: current type, 2: incoming type */
					__( 'This field is currently %1$s and the payload asks for %2$s.', 'wp-acf-json-pro' ),
					$from,
					$to
				),
			options: array(
				array(
					'id'          => self::KEEP_EXISTING,
					'label'       => __( 'Keep existing type', 'wp-acf-json-pro' ),
					'description' => sprintf(
						/* translators: %s: current field type */
						__( 'Leave the field as %s and apply the other settings.', 'wp-acf-json-pro' ),
						$from
					),
					'destructive' => false,
				),
				array(
					'id'          => self::APPLY_INCOMING,
					'label'       => __( 'Change the type', 'wp-acf-json-pro' ),
					'description' => $hasContent
						? __( 'Existing content stays in the database but may no longer display.', 'wp-acf-json-pro' )
						: __( 'No content is stored for this field, so nothing is at risk.', 'wp-acf-json-pro' ),
					'destructive' => $hasContent,
				),
				array(
					'id'          => self::CREATE_NEW,
					'label'       => __( 'Add as a new field', 'wp-acf-json-pro' ),
					'description' => __( 'Keep the original untouched and add a second field with a new name.', 'wp-acf-json-pro' ),
					'destructive' => false,
				),
			),
			suggested: $hasContent ? self::KEEP_EXISTING : self::APPLY_INCOMING,
			context: $context + array( 'from' => $from, 'to' => $to, 'has_content' => $hasContent ),
		);
	}

	/**
	 * Renaming a field changes the meta key its content is stored under. v1 does
	 * not migrate meta - sub-field keys inside repeaters are compound and a partial
	 * migration is worse than none - so the honest options are "don't rename" or
	 * "rename and accept the orphan".
	 *
	 * @param array<string,mixed> $context
	 */
	public static function renameWithData( string $id, string $label, string $from, string $to, array $context = array() ): self {
		return new self(
			id: $id,
			kind: self::RENAME_WITH_DATA,
			title: sprintf(
				/* translators: %s: field label */
				__( 'Rename affects stored content: %s', 'wp-acf-json-pro' ),
				$label
			),
			message: sprintf(
				/* translators: 1: current name, 2: new name */
				__( 'Content is stored under the name "%1$s". Renaming to "%2$s" leaves that content in the database but disconnected from the field.', 'wp-acf-json-pro' ),
				$from,
				$to
			),
			options: array(
				array(
					'id'          => self::LABEL_ONLY,
					'label'       => __( 'Change the label only', 'wp-acf-json-pro' ),
					'description' => __( 'Editors see the new wording; the stored name and all content stay intact.', 'wp-acf-json-pro' ),
					'destructive' => false,
				),
				array(
					'id'          => self::RENAME_ORPHAN,
					'label'       => __( 'Rename anyway', 'wp-acf-json-pro' ),
					'description' => __( 'The field starts empty. Existing content remains in the database under the old name.', 'wp-acf-json-pro' ),
					'destructive' => true,
				),
				array(
					'id'          => self::SKIP,
					'label'       => __( 'Skip this change', 'wp-acf-json-pro' ),
					'description' => __( 'Leave the field exactly as it is.', 'wp-acf-json-pro' ),
					'destructive' => false,
				),
			),
			suggested: self::LABEL_ONLY,
			context: $context + array( 'from' => $from, 'to' => $to ),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function deleteWithData( string $id, string $label, int $descendantCount, array $context = array() ): self {
		$message = $descendantCount > 0
			? sprintf(
				/* translators: 1: field label, 2: number of nested fields */
				__( '"%1$s" holds content and contains %2$d nested fields, which will be removed with it.', 'wp-acf-json-pro' ),
				$label,
				$descendantCount
			)
			: sprintf(
				/* translators: %s: field label */
				__( '"%s" holds content on at least one post, term or user.', 'wp-acf-json-pro' ),
				$label
			);

		return new self(
			id: $id,
			kind: self::DELETE_WITH_DATA,
			title: sprintf(
				/* translators: %s: field label */
				__( 'Delete a field that holds content: %s', 'wp-acf-json-pro' ),
				$label
			),
			message: $message,
			options: array(
				array(
					'id'          => self::SKIP,
					'label'       => __( 'Keep the field', 'wp-acf-json-pro' ),
					'description' => __( 'Leave it in place and continue with the rest of the batch.', 'wp-acf-json-pro' ),
					'destructive' => false,
				),
				array(
					'id'          => self::APPLY_INCOMING,
					'label'       => __( 'Delete it', 'wp-acf-json-pro' ),
					'description' => __( 'The field configuration is removed. Stored values stay in the database but become unreachable.', 'wp-acf-json-pro' ),
					'destructive' => true,
				),
			),
			suggested: self::SKIP,
			context: $context + array( 'descendants' => $descendantCount ),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function nameCollision( string $id, string $name, string $parentLabel, array $context = array() ): self {
		return new self(
			id: $id,
			kind: self::NAME_COLLISION,
			title: sprintf(
				/* translators: %s: field name */
				__( 'Name already in use: %s', 'wp-acf-json-pro' ),
				$name
			),
			message: sprintf(
				/* translators: 1: field name, 2: parent label */
				__( 'A field named "%1$s" already exists in %2$s. Two siblings with the same name write to the same meta key.', 'wp-acf-json-pro' ),
				$name,
				$parentLabel
			),
			options: array(
				array(
					'id'          => self::SKIP,
					'label'       => __( 'Skip the new field', 'wp-acf-json-pro' ),
					'description' => __( 'Keep the existing field and do not add this one.', 'wp-acf-json-pro' ),
					'destructive' => false,
				),
				array(
					'id'          => self::APPLY_INCOMING,
					'label'       => __( 'Update the existing field instead', 'wp-acf-json-pro' ),
					'description' => __( 'Treat this as a change to the field that is already there.', 'wp-acf-json-pro' ),
					'destructive' => false,
				),
				array(
					'id'          => self::CREATE_NEW,
					'label'       => __( 'Add with a suffixed name', 'wp-acf-json-pro' ),
					'description' => __( 'Add the new field with a unique name derived from this one.', 'wp-acf-json-pro' ),
					'destructive' => false,
				),
			),
			suggested: self::APPLY_INCOMING,
			context: $context + array( 'name' => $name ),
		);
	}

	/**
	 * Changing a container's type (repeater ⇄ group, say) restructures how every
	 * child's value is stored. There is no safe in-place transformation.
	 *
	 * @param array<string,mixed> $context
	 */
	public static function containerChange( string $id, string $label, string $from, string $to, array $context = array() ): self {
		return new self(
			id: $id,
			kind: self::CONTAINER_CHANGE,
			title: sprintf(
				/* translators: %s: field label */
				__( 'Container type change: %s', 'wp-acf-json-pro' ),
				$label
			),
			message: sprintf(
				/* translators: 1: current type, 2: incoming type */
				__( 'Changing from %1$s to %2$s changes how every nested value is stored. Existing content cannot be carried across.', 'wp-acf-json-pro' ),
				$from,
				$to
			),
			options: array(
				array(
					'id'          => self::KEEP_EXISTING,
					'label'       => __( 'Keep the current structure', 'wp-acf-json-pro' ),
					'description' => __( 'Apply other settings but leave the container type alone.', 'wp-acf-json-pro' ),
					'destructive' => false,
				),
				array(
					'id'          => self::APPLY_INCOMING,
					'label'       => __( 'Restructure anyway', 'wp-acf-json-pro' ),
					'description' => __( 'All nested content becomes unreachable.', 'wp-acf-json-pro' ),
					'destructive' => true,
				),
			),
			suggested: self::KEEP_EXISTING,
			context: $context + array( 'from' => $from, 'to' => $to ),
		);
	}
}
