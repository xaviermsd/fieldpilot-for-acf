/**
 * FieldPilot for ACF - Onboarding Tour & Spotlight.
 *
 * Implements a 10-step visual guided onboarding walkthrough
 * using pure vanilla JS and CSS.
 */

( function () {
	'use strict';

	if ( ! window.ACFJP || ! window.ACFJP.tour || ! window.ACFJP.tour.active ) {
		return;
	}

	const config = window.ACFJP.tour;
	const restRoot = window.ACFJP.root;
	const restNonce = window.ACFJP.nonce;
	const { __, _n, sprintf } = window.wp.i18n;

	const STEPS = [
		{
			step: 0,
			page: 'dashboard',
			modal: true,
			headline: __( 'Welcome to FieldPilot for ACF', 'fieldpilot-for-acf' ),
			body: __( 'Build, preview and safely apply ACF field changes with AI help and JSON patches, then roll them back from History if you need to. Take the quick 2-minute tour to see how it works.', 'fieldpilot-for-acf' )
		},
		{
			step: 1,
			page: 'dashboard',
			target: '[data-tour="cards"], .acfjp-cards',
			headline: __( 'Health at a glance', 'fieldpilot-for-acf' ),
			body: __( 'How many field groups you can edit, the field types on this site, and every change recorded so it can be rolled back.', 'fieldpilot-for-acf' ),
			placement: 'bottom'
		},
		{
			step: 2,
			page: 'dashboard',
			target: '[data-tour="status-table"], table.wp-list-table, [data-tour="status-col"]',
			headline: __( 'Know what is safe to edit', 'fieldpilot-for-acf' ),
			body: __( 'Green EDITABLE groups live in the database and can be changed here. PHP-registered or JSON-file groups are locked - FieldPilot will not touch them.', 'fieldpilot-for-acf' ),
			placement: 'bottom',
			onEnter: function () {
				ensureSampleStatusTable();
			}
		},
		{
			step: 3,
			page: 'import',
			target: '[data-tour="target-group"], [data-tour="builder"], .acfjp-builder-box',
			headline: __( 'Target field group & visual builder', 'fieldpilot-for-acf' ),
			body: __( 'Select an existing field group, or click "Add New Field Group" to create one, to unlock the custom field builder. Then fill in the settings for each field across the 4 ACF tabs and add it to your queue.', 'fieldpilot-for-acf' ),
			placement: 'bottom',
			onEnter: function () {
				switchImportTab( 'ai' );
			}
		},
		{
			step: 4,
			page: 'import',
			target: '[data-tour="prompt"], #acfjp-prompt-build',
			headline: __( 'Ready AI prompt, no API key needed', 'fieldpilot-for-acf' ),
			body: __( 'FieldPilot writes a precise prompt from your queued fields. Copy it into any AI (ChatGPT, Claude, Gemini, Cursor), then paste the AI\'s JSON reply back using the button in Step 2B.', 'fieldpilot-for-acf' ),
			placement: 'top',
			onEnter: function () {
				switchImportTab( 'ai' );
			}
		},
		{
			step: 5,
			page: 'import',
			target: '[data-tour="editor"], .acfjp-import__editor',
			headline: __( 'Your patch, previewed before anything writes', 'fieldpilot-for-acf' ),
			body: __( 'Paste a FieldPilot patch or a native ACF export, or pick a starting point from "Insert a template...", then click Preview Changes to see the exact diff first.', 'fieldpilot-for-acf' ),
			placement: 'right',
			onEnter: function () {
				switchImportTab( 'editor' );
			}
		},
		{
			step: 6,
			page: 'import',
			target: '.acfjp-scope-box, .is-tour-sample, #acfjp-result, [data-tour="diff-preview"]',
			headline: __( 'Nothing else touched', 'fieldpilot-for-acf' ),
			body: __( 'The Target Isolated box shows the change stays inside its target, so sibling fields and branches are left alone. Every change is marked + added, ~ changed or - removed, and each setting is shown old to new.', 'fieldpilot-for-acf' ),
			placement: 'left',
			onEnter: function () {
				switchImportTab( 'editor' );
				ensureSampleDiffPreview();
			}
		},
		{
			step: 7,
			page: 'import',
			target: '.acfjp-safety-box, [data-tour="safety-checklist"], .acfjp-safety-checklist',
			headline: __( 'Safety checklist, always', 'fieldpilot-for-acf' ),
			body: __( 'Before anything writes to the database, confirm you reviewed the target scope and the diff. FieldPilot never applies a change silently.', 'fieldpilot-for-acf' ),
			placement: 'left',
			onEnter: function () {
				switchImportTab( 'editor' );
				ensureSampleDiffPreview();
			}
		},
		{
			step: 8,
			page: 'import',
			target: '.acfjp-conflict, [data-tour="conflict-box"]',
			headline: __( 'Risky changes stop and ask first', 'fieldpilot-for-acf' ),
			body: __( 'If a patch is risky - for example changing a field\'s type - FieldPilot flags it before applying and lets you choose: keep the existing type, change the type, or add it as a new field.', 'fieldpilot-for-acf' ),
			placement: 'left',
			onEnter: function () {
				switchImportTab( 'editor' );
				ensureSampleDiffPreview();
			}
		},
		{
			step: 9,
			page: 'history',
			target: '[data-tour="history-table"], table.acfjp-history, .acfjp-history',
			headline: __( 'Every change logged, ready to undo', 'fieldpilot-for-acf' ),
			body: __( 'Each applied change is recorded with when it happened, the field group and who made it. Roll back the newest change to restore the snapshot taken before it, then the next one to go further back.', 'fieldpilot-for-acf' ),
			placement: 'bottom'
		},
		{
			step: 10,
			page: 'any',
			modal: true,
			headline: __( 'You\'re all set!', 'fieldpilot-for-acf' ),
			body: __( 'You now know how FieldPilot works. Jump over to the Import screen anytime to build fields visually, generate AI prompts, and safely preview JSON patches before applying.', 'fieldpilot-for-acf' )
		}
	];

	const PAGE_MAP = {
		'fieldpilot-for-acf': 'dashboard',
		'fieldpilot-for-acf-import': 'import',
		'fieldpilot-for-acf-history': 'history',
		'fieldpilot-for-acf-settings': 'settings'
	};

	function getCurrentPage() {
		const page = new URLSearchParams( window.location.search ).get( 'page' ) || '';
		return Object.prototype.hasOwnProperty.call( PAGE_MAP, page ) ? PAGE_MAP[ page ] : 'other';
	}

	function switchImportTab( tabName ) {
		const tabBtn = document.querySelector( '.acfjp-tab[data-tab="' + tabName + '"]' );
		if ( tabBtn ) {
			tabBtn.click();
		}
		document.querySelectorAll( '.acfjp-tab' ).forEach( function ( btn ) {
			btn.classList.toggle( 'is-active', btn.dataset.tab === tabName );
		} );
		document.querySelectorAll( '.acfjp-tab-content' ).forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.id === 'acfjp-tab-' + tabName );
		} );
	}

	function ensureSampleStatusTable() {
		const existingTable = document.querySelector( 'table[data-tour="status-table"], table.wp-list-table' );
		if ( existingTable && existingTable.querySelector( 'tbody tr' ) ) {
			return;
		}

		let container = document.querySelector( '[data-tour="status-table"]' );
		const samplePill = '<span class="acfjp-pill acfjp-pill--sample">' + escapeHtml( __( 'Sample preview', 'fieldpilot-for-acf' ) ) + '</span>';
		const sampleHtml = [
			'<div class="notice notice-info inline acfjp-sample-notice is-tour-sample-dashboard">',
				'<p><strong>' + escapeHtml( __( 'No ACF field groups exist yet.', 'fieldpilot-for-acf' ) ) + '</strong> ' + escapeHtml( __( 'Below is a sample preview demonstrating how FieldPilot distinguishes between editable database groups and locked PHP/JSON groups.', 'fieldpilot-for-acf' ) ) + '</p>',
			'</div>',
			'<table class="wp-list-table widefat fixed striped is-tour-sample-dashboard" data-tour="status-table">',
				'<thead><tr>',
					'<th>' + escapeHtml( __( 'Field group', 'fieldpilot-for-acf' ) ) + '</th>',
					'<th>' + escapeHtml( __( 'Key', 'fieldpilot-for-acf' ) ) + '</th>',
					'<th>' + escapeHtml( __( 'Source', 'fieldpilot-for-acf' ) ) + '</th>',
					'<th data-tour="status-col" class="acfjp-status-col">' + escapeHtml( __( 'Status', 'fieldpilot-for-acf' ) ) + '</th>',
				'</tr></thead>',
				'<tbody>',
					'<tr class="acfjp-dashboard-sample-row">',
						'<td><strong>' + escapeHtml( __( 'Hero Section', 'fieldpilot-for-acf' ) ) + '</strong> ' + samplePill + '</td>',
						'<td><code>group_hero_sample</code></td>',
						'<td>' + escapeHtml( __( 'Database', 'fieldpilot-for-acf' ) ) + '</td>',
						'<td><span class="acfjp-pill acfjp-pill--ok">' + escapeHtml( __( 'Editable', 'fieldpilot-for-acf' ) ) + '</span></td>',
					'</tr>',
					'<tr class="acfjp-dashboard-sample-row">',
						'<td><strong>' + escapeHtml( __( 'Site Settings', 'fieldpilot-for-acf' ) ) + '</strong> ' + samplePill + '</td>',
						'<td><code>group_site_settings_sample</code></td>',
						'<td>' + escapeHtml( __( 'PHP file', 'fieldpilot-for-acf' ) ) + '</td>',
						'<td><span class="acfjp-pill acfjp-pill--blocked">' + escapeHtml( __( 'Read only', 'fieldpilot-for-acf' ) ) + '</span> ' + escapeHtml( __( 'Registered in PHP - edit the code that registers it.', 'fieldpilot-for-acf' ) ) + '</td>',
					'</tr>',
				'</tbody>',
			'</table>'
		].join( '' );

		if ( container && container.tagName === 'DIV' ) {
			container.outerHTML = sampleHtml;
		} else {
			const heading = document.querySelector( 'h2' );
			if ( heading ) {
				heading.insertAdjacentHTML( 'afterend', sampleHtml );
			}
		}
	}

	function ensureSampleDiffPreview() {
		const resultContainer = document.getElementById( 'acfjp-result' );
		if ( ! resultContainer ) {
			return;
		}

		// Hide the sidebar guide when sample diff is shown to give clean review space
		const guide = document.getElementById( 'acfjp-sidebar-guide' );
		if ( guide ) {
			guide.style.display = 'none';
		}

		// If a real diff already exists, leave it.
		if ( resultContainer.querySelector( '.acfjp-scope-box' ) || resultContainer.querySelector( '.acfjp-safety-box' ) ) {
			return;
		}

		// Inject realistic demonstration diff plan with native styling.
		const hero = escapeHtml( __( 'Hero Section', 'fieldpilot-for-acf' ) );
		const typeText = escapeHtml( __( 'Text', 'fieldpilot-for-acf' ) );
		const typeTextarea = escapeHtml( __( 'Textarea', 'fieldpilot-for-acf' ) );
		/* translators: %s: field group title. */
		const targetLabel = escapeHtml( sprintf( __( 'Target: %s', 'fieldpilot-for-acf' ), __( 'Hero Section', 'fieldpilot-for-acf' ) ) );
		/* translators: %d: number of modified fields. */
		const modifiedChip = escapeHtml( sprintf( _n( '%d modified', '%d modified', 1, 'fieldpilot-for-acf' ), 1 ) );
		/* translators: %d: number of added fields. */
		const addedChip = escapeHtml( sprintf( _n( '%d added', '%d added', 1, 'fieldpilot-for-acf' ), 1 ) );
		/* translators: %s: suggested new field name. */
		const renameHint = escapeHtml( sprintf( __( 'Keep existing and create %s', 'fieldpilot-for-acf' ), 'hero_title_new' ) );

		resultContainer.innerHTML = [
			'<div class="acfjp-panel acfjp-panel--plan is-tour-sample">',
				'<div class="acfjp-scope-box" style="margin-bottom: 16px;">',
					'<div class="acfjp-scope-box__top">',
						'<div class="acfjp-target-breadcrumb">',
							'<span class="dashicons dashicons-shield"></span>',
							'<span>' + targetLabel + '</span>',
							'<span class="acfjp-crumb-sep">&rarr;</span>',
							'<span>hero_title</span>',
						'</div>',
						'<span class="acfjp-scope-isolated">',
							'<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span>',
							escapeHtml( __( 'Target Isolated - Unrelated branches are untouched', 'fieldpilot-for-acf' ) ),
						'</span>',
					'</div>',
					'<div class="acfjp-stats-chips" style="margin-top: 8px;">',
						'<span class="acfjp-stat-chip acfjp-stat-chip--modify">~ ' + modifiedChip + '</span>',
						'<span class="acfjp-stat-chip acfjp-stat-chip--add">+ ' + addedChip + '</span>',
						'<span class="acfjp-stat-chip acfjp-stat-chip--protected">' + escapeHtml( __( 'Sibling branches protected', 'fieldpilot-for-acf' ) ) + '</span>',
					'</div>',
				'</div>',
				'<div class="acfjp-changes" style="margin: 16px 0;">',
					'<div class="acfjp-change acfjp-change--update acfjp-change--risk-caution">',
						'<div class="acfjp-change__head">',
							'<span class="acfjp-diff-tag acfjp-diff-tag--caution">! ' + escapeHtml( __( 'Conflict', 'fieldpilot-for-acf' ) ) + '</span>',
							'<strong class="acfjp-change__label">hero_title</strong>',
							'<span class="acfjp-change__summary">(' + typeText + ' &rarr; ' + typeTextarea + ')</span>',
						'</div>',
						'<div class="acfjp-change__path">' + hero + ' &gt; hero_title</div>',
						'<div class="acfjp-conflict" data-tour="conflict-box" style="margin-top: 10px;">',
							'<h4>' + escapeHtml( __( 'Field-type change detected', 'fieldpilot-for-acf' ) ) + '</h4>',
							'<p>' + escapeHtml( __( 'Changing type from text to textarea may alter data rendering on existing posts.', 'fieldpilot-for-acf' ) ) + '</p>',
							'<div class="acfjp-conflict__options">',
								'<label class="acfjp-conflict__option"><input type="radio" name="tour_conflict_hero" value="keep" checked /> <strong>' + escapeHtml( __( 'Keep existing type', 'fieldpilot-for-acf' ) ) + '</strong> <span>' + escapeHtml( __( 'Leave field as Text', 'fieldpilot-for-acf' ) ) + '</span></label>',
								'<label class="acfjp-conflict__option is-destructive"><input type="radio" name="tour_conflict_hero" value="replace" /> <strong>' + escapeHtml( __( 'Change type', 'fieldpilot-for-acf' ) ) + '</strong> <span>' + escapeHtml( __( 'Convert field to Textarea', 'fieldpilot-for-acf' ) ) + '</span></label>',
								'<label class="acfjp-conflict__option"><input type="radio" name="tour_conflict_hero" value="rename" /> <strong>' + escapeHtml( __( 'Add as new field', 'fieldpilot-for-acf' ) ) + '</strong> <span>' + renameHint + '</span></label>',
							'</div>',
						'</div>',
					'</div>',
					'<div class="acfjp-change acfjp-change--add acfjp-change--risk-safe">',
						'<div class="acfjp-change__head">',
							'<span class="acfjp-diff-tag acfjp-diff-tag--add">+ ' + escapeHtml( __( 'Added', 'fieldpilot-for-acf' ) ) + '</span>',
							'<strong class="acfjp-change__label">hero_subtitle</strong>',
							'<span class="acfjp-change__summary">(' + escapeHtml( __( 'Text, Subtitle Field', 'fieldpilot-for-acf' ) ) + ')</span>',
						'</div>',
						'<div class="acfjp-change__path">' + hero + ' &gt; hero_subtitle</div>',
					'</div>',
				'</div>',
				'<div class="acfjp-safety-box" data-tour="safety-checklist" style="margin-top: 20px;">',
					'<h3>' + escapeHtml( __( 'Pre-Apply Safety Checklist', 'fieldpilot-for-acf' ) ) + '</h3>',
					'<div class="acfjp-safety-checklist">',
						'<label class="acfjp-safety-item"><input type="checkbox" id="tour-ack-review" checked /> <span>' + escapeHtml( __( 'I have reviewed the target scope and diff above.', 'fieldpilot-for-acf' ) ) + '</span></label>',
						'<label class="acfjp-safety-item"><input type="checkbox" id="tour-ack-modify" checked /> <span>' + escapeHtml( __( 'I understand this operation will write changes to the ACF database.', 'fieldpilot-for-acf' ) ) + '</span></label>',
					'</div>',
					'<button type="button" class="button button-primary button-hero acfjp-apply-btn is-pulsing" style="margin-top: 14px; width: 100%; justify-content: center; pointer-events: none;">' + escapeHtml( __( 'Apply Changes to Database', 'fieldpilot-for-acf' ) ) + '</button>',
				'</div>',
			'</div>'
		].join( '' );
	}

	class TourManager {
		constructor() {
			this.currentStep = 0;
			this.overlay = null;
			this.cutout = null;
			this.border = null;
			this.tooltip = null;
			this.modalBackdrop = null;
			this.handleResize = this.reposition.bind( this );

			this.init();
		}

		init() {
			this.bindTriggers();

			const stepParam = config.stepParam;
			if ( stepParam !== null && stepParam !== undefined && stepParam >= 0 && stepParam <= 10 ) {
				const numStep = parseInt( stepParam, 10 );
				if ( getCurrentPage() === 'dashboard' && numStep === 2 ) {
					ensureSampleStatusTable();
				}
				if ( getCurrentPage() === 'import' && numStep >= 5 && numStep <= 8 ) {
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, null, '#editor' );
					}
					switchImportTab( 'editor' );
					if ( numStep >= 6 ) {
						ensureSampleDiffPreview();
					}
				}
				this.goTo( numStep );
				return;
			}

			// Auto-show once if not done
			if ( ! config.done ) {
				this.goTo( 0 );
			}
		}

		bindTriggers() {
			const self = this;

			// Replay tour buttons
			document.querySelectorAll( '.acfjp-tour-replay-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					self.resetTour();
				} );
			} );

			// Escape key dismisses modal or tour
			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' || e.key === 'Esc' ) {
					const hasModal = document.getElementById( 'acfjp-tour-modal-backdrop' );
					const hasOverlay = document.getElementById( 'acfjp-tour-overlay' );
					if ( hasModal || ( hasOverlay && hasOverlay.style.display !== 'none' ) ) {
						self.dismiss();
					}
				}
			} );
		}

		buildDOMElements() {
			if ( this.overlay ) {
				return;
			}

			// SVG Overlay
			this.overlay = document.createElement( 'div' );
			this.overlay.id = 'acfjp-tour-overlay';
			this.overlay.innerHTML = [
				'<svg class="acfjp-tour-svg-mask">',
					'<defs>',
						'<mask id="acfjp-mask">',
							'<rect width="100%" height="100%" fill="white" />',
							'<rect id="acfjp-tour-mask-cutout" x="0" y="0" width="0" height="0" rx="8" ry="8" fill="black" />',
						'</mask>',
					'</defs>',
					'<rect width="100%" height="100%" fill="rgba(15, 23, 42, 0.72)" mask="url(#acfjp-mask)" />',
				'</svg>',
				'<div id="acfjp-tour-spotlight-border"></div>'
			].join( '' );

			document.body.appendChild( this.overlay );
			this.cutout = document.getElementById( 'acfjp-tour-mask-cutout' );
			this.border = document.getElementById( 'acfjp-tour-spotlight-border' );

			// Tooltip
			this.tooltip = document.createElement( 'div' );
			this.tooltip.id = 'acfjp-tour-tooltip';
			this.tooltip.style.display = 'none';
			document.body.appendChild( this.tooltip );

			window.addEventListener( 'resize', this.handleResize );
			window.addEventListener( 'scroll', this.handleResize, true );
		}

		goTo( stepIndex ) {
			this.currentStep = stepIndex;
			const step = STEPS[ stepIndex ];
			if ( ! step ) {
				this.dismiss();
				return;
			}

			// If step belongs to another page, hide current overlay and navigate there
			const currentPage = getCurrentPage();
			if ( step.page !== 'any' && step.page !== currentPage ) {
				this.hideSpotlight();
				this.hideModal();

				let targetUrl = config.dashboardUrl;
				if ( step.page === 'import' ) {
					targetUrl = config.importUrl;
					if ( step.step >= 5 && step.step <= 8 ) {
						targetUrl += '&tour_step=' + step.step + '#editor';
					} else {
						targetUrl += '&tour_step=' + step.step + '#ai';
					}
				} else if ( step.page === 'history' ) {
					targetUrl = config.historyUrl + '&tour_step=' + step.step;
				} else {
					targetUrl += '&tour_step=' + step.step;
				}
				window.location.href = targetUrl;
				return;
			}

			// Clean up sample status table if on dashboard and moving away from step 2
			if ( currentPage === 'dashboard' && stepIndex !== 2 ) {
				document.querySelectorAll( '.is-tour-sample-dashboard' ).forEach( function ( el ) {
					el.remove();
				} );
			}

			if ( currentPage === 'dashboard' && stepIndex === 2 ) {
				ensureSampleStatusTable();
			}

			// Clean up sample diff preview ONLY if staying on import page and moving away from steps 6-8
			if ( currentPage === 'import' && ( stepIndex < 6 || stepIndex > 8 ) ) {
				const sample = document.querySelector( '.is-tour-sample' );
				if ( sample ) {
					sample.remove();
					const guide = document.getElementById( 'acfjp-sidebar-guide' );
					if ( guide ) {
						guide.style.display = '';
					}
				}
			}

			if ( currentPage === 'import' ) {
				if ( step.step >= 5 && step.step <= 8 ) {
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, null, '#editor' );
					}
					switchImportTab( 'editor' );
					if ( step.step >= 6 && step.step <= 8 ) {
						ensureSampleDiffPreview();
					}
				} else if ( step.step >= 1 && step.step <= 4 ) {
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, null, '#ai' );
					}
					switchImportTab( 'ai' );
				}
			}

			if ( typeof step.onEnter === 'function' ) {
				step.onEnter();
			}

			if ( step.modal ) {
				this.showModal( step );
			} else {
				this.showSpotlightStep( step, 0 );
			}
		}

		showModal( step ) {
			this.hideSpotlight();
			this.hideModal();
			const self = this;

			this.modalBackdrop = document.createElement( 'div' );
			this.modalBackdrop.id = 'acfjp-tour-modal-backdrop';
			document.body.appendChild( this.modalBackdrop );

			let actionButtons = '';

			if ( step.step === 0 ) {
				actionButtons = [
					'<button type="button" class="button button-primary button-hero acfjp-tour-take-btn">' + escapeHtml( __( 'Take the tour', 'fieldpilot-for-acf' ) ) + '</button>',
					'<button type="button" class="acfjp-tour-skip-btn">' + escapeHtml( __( 'Skip', 'fieldpilot-for-acf' ) ) + '</button>'
				].join( '' );
			} else {
				// Step 10 (Final Step)
				actionButtons = [
					'<button type="button" class="button button-primary button-hero acfjp-tour-finish-btn">' + escapeHtml( __( 'Finish tour', 'fieldpilot-for-acf' ) ) + '</button>',
					'<button type="button" class="button button-secondary button-hero acfjp-tour-modal-back-btn">' + escapeHtml( __( 'Back', 'fieldpilot-for-acf' ) ) + '</button>'
				].join( '' );
			}

			this.modalBackdrop.innerHTML = [
				'<div class="acfjp-tour-modal-card">',
					'<button type="button" class="acfjp-tour-modal-close" aria-label="' + escapeHtml( __( 'Close', 'fieldpilot-for-acf' ) ) + '">&times;</button>',
					'<span class="dashicons dashicons-superhero-alt acfjp-tour-modal-icon"></span>',
					'<h2>' + escapeHtml( step.headline ) + '</h2>',
					'<p>' + escapeHtml( step.body ) + '</p>',
					'<div class="acfjp-tour-modal-actions">',
						actionButtons,
					'</div>',
				'</div>'
			].join( '' );

			this.modalBackdrop.style.display = 'flex';

			// Close button listener
			const closeBtn = this.modalBackdrop.querySelector( '.acfjp-tour-modal-close' );
			if ( closeBtn ) {
				closeBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					self.dismiss();
				} );
			}

			// Backdrop click to dismiss
			this.modalBackdrop.addEventListener( 'click', function ( e ) {
				if ( e.target === self.modalBackdrop ) {
					self.dismiss();
				}
			} );

			// Event listeners inside modal
			const takeBtn = this.modalBackdrop.querySelector( '.acfjp-tour-take-btn' );
			if ( takeBtn ) {
				takeBtn.addEventListener( 'click', function () {
					self.hideModal();
					self.goTo( 1 );
				} );
			}

			const skipBtn = this.modalBackdrop.querySelector( '.acfjp-tour-skip-btn' );
			if ( skipBtn ) {
				skipBtn.addEventListener( 'click', function () {
					self.dismiss();
				} );
			}

			const finishBtn = this.modalBackdrop.querySelector( '.acfjp-tour-finish-btn' );
			if ( finishBtn ) {
				finishBtn.addEventListener( 'click', function () {
					self.dismiss();
				} );
			}

			const modalBackBtn = this.modalBackdrop.querySelector( '.acfjp-tour-modal-back-btn' );
			if ( modalBackBtn ) {
				modalBackBtn.addEventListener( 'click', function () {
					self.hideModal();
					self.goTo( 9 );
				} );
			}
		}

		hideModal() {
			if ( this.modalBackdrop ) {
				this.modalBackdrop.remove();
				this.modalBackdrop = null;
			}
			document.querySelectorAll( '#acfjp-tour-modal-backdrop' ).forEach( function ( el ) {
				el.remove();
			} );
		}

		showSpotlightStep( step, retryCount ) {
			if ( typeof retryCount !== 'number' ) {
				retryCount = 0;
			}
			this.hideModal();
			this.buildDOMElements();

			let targetEl = document.querySelector( step.target );
			if ( ! targetEl ) {
				if ( step.page === 'dashboard' && step.step === 2 ) {
					ensureSampleStatusTable();
					targetEl = document.querySelector( step.target );
				}

				if ( step.page === 'import' && step.step >= 6 && step.step <= 8 ) {
					switchImportTab( 'editor' );
					ensureSampleDiffPreview();
					targetEl = document.querySelector( step.target );
				}

				if ( ! targetEl && retryCount < 5 ) {
					const self = this;
					setTimeout( function () {
						self.showSpotlightStep( step, retryCount + 1 );
					}, 120 );
					return;
				}

				if ( ! targetEl ) {
					console.warn( '[FieldPilot Tour] Target element not found for step ' + step.step + ' (' + step.target + '). Continuing...' );
					this.goTo( step.step + 1 );
					return;
				}
			}

			this.overlay.style.display = 'block';
			this.overlay.style.opacity = '1';
			this.tooltip.style.display = 'block';

			// Smooth scroll target into view
			targetEl.scrollIntoView( { behavior: 'smooth', block: 'center', inline: 'nearest' } );

			// Delay slightly to let scroll settle
			const self = this;
			setTimeout( function () {
				self.updateSpotlight( targetEl );
				self.renderTooltip( step, targetEl );
			}, 150 );
		}

		updateSpotlight( targetEl ) {
			const rect = targetEl.getBoundingClientRect();
			const padding = 8;

			const x = Math.max( 0, rect.left - padding );
			const y = Math.max( 0, rect.top - padding );
			const w = rect.width + padding * 2;
			const h = rect.height + padding * 2;

			this.cutout.setAttribute( 'x', x );
			this.cutout.setAttribute( 'y', y );
			this.cutout.setAttribute( 'width', w );
			this.cutout.setAttribute( 'height', h );

			this.border.style.top = y + 'px';
			this.border.style.left = x + 'px';
			this.border.style.width = w + 'px';
			this.border.style.height = h + 'px';
		}

		renderTooltip( step, targetEl ) {
			const self = this;
			const totalSteps = 10;
			/* translators: 1: current step number, 2: total number of steps. */
			const stepLabel = sprintf( __( 'Step %1$d of %2$d', 'fieldpilot-for-acf' ), step.step, totalSteps );

			this.tooltip.innerHTML = [
				'<div class="acfjp-tour-header">',
					'<span class="acfjp-tour-badge">' + escapeHtml( stepLabel ) + '</span>',
					'<button type="button" class="acfjp-tour-close" aria-label="' + escapeHtml( __( 'Skip tour', 'fieldpilot-for-acf' ) ) + '">&times;</button>',
				'</div>',
				'<h3 class="acfjp-tour-title">' + escapeHtml( step.headline ) + '</h3>',
				'<p class="acfjp-tour-body">' + escapeHtml( step.body ) + '</p>',
				'<div class="acfjp-tour-footer">',
					'<button type="button" class="button-link acfjp-tour-btn-skip">' + escapeHtml( __( 'Skip', 'fieldpilot-for-acf' ) ) + '</button>',
					'<div class="acfjp-tour-nav">',
						( step.step > 1 ? '<button type="button" class="button acfjp-tour-btn-back">' + escapeHtml( __( 'Back', 'fieldpilot-for-acf' ) ) + '</button>' : '' ),
						'<button type="button" class="button button-primary acfjp-tour-btn-next">' + escapeHtml( __( 'Next', 'fieldpilot-for-acf' ) ) + '</button>',
					'</div>',
				'</div>'
			].join( '' );

			// Position tooltip relative to target
			this.positionTooltip( targetEl, step.placement || 'bottom' );

			// Event listeners
			this.tooltip.querySelector( '.acfjp-tour-close' ).addEventListener( 'click', function () {
				self.dismiss();
			} );

			this.tooltip.querySelector( '.acfjp-tour-btn-skip' ).addEventListener( 'click', function () {
				self.dismiss();
			} );

			const backBtn = this.tooltip.querySelector( '.acfjp-tour-btn-back' );
			if ( backBtn ) {
				backBtn.addEventListener( 'click', function () {
					self.goTo( step.step - 1 );
				} );
			}

			this.tooltip.querySelector( '.acfjp-tour-btn-next' ).addEventListener( 'click', function () {
				self.goTo( step.step + 1 );
			} );
		}

		positionTooltip( targetEl, preferredPlacement ) {
			const rect = targetEl.getBoundingClientRect();
			const tipWidth = this.tooltip.offsetWidth || 380;
			const tipHeight = this.tooltip.offsetHeight || 180;
			const margin = 16;

			// On small viewports (< 768px), center at bottom
			if ( window.innerWidth < 768 ) {
				this.tooltip.style.left = Math.max( 12, ( window.innerWidth - tipWidth ) / 2 ) + 'px';
				this.tooltip.style.top = Math.max( 12, window.innerHeight - tipHeight - 20 ) + 'px';
				return;
			}

			let top = 0;
			let left = 0;

			if ( preferredPlacement === 'bottom' ) {
				top = rect.bottom + margin;
				left = rect.left + ( rect.width - tipWidth ) / 2;
			} else if ( preferredPlacement === 'top' ) {
				top = rect.top - tipHeight - margin;
				left = rect.left + ( rect.width - tipWidth ) / 2;
			} else if ( preferredPlacement === 'left' ) {
				top = rect.top + ( rect.height - tipHeight ) / 2;
				left = rect.left - tipWidth - margin;
			} else if ( preferredPlacement === 'right' ) {
				top = rect.top + ( rect.height - tipHeight ) / 2;
				left = rect.right + margin;
			}

			// Boundary checks - clamp inside viewport
			if ( left < 16 ) {
				left = 16;
			} else if ( left + tipWidth > window.innerWidth - 16 ) {
				left = window.innerWidth - tipWidth - 16;
			}

			if ( top < 40 ) {
				// Avoid covering WP admin bar
				top = 40;
			} else if ( top + tipHeight > window.innerHeight - 16 ) {
				top = window.innerHeight - tipHeight - 16;
			}

			this.tooltip.style.top = top + 'px';
			this.tooltip.style.left = left + 'px';
		}

		reposition() {
			const step = STEPS[ this.currentStep ];
			if ( ! step || step.modal ) {
				return;
			}

			const targetEl = document.querySelector( step.target );
			if ( targetEl && this.cutout && this.border ) {
				this.updateSpotlight( targetEl );
				this.positionTooltip( targetEl, step.placement || 'bottom' );
			}
		}

		hideSpotlight() {
			if ( this.overlay ) {
				this.overlay.style.display = 'none';
			}
			if ( this.tooltip ) {
				this.tooltip.style.display = 'none';
			}
		}

		dismiss() {
			this.hideSpotlight();
			this.hideModal();
			if ( this.overlay ) {
				this.overlay.remove();
				this.overlay = null;
			}
			if ( this.tooltip ) {
				this.tooltip.remove();
				this.tooltip = null;
			}
			document.querySelectorAll( '#acfjp-tour-overlay, #acfjp-tour-tooltip, #acfjp-tour-modal-backdrop' ).forEach( function ( el ) {
				el.remove();
			} );
			config.done = true;

			// Clean up demonstration preview if injected
			document.querySelectorAll( '.is-tour-sample-dashboard' ).forEach( function ( el ) {
				el.remove();
			} );
			const samplePanel = document.querySelector( '.is-tour-sample' );
			if ( samplePanel ) {
				samplePanel.remove();
			}
			const guide = document.getElementById( 'acfjp-sidebar-guide' );
			if ( guide ) {
				guide.style.display = '';
			}

			// Remove tour_step query arg from URL without reload
			if ( window.history && window.history.replaceState ) {
				const url = new URL( window.location.href );
				url.searchParams.delete( 'tour_step' );
				window.history.replaceState( {}, document.title, url.toString() );
			}

			// Call REST dismiss
			fetch( restRoot + '/tour/dismiss', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce
				}
			} ).catch( function ( err ) {
				console.error( '[FieldPilot Tour] Dismiss failed:', err );
			} );
		}

		resetTour() {
			this.hideSpotlight();
			this.hideModal();

			fetch( restRoot + '/tour/reset', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce
				}
			} ).then( function () {
				window.location.href = config.dashboardUrl + '&tour_step=0';
			} ).catch( function () {
				window.location.href = config.dashboardUrl + '&tour_step=0';
			} );
		}
	}

	function escapeHtml( str ) {
		if ( ! str ) {
			return '';
		}
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	// Launch when DOM is ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			new TourManager();
		} );
	} else {
		new TourManager();
	}

} )();
