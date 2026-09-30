/**
 * FieldPilot for ACF - admin behaviour.
 *
 * Plain ES modules against the REST API. The UI is a thin client over the same
 * endpoints a deployment script would call, which is deliberate: if a flow cannot
 * be expressed as plan-then-apply over REST, it does not belong in the product.
 */
( function () {
	'use strict';

	const cfg = window.ACFJP || {};
	const s = cfg.strings || {};

	let editor = null;
	let currentPlan = null;

	/** REST helper. Always resolves to { ok, ...data } or { ok:false, error }. */
	async function api( path, options ) {
		const response = await fetch( cfg.root + path, Object.assign(
			{
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce,
				},
				credentials: 'same-origin',
			},
			options || {}
		) );

		try {
			return await response.json();
		} catch ( e ) {
			return { ok: false, error: { code: 'BAD_RESPONSE', message: s.genericError } };
		}
	}

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) node.className = className;
		if ( undefined !== text && null !== text ) node.textContent = text;
		return node;
	}

	function result() {
		return document.getElementById( 'acfjp-result' );
	}

	function showSidebarGuide( show ) {
		const guide = document.getElementById( 'acfjp-sidebar-guide' );
		if ( guide ) {
			guide.style.display = show ? 'block' : 'none';
		}
	}

	function clear() {
		const target = result();
		if ( target ) target.innerHTML = '';
		showSidebarGuide( true );
		return target;
	}

	function busy( message ) {
		showSidebarGuide( false );
		const target = result();
		if ( target ) {
			target.innerHTML = '';
			target.appendChild( el( 'p', 'acfjp-busy', message ) );
		}
	}

	function payload() {
		const raw = editor ? editor.codemirror.getValue() : ( document.getElementById( 'acfjp-json' ) || {} ).value;
		const operation = ( document.getElementById( 'acfjp-operation' ) || {} ).value || '';

		const body = { json: raw };
		if ( operation ) body.operation = operation;

		return JSON.stringify( body );
	}

	// ---- Rendering ---------------------------------------------------------

	function renderError( error ) {
		showSidebarGuide( false );
		const target = result();
		if ( ! target ) return;
		target.innerHTML = '';

		const box = el( 'div', 'acfjp-panel acfjp-panel--error' );
		box.appendChild( el( 'h2', null, error.message || s.genericError ) );

		if ( error.pointer ) {
			box.appendChild( el( 'p', 'acfjp-pointer', error.pointer ) );
		}

		if ( error.suggestions && error.suggestions.length ) {
			const list = el( 'ul', 'acfjp-suggestions' );
			error.suggestions.forEach( function ( suggestion ) {
				const li = el( 'li', null );
				const match = suggestion.match( /^([^(]+)\s*\((group_[a-z0-9_]+)\)$/i );
				if ( match ) {
					const groupTitle = match[1].trim();
					li.appendChild( document.createTextNode( suggestion + ' ' ) );
					const fixBtn = el( 'button', 'button button-small', 'Use "' + groupTitle + '" as Target' );
					fixBtn.style.marginLeft = '8px';
					fixBtn.type = 'button';
					fixBtn.addEventListener( 'click', function () {
						const raw = editor ? editor.codemirror.getValue() : ( document.getElementById( 'acfjp-json' ) || {} ).value;
						try {
							const parsed = JSON.parse( raw );
							if ( parsed && parsed.target ) {
								parsed.target.field_group = groupTitle;
								setEditorValue( JSON.stringify( parsed, null, 2 ) );
								const tSelect = document.getElementById( 'acfjp-template-group-select' );
								if ( tSelect ) tSelect.value = groupTitle;
								preview();
							}
						} catch ( err ) {}
					} );
					li.appendChild( fixBtn );
				} else {
					li.textContent = suggestion;
				}
				list.appendChild( li );
			} );
			box.appendChild( list );
		}

		// If error is about a missing field group, offer to convert to a "create" operation
		if ( error.message && error.message.toLowerCase().includes( 'not found' ) && error.message.toLowerCase().includes( 'field group' ) ) {
			const actions = el( 'div', null );
			actions.style.marginTop = '12px';
			actions.style.paddingTop = '12px';
			actions.style.borderTop = '1px solid #f0b849';

			const convertBtn = el( 'button', 'button button-secondary', '➕ Convert to "create" (Add as New Field Group)' );
			convertBtn.type = 'button';
			convertBtn.addEventListener( 'click', function () {
				const raw = editor ? editor.codemirror.getValue() : ( document.getElementById( 'acfjp-json' ) || {} ).value;
				try {
					const parsed = JSON.parse( raw );
					parsed.operation = 'create';
					const currentTarget = ( parsed.target && parsed.target.field_group ) || 'New Field Group';
					delete parsed.target;
					parsed.group_changes = { title: currentTarget };
					setEditorValue( JSON.stringify( parsed, null, 2 ) );
					const tSelect = document.getElementById( 'acfjp-template-group-select' );
					if ( tSelect ) tSelect.value = '__new__';
					preview();
				} catch ( err ) {}
			} );
			actions.appendChild( convertBtn );
			box.appendChild( actions );
		}

		target.appendChild( box );
	}

	function renderIssues( validation, container ) {
		[ 'errors', 'warnings' ].forEach( function ( bucket ) {
			( validation[ bucket ] || [] ).forEach( function ( issue ) {
				const row = el( 'div', 'acfjp-issue acfjp-issue--' + ( 'errors' === bucket ? 'error' : 'warning' ) );
				row.appendChild( el( 'strong', null, issue.message ) );

				if ( issue.pointer ) {
					row.appendChild( el( 'code', 'acfjp-pointer', issue.pointer ) );
				}

				( issue.suggestions || [] ).forEach( function ( suggestion ) {
					row.appendChild( el( 'span', 'acfjp-suggestion', '→ ' + suggestion ) );
				} );

				container.appendChild( row );
			} );
		} );
	}

	function markerFor( type ) {
		if ( 'add' === type || 'add_layout' === type || 'create_group' === type ) return '+';
		if ( 'delete' === type || 'delete_layout' === type ) return '−';
		if ( 'move' === type ) return '»';
		return '~';
	}

	function renderChange( change ) {
		const row = el( 'div', 'acfjp-change acfjp-change--' + change.type + ' acfjp-change--risk-' + change.risk );

		const head = el( 'div', 'acfjp-change__head' );
		head.appendChild( el( 'span', 'acfjp-marker', markerFor( change.type ) ) );
		head.appendChild( el( 'span', 'acfjp-change__label', change.label ) );
		head.appendChild( el( 'span', 'acfjp-change__summary', change.summary || '' ) );

		if ( 'safe' !== change.risk ) {
			head.appendChild( el( 'span', 'acfjp-pill acfjp-pill--' + change.risk, change.risk ) );
		}

		row.appendChild( head );
		row.appendChild( el( 'div', 'acfjp-change__path', change.path || '' ) );

		if ( change.setting_diffs ) {
			const table = el( 'table', 'acfjp-diffs' );

			Object.keys( change.setting_diffs ).forEach( function ( setting ) {
				const diff = change.setting_diffs[ setting ];
				const tr = el( 'tr' );
				tr.appendChild( el( 'th', null, setting ) );
				tr.appendChild( el( 'td', 'acfjp-from', format( diff.from ) ) );
				tr.appendChild( el( 'td', 'acfjp-arrow', '→' ) );
				tr.appendChild( el( 'td', 'acfjp-to', format( diff.to ) ) );
				table.appendChild( tr );
			} );

			row.appendChild( table );
		}

		if ( change.conflict ) {
			row.appendChild( renderConflict( change.conflict ) );
		}

		return row;
	}

	function format( value ) {
		if ( null === value || undefined === value ) return '-';
		if ( 'boolean' === typeof value ) return value ? 'true' : 'false';
		if ( '' === value ) return '""';
		if ( 'object' === typeof value ) return Array.isArray( value ) ? value.length + ' items' : 'object';
		return String( value );
	}

	function renderConflict( conflict ) {
		const box = el( 'div', 'acfjp-conflict' );
		box.appendChild( el( 'h4', null, conflict.title ) );
		box.appendChild( el( 'p', null, conflict.message ) );

		const options = el( 'div', 'acfjp-conflict__options' );

		( conflict.options || [] ).forEach( function ( option ) {
			const id = 'acfjp-c-' + conflict.id + '-' + option.id;

			const wrap = el( 'label', 'acfjp-conflict__option' + ( option.destructive ? ' is-destructive' : '' ) );

			const input = document.createElement( 'input' );
			input.type = 'radio';
			input.name = 'acfjp-conflict-' + conflict.id;
			input.value = option.id;
			input.id = id;
			input.dataset.conflict = conflict.id;
			input.checked = conflict.resolution ? conflict.resolution === option.id : false;

			wrap.appendChild( input );
			wrap.appendChild( el( 'strong', null, option.label ) );
			wrap.appendChild( el( 'span', null, option.description ) );

			options.appendChild( wrap );
		} );

		box.appendChild( options );

		if ( conflict.suggested ) {
			box.appendChild( el( 'p', 'description', 'Suggested: ' + conflict.suggested ) );
		}

		return box;
	}

	function collectResolutions() {
		const resolutions = {};

		document.querySelectorAll( 'input[type=radio][data-conflict]:checked' ).forEach( function ( input ) {
			resolutions[ input.dataset.conflict ] = input.value;
		} );

		return resolutions;
	}

	function countConflicts() {
		const ids = new Set();

		document.querySelectorAll( 'input[type=radio][data-conflict]' ).forEach( function ( input ) {
			ids.add( input.dataset.conflict );
		} );

		return ids.size;
	}

	function renderPlan( plan ) {
		showSidebarGuide( false );
		const target = result();
		if ( ! target ) return;
		target.innerHTML = '';

		currentPlan = plan;

		const panel = el( 'div', 'acfjp-panel' );

		if ( plan.validation && ( plan.validation.errors.length || plan.validation.warnings.length ) ) {
			const issues = el( 'div', 'acfjp-issues' );
			renderIssues( plan.validation, issues );
			panel.appendChild( issues );
		}

		const changeset = plan.changeset || { changes: [] };

		if ( ! changeset.changes.length ) {
			panel.appendChild( el( 'p', 'acfjp-empty', s.noChanges ) );
			target.appendChild( panel );
			return;
		}

		// Target Scope Box
		const scopeBox = el( 'div', 'acfjp-scope-box' );
		const scopeTop = el( 'div', 'acfjp-scope-box__top' );

		const breadcrumb = el( 'div', 'acfjp-target-breadcrumb' );
		const icon = el( 'span', 'dashicons dashicons-location' );
		breadcrumb.appendChild( icon );

		const groupTitle = changeset.group_title || changeset.group_key || 'Field Group';
		breadcrumb.appendChild( el( 'span', null, groupTitle ) );

		const meta = plan.meta || {};
		const locusDisplay = ( meta.locus && meta.locus.display ) ? meta.locus.display : '';
		if ( locusDisplay && locusDisplay !== groupTitle ) {
			breadcrumb.appendChild( el( 'span', 'acfjp-crumb-sep', '›' ) );
			breadcrumb.appendChild( el( 'span', 'acfjp-target-badge', locusDisplay ) );
		}

		scopeTop.appendChild( breadcrumb );

		const isolatedBadge = el( 'span', 'acfjp-scope-isolated' );
		const checkIcon = el( 'span', 'dashicons dashicons-yes-alt' );
		isolatedBadge.appendChild( checkIcon );
		isolatedBadge.appendChild( el( 'span', null, s.scopeIsolated || 'Target Isolated - Unrelated branches protected' ) );
		scopeTop.appendChild( isolatedBadge );

		scopeBox.appendChild( scopeTop );

		// Stats Chips
		const statsChips = el( 'div', 'acfjp-stats-chips' );
		const counts = changeset.counts || {};
		const addCount = ( counts.add || 0 ) + ( counts.add_layout || 0 ) + ( counts.create_group || 0 );
		const modCount = ( counts.update || 0 ) + ( counts.move || 0 ) + ( counts.update_setting || 0 );
		const delCount = ( counts.delete || 0 ) + ( counts.delete_layout || 0 );

		if ( addCount > 0 ) {
			statsChips.appendChild( el( 'span', 'acfjp-stat-chip acfjp-stat-chip--add', '+ ' + addCount + ' added' ) );
		}
		if ( modCount > 0 ) {
			statsChips.appendChild( el( 'span', 'acfjp-stat-chip acfjp-stat-chip--modify', '~ ' + modCount + ' modified' ) );
		}
		if ( delCount > 0 ) {
			statsChips.appendChild( el( 'span', 'acfjp-stat-chip acfjp-stat-chip--delete', '- ' + delCount + ' deleted' ) );
		}
		statsChips.appendChild( el( 'span', 'acfjp-stat-chip acfjp-stat-chip--protected', '🛡️ Protected outside target: 0 touched' ) );
		scopeBox.appendChild( statsChips );

		panel.appendChild( scopeBox );

		panel.appendChild(
			el( 'h2', null, changeset.group_title + ' - ' + changeset.count + ' change' + ( 1 === changeset.count ? '' : 's' ) )
		);

		const list = el( 'div', 'acfjp-changes' );
		changeset.changes.forEach( function ( change ) {
			list.appendChild( renderChange( change ) );
		} );
		panel.appendChild( list );

		// Safety & Confirmation Box
		const isDestructive = 'destructive' === changeset.risk || delCount > 0;
		const safetyBox = el( 'div', 'acfjp-safety-box' + ( isDestructive ? ' acfjp-safety-box--destructive' : '' ) );
		safetyBox.appendChild( el( 'h3', null, isDestructive ? '⚠️ Pre-Apply Safety Checklist (Destructive Changes)' : '🛡️ Pre-Apply Safety Checklist' ) );

		const checklist = el( 'div', 'acfjp-safety-checklist' );

		// Checkbox 1: Review
		const reviewLabel = el( 'label', 'acfjp-safety-item' );
		const reviewInput = document.createElement( 'input' );
		reviewInput.type = 'checkbox';
		reviewInput.id = 'acfjp-ack-review';
		reviewLabel.appendChild( reviewInput );
		reviewLabel.appendChild( el( 'span', null, s.ackReview || 'I have reviewed the target scope and diff above.' ) );
		checklist.appendChild( reviewLabel );

		// Checkbox 2: DB write
		const dbLabel = el( 'label', 'acfjp-safety-item' );
		const dbInput = document.createElement( 'input' );
		dbInput.type = 'checkbox';
		dbInput.id = 'acfjp-ack-modify';
		dbLabel.appendChild( dbInput );
		dbLabel.appendChild( el( 'span', null, s.ackModify || 'I understand this operation will write changes to the ACF database.' ) );
		checklist.appendChild( dbLabel );

		// Checkbox 3: Destructive acknowledgement if applicable
		if ( isDestructive ) {
			const destLabel = el( 'label', 'acfjp-safety-item is-destructive' );
			const destInput = document.createElement( 'input' );
			destInput.type = 'checkbox';
			destInput.id = 'acfjp-confirm';
			destLabel.appendChild( destInput );
			destLabel.appendChild( el( 'span', null, s.ackDestructive || s.confirmTitle || 'I acknowledge that this operation contains destructive modifications or deletions.' ) );
			checklist.appendChild( destLabel );
		}

		safetyBox.appendChild( checklist );

		// Toolbar with Export Backup and Apply button
		const toolbar = el( 'div', 'acfjp-preview-toolbar' );
		const toolbarLeft = el( 'div', 'acfjp-preview-toolbar__left' );
		const toolbarRight = el( 'div', 'acfjp-preview-toolbar__right' );

		if ( changeset.group_key ) {
			const exportBtn = el( 'button', 'button button-secondary', s.exportConfig || 'Export Current Configuration (JSON)' );
			exportBtn.type = 'button';
			exportBtn.id = 'acfjp-export-current';
			exportBtn.dataset.groupKey = changeset.group_key;
			toolbarLeft.appendChild( exportBtn );
		}

		const applyButton = el( 'button', 'button button-primary button-large', 'Apply ' + changeset.count + ' change' + ( 1 === changeset.count ? '' : 's' ) );
		applyButton.type = 'button';
		applyButton.id = 'acfjp-apply';
		applyButton.disabled = true;
		toolbarRight.appendChild( applyButton );

		function updateApplyState() {
			const hasReview = reviewInput.checked;
			const hasDb = dbInput.checked;
			const hasDest = isDestructive ? ( ( document.getElementById( 'acfjp-confirm' ) || {} ).checked || false ) : true;
			const isReady = ( hasReview && hasDb && hasDest );
			applyButton.disabled = ! isReady;
			applyButton.classList.toggle( 'is-ready-to-apply', isReady );
		}

		reviewInput.addEventListener( 'change', updateApplyState );
		dbInput.addEventListener( 'change', updateApplyState );
		if ( isDestructive ) {
			const destInputEl = document.getElementById( 'acfjp-confirm' );
			if ( destInputEl ) destInputEl.addEventListener( 'change', updateApplyState );
		}

		toolbar.appendChild( toolbarLeft );
		toolbar.appendChild( toolbarRight );
		safetyBox.appendChild( toolbar );

		panel.appendChild( safetyBox );
		target.appendChild( panel );
	}

	function renderApplied( data ) {
		showSidebarGuide( false );
		const target = result();
		if ( ! target ) return;
		target.innerHTML = '';

		const panel = el( 'div', 'acfjp-panel acfjp-panel--success' );
		panel.appendChild( el( 'h2', null, s.applied ) );
		panel.appendChild(
			el( 'p', null, data.count + ' change' + ( 1 === data.count ? '' : 's' ) + ' applied to "' + data.group_title + '".' )
		);

		( data.warnings || [] ).forEach( function ( warning ) {
			panel.appendChild( el( 'p', 'acfjp-issue acfjp-issue--warning', warning ) );
		} );

		panel.appendChild( el( 'p', 'description', s.rollbackHint ) );
		target.appendChild( panel );

		currentPlan = null;
	}

	// ---- Actions -----------------------------------------------------------

	async function validate() {
		busy( s.validating );

		const response = await api( '/validate', { method: 'POST', body: payload() } );

		if ( ! response.ok ) {
			renderError( response.error || {} );
			return;
		}

		const target = clear();
		const panel = el( 'div', 'acfjp-panel' );

		if ( response.validation.valid && ! response.validation.warnings.length ) {
			panel.appendChild( el( 'p', 'acfjp-ok', s.valid ) );
		}

		renderIssues( response.validation, panel );
		target.appendChild( panel );
	}

	async function preview() {
		busy( s.planning );

		const response = await api( '/plan', { method: 'POST', body: payload() } );

		if ( ! response.ok ) {
			renderError( response.error || {} );
			return;
		}

		renderPlan( response );
	}

	async function exportConfiguration( groupKey, button ) {
		if ( ! groupKey ) return;
		if ( button ) {
			button.disabled = true;
			button.textContent = s.exporting || 'Exporting...';
		}

		const response = await api( '/field-groups/' + encodeURIComponent( groupKey ), { method: 'GET' } );

		if ( button ) {
			button.disabled = false;
			button.textContent = s.exportConfig || 'Export Current Configuration (JSON)';
		}

		if ( ! response.ok || ! response.field_group ) {
			window.alert( ( response.error && response.error.message ) || s.genericError );
			return;
		}

		const blob = new Blob( [ JSON.stringify( [ response.field_group ], null, 2 ) ], { type: 'application/json' } );
		const url = URL.createObjectURL( blob );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = 'acf-export-' + groupKey + '-' + ( new Date().toISOString().slice( 0, 10 ) ) + '.json';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	async function apply() {
		if ( ! currentPlan ) return;

		const resolutions = collectResolutions();

		if ( Object.keys( resolutions ).length < countConflicts() ) {
			window.alert( s.unresolved );
			return;
		}

		const reviewBox = document.getElementById( 'acfjp-ack-review' );
		const dbBox = document.getElementById( 'acfjp-ack-modify' );
		const confirmBox = document.getElementById( 'acfjp-confirm' );

		if ( ( reviewBox && ! reviewBox.checked ) || ( dbBox && ! dbBox.checked ) ) {
			window.alert( 'Please review and check the safety acknowledgements before applying.' );
			return;
		}

		busy( s.applying );

		const response = await api( '/apply', {
			method: 'POST',
			body: JSON.stringify( {
				plan_id: currentPlan.plan_id,
				resolutions: resolutions,
				confirm: confirmBox ? confirmBox.checked : false,
			} ),
		} );

		if ( ! response.ok ) {
			renderError( response.error || {} );
			return;
		}

		renderApplied( response );
	}

	async function rollback( id, button ) {
		button.disabled = true;

		const response = await api( '/history/' + id + '/rollback', { method: 'POST' } );

		if ( ! response.ok ) {
			button.disabled = false;
			window.alert( ( response.error && response.error.message ) || s.genericError );
			return;
		}

		window.location.reload();
	}

	async function buildPrompt() {
		const groupSelect = document.getElementById( 'acfjp-prompt-group' );
		const groupVal = ( groupSelect && groupSelect.value ) ? groupSelect.value : '';
		const intent = ( document.getElementById( 'acfjp-prompt-intent' ) || {} ).value || '';

		const query = '/prompt?group_key=' + encodeURIComponent( groupVal ) + '&intent=' + encodeURIComponent( intent );
		const response = await api( query, { method: 'GET' } );

		if ( ! response.ok ) {
			renderError( response.error || {} );
			return;
		}

		const output = document.getElementById( 'acfjp-prompt-output' );
		const panel = document.getElementById( 'acfjp-prompt-result' );
		const copyBtn = document.getElementById( 'acfjp-prompt-copy' );

		if ( output ) output.value = response.prompt;
		if ( panel ) {
			panel.hidden = false;
			panel.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
		if ( copyBtn ) {
			copyBtn.focus();
		}
	}

	function copyFrom( node, button ) {
		node.select();

		try {
			document.execCommand( 'copy' );
			const original = button.textContent;
			button.textContent = s.copied || 'Copied';
			window.setTimeout( function () { button.textContent = original; }, 1500 );
		} catch ( e ) { /* clipboard unavailable; the text is selected for manual copy */ }
	}

	async function runSelfTest( button ) {
		const output = document.getElementById( 'acfjp-selftest-output' );
		if ( ! output ) return;

		button.disabled = true;
		const originalLabel = button.textContent;
		button.textContent = s.selfTestRunning || 'Running…';

		output.innerHTML = '';
		output.appendChild( el( 'p', 'acfjp-busy', s.selfTestRunning || 'Running…' ) );

		const response = await api( '/self-test', { method: 'POST' } );

		button.disabled = false;
		button.textContent = originalLabel;

		if ( ! response.ok ) {
			output.innerHTML = '';
			renderErrorInto( output, response.error || {} );
			return;
		}

		renderSelfTest( output, response.self_test );
	}

	function renderErrorInto( target, error ) {
		const box = el( 'div', 'acfjp-panel acfjp-panel--error' );
		box.appendChild( el( 'h2', null, error.message || s.genericError ) );
		( error.suggestions || [] ).forEach( function ( suggestion ) {
			box.appendChild( el( 'p', 'acfjp-suggestion', '→ ' + suggestion ) );
		} );
		target.appendChild( box );
	}

	function renderSelfTest( target, data ) {
		target.innerHTML = '';

		const headline = el(
			'div',
			'acfjp-panel ' + ( data.ok ? 'acfjp-panel--success' : ( data.critical ? 'acfjp-panel--error' : 'acfjp-panel--warn' ) )
		);

		headline.appendChild(
			el( 'h2', null, data.ok
				? ( s.selfTestPass || 'All checks passed.' )
				: ( data.failed + ' check' + ( 1 === data.failed ? '' : 's' ) + ' failed.' ) )
		);

		headline.appendChild(
			el( 'p', null, data.passed + ' passed · ' + data.failed + ' failed · ' + data.skipped + ' skipped · ' + data.duration + 'ms' )
		);

		if ( data.ok ) {
			headline.appendChild( el( 'p', 'description', s.selfTestPassHint || '' ) );
		} else if ( data.critical ) {
			headline.appendChild( el( 'p', 'acfjp-critical', s.selfTestCritical || '' ) );
		}

		target.appendChild( headline );

		Object.keys( data.sections || {} ).forEach( function ( section ) {
			const wrap = el( 'div', 'acfjp-selftest__section' );
			wrap.appendChild( el( 'h3', null, section ) );

			data.sections[ section ].forEach( function ( check ) {
				const row = el( 'div', 'acfjp-selftest__check is-' + check.status );

				const mark = 'pass' === check.status ? '✓' : ( 'fail' === check.status ? '✗' : '‒' );
				row.appendChild( el( 'span', 'acfjp-selftest__mark', mark ) );

				const body = el( 'div', 'acfjp-selftest__body' );
				body.appendChild( el( 'span', 'acfjp-selftest__name', check.name ) );

				if ( check.detail ) {
					body.appendChild( el( 'span', 'acfjp-selftest__detail', check.detail ) );
				}

				if ( check.critical && 'fail' === check.status ) {
					body.appendChild( el( 'span', 'acfjp-pill acfjp-pill--destructive', s.selfTestBlocking || 'blocking' ) );
				}

				row.appendChild( body );
				wrap.appendChild( row );
			} );

			target.appendChild( wrap );
		} );
	}

	function cleanJson( text ) {
		if ( ! text ) return '';
		let cleaned = text.trim();
		// Strip markdown code fences if wrapped in ```json or ```
		const fenceMatch = cleaned.match( /^```(?:json)?\s*([\s\S]*?)\s*```$/i );
		if ( fenceMatch ) {
			cleaned = fenceMatch[1].trim();
		}
		// Try parsing and pretty printing
		try {
			const parsed = JSON.parse( cleaned );
			return JSON.stringify( parsed, null, 2 );
		} catch ( e ) {
			return cleaned;
		}
	}

	function switchTab( tabName ) {
		document.querySelectorAll( '.acfjp-tab' ).forEach( function ( btn ) {
			const isActive = btn.dataset.tab === tabName;
			btn.classList.toggle( 'is-active', isActive );
			btn.classList.toggle( 'nav-tab-active', isActive );
			btn.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
		} );

		document.querySelectorAll( '.acfjp-tab-content' ).forEach( function ( content ) {
			const isActive = content.id === 'acfjp-tab-' + tabName;
			content.classList.toggle( 'is-active', isActive );
			content.hidden = ! isActive;
			content.style.display = isActive ? 'block' : 'none';
		} );

		if ( 'editor' === tabName && editor && editor.codemirror ) {
			window.setTimeout( function () {
				editor.codemirror.refresh();
			}, 50 );
		}
	}

	async function pasteFromClipboard( autoPreview ) {
		let text = '';
		try {
			if ( navigator.clipboard && navigator.clipboard.readText ) {
				text = await navigator.clipboard.readText();
			}
		} catch ( err ) {
			/* clipboard permissions or not supported */
		}

		if ( ! text ) {
			switchTab( 'editor' );
			setEditorValue( '' );
			window.alert( s.pasteError || 'Please press Ctrl+V or Cmd+V directly into the editor.' );
			return;
		}

		const formatted = cleanJson( text );
		setEditorValue( formatted );

		switchTab( 'editor' );

		const editorEl = document.getElementById( 'acfjp-import' );
		if ( editorEl ) {
			editorEl.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		if ( autoPreview ) {
			window.setTimeout( function () {
				preview();
			}, 300 );
		}
	}

	function setEditorValue( value ) {
		if ( editor ) {
			editor.codemirror.setValue( value );
			editor.codemirror.focus();
			return;
		}

		const textarea = document.getElementById( 'acfjp-json' );
		if ( textarea ) {
			textarea.value = value;
			textarea.focus();
		}
	}

	/**
	 * CodeMirror's JSON linter reports "unexpected EOF" on an empty document,
	 * which greets every first-time visitor with a red error before they have
	 * typed anything. Lint only once there is something to lint.
	 */
	function syncLint() {
		if ( ! editor ) return;

		const cm = editor.codemirror;
		const empty = '' === cm.getValue().trim();

		cm.setOption( 'lint', empty ? false : ( cfg.editor && cfg.editor.codemirror && cfg.editor.codemirror.lint ) || true );

		if ( empty && cm.clearGutter ) {
			try { cm.clearGutter( 'CodeMirror-lint-markers' ); } catch ( e ) {}
		}
	}

	// ---- Wiring ------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		const textarea = document.getElementById( 'acfjp-json' );

		if ( textarea && cfg.editor && window.wp && window.wp.codeEditor ) {
			editor = window.wp.codeEditor.initialize( textarea, cfg.editor );
			syncLint();
			editor.codemirror.on( 'change', syncLint );
		}

		// Template selector & target group selector change
		const templateSelect = document.getElementById( 'acfjp-template-select' );
		const templateGroupSelect = document.getElementById( 'acfjp-template-group-select' );

		if ( templateSelect ) {
			templateSelect.addEventListener( 'change', function () {
				const val = templateSelect.value;
				if ( ! val || ! cfg.examples || ! cfg.examples[ val ] ) return;

				const templatePayload = JSON.parse( JSON.stringify( cfg.examples[ val ].payload ) );

				// Target group override from user selector
				const selectedGroup = templateGroupSelect ? templateGroupSelect.value : '';

				if ( val === 'create_group' ) {
					// Add New Field Group template
					setEditorValue( JSON.stringify( templatePayload, null, 2 ) );
				} else {
					let targetName = selectedGroup;
					if ( ! targetName && templateGroupSelect ) {
						const opt = templateGroupSelect.querySelector( 'option[data-key]' );
						if ( opt ) {
							targetName = opt.value;
							templateGroupSelect.value = targetName;
						}
					}

					if ( targetName ) {
						if ( templatePayload.target ) {
							templatePayload.target.field_group = targetName;
						}
					}

					setEditorValue( JSON.stringify( templatePayload, null, 2 ) );
				}

				templateSelect.value = '';
			} );
		}

		if ( templateGroupSelect ) {
			templateGroupSelect.addEventListener( 'change', function () {
				const selectedGroup = templateGroupSelect.value;
				if ( ! selectedGroup ) return;

				const raw = editor ? editor.codemirror.getValue() : ( document.getElementById( 'acfjp-json' ) || {} ).value;
				if ( raw && raw.trim() ) {
					try {
						const parsed = JSON.parse( raw );
						if ( parsed.target && parsed.target.field_group !== undefined ) {
							parsed.target.field_group = selectedGroup;
							setEditorValue( JSON.stringify( parsed, null, 2 ) );
						}
					} catch ( err ) {
						/* ignore unparseable json */
					}
				}
			} );
		}

		// Option 1: Target Field Group & Inline Creation Handler
		const promptGroupSelect = document.getElementById( 'acfjp-prompt-group' );
		const toggleNewGroupBtn = document.getElementById( 'acfjp-btn-toggle-new-group' );
		const newGroupContainer = document.getElementById( 'acfjp-new-group-container' );
		const newGroupTitleInput = document.getElementById( 'acfjp-new-group-title' );
		const createGroupBtn = document.getElementById( 'acfjp-btn-create-group' );
		const cancelNewGroupBtn = document.getElementById( 'acfjp-btn-cancel-new-group' );
		const createGroupFeedback = document.getElementById( 'acfjp-create-group-feedback' );

		function setGroupFormOpen( isOpen ) {
			if ( ! newGroupContainer ) return;

			// If closing, ensure we never hide the form if 0 field groups exist
			if ( ! isOpen ) {
				const hasValidGroup = promptGroupSelect && Array.from( promptGroupSelect.options ).some( function ( opt ) {
					return opt.value && ! opt.disabled;
				} );
				if ( ! hasValidGroup ) {
					newGroupContainer.style.display = 'block';
					if ( toggleNewGroupBtn ) toggleNewGroupBtn.style.display = 'none';
					if ( cancelNewGroupBtn ) cancelNewGroupBtn.style.display = 'none';
					return;
				}
			}

			if ( isOpen ) {
				newGroupContainer.style.display = 'block';
				if ( toggleNewGroupBtn ) {
					toggleNewGroupBtn.innerHTML = '<span class="dashicons dashicons-no-alt" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span> <span>' + ( s.close || 'Close' ) + '</span>';
				}
				if ( newGroupTitleInput ) {
					newGroupTitleInput.focus();
				}
			} else {
				newGroupContainer.style.display = 'none';
				if ( toggleNewGroupBtn ) {
					toggleNewGroupBtn.innerHTML = '<span class="dashicons dashicons-plus-alt2" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span> <span>' + ( s.addNewFieldGroup || 'Add New Field Group' ) + '</span>';
				}
				if ( newGroupTitleInput ) {
					newGroupTitleInput.classList.remove( 'acfjp-input-error' );
				}
				if ( createGroupFeedback ) {
					createGroupFeedback.style.display = 'none';
				}
			}
		}

		if ( toggleNewGroupBtn && newGroupContainer ) {
			toggleNewGroupBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const isVisible = newGroupContainer.style.display !== 'none';
				setGroupFormOpen( ! isVisible );
			} );
		}

		if ( cancelNewGroupBtn && newGroupContainer ) {
			cancelNewGroupBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setGroupFormOpen( false );
			} );
		}

		function isGroupLocked() {
			if ( ! promptGroupSelect ) return false;
			const val = promptGroupSelect.value;
			if ( ! val || val === '' || promptGroupSelect.options.length === 0 ) return true;
			const selectedOpt = promptGroupSelect.options[ promptGroupSelect.selectedIndex ];
			return selectedOpt ? selectedOpt.disabled : false;
		}

		function updateGroupLockState() {
			const locked = isGroupLocked();
			const gateBanner = document.getElementById( 'acfjp-builder-gate-banner' );
			const builderBox = document.getElementById( 'acfjp-builder-box' );
			const quickChips = document.getElementById( 'acfjp-quick-chips' );
			const quickDropdown = document.getElementById( 'acfjp-quick-dropdown-row' );
			const buildBtn = document.getElementById( 'acfjp-prompt-build' );

			if ( gateBanner ) gateBanner.style.display = locked ? 'flex' : 'none';
			if ( builderBox ) builderBox.classList.toggle( 'is-locked', locked );
			if ( quickChips ) quickChips.classList.toggle( 'is-locked', locked );
			if ( quickDropdown ) quickDropdown.classList.toggle( 'is-locked', locked );
			if ( buildBtn && locked ) {
				buildBtn.classList.remove( 'is-ready' );
			}
		}

		function highlightGateNotice() {
			const gateBanner = document.getElementById( 'acfjp-builder-gate-banner' );
			const newGroupContainer = document.getElementById( 'acfjp-new-group-container' );
			const newGroupTitle = document.getElementById( 'acfjp-new-group-title' );

			if ( gateBanner ) {
				gateBanner.classList.remove( 'acfjp-pulse-alert' );
				void gateBanner.offsetWidth; // re-flow
				gateBanner.classList.add( 'acfjp-pulse-alert' );
				gateBanner.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			}

			if ( newGroupContainer && newGroupContainer.style.display !== 'none' && newGroupTitle ) {
				newGroupTitle.classList.add( 'acfjp-input-error' );
				newGroupTitle.focus();
			} else if ( promptGroupSelect ) {
				promptGroupSelect.focus();
			}
		}

		if ( promptGroupSelect ) {
			promptGroupSelect.addEventListener( 'change', function () {
				updateGroupLockState();
			} );
		}

		// Initial gate state check
		updateGroupLockState();

		if ( newGroupTitleInput ) {
			newGroupTitleInput.addEventListener( 'input', function () {
				newGroupTitleInput.classList.remove( 'acfjp-input-error' );
				if ( createGroupFeedback ) {
					createGroupFeedback.style.display = 'none';
				}
			} );
		}

		async function handleCreateFieldGroup() {
			if ( ! newGroupTitleInput || ! createGroupBtn ) return;
			const title = newGroupTitleInput.value.trim();
			if ( ! title ) {
				newGroupTitleInput.classList.add( 'acfjp-input-error' );
				if ( createGroupFeedback ) {
					createGroupFeedback.style.display = 'flex';
					createGroupFeedback.style.alignItems = 'center';
					createGroupFeedback.style.gap = '4px';
					createGroupFeedback.style.color = '#d63638';
					createGroupFeedback.style.fontWeight = '600';
					createGroupFeedback.innerHTML = '<span class="dashicons dashicons-warning" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span> Field group title is required.';
				}
				newGroupTitleInput.focus();
				return;
			}

			createGroupBtn.disabled = true;
			const origText = createGroupBtn.textContent;
			createGroupBtn.textContent = 'Creating...';
			if ( createGroupFeedback ) {
				createGroupFeedback.style.display = 'block';
				createGroupFeedback.style.color = '#50575e';
				createGroupFeedback.textContent = 'Creating field group in ACF...';
			}

			try {
				const params = new URLSearchParams();
				params.append( 'action', 'fieldpilot_create_field_group' );
				params.append( 'nonce', ( window.ACFJP && window.ACFJP.ajaxNonce ) || '' );
				params.append( 'title', title );

				const ajaxUrl = ( window.ACFJP && window.ACFJP.ajaxUrl ) || '/wp-admin/admin-ajax.php';
				const res = await fetch( ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: params.toString()
				} );

				const data = await res.json();
				if ( ! data.success ) {
					throw new Error( ( data.data && data.data.message ) || 'Failed to create field group.' );
				}

				const created = data.data;

				// Append to Tab 1 promptGroupSelect
				if ( promptGroupSelect ) {
					const placeholderOpt = promptGroupSelect.querySelector( 'option[disabled]' );
					if ( placeholderOpt ) {
						placeholderOpt.remove();
					}
					const opt = document.createElement( 'option' );
					opt.value = created.key;
					opt.dataset.title = created.title;
					opt.textContent = created.title + ' (' + created.key + ')';
					promptGroupSelect.appendChild( opt );
					promptGroupSelect.value = created.key;
				}

				// Append to Tab 2 templateGroupSelect
				const templateGroupSelect = document.getElementById( 'acfjp-template-group-select' );
				if ( templateGroupSelect ) {
					const placeholderOpt2 = templateGroupSelect.querySelector( 'option[disabled]' );
					if ( placeholderOpt2 ) {
						placeholderOpt2.remove();
					}
					const opt2 = document.createElement( 'option' );
					opt2.value = created.title;
					opt2.dataset.key = created.key;
					opt2.textContent = '🎯 ' + created.title;
					templateGroupSelect.appendChild( opt2 );
					templateGroupSelect.value = created.title;
				}

				newGroupTitleInput.value = '';
				newGroupTitleInput.classList.remove( 'acfjp-input-error' );
				if ( cancelNewGroupBtn ) {
					cancelNewGroupBtn.style.display = 'inline-block';
				}
				const newGroupLabel = document.getElementById( 'acfjp-new-group-label' );
				if ( newGroupLabel ) {
					newGroupLabel.textContent = 'Create New ACF Field Group:';
				}
				if ( toggleNewGroupBtn ) {
					toggleNewGroupBtn.style.display = 'inline-flex';
				}
				setGroupFormOpen( false );

				// Unlock builder immediately
				updateGroupLockState();

				// Show brief success notice next to select
				const desc = document.getElementById( 'acfjp-prompt-group-desc' );
				if ( desc ) {
					desc.innerHTML = '<span style="color: #007017; font-weight: 600;">✓ Field group "' + created.title + '" created and selected.</span>';
					setTimeout( function () {
						desc.textContent = 'Select the field group you want to modify, or click "Add New Field Group" to create one.';
					}, 4000 );
				}

				const customNameInput = document.getElementById( 'acfjp-custom-name' );
				if ( customNameInput ) {
					customNameInput.focus();
				}
			} catch ( err ) {
				if ( createGroupFeedback ) {
					createGroupFeedback.style.display = 'block';
					createGroupFeedback.style.color = '#d63638';
					createGroupFeedback.textContent = err.message || 'Error creating group.';
				}
			} finally {
				createGroupBtn.disabled = false;
				createGroupBtn.textContent = origText;
			}
		}

		if ( createGroupBtn ) {
			createGroupBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				handleCreateFieldGroup();
			} );
		}

		if ( newGroupTitleInput ) {
			newGroupTitleInput.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					handleCreateFieldGroup();
				}
			} );
		}

		// Quick field type selector change (bulk append)
		const quickFieldSelect = document.getElementById( 'acfjp-quick-field-select' );
		if ( quickFieldSelect ) {
			quickFieldSelect.addEventListener( 'change', function () {
				const val = quickFieldSelect.value;
				if ( val ) {
					appendIntent( val );
					quickFieldSelect.value = '';
				}
			} );
		}

		function updateGenerateButtonState() {
			const intentInput = document.getElementById( 'acfjp-prompt-intent' );
			const buildBtn = document.getElementById( 'acfjp-prompt-build' );
			const genHint = document.getElementById( 'acfjp-gen-hint' );
			if ( ! buildBtn ) return;

			const hasGroup = ! isGroupLocked();
			const hasText = intentInput && intentInput.value.trim().length > 0;
			const isReady = hasGroup && hasText;

			buildBtn.classList.toggle( 'is-ready', isReady );
			if ( genHint ) {
				genHint.style.display = isReady ? 'inline-block' : 'none';
			}
		}

		function appendIntent( text ) {
			if ( isGroupLocked() ) {
				highlightGateNotice();
				return;
			}

			const intentInput = document.getElementById( 'acfjp-prompt-intent' );
			if ( ! intentInput || ! text ) return;

			const current = intentInput.value.trim();
			if ( ! current ) {
				intentInput.value = text;
			} else {
				intentInput.value = current + '\n' + text;
			}
			intentInput.focus();
			intentInput.scrollTop = intentInput.scrollHeight;
			updateGenerateButtonState();
		}

		// 4-Tab Custom Field Builder Handler
		function updateBuilderFieldVisibility() {
			const typeSelect = document.getElementById( 'acfjp-custom-type' );
			if ( ! typeSelect ) return;
			const type = typeSelect.value || 'text';

			const wrapReturn = document.getElementById( 'acfjp-bwrap-return-format' );
			const wrapChoices = document.getElementById( 'acfjp-bwrap-choices' );
			const wrapDefault = document.getElementById( 'acfjp-bwrap-default' );
			const wrapSubfields = document.getElementById( 'acfjp-bwrap-subfields' );
			const wrapLayouts = document.getElementById( 'acfjp-bwrap-layouts' );
			const wrapPosttypes = document.getElementById( 'acfjp-bwrap-posttypes' );
			const wrapTaxonomy = document.getElementById( 'acfjp-bwrap-taxonomy' );
			const wrapToolbar = document.getElementById( 'acfjp-bwrap-toolbar' );
			const wrapBtnlabel = document.getElementById( 'acfjp-bwrap-btnlabel' );

			const isMedia = [ 'image', 'file', 'gallery', 'link' ].indexOf( type ) !== -1;
			const isChoice = [ 'select', 'checkbox', 'radio', 'button_group' ].indexOf( type ) !== -1;
			const isStructure = [ 'repeater', 'group' ].indexOf( type ) !== -1;
			const isFlex = 'flexible_content' === type;
			const isRelational = [ 'post_object', 'relationship', 'page_link' ].indexOf( type ) !== -1;
			const isTaxonomy = 'taxonomy' === type;
			const isWysiwyg = 'wysiwyg' === type;

			if ( wrapReturn ) wrapReturn.style.display = ( isMedia || isRelational || isTaxonomy ) ? 'block' : 'none';
			if ( wrapChoices ) wrapChoices.style.display = isChoice ? 'block' : 'none';
			if ( wrapDefault ) wrapDefault.style.display = ( isChoice || [ 'text', 'textarea', 'number', 'range', 'email', 'url', 'color_picker' ].indexOf( type ) !== -1 ) ? 'block' : 'none';
			if ( wrapSubfields ) wrapSubfields.style.display = isStructure ? 'block' : 'none';
			if ( wrapLayouts ) wrapLayouts.style.display = isFlex ? 'block' : 'none';
			if ( wrapPosttypes ) wrapPosttypes.style.display = isRelational ? 'block' : 'none';
			if ( wrapTaxonomy ) wrapTaxonomy.style.display = isTaxonomy ? 'block' : 'none';
			if ( wrapToolbar ) wrapToolbar.style.display = isWysiwyg ? 'block' : 'none';
			if ( wrapBtnlabel ) wrapBtnlabel.style.display = ( isStructure || isFlex ) ? 'block' : 'none';
		}

		const customTypeSelect = document.getElementById( 'acfjp-custom-type' );
		if ( customTypeSelect ) {
			customTypeSelect.addEventListener( 'change', updateBuilderFieldVisibility );
			updateBuilderFieldVisibility();
		}

		// Builder sub-tabs switching
		document.addEventListener( 'click', function ( e ) {
			const bTab = e.target.closest( '.acfjp-builder-tab' );
			if ( bTab ) {
				e.preventDefault();
				const targetTab = bTab.dataset.builderTab;
				document.querySelectorAll( '.acfjp-builder-tab' ).forEach( function ( t ) {
					t.classList.remove( 'is-active' );
				} );
				bTab.classList.add( 'is-active' );

				document.querySelectorAll( '.acfjp-builder-panel' ).forEach( function ( p ) {
					p.classList.remove( 'is-active' );
				} );
				const activePanel = document.getElementById( 'acfjp-bpanel-' + targetTab );
				if ( activePanel ) {
					activePanel.classList.add( 'is-active' );
				}
			}
		} );

		function addCustomField() {
			if ( isGroupLocked() ) {
				highlightGateNotice();
				return;
			}

			const nameInput = document.getElementById( 'acfjp-custom-name' );
			const typeSelect = document.getElementById( 'acfjp-custom-type' );
			const widthSelect = document.getElementById( 'acfjp-custom-width' );
			const reqCheckbox = document.getElementById( 'acfjp-custom-required' );
			const instInput = document.getElementById( 'acfjp-custom-instructions' );
			const nameErr = document.getElementById( 'acfjp-custom-name-error' );

			if ( ! nameInput || ! typeSelect ) return;

			const name = nameInput.value.trim();
			const type = typeSelect.value || 'text';
			const width = widthSelect ? widthSelect.value : '';
			const isReq = reqCheckbox && reqCheckbox.checked;
			const instructions = instInput ? instInput.value.trim() : '';

			if ( ! name ) {
				nameInput.classList.add( 'acfjp-input-error' );
				if ( nameErr ) {
					nameErr.style.display = 'flex';
					nameErr.innerHTML = '<span class="dashicons dashicons-warning" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span> Please enter a Field Label or Name (e.g. Price, Subtitle) to append.';
				}
				nameInput.focus();
				return;
			}

			// Validation: min vs max
			const minInput = document.getElementById( 'acfjp-b-min' );
			const maxInput = document.getElementById( 'acfjp-b-max' );
			if ( minInput && maxInput && minInput.value.trim() !== '' && maxInput.value.trim() !== '' ) {
				const minVal = parseFloat( minInput.value.trim() );
				const maxVal = parseFloat( maxInput.value.trim() );
				if ( ! isNaN( minVal ) && ! isNaN( maxVal ) && minVal > maxVal ) {
					minInput.classList.add( 'acfjp-input-error' );
					maxInput.classList.add( 'acfjp-input-error' );
					if ( nameErr ) {
						nameErr.style.display = 'flex';
						nameErr.innerHTML = '<span class="dashicons dashicons-warning" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span> Minimum value cannot be greater than Maximum value in Validation settings.';
					}
					const valTabBtn = document.querySelector( '.acfjp-builder-tab[data-builder-tab="validation"]' );
					if ( valTabBtn ) valTabBtn.click();
					minInput.focus();
					return;
				}
			}

			let spec = '- Add a ' + type + ' field named "' + name + '"';
			const extras = [];

			// Tab 1: General settings
			const returnSelect = document.getElementById( 'acfjp-b-return-format' );
			const choicesInput = document.getElementById( 'acfjp-b-choices' );
			const defaultInput = document.getElementById( 'acfjp-b-default' );
			const subfieldsInput = document.getElementById( 'acfjp-b-subfields' );
			const layoutsInput = document.getElementById( 'acfjp-b-layouts' );
			const posttypesInput = document.getElementById( 'acfjp-b-posttypes' );
			const taxonomyInput = document.getElementById( 'acfjp-b-taxonomy' );
			const toolbarSelect = document.getElementById( 'acfjp-b-toolbar' );
			const btnlabelInput = document.getElementById( 'acfjp-b-btnlabel' );

			const isMedia = [ 'image', 'file', 'gallery', 'link' ].indexOf( type ) !== -1;
			const isChoice = [ 'select', 'checkbox', 'radio', 'button_group' ].indexOf( type ) !== -1;
			const isStructure = [ 'repeater', 'group' ].indexOf( type ) !== -1;
			const isFlex = 'flexible_content' === type;
			const isRelational = [ 'post_object', 'relationship', 'page_link' ].indexOf( type ) !== -1;
			const isTaxonomy = 'taxonomy' === type;
			const isWysiwyg = 'wysiwyg' === type;

			if ( ( isMedia || isRelational || isTaxonomy ) && returnSelect && returnSelect.value ) {
				extras.push( 'return_format: ' + returnSelect.value );
			}
			if ( isChoice && choicesInput && choicesInput.value.trim() ) {
				extras.push( 'choices: "' + choicesInput.value.trim() + '"' );
			}
			if ( defaultInput && defaultInput.value.trim() ) {
				extras.push( 'default: "' + defaultInput.value.trim() + '"' );
			}
			if ( isStructure && subfieldsInput && subfieldsInput.value.trim() ) {
				extras.push( 'sub_fields: [' + subfieldsInput.value.trim() + ']' );
			}
			if ( isFlex && layoutsInput && layoutsInput.value.trim() ) {
				extras.push( 'layouts: [' + layoutsInput.value.trim() + ']' );
			}
			if ( isRelational && posttypesInput && posttypesInput.value.trim() ) {
				extras.push( 'post_types: [' + posttypesInput.value.trim() + ']' );
			}
			if ( isTaxonomy && taxonomyInput && taxonomyInput.value.trim() ) {
				extras.push( 'taxonomy: "' + taxonomyInput.value.trim() + '"' );
			}
			if ( isWysiwyg && toolbarSelect && toolbarSelect.value ) {
				extras.push( 'toolbar: ' + toolbarSelect.value );
			}
			if ( ( isStructure || isFlex ) && btnlabelInput && btnlabelInput.value.trim() ) {
				extras.push( 'button_label: "' + btnlabelInput.value.trim() + '"' );
			}

			// Tab 2: Validation settings
			if ( isReq ) extras.push( 'required' );

			const stepInput = document.getElementById( 'acfjp-b-step' );
			const maxlenInput = document.getElementById( 'acfjp-b-maxlength' );
			const mimesInput = document.getElementById( 'acfjp-b-mimes' );

			if ( minInput && minInput.value.trim() ) extras.push( 'min: ' + minInput.value.trim() );
			if ( maxInput && maxInput.value.trim() ) extras.push( 'max: ' + maxInput.value.trim() );
			if ( stepInput && stepInput.value.trim() ) extras.push( 'step: ' + stepInput.value.trim() );
			if ( maxlenInput && maxlenInput.value.trim() ) extras.push( 'maxlength: ' + maxlenInput.value.trim() );
			if ( mimesInput && mimesInput.value.trim() ) extras.push( 'mime_types: "' + mimesInput.value.trim() + '"' );

			// Tab 3: Presentation settings
			if ( width ) extras.push( 'width: ' + width );
			if ( instructions ) extras.push( 'instructions: "' + instructions + '"' );

			const phInput = document.getElementById( 'acfjp-b-placeholder' );
			const prepInput = document.getElementById( 'acfjp-b-prepend' );
			const appInput = document.getElementById( 'acfjp-b-append' );
			const rowsInput = document.getElementById( 'acfjp-b-rows' );
			const classInput = document.getElementById( 'acfjp-b-class' );

			if ( phInput && phInput.value.trim() ) extras.push( 'placeholder: "' + phInput.value.trim() + '"' );
			if ( prepInput && prepInput.value.trim() ) extras.push( 'prepend: "' + prepInput.value.trim() + '"' );
			if ( appInput && appInput.value.trim() ) extras.push( 'append: "' + appInput.value.trim() + '"' );
			if ( 'textarea' === type && rowsInput && rowsInput.value.trim() ) extras.push( 'rows: ' + rowsInput.value.trim() );
			if ( classInput && classInput.value.trim() ) extras.push( 'wrapper_class: "' + classInput.value.trim() + '"' );

			// Tab 4: Conditional Logic
			const condField = document.getElementById( 'acfjp-b-cond-field' );
			const condOp = document.getElementById( 'acfjp-b-cond-op' );
			const condVal = document.getElementById( 'acfjp-b-cond-val' );

			if ( condField && condField.value.trim() && condVal && condVal.value.trim() ) {
				const op = condOp ? condOp.value : '==';
				extras.push( 'conditional: ' + condField.value.trim() + ' ' + op + ' "' + condVal.value.trim() + '"' );
			}

			if ( extras.length > 0 ) {
				spec += ' (' + extras.join( ', ' ) + ')';
			}

			appendIntent( spec );

			// Visual indicator
			const indicator = document.getElementById( 'acfjp-added-indicator' );
			if ( indicator ) {
				indicator.style.display = 'inline-flex';
				window.setTimeout( function () {
					indicator.style.display = 'none';
				}, 2200 );
			}

			// Clean inputs
			nameInput.value = '';
			if ( instInput ) instInput.value = '';
			if ( widthSelect ) widthSelect.value = '';
			if ( reqCheckbox ) reqCheckbox.checked = false;
			if ( choicesInput ) choicesInput.value = '';
			if ( defaultInput ) defaultInput.value = '';
			if ( subfieldsInput ) subfieldsInput.value = '';
			if ( layoutsInput ) layoutsInput.value = '';
			if ( posttypesInput ) posttypesInput.value = '';
			if ( taxonomyInput ) taxonomyInput.value = '';
			if ( btnlabelInput ) btnlabelInput.value = '';
			if ( minInput ) minInput.value = '';
			if ( maxInput ) maxInput.value = '';
			if ( stepInput ) stepInput.value = '';
			if ( maxlenInput ) maxlenInput.value = '';
			if ( mimesInput ) mimesInput.value = '';
			if ( phInput ) phInput.value = '';
			if ( prepInput ) prepInput.value = '';
			if ( appInput ) appInput.value = '';
			if ( rowsInput ) rowsInput.value = '';
			if ( classInput ) classInput.value = '';
			if ( condField ) condField.value = '';
			if ( condVal ) condVal.value = '';

			nameInput.classList.remove( 'acfjp-input-error' );
			if ( nameErr ) nameErr.style.display = 'none';
			if ( minInput ) minInput.classList.remove( 'acfjp-input-error' );
			if ( maxInput ) maxInput.classList.remove( 'acfjp-input-error' );

			nameInput.focus();
		}

		// Real-time error removal on typing
		const customNameEl = document.getElementById( 'acfjp-custom-name' );
		if ( customNameEl ) {
			customNameEl.addEventListener( 'input', function () {
				customNameEl.classList.remove( 'acfjp-input-error' );
				const nameErr = document.getElementById( 'acfjp-custom-name-error' );
				if ( nameErr ) {
					nameErr.style.display = 'none';
				}
			} );
		}

		const minEl = document.getElementById( 'acfjp-b-min' );
		const maxEl = document.getElementById( 'acfjp-b-max' );
		[ minEl, maxEl ].forEach( function ( el ) {
			if ( el ) {
				el.addEventListener( 'input', function () {
					if ( minEl ) minEl.classList.remove( 'acfjp-input-error' );
					if ( maxEl ) maxEl.classList.remove( 'acfjp-input-error' );
					const nameErr = document.getElementById( 'acfjp-custom-name-error' );
					if ( nameErr ) nameErr.style.display = 'none';
				} );
			}
		} );

		// Attach Enter key triggers to all inputs in the builder box
		document.querySelectorAll( '.acfjp-builder-box input' ).forEach( function ( input ) {
			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					addCustomField();
				}
			} );
		} );

		// Hash change synchronization (default to AI tab if not specified)
		function syncTabWithHash() {
			const hash = ( window.location.hash || '' ).replace( '#', '' );
			if ( hash && ( 'editor' === hash || 'ai' === hash || 'guide' === hash ) ) {
				switchTab( hash );
			} else {
				switchTab( 'ai' );
			}
		}

		syncTabWithHash();
		window.addEventListener( 'hashchange', syncTabWithHash );

		const intentTextarea = document.getElementById( 'acfjp-prompt-intent' );
		if ( intentTextarea ) {
			intentTextarea.addEventListener( 'input', updateGenerateButtonState );
		}
		updateGenerateButtonState();

		document.addEventListener( 'click', function ( event ) {
			const target = event.target;

			// If group is locked and user clicks on builder, chips, or build button
			if ( isGroupLocked() && ( target.closest( '#acfjp-builder-box' ) || target.closest( '#acfjp-quick-chips' ) || target.closest( '#acfjp-quick-dropdown-row' ) || target.closest( '#acfjp-builder-gate-banner' ) || target.closest( '#acfjp-prompt-build' ) ) ) {
				event.preventDefault();
				highlightGateNotice();
				return;
			}

			// Custom field builder add (top or bottom button)
			if ( target.closest( '#acfjp-custom-add' ) || target.closest( '#acfjp-custom-add-main' ) ) {
				event.preventDefault();
				addCustomField();
				return;
			}

			// Tabs
			const tabBtn = target.closest( '.acfjp-tab' );
			if ( tabBtn ) {
				event.preventDefault();
				const tab = tabBtn.dataset.tab;
				if ( tab ) {
					switchTab( tab );
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, null, '#' + tab );
					}
				}
				return;
			}

			// Clear intent
			if ( target.closest( '#acfjp-intent-clear' ) ) {
				event.preventDefault();
				const intentInput = document.getElementById( 'acfjp-prompt-intent' );
				if ( intentInput ) {
					intentInput.value = '';
					intentInput.focus();
					updateGenerateButtonState();
				}
				return;
			}

			// Chips (bulk append)
			const chip = target.closest( '.acfjp-chip' );
			if ( chip ) {
				event.preventDefault();
				const intent = chip.dataset.intent;
				if ( intent ) {
					appendIntent( intent );
				}
				return;
			}

			// Key badges
			const keyBadge = target.closest( '.acfjp-key-badge' );
			if ( keyBadge ) {
				event.preventDefault();
				const key = keyBadge.dataset.key;
				const title = keyBadge.dataset.title;
				const tGroupSelect = document.getElementById( 'acfjp-template-group-select' );
				if ( title && tGroupSelect ) {
					tGroupSelect.value = title;
				}
				if ( key && navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( key );
					const originalText = keyBadge.innerHTML;
					keyBadge.innerHTML = '<code>' + ( s.copied || 'Copied!' ) + '</code>';
					window.setTimeout( function () { keyBadge.innerHTML = originalText; }, 1500 );
				}
				return;
			}

			// Buttons
			if ( target.closest( '#acfjp-validate' ) ) { event.preventDefault(); validate(); return; }
			if ( target.closest( '#acfjp-preview' ) ) { event.preventDefault(); preview(); return; }
			if ( target.closest( '#acfjp-apply' ) ) { event.preventDefault(); apply(); return; }
			if ( target.closest( '#acfjp-prompt-build' ) ) { event.preventDefault(); buildPrompt(); return; }
			if ( target.closest( '#acfjp-paste-clipboard' ) ) { event.preventDefault(); pasteFromClipboard( false ); return; }
			if ( target.closest( '#acfjp-paste-and-preview' ) ) { event.preventDefault(); pasteFromClipboard( true ); return; }

			const exportBtn = target.closest( '#acfjp-export-current' );
			if ( exportBtn ) {
				event.preventDefault();
				exportConfiguration( exportBtn.dataset.groupKey, exportBtn );
				return;
			}

			const selfTestButton = target.closest( '#acfjp-selftest-run' );
			if ( selfTestButton ) { event.preventDefault(); runSelfTest( selfTestButton ); return; }

			if ( target.closest( '#acfjp-clear' ) ) {
				event.preventDefault();
				setEditorValue( '' );
				clear();
				return;
			}

			const promptCopy = target.closest( '#acfjp-prompt-copy' );
			if ( promptCopy ) {
				event.preventDefault();
				const output = document.getElementById( 'acfjp-prompt-output' );
				if ( output ) {
					const original = promptCopy.innerHTML;
					copyFrom( output, promptCopy );
					promptCopy.innerHTML = '<span class="dashicons dashicons-yes"></span> ' + ( s.promptCopied || '1. Copied! Now paste into AI ➔' );
					window.setTimeout( function () { promptCopy.innerHTML = original; }, 2500 );

					const step2b = document.getElementById( 'acfjp-prompt-step2b' );
					if ( step2b ) {
						step2b.classList.add( 'is-active-step' );
					}
				}
				return;
			}

			// Guide template loaders & copy actions
			const guideLoadBtn = target.closest( '.acfjp-guide-load' );
			if ( guideLoadBtn ) {
				event.preventDefault();
				const targetId = guideLoadBtn.dataset.exampleTarget;
				const codeEl = targetId ? document.getElementById( targetId ) : null;
				if ( codeEl ) {
					const jsonText = codeEl.textContent || '';
					setEditorValue( cleanJson( jsonText ) );
					switchTab( 'editor' );
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, null, '#editor' );
					}
					const editorEl = document.getElementById( 'acfjp-import' );
					if ( editorEl ) {
						editorEl.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				}
				return;
			}

			const guideCopyBtn = target.closest( '.acfjp-guide-copy' );
			if ( guideCopyBtn ) {
				event.preventDefault();
				const targetId = guideCopyBtn.dataset.exampleTarget;
				const codeEl = targetId ? document.getElementById( targetId ) : null;
				if ( codeEl && navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( codeEl.textContent || '' );
					const origHtml = guideCopyBtn.innerHTML;
					guideCopyBtn.innerHTML = '<span class="dashicons dashicons-yes"></span> ' + ( s.copied || 'Copied!' );
					window.setTimeout( function () { guideCopyBtn.innerHTML = origHtml; }, 1500 );
				}
				return;
			}

			const copyButton = target.closest( '.acfjp-copy' );
			if ( copyButton ) {
				event.preventDefault();
				const node = document.getElementById( copyButton.dataset.target );
				if ( node ) copyFrom( node, copyButton );
				return;
			}

			const rollbackButton = target.closest( '.acfjp-rollback' );
			if ( rollbackButton ) {
				event.preventDefault();
				if ( window.confirm( 'Restore this field group to its previous state?' ) ) {
					rollback( rollbackButton.dataset.id, rollbackButton );
				}
			}
		} );
	} );
}() );
