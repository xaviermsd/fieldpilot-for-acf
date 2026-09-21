<?php
/**
 * Dashboard: what exists, and what this plugin can actually change.
 *
 * The mutability column is the point of this screen. A developer should learn that
 * their PHP-registered field groups are off limits HERE, calmly, rather than after
 * pasting JSON and having it refused.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin\Screens;

use ACFJP\Acf\Mutability;
use ACFJP\Acf\MutabilityClassifier;
use ACFJP\Journal\Journal;
use ACFJP\Json\FieldTypeSchemas;

defined( 'ABSPATH' ) || exit;

final class Dashboard extends Screen {

	protected function title(): string {
		return __( 'WP ACF JSON Pro', 'fieldpilot-for-acf' );
	}

	protected function body(): void {
		$classifier = $this->container->get( MutabilityClassifier::class );
		$schemas    = $this->container->get( FieldTypeSchemas::class );
		$journal    = $this->container->get( Journal::class );

		$reports   = $classifier->classifyAll();
		$patchable = array_filter( $reports, static fn ( $r ): bool => $r->isPatchable() );

		echo '<div class="acfjp-cards">';

		$this->card(
			__( 'Field groups', 'fieldpilot-for-acf' ),
			(string) count( $reports ),
			sprintf(
				/* translators: %d: number of patchable field groups */
				__( '%d can be changed from here', 'fieldpilot-for-acf' ),
				count( $patchable )
			)
		);

		$this->card(
			__( 'Field types', 'fieldpilot-for-acf' ),
			(string) count( $schemas->installedTypes() ),
			$schemas->available()
				? __( 'Validated against ACF schemas', 'fieldpilot-for-acf' )
				: __( 'ACF 6.8+ enables strict validation', 'fieldpilot-for-acf' )
		);

		$this->card(
			__( 'Changes recorded', 'fieldpilot-for-acf' ),
			(string) $journal->countAll(),
			__( 'Every change can be rolled back', 'fieldpilot-for-acf' )
		);

		echo '</div>';

		printf(
			'<p><a href="%s" class="button button-primary button-hero">%s</a></p>',
			esc_url( $this->url( '-import' ) ),
			esc_html__( 'Import JSON', 'fieldpilot-for-acf' )
		);

		echo '<h2>' . esc_html__( 'Field groups', 'fieldpilot-for-acf' ) . '</h2>';

		if ( array() === $reports ) {
			$this->notice( esc_html__( 'No ACF field groups exist yet. Create one with an "operation": "create" payload on the Import screen.', 'fieldpilot-for-acf' ) );
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Field group', 'fieldpilot-for-acf' ) );
		printf( '<th>%s</th>', esc_html__( 'Key', 'fieldpilot-for-acf' ) );
		printf( '<th>%s</th>', esc_html__( 'Source', 'fieldpilot-for-acf' ) );
		printf( '<th>%s</th>', esc_html__( 'Status', 'fieldpilot-for-acf' ) );
		echo '</tr></thead><tbody>';

		foreach ( $reports as $report ) {
			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( $report->groupTitle ) );
			printf( '<td><code>%s</code></td>', esc_html( $report->groupKey ) );
			printf( '<td>%s</td>', esc_html( $report->mutability->label() ) );

			echo '<td>';

			if ( $report->isPatchable() ) {
				printf(
					'<span class="acfjp-pill acfjp-pill--ok">%s</span>',
					esc_html__( 'Editable', 'fieldpilot-for-acf' )
				);

				if ( $report->syncPending ) {
					printf(
						' <span class="acfjp-pill acfjp-pill--warn">%s</span>',
						esc_html__( 'JSON file is newer', 'fieldpilot-for-acf' )
					);
				}
			} else {
				printf(
					'<span class="acfjp-pill acfjp-pill--blocked">%s</span> %s',
					esc_html__( 'Read only', 'fieldpilot-for-acf' ),
					esc_html( $this->explain( $report->mutability ) )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function explain( Mutability $mutability ): string {
		return match ( $mutability ) {
			Mutability::LocalPhp  => __( 'Registered in PHP - edit the code that registers it.', 'fieldpilot-for-acf' ),
			Mutability::LocalJson => __( 'Sync it into the database first.', 'fieldpilot-for-acf' ),
			default               => '',
		};
	}

	private function card( string $label, string $value, string $hint ): void {
		printf(
			'<div class="acfjp-card"><span class="acfjp-card__value">%s</span><span class="acfjp-card__label">%s</span><span class="acfjp-card__hint">%s</span></div>',
			esc_html( $value ),
			esc_html( $label ),
			esc_html( $hint )
		);
	}
}
