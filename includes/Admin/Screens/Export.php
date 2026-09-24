<?php
/**
 * Export: get a field group out, in either dialect.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin\Screens;

use ACFJP\Acf\GroupLocator;
use ACFJP\Acf\TreeReader;
use ACFJP\Apply\Guard;
use ACFJP\Model\Field;

defined( 'ABSPATH' ) || exit;

final class Export extends Screen {

	private const NONCE = 'acfjp_export_action';

	protected function title(): string {
		return __( 'Export', 'fieldpilot-for-acf' );
	}

	protected function body(): void {
		$groups   = $this->container->get( GroupLocator::class )->groups();
		$selected = '';
		$dialect  = 'native';

		if ( isset( $_POST['_wpnonce'] ) ) {
			if ( ! current_user_can( (string) apply_filters( 'acfjp_capability', Guard::CAPABILITY ) ) ) {
				$this->notice( esc_html__( 'You do not have permission to export field groups.', 'fieldpilot-for-acf' ), 'error' );
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
				$this->notice( esc_html__( 'That request could not be verified. Please try again.', 'fieldpilot-for-acf' ), 'error' );
				return;
			}

			$selected = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['group'] ) ) : '';
			$dialect  = isset( $_POST['dialect'] ) ? sanitize_key( (string) $_POST['dialect'] ) : 'native';

			if ( ! in_array( $dialect, array( 'native', 'acfjp' ), true ) ) {
				$dialect = 'native';
			}
		}

		?>
		<form method="post" class="acfjp-export-form">
			<?php wp_nonce_field( self::NONCE ); ?>
			<select name="group">
				<option value=""><?php esc_html_e( 'Choose a field group…', 'fieldpilot-for-acf' ); ?></option>
				<?php foreach ( $groups as $group ) : ?>
					<option value="<?php echo esc_attr( $group['key'] ); ?>" <?php selected( $selected, $group['key'] ); ?>>
						<?php echo esc_html( $group['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="dialect">
				<option value="native" <?php selected( $dialect, 'native' ); ?>>
					<?php esc_html_e( 'Native ACF export', 'fieldpilot-for-acf' ); ?>
				</option>
				<option value="acfjp" <?php selected( $dialect, 'acfjp' ); ?>>
					<?php esc_html_e( 'FieldPilot patch skeleton', 'fieldpilot-for-acf' ); ?>
				</option>
			</select>

			<button type="submit" class="button button-primary"><?php esc_html_e( 'Export', 'fieldpilot-for-acf' ); ?></button>
		</form>
		<?php

		if ( '' === $selected ) {
			return;
		}

		try {
			$reader = $this->container->get( TreeReader::class );
			$key    = $this->container->get( GroupLocator::class )->locate( $selected );

			$json = 'acfjp' === $dialect
				? $this->patchSkeleton( $reader, $key )
				: (string) wp_json_encode( $reader->exportArray( $key ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		} catch ( \Throwable $e ) {
			$this->notice( esc_html( $e->getMessage() ), 'error' );
			return;
		}

		printf(
			'<p><button type="button" class="button acfjp-copy" data-target="acfjp-export-output">%s</button></p>',
			esc_html__( 'Copy to clipboard', 'fieldpilot-for-acf' )
		);

		printf(
			'<textarea id="acfjp-export-output" class="acfjp-export-output" rows="24" readonly>%s</textarea>',
			esc_textarea( $json )
		);
	}

	/**
	 * A starting point in our own dialect: the group's fields expressed as an
	 * `add` payload, so a developer can delete what they do not want rather than
	 * type the structure from scratch.
	 */
	private function patchSkeleton( TreeReader $reader, string $key ): string {
		$tree = $reader->readRaw( $key );

		return (string) wp_json_encode(
			array(
				'version'   => '1.0',
				'operation' => 'add',
				'target'    => array( 'field_group' => $tree->group->title ),
				'add'       => array_map(
					static fn ( Field $f ): array => self::strip( $f->toAcfArray() ),
					$tree->group->fields
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Keys are site-specific and must not be carried into a patch payload - the
	 * engine mints new ones, and reusing an existing key would collide.
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	private static function strip( array $field ): array {
		unset( $field['key'], $field['ID'], $field['parent'], $field['menu_order'], $field['modified'] );

		if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$field['sub_fields'] = array_map( array( self::class, 'strip' ), $field['sub_fields'] );
		}

		if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			$layouts = array();

			foreach ( $field['layouts'] as $layout ) {
				if ( ! is_array( $layout ) ) {
					continue;
				}

				unset( $layout['key'] );

				if ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
					$layout['sub_fields'] = array_map( array( self::class, 'strip' ), $layout['sub_fields'] );
				}

				$layouts[] = $layout;
			}

			$field['layouts'] = $layouts;
		}

		return $field;
	}
}
