<?php
/**
 * Finds a field group from whatever the developer wrote.
 *
 * Accepts a key, an exact title, or a close-enough title. Ambiguity is always an
 * error - two groups called "Settings" is exactly the situation where guessing
 * writes to the wrong one.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Acf;

use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\ResolutionException;

defined( 'ABSPATH' ) || exit;

final class GroupLocator {

	/** @var list<array{key:string,title:string}>|null */
	private ?array $index = null;

	/**
	 * @throws ResolutionException When the group is missing or the reference is ambiguous.
	 */
	public function locate( string $reference ): string {
		$reference = trim( $reference );

		if ( '' === $reference ) {
			$e = new ResolutionException(
				ErrorCodes::MISSING_TARGET,
				esc_html__( 'No field group was named.', 'fieldpilot-for-acf' ),
				array(),
				$this->allLabels()
			);
			throw $e;
		}

		// 1. Exact key.
		if ( str_starts_with( $reference, 'group_' ) ) {
			foreach ( $this->groups() as $group ) {
				if ( $group['key'] === $reference ) {
					return $group['key'];
				}
			}

			$e = new ResolutionException(
				ErrorCodes::GROUP_NOT_FOUND,
				sprintf(
					/* translators: %s: field group key */
					__( 'No field group has the key "%s".', 'fieldpilot-for-acf' ),
					$reference
				),
				array( 'reference' => $reference ),
				$this->allLabels()
			);
			throw $e;
		}

		// 2. Exact title, case-insensitive.
		$exact = array();

		foreach ( $this->groups() as $group ) {
			if ( 0 === strcasecmp( $group['title'], $reference ) ) {
				$exact[] = $group;
			}
		}

		if ( 1 === count( $exact ) ) {
			return $exact[0]['key'];
		}

		if ( count( $exact ) > 1 ) {
			$e = new ResolutionException(
				ErrorCodes::AMBIGUOUS_TARGET,
				sprintf(
					/* translators: %1$d: number of matches, %2$s: the title */
					__( '%1$d field groups are called "%2$s". Target one by key instead.', 'fieldpilot-for-acf' ),
					count( $exact ),
					$reference
				),
				array( 'reference' => $reference, 'matches' => array_column( $exact, 'key' ) ),
				array_map(
					static fn( array $g ): string => sprintf( '%s (%s)', $g['title'], $g['key'] ),
					$exact
				)
			);
			throw $e;
		}

		// 3. Nothing matched. Suggest, never guess.
		$e = new ResolutionException(
			ErrorCodes::GROUP_NOT_FOUND,
			sprintf(
				/* translators: %s: the field group reference supplied */
				__( 'Field group "%s" was not found.', 'fieldpilot-for-acf' ),
				$reference
			),
			array( 'reference' => $reference ),
			$this->suggest( $reference )
		);
		throw $e;
	}

	public function exists( string $reference ): bool {
		try {
			$this->locate( $reference );

			return true;
		} catch ( ResolutionException ) {
			return false;
		}
	}

	/**
	 * @return list<array{key:string,title:string}>
	 */
	public function groups(): array {
		if ( null !== $this->index ) {
			return $this->index;
		}

		$this->index = array();

		foreach ( acf_get_field_groups() as $group ) {
			if ( empty( $group['key'] ) ) {
				continue;
			}

			$this->index[] = array(
				'key'   => (string) $group['key'],
				'title' => (string) ( $group['title'] ?? $group['key'] ),
			);
		}

		return $this->index;
	}

	public function forget(): void {
		$this->index = null;
	}

	/** @return list<string> */
	private function allLabels(): array {
		return array_map(
			static fn( array $g ): string => sprintf( '%s (%s)', $g['title'], $g['key'] ),
			array_slice( $this->groups(), 0, 10 )
		);
	}

	/** @return list<string> */
	private function suggest( string $needle ): array {
		$scored = array();
		$n      = substr( strtolower( $needle ), 0, 255 );

		foreach ( $this->groups() as $group ) {
			$t = substr( strtolower( $group['title'] ), 0, 255 );

			$scored[] = array(
				'label' => sprintf( '%s (%s)', $group['title'], $group['key'] ),
				'score' => levenshtein( $n, $t ),
			);
		}

		usort( $scored, static fn( array $a, array $b ): int => $a['score'] <=> $b['score'] );

		return array_column( array_slice( $scored, 0, 5 ), 'label' );
	}
}
