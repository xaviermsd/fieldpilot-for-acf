<?php
/**
 * A minimal service container.
 *
 * Eighty lines instead of a library. It does lazy singletons and nothing else,
 * because nothing else is needed: this plugin has ~30 services and no runtime
 * configuration to inject. Auto-wiring, contextual bindings and interface maps
 * would all be abstraction we never spend.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Core;

defined( 'ABSPATH' ) || exit;

final class Container {

	/** @var array<string,\Closure> */
	private array $factories = array();

	/** @var array<string,object> */
	private array $resolved = array();

	/**
	 * Register a lazy singleton factory.
	 *
	 * @template T of object
	 * @param class-string<T>|string $id      Service id, conventionally the class name.
	 * @param \Closure(Container):T  $factory Receives the container, returns the service.
	 */
	public function set( string $id, \Closure $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Resolve a service, building it on first use.
	 *
	 * @template T of object
	 * @param class-string<T> $id Service id.
	 * @return T
	 * @throws \LogicException When the id was never registered.
	 */
	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			/** @var T */
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			$e = new \LogicException( sprintf( 'ACFJP: service "%s" is not registered.', esc_html( $id ) ) );
			throw $e;
		}

		/** @var T $service */
		$service = ( $this->factories[ $id ] )( $this );

		$this->resolved[ $id ] = $service;

		return $service;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Drop every resolved instance. Tests only.
	 */
	public function flush(): void {
		$this->resolved = array();
	}
}
