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
			echo '<div class="notice notice-info inline" style="margin: 0 0 16px 0; padding: 10px 14px; border-left-color: #2271b1;">';
			echo '<p><strong>' . esc_html__( 'No history recorded yet.', 'fieldpilot-for-acf' ) . '</strong> ' . esc_html__( 'Below is a sample record demonstrating how your applied configuration patches and one-click rollback points will appear.', 'fieldpilot-for-acf' ) . '</p>';
			echo '</div>';

			echo '<table class="wp-list-table widefat fixed striped acfjp-history" data-tour="history-table"><thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'When', 'fieldpilot-for-acf' ) );
			printf( '<th>%s</th>', esc_html__( 'Field group', 'fieldpilot-for-acf' ) );
			printf( '<th>%s</th>', esc_html__( 'Operation', 'fieldpilot-for-acf' ) );
			printf( '<th>%s</th>', esc_html__( 'Changes', 'fieldpilot-for-acf' ) );
			printf( '<th>%s</th>', esc_html__( 'By', 'fieldpilot-for-acf' ) );
			printf( '<th>%s</th>', esc_html__( 'Status', 'fieldpilot-for-acf' ) );
			echo '</tr></thead><tbody>';

			$currentUser = wp_get_current_user();
			$userName    = ! empty( $currentUser->display_name ) ? $currentUser->display_name : 'administrator';

			echo '<tr class="acfjp-history-sample-row" style="background-color: #fafbfc;">';
			printf(
				'<td>%s<br /><span class="description">%s</span></td>',
				esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), current_time( 'mysql' ) ) ),
				esc_html__( 'wp-admin (Sample snapshot)', 'fieldpilot-for-acf' )
			);
			printf(
				'<td><strong>%s</strong><br /><code>%s</code></td>',
				esc_html__( 'Hero Section', 'fieldpilot-for-acf' ),
				'group_hero_sample'
			);
			printf( '<td>%s</td>', 'update' );
			echo '<td><strong>2</strong>';
			echo '<details open><summary>' . esc_html__( 'details', 'fieldpilot-for-acf' ) . '</summary><ul class="acfjp-history__changes">';
			echo '<li><span class="acfjp-marker acfjp-marker--add">+</span> hero_subtitle - ' . esc_html__( 'Add Subtitle Field', 'fieldpilot-for-acf' ) . '</li>';
			echo '<li><span class="acfjp-marker acfjp-marker--update">~</span> hero_title - ' . esc_html__( 'Update field instructions', 'fieldpilot-for-acf' ) . '</li>';
			echo '</ul></details></td>';
			printf( '<td>%s</td>', esc_html( $userName ) );
			echo '<td>';
			echo '<button type="button" class="button button-secondary acfjp-rollback is-disabled" disabled style="opacity: 0.55; cursor: not-allowed; pointer-events: none;" title="' . esc_attr__( 'Sample preview - not reversible', 'fieldpilot-for-acf' ) . '">' . esc_html__( 'Roll back', 'fieldpilot-for-acf' ) . '</button>';
			echo '<span class="acfjp-pill" style="margin-left: 6px; background: #e0f0ff; color: #005a9c; border-color: #c2e0ff;">' . esc_html__( 'Sample', 'fieldpilot-for-acf' ) . '</span>';
			echo '<p class="description" style="margin-top: 4px; font-size: 11px;">' . esc_html__( 'Non-revocable preview', 'fieldpilot-for-acf' ) . '</p>';
			echo '</td>';
			echo '</tr>';

			echo '</tbody></table>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped acfjp-history" data-tour="history-table"><thead><tr>';
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
