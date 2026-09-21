<?php
/**
 * Shared behaviour for admin screens.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin\Screens;

use ACFJP\Admin\Menu;
use ACFJP\Apply\Guard;
use ACFJP\Core\Container;

defined( 'ABSPATH' ) || exit;

abstract class Screen {

	public function __construct( protected readonly Container $container ) {}

	abstract protected function title(): string;

	abstract protected function body(): void;

	public function render(): void {
		if ( ! current_user_can( (string) apply_filters( 'acfjp_capability', Guard::CAPABILITY ) ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'fieldpilot-for-acf' ) );
		}

		echo '<div class="wrap acfjp">';
		printf( '<h1>%s</h1>', esc_html( $this->title() ) );

		$this->body();

		echo '</div>';
	}

	protected function url( string $suffix = '', array $args = array() ): string {
		return add_query_arg(
			$args,
			admin_url( 'admin.php?page=' . Menu::SLUG . $suffix )
		);
	}

	/**
	 * Render a notice.
	 */
	protected function notice( string $message, string $type = 'info' ): void {
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $type ),
			wp_kses_post( $message )
		);
	}
}
