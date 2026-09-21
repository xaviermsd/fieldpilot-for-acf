<?php
/**
 * The outcome of validating a payload.
 *
 * Validation collects every finding rather than throwing on the first. One round
 * trip that lists eight problems beats eight round trips that list one - for a
 * human, and much more so for an AI regenerating its output.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

defined( 'ABSPATH' ) || exit;

final class Report implements \JsonSerializable {

	/** @var list<Issue> */
	private array $issues = array();

	public function add( Issue $issue ): void {
		$this->issues[] = $issue;
	}

	/** @param list<Issue> $issues */
	public function addAll( array $issues ): void {
		foreach ( $issues as $issue ) {
			$this->add( $issue );
		}
	}

	public function isValid(): bool {
		return array() === $this->errors();
	}

	/** @return list<Issue> */
	public function errors(): array {
		return array_values( array_filter( $this->issues, static fn( Issue $i ): bool => $i->isError() ) );
	}

	/** @return list<Issue> */
	public function warnings(): array {
		return array_values( array_filter( $this->issues, static fn( Issue $i ): bool => ! $i->isError() ) );
	}

	/** @return list<Issue> */
	public function all(): array {
		return $this->issues;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'valid'    => $this->isValid(),
			'errors'   => array_map( static fn( Issue $i ): array => $i->jsonSerialize(), $this->errors() ),
			'warnings' => array_map( static fn( Issue $i ): array => $i->jsonSerialize(), $this->warnings() ),
		);
	}
}
