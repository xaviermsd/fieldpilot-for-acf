// Feature cards shared by banner.html and social.html.
const FIELDPILOT_FEATURES = [
	[ 'ti-list-details', 'Describe', 'Queue the fields', 'Across ACF’s four tabs, no JSON by hand' ],
	[ 'ti-sparkles', 'Prompt', 'Ask your AI', 'A ready prompt with your real field names' ],
	[ 'ti-code', 'Paste', 'Paste JSON back', 'AI replies or native ACF exports' ],
	[ 'ti-git-compare', 'Preview', 'See the diff first', 'Only what you named changes' ],
	[ 'ti-shield-check', 'Apply', 'Apply, then undo', 'A snapshot before every write' ],
	[ 'ti-terminal-2', 'Automate', 'REST and WP-CLI', 'The same preview and rollback' ],
];

document.getElementById( 'grid' ).innerHTML = FIELDPILOT_FEATURES.map(
	( [ icon, tag, title, text ] ) =>
		`<div class="card"><div class="chip"><i class="ti ${ icon }"></i></div><div class="tag">${ tag.toUpperCase() }</div><h3>${ title }</h3><p>${ text }</p></div>`
).join( '' );
