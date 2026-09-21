<?php
/**
 * The outcome of a self-test run.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Result implements \JsonSerializable {

	/** @var list<Check> */
	private array $checks = array();

	private float $startedAt;

	public function __construct() {
		$this->startedAt = microtime( true );
	}

	public function add( Check $check ): Check {
		$this->checks[] = $check;

		return $check;
	}

	/** @param list<Check> $checks */
	public function addAll( array $checks ): void {
		foreach ( $checks as $check ) {
			$this->add( $check );
		}
	}

	/** @return list<Check> */
	public function all(): array {
		return $this->checks;
	}

	/** @return list<Check> */
	public function failures(): array {
		return array_values( array_filter( $this->checks, static fn ( Check $c ): bool => $c->failed() ) );
	}

	public function passedCount(): int {
		return count( array_filter( $this->checks, static fn ( Check $c ): bool => $c->passed() ) );
	}

	public function failedCount(): int {
		return count( $this->failures() );
	}

	public function skippedCount(): int {
		return count( array_filter( $this->checks, static fn ( Check $c ): bool => Check::SKIP === $c->status ) );
	}

	public function ok(): bool {
		return 0 === $this->failedCount();
	}

	/**
	 * Did anything that endangers configuration fail? Distinguishes "a cosmetic
	 * assertion is off" from "do not use this on real data".
	 */
	public function hasCriticalFailure(): bool {
		foreach ( $this->failures() as $failure ) {
			if ( $failure->critical ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks grouped by section, in insertion order.
	 *
	 * @return array<string,list<Check>>
	 */
	public function bySection(): array {
		$sections = array();

		foreach ( $this->checks as $check ) {
			$sections[ $check->section ][] = $check;
		}

		return $sections;
	}

	public function durationMs(): int {
		return (int) round( ( microtime( true ) - $this->startedAt ) * 1000 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'ok'       => $this->ok(),
			'critical' => $this->hasCriticalFailure(),
			'passed'   => $this->passedCount(),
			'failed'   => $this->failedCount(),
			'skipped'  => $this->skippedCount(),
			'duration' => $this->durationMs(),
			'sections' => array_map(
				static fn ( array $checks ): array => array_map(
					static fn ( Check $c ): array => $c->jsonSerialize(),
					$checks
				),
				$this->bySection()
			),
		);
	}
}
