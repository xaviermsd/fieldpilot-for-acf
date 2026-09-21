<?php
/**
 * History: every change this plugin made, with one-click rollback.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin\Screens;

use ACFJP\Journal\Entry;
use ACFJP\Journal\Journal;

defined( 'ABSPATH' ) || exit;

final class History extends Screen {

	protected function title(): string {
		return __( 'History', 'fieldpilot-for-acf' );
	}

	protected function body(): void {
		$journal = $this->container->get( Journal::class );

		$page    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$perPage = 25;

		$entries = $journal->find( array( 'limit' => $perPage, 'offset' => ( $page - 1 ) * $perPage ) );
		$total   = $journal->countAll();

		if ( array() === $entries ) {
			$this->notice( esc_html__( 'Nothing has been changed through this plugin yet.', 'fieldpilot-for-acf' ) );
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped acfjp-history"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'When', 'fieldpilot-for-acf' ) );
		printf( '<th>%s</th>', esc_html__( 'Field group', 'fieldpilot-for-acf' ) );
		printf( '<th>%s</th>', esc_html__( 'Operation', 'fieldpilot-for-acf' ) );
		printf( '<th>%s</th>', esc_html__( 'Changes', 'fieldpilot-for-acf' ) );
		printf( '<th>%s</th>', esc_html__( 'By', 'fieldpilot-for-acf' ) );
		printf( '<th>%s</th>', esc_html__( 'Status', 'fieldpilot-for-acf' ) );
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$this->row( $entry );
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $total / $perPage );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => $this->url( '-history', array( 'paged' => '%#%' ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				) ?? ''
			);
			echo '</div></div>';
		}
	}

	private function row( Entry $entry ): void {
		echo '<tr>';

		printf(
			'<td>%s<br /><span class="description">%s</span></td>',
			esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->createdAt ) ),
			esc_html( $entry->source )
		);

		printf(
			'<td><strong>%s</strong><br /><code>%s</code></td>',
			esc_html( $entry->groupTitle ),
			esc_html( $entry->groupKey )
		);

		printf( '<td>%s</td>', esc_html( $entry->operation ) );

		echo '<td>';
		printf( '<strong>%d</strong>', (int) $entry->changeCount );

		$changes = $entry->changeset['changes'] ?? array();

		if ( is_array( $changes ) && array() !== $changes ) {
			echo '<details><summary>' . esc_html__( 'details', 'fieldpilot-for-acf' ) . '</summary><ul class="acfjp-history__changes">';

			foreach ( $changes as $change ) {
				if ( ! is_array( $change ) ) {
					continue;
				}

				printf(
					'<li><span class="acfjp-marker acfjp-marker--%s"></span> %s - %s</li>',
					esc_attr( (string) ( $change['type'] ?? '' ) ),
					esc_html( (string) ( $change['label'] ?? '' ) ),
					esc_html( (string) ( $change['summary'] ?? '' ) )
				);
			}

			echo '</ul></details>';
		}

		echo '</td>';

		printf( '<td>%s</td>', esc_html( $entry->userName() ) );

		echo '<td>';

		if ( $entry->isRollbackable() ) {
			printf(
				'<button type="button" class="button acfjp-rollback" data-id="%d">%s</button>',
				(int) $entry->id,
				esc_html__( 'Roll back', 'fieldpilot-for-acf' )
			);
		} else {
			printf( '<span class="acfjp-pill">%s</span>', esc_html( $this->statusLabel( $entry->status ) ) );
		}

		if ( null !== $entry->message ) {
			printf( '<p class="description">%s</p>', esc_html( $entry->message ) );
		}

		echo '</td></tr>';
	}

	private function statusLabel( string $status ): string {
		return match ( $status ) {
			Entry::STATUS_APPLIED     => __( 'Applied', 'fieldpilot-for-acf' ),
			Entry::STATUS_FAILED      => __( 'Failed - rolled back', 'fieldpilot-for-acf' ),
			Entry::STATUS_ROLLED_BACK => __( 'Rolled back', 'fieldpilot-for-acf' ),
			Entry::STATUS_REVERTED    => __( 'Rollback', 'fieldpilot-for-acf' ),
			default                   => $status,
		};
	}
}
