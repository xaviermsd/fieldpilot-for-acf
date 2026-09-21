<?php
/**
 * Composition root.
 *
 * Every service is registered here and nowhere else. No class in this plugin calls
 * `new` on another service or reaches for a global singleton - they take their
 * collaborators as constructor arguments, which is what makes the pure layers
 * testable without WordPress loaded.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Core;

use ACFJP\Acf\DataProbe;
use ACFJP\Acf\GroupLocator;
use ACFJP\Acf\KeyFactory;
use ACFJP\Acf\MutabilityClassifier;
use ACFJP\Acf\TreeReader;
use ACFJP\Admin\Assets;
use ACFJP\Admin\Menu;
use ACFJP\Apply\Engine;
use ACFJP\Apply\Guard;
use ACFJP\Apply\PlanStore;
use ACFJP\Apply\Restore;
use ACFJP\Apply\Verifier;
use ACFJP\Apply\Writer;
use ACFJP\Cli\Command;
use ACFJP\Diagnostics\SelfTest;
use ACFJP\Diff\Comparator;
use ACFJP\Diff\Matcher;
use ACFJP\Diff\SettingsDiff;
use ACFJP\Journal\Journal;
use ACFJP\Journal\Retention;
use ACFJP\Journal\SnapshotStore;
use ACFJP\Json\Dialect;
use ACFJP\Json\FieldTypeSchemas;
use ACFJP\Json\Normalizer;
use ACFJP\Json\Parser;
use ACFJP\Json\SchemaValidator;
use ACFJP\Json\Validator;
use ACFJP\Resolve\TargetResolver;
use ACFJP\Rest\Controller;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
		$this->register();
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function container(): Container {
		return $this->container;
	}

	public function engine(): Engine {
		return $this->container->get( Engine::class );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Activation::maybeMigrate();

		add_action( 'init', array( $this, 'loadTextDomain' ) );

		if ( is_admin() ) {
			$this->container->get( Menu::class )->register();
			$this->container->get( Assets::class )->register();
		}

		add_action(
			'rest_api_init',
			fn () => $this->container->get( Controller::class )->registerRoutes()
		);

		$this->container->get( Retention::class )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Command::register( $this->engine(), $this->container );
		}

		/**
		 * Fires once FieldPilot is ready. Add-ons should hook here.
		 *
		 * @param Container $container
		 */
		do_action( 'acfjp/booted', $this->container );
	}

	public function loadTextDomain(): void {
		load_plugin_textdomain( 'fieldpilot-for-acf', false, dirname( plugin_basename( ACFJP_FILE ) ) . '/languages' );
	}

	/**
	 * Service definitions. Order is irrelevant - everything is lazy.
	 */
	private function register(): void {
		$c = $this->container;

		// -- Stateless helpers -------------------------------------------------
		$c->set( SchemaValidator::class, static fn (): SchemaValidator => new SchemaValidator() );
		$c->set( Dialect::class, static fn (): Dialect => new Dialect() );
		$c->set( Matcher::class, static fn (): Matcher => new Matcher() );
		$c->set( SettingsDiff::class, static fn (): SettingsDiff => new SettingsDiff() );
		$c->set( Parser::class, static fn (): Parser => new Parser() );

		// -- ACF-facing --------------------------------------------------------
		$c->set( KeyFactory::class, static fn (): KeyFactory => new KeyFactory() );
		$c->set( TreeReader::class, static fn (): TreeReader => new TreeReader() );
		$c->set( GroupLocator::class, static fn (): GroupLocator => new GroupLocator() );
		$c->set( MutabilityClassifier::class, static fn (): MutabilityClassifier => new MutabilityClassifier() );
		$c->set(
			DataProbe::class,
			static function (): DataProbe {
				$settings = (array) get_option( 'acfjp_settings', array() );

				return new DataProbe( ! isset( $settings['data_probe'] ) || (bool) $settings['data_probe'] );
			}
		);

		$c->set(
			FieldTypeSchemas::class,
			static fn ( Container $c ): FieldTypeSchemas => new FieldTypeSchemas( $c->get( SchemaValidator::class ) )
		);

		// -- Pure pipeline -----------------------------------------------------
		$c->set(
			Normalizer::class,
			static fn ( Container $c ): Normalizer => new Normalizer( $c->get( Dialect::class ) )
		);

		$c->set(
			Validator::class,
			static fn ( Container $c ): Validator => new Validator( $c->get( FieldTypeSchemas::class ) )
		);

		$c->set( TargetResolver::class, static fn (): TargetResolver => new TargetResolver() );

		$c->set(
			Comparator::class,
			static fn ( Container $c ): Comparator => new Comparator(
				$c->get( TargetResolver::class ),
				$c->get( Matcher::class ),
				$c->get( SettingsDiff::class ),
				$c->get( DataProbe::class ),
			)
		);

		// -- History -----------------------------------------------------------
		$c->set( SnapshotStore::class, static fn (): SnapshotStore => new SnapshotStore() );

		$c->set(
			Journal::class,
			static fn ( Container $c ): Journal => new Journal( $c->get( SnapshotStore::class ) )
		);

		$c->set(
			Retention::class,
			static fn ( Container $c ): Retention => new Retention( $c->get( Journal::class ) )
		);

		// -- Effect zone -------------------------------------------------------
		$c->set(
			Guard::class,
			static fn ( Container $c ): Guard => new Guard(
				$c->get( MutabilityClassifier::class ),
				$c->get( FieldTypeSchemas::class ),
			)
		);

		$c->set(
			Writer::class,
			static fn ( Container $c ): Writer => new Writer( $c->get( KeyFactory::class ) )
		);

		$c->set(
			Verifier::class,
			static fn ( Container $c ): Verifier => new Verifier(
				$c->get( TreeReader::class ),
				$c->get( SettingsDiff::class ),
			)
		);

		$c->set(
			Restore::class,
			static fn ( Container $c ): Restore => new Restore(
				$c->get( SnapshotStore::class ),
				$c->get( TreeReader::class ),
			)
		);

		$c->set( PlanStore::class, static fn (): PlanStore => new PlanStore() );

		$c->set(
			Engine::class,
			static fn ( Container $c ): Engine => new Engine(
				$c->get( Parser::class ),
				$c->get( Normalizer::class ),
				$c->get( Validator::class ),
				$c->get( GroupLocator::class ),
				$c->get( TreeReader::class ),
				$c->get( TargetResolver::class ),
				$c->get( Comparator::class ),
				$c->get( Guard::class ),
				$c->get( Writer::class ),
				$c->get( Verifier::class ),
				$c->get( Restore::class ),
				$c->get( SnapshotStore::class ),
				$c->get( Journal::class ),
				$c->get( PlanStore::class ),
			)
		);

		$c->set(
			SelfTest::class,
			static fn ( Container $c ): SelfTest => new SelfTest(
				$c->get( Engine::class ),
				$c->get( GroupLocator::class ),
				$c->get( TreeReader::class ),
			)
		);

		// -- Surfaces ----------------------------------------------------------
		$c->set(
			Controller::class,
			static fn ( Container $c ): Controller => new Controller(
				$c->get( Engine::class ),
				$c->get( Journal::class ),
				$c->get( MutabilityClassifier::class ),
				$c->get( TreeReader::class ),
				$c->get( GroupLocator::class ),
				$c->get( FieldTypeSchemas::class ),
				$c->get( SelfTest::class ),
			)
		);

		$c->set(
			Menu::class,
			static fn ( Container $c ): Menu => new Menu( $c )
		);

		$c->set( Assets::class, static fn (): Assets => new Assets() );
	}
}
