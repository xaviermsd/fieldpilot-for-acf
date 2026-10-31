// Feature cards shared by banner.html and social.html.
const FIELDPILOT_FEATURES = [
	[ 'ti-sparkles', 'Build', 'Prompt your AI', 'A ready prompt, no API key' ],
	[ 'ti-code', 'Build', 'Paste JSON', 'AI replies or ACF exports' ],
	[ 'ti-git-compare', 'Preview', 'Diff first', 'Only what you named changes' ],
	[ 'ti-shield-check', 'Protect', 'Risky changes ask', 'Type changes, deletes with content' ],
	[ 'ti-history', 'Undo', 'Snapshot and undo', 'Every batch, newest first' ],
	[ 'ti-terminal-2', 'Automate', 'REST and WP-CLI', 'Apply from deployments' ],
];

document.getElementById( 'grid' ).innerHTML = FIELDPILOT_FEATURES.map(
	( [ icon, tag, title, text ] ) =>
		`<div class="card"><div class="chip"><i class="ti ${ icon }"></i></div><div class="tag">${ tag.toUpperCase() }</div><h3>${ title }</h3><p>${ text }</p></div>`
).join( '' );
