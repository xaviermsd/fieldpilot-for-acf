<?php
/**
 * The REST surface: full parity with the admin UI.
 *
 * Parity is a design rule, not a nice-to-have. Anything the UI can do must be
 * scriptable, because the developers this product is for run deployments, and a
 * configuration tool that only works when a human clicks is a tool that gets
 * worked around.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Rest;

use ACFJP\Acf\GroupLocator;
use ACFJP\Acf\MutabilityClassifier;
use ACFJP\Acf\TreeReader;
use ACFJP\Apply\Engine;
use ACFJP\Apply\Guard;
use ACFJP\Diagnostics\SelfTest;
use ACFJP\Journal\Journal;
use ACFJP\Json\FieldTypeSchemas;
use ACFJP\Model\Operation;

defined( 'ABSPATH' ) || exit;

final class Controller {

	public const NAMESPACE = 'fieldpilot-for-acf/v1';

	public function __construct(
		private readonly Engine $engine,
		private readonly Journal $journal,
		private readonly MutabilityClassifier $mutability,
		private readonly TreeReader $reader,
		private readonly GroupLocator $groups,
		private readonly FieldTypeSchemas $schemas,
		private readonly SelfTest $selfTest,
	) {}

	public function registerRoutes(): void {
		$auth = array( $this, 'authorize' );

		register_rest_route(
			self::NAMESPACE,
			'/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'validate' ),
				'args'                => $this->payloadArgs(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/plan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'plan' ),
				'args'                => $this->payloadArgs(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'apply' ),
				'args'                => array(
					'plan_id'     => array( 'type' => 'string', 'required' => true ),
					'resolutions' => array( 'type' => 'object', 'default' => array() ),
					'confirm'     => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/field-groups',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'listGroups' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/field-groups/(?P<key>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'getGroup' ),
				'args'                => array(
					'key'     => array( 'type' => 'string', 'required' => true ),
					'dialect' => array( 'type' => 'string', 'default' => 'native', 'enum' => array( 'native', 'acfjp' ) ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'history' ),
				'args'                => array(
					'group_key' => array( 'type' => 'string' ),
					'limit'     => array( 'type' => 'integer', 'default' => 25 ),
					'offset'    => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/history/(?P<id>\d+)/rollback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'rollback' ),
				'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/self-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'selfTest' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/prompt',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $auth,
				'callback'            => array( $this, 'prompt' ),
				'args'                => array(
					'group_key' => array( 'type' => 'string' ),
					'intent'    => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}

	/**
	 * Capability only. WordPress already enforces the REST nonce for cookie-based
	 * requests; application passwords and other auth schemes are handled upstream.
	 */
	public function authorize(): bool|\WP_Error {
		$capability = (string) apply_filters( 'acfjp/capability', Guard::CAPABILITY );

		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new \WP_Error(
			'acfjp_forbidden',
			__( 'You do not have permission to manage ACF field configuration.', 'fieldpilot-for-acf' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	// ---- Handlers ------------------------------------------------------------

	public function validate( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$report = $this->engine->validate(
				$this->inputFrom( $request ),
				$this->operationFrom( $request )
			);

			return ErrorEnvelope::ok( array( 'validation' => $report->jsonSerialize() ) );
		} catch ( \Throwable $e ) {
			return ErrorEnvelope::fromThrowable( $e );
		}
	}

	public function plan( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$plan = $this->engine->plan(
				$this->inputFrom( $request ),
				$this->operationFrom( $request ),
				array( 'skip_data_probe' => (bool) $request->get_param( 'skip_data_probe' ) )
			);

			return ErrorEnvelope::ok( $plan->jsonSerialize() );
		} catch ( \Throwable $e ) {
			return ErrorEnvelope::fromThrowable( $e );
		}
	}

	public function apply( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$resolutions = (array) ( $request->get_param( 'resolutions' ) ?? array() );

			$result = $this->engine->apply(
				(string) $request->get_param( 'plan_id' ),
				array_map( 'strval', $resolutions ),
				(bool) $request->get_param( 'confirm' ),
				'rest'
			);

			return ErrorEnvelope::ok( $result->jsonSerialize() );
		} catch ( \Throwable $e ) {
			return ErrorEnvelope::fromThrowable( $e );
		}
	}

	public function listGroups(): \WP_REST_Response {
		$groups = array();

		foreach ( $this->mutability->classifyAll() as $report ) {
			$groups[] = $report->jsonSerialize();
		}

		return ErrorEnvelope::ok(
			array(
				'field_groups' => $groups,
				'capabilities' => array(
					'field_type_schemas' => $this->schemas->available(),
					'installed_types'    => $this->schemas->installedTypes(),
				),
			)
		);
	}

	public function getGroup( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$key = $this->groups->locate( (string) $request->get_param( 'key' ) );

			return ErrorEnvelope::ok(
				array(
					'field_group' => $this->reader->exportArray( $key ),
					'mutability'  => $this->mutability->classify( $key )->jsonSerialize(),
				)
			);
		} catch ( \Throwable $e ) {
			return ErrorEnvelope::fromThrowable( $e );
		}
	}

	public function history( \WP_REST_Request $request ): \WP_REST_Response {
		$groupKey = $request->get_param( 'group_key' );

		$entries = $this->journal->find(
			array(
				'group_key' => is_string( $groupKey ) ? $groupKey : null,
				'limit'     => (int) $request->get_param( 'limit' ),
				'offset'    => (int) $request->get_param( 'offset' ),
			)
		);

		return ErrorEnvelope::ok(
			array(
				'entries' => array_map( static fn ( $e ): array => $e->jsonSerialize(), $entries ),
				'total'   => $this->journal->countAll( is_string( $groupKey ) ? $groupKey : null ),
			)
		);
	}

	public function rollback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$entry = $this->engine->rollback( (int) $request->get_param( 'id' ), 'rest' );

			return ErrorEnvelope::ok(
				array(
					'rolled_back' => $entry->jsonSerialize(),
					'message'     => sprintf(
						/* translators: %s: field group title */
						__( '"%s" was restored to its previous state.', 'fieldpilot-for-acf' ),
						$entry->groupTitle
					),
				)
			);
		} catch ( \Throwable $e ) {
			return ErrorEnvelope::fromThrowable( $e );
		}
	}

	public function selfTest(): \WP_REST_Response {
		try {
			return ErrorEnvelope::ok( array( 'self_test' => $this->selfTest->run()->jsonSerialize() ) );
		} catch ( \Throwable $e ) {
			return ErrorEnvelope::fromThrowable( $e );
		}
	}

	public function schema(): \WP_REST_Response {
		$path = ACFJP_DIR . 'schemas/fieldpilot-for-acf-v1.json';

		$schema = file_exists( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions

		return ErrorEnvelope::ok(
			array(
				'version' => '1.0',
				'schema'  => is_array( $schema ) ? $schema : array(),
			)
		);
	}

	public function prompt( \WP_REST_Request $request ): \WP_REST_Response {
		$generator = new PromptGenerator( $this->reader, $this->groups, $this->schemas );

		$groupKey = $request->get_param( 'group_key' );

		return ErrorEnvelope::ok(
			array(
				'prompt' => $generator->generate(
					is_string( $groupKey ) && '' !== $groupKey ? $groupKey : null,
					(string) $request->get_param( 'intent' )
				),
			)
		);
	}

	// ---- Helpers -------------------------------------------------------------

	/**
	 * @return string|array<string,mixed>
	 */
	private function inputFrom( \WP_REST_Request $request ): string|array {
		$json = $request->get_param( 'json' );

		if ( is_string( $json ) ) {
			return $json;
		}

		$payload = $request->get_param( 'payload' );

		return is_array( $payload ) ? $payload : (string) $json;
	}

	private function operationFrom( \WP_REST_Request $request ): ?Operation {
		$operation = $request->get_param( 'operation' );

		if ( ! is_string( $operation ) || '' === $operation ) {
			return null;
		}

		try {
			return Operation::fromString( $operation );
		} catch ( \ValueError ) {
			return null;
		}
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function payloadArgs(): array {
		return array(
			'json'            => array( 'type' => 'string' ),
			'payload'         => array( 'type' => 'object' ),
			'operation'       => array( 'type' => 'string', 'enum' => Operation::names() ),
			'skip_data_probe' => array( 'type' => 'boolean', 'default' => false ),
		);
	}
}
