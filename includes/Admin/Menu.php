<?php
/**
 * Admin menu and screen dispatch.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin;

use ACFJP\Admin\Screens\Dashboard;
use ACFJP\Admin\Screens\Diagnostics;
use ACFJP\Admin\Screens\Export;
use ACFJP\Admin\Screens\History;
use ACFJP\Admin\Screens\Import;
use ACFJP\Admin\Screens\Settings;
use ACFJP\Apply\Guard;
use ACFJP\Core\Container;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const SLUG = 'fieldpilot-for-acf';

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPages' ) );
	}

	public function addPages(): void {
		$capability = (string) apply_filters( 'acfjp/capability', Guard::CAPABILITY );

		add_menu_page(
			__( 'FieldPilot for ACF', 'fieldpilot-for-acf' ),
			__( 'FieldPilot', 'fieldpilot-for-acf' ),
			$capability,
			self::SLUG,
			array( $this, 'renderDashboard' ),
			'dashicons-superhero-alt',
			81
		);

		$pages = array(
			''          => __( 'Dashboard', 'fieldpilot-for-acf' ),
			'-import'   => __( 'Import JSON', 'fieldpilot-for-acf' ),
			'-history'  => __( 'History', 'fieldpilot-for-acf' ),
			'-export'   => __( 'Export', 'fieldpilot-for-acf' ),
			'-diagnostics' => __( 'Diagnostics', 'fieldpilot-for-acf' ),
			'-settings' => __( 'Settings', 'fieldpilot-for-acf' ),
		);

		$callbacks = array(
			''          => array( $this, 'renderDashboard' ),
			'-import'   => array( $this, 'renderImport' ),
			'-history'  => array( $this, 'renderHistory' ),
			'-export'   => array( $this, 'renderExport' ),
			'-diagnostics' => array( $this, 'renderDiagnostics' ),
			'-settings' => array( $this, 'renderSettings' ),
		);

		foreach ( $pages as $suffix => $title ) {
			add_submenu_page(
				self::SLUG,
				$title,
				$title,
				$capability,
				self::SLUG . $suffix,
				$callbacks[ $suffix ]
			);
		}
	}

	public function renderDashboard(): void {
		( new Dashboard( $this->container ) )->render();
	}

	public function renderImport(): void {
		( new Import( $this->container ) )->render();
	}

	public function renderHistory(): void {
		( new History( $this->container ) )->render();
	}

	public function renderExport(): void {
		( new Export( $this->container ) )->render();
	}

	public function renderDiagnostics(): void {
		( new Diagnostics( $this->container ) )->render();
	}

	public function renderSettings(): void {
		( new Settings( $this->container ) )->render();
	}
}
