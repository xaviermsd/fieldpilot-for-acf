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

	const STEPS = [
		{
			step: 0,
			page: 'dashboard',
			modal: true,
			headline: 'Welcome to FieldPilot for ACF',
			body: 'Build, preview, and safely apply ACF field changes with AI help, JSON patches, and one-click rollback. Take the quick 2-minute tour to see how it works.'
		},
		{
			step: 1,
			page: 'dashboard',
			target: '[data-tour="cards"], .acfjp-cards',
			headline: 'Health at a glance',
			body: 'Field groups you can edit, field types validated against ACF schemas, and every change recorded so it can be rolled back.',
			placement: 'bottom'
		},
		{
			step: 2,
			page: 'dashboard',
			target: '[data-tour="status-col"], .acfjp-status-col, table.wp-list-table th:nth-child(4), [data-tour="status-table"]',
			headline: 'Know what is safe to edit',
			body: 'Green EDITABLE groups live in the database and can be changed here. PHP-registered or JSON-file groups are locked - FieldPilot will not touch them.',
			placement: 'bottom'
		},
		{
			step: 3,
			page: 'import',
			target: '[data-tour="target-group"], [data-tour="builder"], .acfjp-builder-box',
			headline: 'Target field group & visual builder',
			body: 'Select an existing field group (or click "+ Add New Field Group" to create one instantly) to unlock the custom field builder. Then configure your fields across all 4 ACF tabs and append them to your queue.',
			placement: 'bottom',
			onEnter: function () {
				switchImportTab( 'ai' );
			}
		},
		{
			step: 4,
			page: 'import',
			target: '[data-tour="prompt"], #acfjp-prompt-build',
			headline: 'Ready AI prompt, no API key needed',
			body: 'FieldPilot writes a precise prompt from your queued fields. Copy it into any AI (ChatGPT, Claude, Gemini, Cursor), then paste the AI\'s JSON reply back using the button in Step 2B.',
			placement: 'top',
			onEnter: function () {
				switchImportTab( 'ai' );
			}
		},
		{
			step: 5,
			page: 'import',
			target: '[data-tour="editor"], .acfjp-import__editor',
			headline: 'Your patch, previewed before anything writes',
			body: 'Paste a FieldPilot patch or a native ACF export JSON - or pick a starting point from Insert Template - then hit Preview Changes to see the exact diff first.',
			placement: 'right',
			onEnter: function () {
				switchImportTab( 'editor' );
			}
		},
		{
			step: 6,
			page: 'import',
			target: '.acfjp-scope-box, .is-tour-sample, #acfjp-result, [data-tour="diff-preview"]',
			headline: 'Nothing else touched',
			body: 'The Target Isolated box proves sibling fields and branches stay protected. Green [+ ADDED] and [~ MODIFIED] badges show exactly what will change.',
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
			headline: 'Safety checklist, always',
			body: 'Before anything writes to the database, confirm you reviewed the target scope and the diff. FieldPilot never applies a change silently.',
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
			headline: 'Risky changes stop and ask first',
			body: 'If a patch is risky - for example changing a field\'s type - FieldPilot flags it before applying and lets you choose: keep the existing type, change the type, or add it as a new field.',
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
			headline: 'Every change logged, one-click undo',
			body: 'Each applied patch is recorded with date, field group, and actor. Hit Roll back on any row to restore that snapshot instantly.',
			placement: 'bottom'
		},
		{
			step: 10,
			page: 'any',
			modal: true,
			headline: "You're all set!",
			body: 'You now know how FieldPilot works. Jump over to the Import screen anytime to build fields visually, generate AI prompts, and safely preview JSON patches before applying.'
		}
	];

	function getCurrentPage() {
		const href = window.location.href;
		if ( href.indexOf( 'page=fieldpilot-for-acf-import' ) !== -1 ) {
			return 'import';
		}
		if ( href.indexOf( 'page=fieldpilot-for-acf-history' ) !== -1 ) {
			return 'history';
		}
		if ( href.indexOf( 'page=fieldpilot-for-acf-settings' ) !== -1 ) {
			return 'settings';
		}
		if ( href.indexOf( 'page=fieldpilot-for-acf' ) !== -1 ) {
			return 'dashboard';
		}
		return 'other';
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
		resultContainer.innerHTML = [
			'<div class="acfjp-panel acfjp-panel--plan is-tour-sample">',
				'<div class="acfjp-scope-box" style="margin-bottom: 16px;">',
					'<div class="acfjp-scope-box__top">',
						'<div class="acfjp-target-breadcrumb">',
							'<span class="dashicons dashicons-shield"></span>',
							'<span>Target: Hero Section</span>',
							'<span class="acfjp-crumb-sep">&rarr;</span>',
							'<span>hero_title</span>',
						'</div>',
						'<span class="acfjp-scope-isolated">',
							'<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span>',
							'Target Isolated - Unrelated branches are untouched',
						'</span>',
					'</div>',
					'<div class="acfjp-stats-chips" style="margin-top: 8px;">',
						'<span class="acfjp-stat-chip acfjp-stat-chip--modify">~ 1 Modified</span>',
						'<span class="acfjp-stat-chip acfjp-stat-chip--add">+ 1 Added</span>',
						'<span class="acfjp-stat-chip acfjp-stat-chip--protected">🛡️ Sibling branches protected</span>',
					'</div>',
				'</div>',
				'<div class="acfjp-changes" style="margin: 16px 0;">',
					'<div class="acfjp-change acfjp-change--update acfjp-change--risk-caution">',
						'<div class="acfjp-change__head">',
							'<span class="acfjp-diff-tag acfjp-diff-tag--caution">! CONFLICT</span>',
							'<strong class="acfjp-change__label">hero_title</strong>',
							'<span class="acfjp-change__summary">(Text &rarr; Textarea)</span>',
						'</div>',
						'<div class="acfjp-change__path">Hero Section &gt; hero_title</div>',
						'<div class="acfjp-conflict" data-tour="conflict-box" style="margin-top: 10px;">',
							'<h4>Field-type change detected</h4>',
							'<p>Changing type from text to textarea may alter data rendering on existing posts.</p>',
							'<div class="acfjp-conflict__options">',
								'<label class="acfjp-conflict__option"><input type="radio" name="tour_conflict_hero" value="keep" checked /> <strong>Keep existing type</strong> <span>Leave field as Text</span></label>',
								'<label class="acfjp-conflict__option is-destructive"><input type="radio" name="tour_conflict_hero" value="replace" /> <strong>Change type</strong> <span>Convert field to Textarea</span></label>',
								'<label class="acfjp-conflict__option"><input type="radio" name="tour_conflict_hero" value="rename" /> <strong>Add as new field</strong> <span>Keep existing and create hero_title_new</span></label>',
							'</div>',
						'</div>',
					'</div>',
					'<div class="acfjp-change acfjp-change--add acfjp-change--risk-safe">',
						'<div class="acfjp-change__head">',
							'<span class="acfjp-diff-tag acfjp-diff-tag--add">+ ADDED</span>',
							'<strong class="acfjp-change__label">hero_subtitle</strong>',
							'<span class="acfjp-change__summary">(Text, Subtitle Field)</span>',
						'</div>',
						'<div class="acfjp-change__path">Hero Section &gt; hero_subtitle</div>',
					'</div>',
				'</div>',
				'<div class="acfjp-safety-box" data-tour="safety-checklist" style="margin-top: 20px;">',
					'<h3>🛡️ Pre-Apply Safety Checklist</h3>',
					'<div class="acfjp-safety-checklist">',
						'<label class="acfjp-safety-item"><input type="checkbox" id="tour-ack-review" checked /> <span>I have reviewed the target scope and diff above.</span></label>',
						'<label class="acfjp-safety-item"><input type="checkbox" id="tour-ack-modify" checked /> <span>I understand this operation will write changes to the ACF database.</span></label>',
					'</div>',
					'<button type="button" class="button button-primary button-hero acfjp-apply-btn is-pulsing" style="margin-top: 14px; width: 100%; justify-content: center; pointer-events: none;">Apply Changes to Database ➔</button>',
				'</div>',
			'</div>'
		].join( '' );
	}

	function injectTourStyles() {
		if ( document.getElementById( 'acfjp-tour-injected-css' ) ) {
			return;
		}
		const style = document.createElement( 'style' );
		style.id = 'acfjp-tour-injected-css';
		style.textContent = [
			'#acfjp-tour-overlay { position: fixed !important; inset: 0 !important; width: 100vw !important; height: 100vh !important; pointer-events: auto !important; z-index: 999998 !important; transition: opacity 0.25s ease !important; }',
			'.acfjp-tour-svg-mask { position: absolute !important; inset: 0 !important; width: 100% !important; height: 100% !important; pointer-events: none !important; }',
			'#acfjp-tour-spotlight-border { position: fixed !important; border: 2px solid #2271b1 !important; border-radius: 8px !important; box-shadow: 0 0 0 4px rgba(34, 113, 177, 0.35), 0 8px 24px rgba(0, 0, 0, 0.25) !important; pointer-events: none !important; z-index: 999998 !important; box-sizing: border-box !important; }',
			'#acfjp-tour-tooltip { position: fixed !important; z-index: 999999 !important; width: 380px !important; max-width: calc(100vw - 32px) !important; background: #ffffff !important; border-radius: 8px !important; box-shadow: 0 12px 32px rgba(15, 23, 42, 0.25), 0 2px 6px rgba(15, 23, 42, 0.1) !important; border: 1px solid #c3c4c7 !important; padding: 20px !important; box-sizing: border-box !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important; }',
			'.acfjp-tour-header { display: flex !important; justify-content: space-between !important; align-items: center !important; margin-bottom: 10px !important; }',
			'.acfjp-tour-badge { display: inline-block !important; background: #f0f6fc !important; color: #005a9c !important; font-size: 11px !important; font-weight: 700 !important; padding: 2px 8px !important; border-radius: 12px !important; text-transform: uppercase !important; letter-spacing: 0.04em !important; border: 1px solid #c2e0ff !important; }',
			'.acfjp-tour-close { background: none !important; border: none !important; cursor: pointer !important; color: #8c8f94 !important; font-size: 20px !important; line-height: 1 !important; padding: 2px 4px !important; }',
			'.acfjp-tour-title { margin: 0 0 8px 0 !important; font-size: 16px !important; font-weight: 700 !important; color: #1d2327 !important; line-height: 1.3 !important; }',
			'.acfjp-tour-body { margin: 0 0 16px 0 !important; font-size: 13px !important; color: #50575e !important; line-height: 1.5 !important; }',
			'.acfjp-tour-footer { display: flex !important; justify-content: space-between !important; align-items: center !important; margin-top: 16px !important; padding-top: 12px !important; border-top: 1px solid #f0f0f1 !important; }',
			'.acfjp-tour-nav { display: flex !important; gap: 8px !important; }',
			'.acfjp-tour-btn-skip { color: #646970 !important; text-decoration: none !important; font-size: 12px !important; cursor: pointer !important; padding: 0 !important; }',
			'#acfjp-tour-modal-backdrop { position: fixed; inset: 0; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.75); z-index: 999999; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; }',
			'.acfjp-tour-modal-card { background: #ffffff !important; border-radius: 12px !important; max-width: 520px !important; width: 100% !important; padding: 36px 32px 32px 32px !important; box-shadow: 0 20px 48px rgba(0, 0, 0, 0.35) !important; text-align: center !important; box-sizing: border-box !important; position: relative !important; border: 1px solid #dcdcde !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important; }',
			'.acfjp-tour-modal-close { position: absolute !important; top: 12px !important; right: 12px !important; width: 32px !important; height: 32px !important; background: transparent !important; border: none !important; box-shadow: none !important; outline: none !important; font-size: 24px !important; line-height: 28px !important; text-align: center !important; color: #8c8f94 !important; cursor: pointer !important; padding: 0 !important; border-radius: 4px !important; transition: color 0.15s ease, background 0.15s ease !important; }',
			'.acfjp-tour-modal-close:hover { color: #d63638 !important; background: #f0f0f1 !important; }',
			'.acfjp-tour-modal-icon { font-size: 48px !important; width: 48px !important; height: 48px !important; line-height: 48px !important; color: #2271b1 !important; margin: 0 auto 16px auto !important; display: block !important; }',
			'.acfjp-tour-modal-card h2 { margin: 0 0 12px 0 !important; font-size: 22px !important; font-weight: 700 !important; color: #1d2327 !important; }',
			'.acfjp-tour-modal-card p { font-size: 14px !important; line-height: 1.55 !important; color: #50575e !important; margin: 0 0 24px 0 !important; }',
			'.acfjp-tour-modal-actions { display: flex !important; align-items: center !important; justify-content: center !important; gap: 12px !important; flex-wrap: wrap !important; margin-top: 24px !important; }',
			'.acfjp-tour-modal-actions .button-hero { height: 44px !important; line-height: 42px !important; padding: 0 20px !important; font-size: 14px !important; border-radius: 4px !important; box-sizing: border-box !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; }',
			'.acfjp-tour-skip-btn { background: transparent !important; border: none !important; box-shadow: none !important; outline: none !important; color: #646970 !important; font-size: 14px !important; cursor: pointer !important; padding: 8px 12px !important; text-decoration: underline !important; line-height: normal !important; height: auto !important; align-self: center !important; transition: color 0.15s ease !important; }',
			'.acfjp-tour-skip-btn:hover { color: #d63638 !important; text-decoration: none !important; }',
			'.acfjp-diff-tag { display: inline-flex !important; align-items: center !important; gap: 4px !important; padding: 2px 7px !important; border-radius: 4px !important; font-size: 11px !important; font-weight: 700 !important; line-height: 1.3 !important; letter-spacing: 0.03em !important; text-transform: uppercase !important; box-sizing: border-box !important; width: auto !important; height: auto !important; }',
			'.acfjp-diff-tag--add { background: #edfaef !important; color: #007017 !important; border: 1px solid #c2edc8 !important; }',
			'.acfjp-diff-tag--modify { background: #f0f6fc !important; color: #005a9c !important; border: 1px solid #c2e0ff !important; }',
			'.acfjp-diff-tag--caution { background: #fff8e5 !important; color: #996800 !important; border: 1px solid #ffe899 !important; }',
			'.acfjp-history-empty-card { background: #ffffff !important; border: 1px solid #c3c4c7 !important; border-radius: 8px !important; padding: 32px 28px !important; margin: 20px 0 !important; max-width: 640px !important; text-align: center !important; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04) !important; box-sizing: border-box !important; }',
			'.acfjp-history-empty-icon .dashicons { font-size: 40px !important; width: 40px !important; height: 40px !important; line-height: 40px !important; color: #2271b1 !important; margin-bottom: 12px !important; display: inline-block !important; }',
			'.acfjp-history-empty-card h3 { margin: 0 0 8px 0 !important; font-size: 16px !important; font-weight: 600 !important; color: #1d2327 !important; }',
			'.acfjp-history-empty-card p { margin: 0 !important; font-size: 13px !important; color: #50575e !important; line-height: 1.55 !important; }',
			'.acfjp-history-sample-row { background-color: #fafbfc !important; }',
			'.acfjp-history-sample-row td { color: #50575e !important; }',
			'.acfjp-history-sample-row .acfjp-rollback[disabled] { opacity: 0.55 !important; cursor: not-allowed !important; pointer-events: none !important; }'
		].join( '\n' );
		document.head.appendChild( style );
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
			injectTourStyles();
			this.bindTriggers();

			const stepParam = config.stepParam;
			if ( stepParam !== null && stepParam !== undefined && stepParam >= 0 && stepParam <= 10 ) {
				const numStep = parseInt( stepParam, 10 );
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
					'<button type="button" class="button button-primary button-hero acfjp-tour-take-btn">Take the tour</button>',
					'<button type="button" class="acfjp-tour-skip-btn">Skip</button>'
				].join( '' );
			} else {
				// Step 10 (Final Step)
				actionButtons = [
					'<button type="button" class="button button-primary button-hero acfjp-tour-finish-btn">Finish tour</button>',
					'<button type="button" class="button button-secondary button-hero acfjp-tour-modal-back-btn">Back</button>'
				].join( '' );
			}

			this.modalBackdrop.innerHTML = [
				'<div class="acfjp-tour-modal-card">',
					'<button type="button" class="acfjp-tour-modal-close" aria-label="Close modal">&times;</button>',
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

			this.tooltip.innerHTML = [
				'<div class="acfjp-tour-header">',
					'<span class="acfjp-tour-badge">Step ' + step.step + ' of ' + totalSteps + '</span>',
					'<button type="button" class="acfjp-tour-close" aria-label="Skip tour">&times;</button>',
				'</div>',
				'<h3 class="acfjp-tour-title">' + escapeHtml( step.headline ) + '</h3>',
				'<p class="acfjp-tour-body">' + escapeHtml( step.body ) + '</p>',
				'<div class="acfjp-tour-footer">',
					'<button type="button" class="button-link acfjp-tour-btn-skip">Skip</button>',
					'<div class="acfjp-tour-nav">',
						( step.step > 1 ? '<button type="button" class="button acfjp-tour-btn-back">Back</button>' : '' ),
						'<button type="button" class="button button-primary acfjp-tour-btn-next">Next</button>',
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

			// Clean up demonstration diff preview if it was injected
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
