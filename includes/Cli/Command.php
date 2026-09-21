<?php
/**
 * WP-CLI surface.
 *
 * Every subcommand routes through the same Engine as the UI, so a deployment
 * script and a human click produce identical journal entries and identical safety
 * behaviour. `apply` refuses destructive batches without --confirm, deliberately:
 * an unattended script is exactly where an accidental delete does most damage.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Cli;

use ACFJP\Acf\MutabilityClassifier;
use ACFJP\Apply\Engine;
use ACFJP\Core\Container;
use ACFJP\Diagnostics\Check;
use ACFJP\Diagnostics\SelfTest;
use ACFJP\Exceptions\AcfjpException;
use ACFJP\Journal\Journal;
use ACFJP\Model\Operation;

defined( 'ABSPATH' ) || exit;

final class Command {

	public function __construct(
		private readonly Engine $engine,
		private readonly Container $container,
	) {}

	public static function register( Engine $engine, Container $container ): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'acfjp', new self( $engine, $container ) );
	}

	/**
	 * Validate a JSON payload without touching ACF.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to a .json file. Reads STDIN when omitted.
	 *
	 * [--operation=<operation>]
	 * : Operation to assume when the payload declares none.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function validate( array $args, array $assoc ): void {
		try {
			$report = $this->engine->validate( $this->input( $args ), $this->operation( $assoc ) );

			foreach ( $report->warnings() as $warning ) {
				\WP_CLI::warning( $warning->message );
			}

			foreach ( $report->errors() as $error ) {
				\WP_CLI::log( \WP_CLI::colorize( '%r✗%n ' ) . $error->message );
			}

			if ( $report->isValid() ) {
				\WP_CLI::success( 'Payload is valid.' );
				return;
			}

			\WP_CLI::error( sprintf( '%d problem(s) found.', count( $report->errors() ) ) );
		} catch ( AcfjpException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Preview what a payload would change. Writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to a .json file. Reads STDIN when omitted.
	 *
	 * [--operation=<operation>]
	 * : Operation to assume when the payload declares none.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function plan( array $args, array $assoc ): void {
		try {
			$plan = $this->engine->plan( $this->input( $args ), $this->operation( $assoc ) );

			if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
				\WP_CLI::line( (string) wp_json_encode( $plan->jsonSerialize(), JSON_PRETTY_PRINT ) );
				return;
			}

			$this->renderPlan( $plan );
		} catch ( AcfjpException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Apply a payload.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to a .json file. Reads STDIN when omitted.
	 *
	 * [--operation=<operation>]
	 * : Operation to assume when the payload declares none.
	 *
	 * [--confirm]
	 * : Required when the batch contains destructive changes.
	 *
	 * [--resolve=<pairs>]
	 * : Conflict resolutions as conflict_id:option,conflict_id:option.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function apply( array $args, array $assoc ): void {
		try {
			$result = $this->engine->run(
				$this->input( $args ),
				$this->operation( $assoc ),
				$this->resolutions( $assoc ),
				isset( $assoc['confirm'] ),
				'cli'
			);

			\WP_CLI::success(
				sprintf(
					'%d change(s) applied to "%s". Roll back with: wp acfjp rollback %d',
					$result->changeSet->count(),
					$result->changeSet->groupTitle,
					$result->journalId
				)
			);
		} catch ( AcfjpException $e ) {
			foreach ( $e->suggestions() as $suggestion ) {
				\WP_CLI::log( '  → ' . $suggestion );
			}

			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Export a field group as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <group>
	 * : Field group title or key.
	 *
	 * [--file=<path>]
	 * : Write to this file instead of STDOUT.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function export( array $args, array $assoc ): void {
		try {
			$reader = $this->container->get( \ACFJP\Acf\TreeReader::class );
			$groups = $this->container->get( \ACFJP\Acf\GroupLocator::class );

			$key  = $groups->locate( (string) ( $args[0] ?? '' ) );
			$json = (string) wp_json_encode( $reader->exportArray( $key ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( isset( $assoc['file'] ) ) {
				file_put_contents( $assoc['file'], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				\WP_CLI::success( sprintf( 'Exported to %s', $assoc['file'] ) );
				return;
			}

			\WP_CLI::line( $json );
		} catch ( AcfjpException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * List field groups and whether this plugin can change them.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function groups( array $args, array $assoc ): void {
		$classifier = $this->container->get( MutabilityClassifier::class );

		$rows = array();

		foreach ( $classifier->classifyAll() as $report ) {
			$rows[] = array(
				'title'     => $report->groupTitle,
				'key'       => $report->groupKey,
				'source'    => $report->mutability->value,
				'patchable' => $report->isPatchable() ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'title', 'key', 'source', 'patchable' ) );
	}

	/**
	 * Show change history.
	 *
	 * ## OPTIONS
	 *
	 * [--group=<group>]
	 * : Limit to one field group key.
	 *
	 * [--limit=<n>]
	 * : Default 20.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function history( array $args, array $assoc ): void {
		$journal = $this->container->get( Journal::class );

		$entries = $journal->find(
			array(
				'group_key' => $assoc['group'] ?? null,
				'limit'     => (int) ( $assoc['limit'] ?? 20 ),
			)
		);

		$rows = array();

		foreach ( $entries as $entry ) {
			$rows[] = array(
				'id'        => $entry->id,
				'when'      => $entry->createdAt,
				'group'     => $entry->groupTitle,
				'operation' => $entry->operation,
				'changes'   => $entry->changeCount,
				'status'    => $entry->status,
			);
		}

		\WP_CLI\Utils\format_items(
			$assoc['format'] ?? 'table',
			$rows,
			array( 'id', 'when', 'group', 'operation', 'changes', 'status' )
		);
	}

	/**
	 * Roll a change back.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : History entry id, from `wp acfjp history`.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function rollback( array $args, array $assoc ): void {
		try {
			$entry = $this->engine->rollback( (int) ( $args[0] ?? 0 ), 'cli' );

			\WP_CLI::success( sprintf( '"%s" restored to its state before change #%d.', $entry->groupTitle, $entry->id ) );
		} catch ( AcfjpException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Print a ready-to-paste AI prompt.
	 *
	 * ## OPTIONS
	 *
	 * [--group=<group>]
	 * : Include this field group's current structure.
	 *
	 * [--intent=<text>]
	 * : Describe the change you want.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function prompt( array $args, array $assoc ): void {
		$generator = new \ACFJP\Rest\PromptGenerator(
			$this->container->get( \ACFJP\Acf\TreeReader::class ),
			$this->container->get( \ACFJP\Acf\GroupLocator::class ),
			$this->container->get( \ACFJP\Json\FieldTypeSchemas::class ),
		);

		\WP_CLI::line( $generator->generate( $assoc['group'] ?? null, (string) ( $assoc['intent'] ?? '' ) ) );
	}

	/**
	 * Run the engine end to end against this installation's real ACF.
	 *
	 * Creates one temporary field group of its own and removes it afterwards. It
	 * never touches a field group it did not create. Exits non-zero on failure, so
	 * it can gate a deployment.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @subcommand self-test
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public function self_test( array $args, array $assoc ): void {
		$result = $this->container->get( SelfTest::class )->run();

		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $result->jsonSerialize(), JSON_PRETTY_PRINT ) );

			if ( ! $result->ok() ) {
				\WP_CLI::halt( 1 );
			}

			return;
		}

		foreach ( $result->bySection() as $section => $checks ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( \WP_CLI::colorize( '%B' . $section . '%n' ) );

			foreach ( $checks as $check ) {
				$marker = match ( $check->status ) {
					Check::PASS => \WP_CLI::colorize( '  %g✓%n ' ),
					Check::FAIL => \WP_CLI::colorize( '  %r✗%n ' ),
					default     => \WP_CLI::colorize( '  %y‒%n ' ),
				};

				\WP_CLI::log( $marker . $check->name );

				if ( '' !== $check->detail ) {
					\WP_CLI::log( '      ' . $check->detail );
				}
			}
		}

		\WP_CLI::log( '' );

		$summary = sprintf(
			'%d passed, %d failed, %d skipped in %dms',
			$result->passedCount(),
			$result->failedCount(),
			$result->skippedCount(),
			$result->durationMs()
		);

		if ( $result->ok() ) {
			\WP_CLI::success( $summary . ' - the write path works on this install.' );

			return;
		}

		if ( $result->hasCriticalFailure() ) {
			\WP_CLI::log( \WP_CLI::colorize( '%rSomething that protects your configuration failed. Do not use this on data you care about yet.%n' ) );
		}

		\WP_CLI::error( $summary, $result->failedCount() );
	}

	// ---- Helpers -------------------------------------------------------------

	private function renderPlan( \ACFJP\Apply\Plan $plan ): void {
		$changeSet = $plan->changeSet;

		foreach ( $plan->report->errors() as $error ) {
			\WP_CLI::warning( $error->message );
		}

		if ( $changeSet->isEmpty() ) {
			\WP_CLI::log( 'No changes.' );
			return;
		}

		\WP_CLI::log( sprintf( '%s - %d change(s)', $changeSet->groupTitle, $changeSet->count() ) );
		\WP_CLI::log( '' );

		foreach ( $changeSet->changes as $change ) {
			$marker = match ( $change->type ) {
				'add', 'add_layout'       => '+',
				'delete', 'delete_layout' => '-',
				'move'                    => '»',
				default                   => '~',
			};

			\WP_CLI::log( sprintf( '  %s %s - %s [%s]', $marker, $change->label, $change->summary(), $change->risk->value ) );

			if ( $change->hasConflict() ) {
				\WP_CLI::log( sprintf( '      conflict %s: %s', (string) $change->conflict?->id, (string) $change->conflict?->title ) );
			}
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'plan_id: %s', $plan->id ) );
	}

	/**
	 * @param list<string> $args
	 */
	private function input( array $args ): string {
		if ( isset( $args[0] ) && '' !== $args[0] ) {
			if ( ! file_exists( $args[0] ) ) {
				\WP_CLI::error( sprintf( 'File not found: %s', $args[0] ) );
			}

			return (string) file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return (string) file_get_contents( 'php://stdin' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * @param array<string,string> $assoc
	 */
	private function operation( array $assoc ): ?Operation {
		if ( empty( $assoc['operation'] ) ) {
			return null;
		}

		try {
			return Operation::fromString( $assoc['operation'] );
		} catch ( \ValueError ) {
			\WP_CLI::error( sprintf( 'Unknown operation "%s". Use one of: %s', $assoc['operation'], implode( ', ', Operation::names() ) ) );
		}
	}

	/**
	 * @param array<string,string> $assoc
	 * @return array<string,string>
	 */
	private function resolutions( array $assoc ): array {
		if ( empty( $assoc['resolve'] ) ) {
			return array();
		}

		$out = array();

		foreach ( explode( ',', $assoc['resolve'] ) as $pair ) {
			$parts = explode( ':', trim( $pair ), 2 );

			if ( 2 === count( $parts ) ) {
				$out[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		return $out;
	}
}
