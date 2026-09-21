<?php
/**
 * Environment requirement checks.
 *
 * Contract: this class NEVER throws, NEVER calls wp_die(), and NEVER deactivates
 * anything. A configuration-management plugin that fatals the site when its
 * dependency is missing is worse than useless - it takes down the very site the
 * developer is trying to fix. It reports, and the bootstrap returns early.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Core;

defined( 'ABSPATH' ) || exit;

final class Requirements {

	/** @var list<string> Human-readable, translated failure reasons. */
	private array $failures = array();

	private ?bool $result = null;

	/**
	 * Whether every hard requirement is satisfied.
	 */
	public function met(): bool {
		if ( null !== $this->result ) {
			return $this->result;
		}

		$this->failures = array();

		if ( version_compare( PHP_VERSION, ACFJP_MIN_PHP, '<' ) ) {
			$this->failures[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'PHP %1$s or newer is required. This site runs PHP %2$s.', 'wp-acf-json-pro' ),
				ACFJP_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), ACFJP_MIN_WP, '<' ) ) {
			$this->failures[] = sprintf(
				/* translators: 1: required WP version, 2: current WP version */
				__( 'WordPress %1$s or newer is required. This site runs WordPress %2$s.', 'wp-acf-json-pro' ),
				ACFJP_MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		// Feature detection, not version sniffing: a function we actually call.
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			$this->failures[] = __( 'Advanced Custom Fields is not active. WP ACF JSON Pro extends ACF and cannot run without it.', 'wp-acf-json-pro' );
		} elseif ( defined( 'ACF_VERSION' ) && version_compare( ACF_VERSION, ACFJP_MIN_ACF, '<' ) ) {
			$this->failures[] = sprintf(
				/* translators: 1: required ACF version, 2: current ACF version */
				__( 'Advanced Custom Fields %1$s or newer is required. This site runs ACF %2$s.', 'wp-acf-json-pro' ),
				ACFJP_MIN_ACF,
				ACF_VERSION
			);
		}

		$this->result = array() === $this->failures;

		return $this->result;
	}

	/**
	 * Soft capabilities - present or absent, never fatal. Surfaced in the UI so the
	 * developer understands why validation is stricter or looser on their install.
	 *
	 * @return array<string,bool>
	 */
	public function optional(): array {
		return array(
			// ACF 6.8.0+ ships per-field-type JSON Schemas we validate against.
			// Below that we degrade to loose validation. See ARCHITECTURE-REVIEW B.1.
			'field_type_schemas' => function_exists( 'acf_get_field_json_schema' ),
			// ACF PRO supplies repeater, flexible_content, clone and gallery.
			'acf_pro'            => class_exists( 'acf_pro' ) || defined( 'ACF_PRO' ),
		);
	}

	/**
	 * @return list<string>
	 */
	public function failures(): array {
		$this->met();
		return $this->failures;
	}

	/**
	 * Register the admin notice describing why the plugin is dormant.
	 */
	public function registerNotice(): void {
		add_action(
			'admin_notices',
			function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-error"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px">%s</ul></div>',
					esc_html__( 'WP ACF JSON Pro is inactive.', 'wp-acf-json-pro' ),
					implode(
						'',
						array_map(
							static fn( string $f ): string => '<li>' . esc_html( $f ) . '</li>',
							$this->failures()
						)
					)
				);
			}
		);
	}
}
