# 13 - Extensibility

Every hook is prefixed `acfjp/`. Anything not listed here is internal and may change
without a major version bump.

---

## Actions

### `acfjp/booted`

Fires once the plugin is ready. The place to register add-ons.

```php
add_action( 'acfjp/booted', function ( ACFJP\Core\Container $container ) {
    $engine = $container->get( ACFJP\Apply\Engine::class );
} );
```

### `acfjp/applied`

Fires after a batch has been applied **and verified**. Not fired on failure, so it is
safe to treat as "this definitely happened."

```php
add_action( 'acfjp/applied', function ( ACFJP\Apply\ApplyResult $result ) {
    error_log( sprintf(
        '%d change(s) applied to %s',
        $result->changeSet->count(),
        $result->changeSet->groupTitle
    ) );
} );
```

### `acfjp/restored`

Fires after a field group has been restored from a snapshot, by rollback or by
automatic recovery from a failed write.

```php
add_action( 'acfjp/restored', function ( string $groupKey, array $snapshot ) {}, 10, 2 );
```

### `acfjp/journal/recorded`

Fires after a batch is written to the journal, including failures.

```php
add_action( 'acfjp/journal/recorded', function ( int $id, ACFJP\Diff\ChangeSet $set, string $status ) {}, 10, 3 );
```

### `acfjp/audit`

Fires on a security-relevant event that made **no** changes - a denied capability, a
refused payload. Deliberately an action rather than a table: sites with an audit
plugin get integration for free, and sites without one do not pay for storage they
will never read.

```php
add_action( 'acfjp/audit', function ( string $event, array $context, int $userId ) {
    my_audit_log( $event, $context, $userId );
}, 10, 3 );
```

---

## Filters

### `acfjp/capability`

The capability required to change ACF configuration. Default `manage_options`.

```php
add_filter( 'acfjp/capability', fn () => 'edit_acf_config' );
```

Applies uniformly to the admin screens, REST and WP-CLI - there is no second path
that skips it.

### `acfjp/field_type/schema`

Supply or override the JSON Schema used to validate one field type. Use this to make
a third-party field type validate as strictly as ACF's own.

```php
add_filter( 'acfjp/field_type/schema', function ( array $schema, string $type ): array {
    if ( 'my_custom_field' !== $type ) {
        return $schema;
    }

    return array(
        'type'       => 'object',
        'properties' => array(
            'label'    => array( 'type' => 'string', 'minLength' => 1 ),
            'type'     => array( 'type' => 'string', 'enum' => array( 'my_custom_field' ) ),
            'my_limit' => array( 'type' => 'integer', 'minimum' => 1 ),
        ),
        'required'   => array( 'label', 'type' ),
    );
}, 10, 2 );
```

A field type with no schema is accepted structurally and validated loosely, with a
warning in the preview. Refusing unknown types outright would break every site using
a third-party field plugin, which is not an acceptable failure mode for a tool whose
promise is safety.

### `acfjp/data_probe/enabled`

Whether to scan for stored content before a delete. Default true, also settable in
the admin. When disabled, previews report content status as *unknown* rather than as
*none* - the distinction matters and the UI states it.

```php
add_filter( 'acfjp/data_probe/enabled', '__return_false' );
```

### `acfjp/max_depth`

Maximum nesting depth the reader and normalizer will descend. Default 32.

This bound exists for safety, not taste: unbounded recursion over externally supplied
JSON is a stack-exhaustion DoS. Real ACF trees are 3-5 deep.

### `acfjp/max_payload_bytes`

Maximum accepted payload size. Default 8 MB. A 500-field group exports well under 1 MB.

### `acfjp/plan_ttl`

How long a preview stays applicable, in seconds. Default 900.

A plan is also invalidated by its optimistic-concurrency check: if ACF changed after
the preview was generated, `apply` returns `409 STATE_CHANGED` regardless of the TTL.

### `acfjp/retention/keep_per_group` · `acfjp/retention/keep_days`

Journal retention. Defaults 50 entries per field group and 90 days, whichever keeps
more. Snapshots are reference-counted and collected once nothing points at them.

---

## Using the engine directly

The whole plugin is one pipeline, and it is public.

```php
$engine = ACFJP\Core\Plugin::instance()->engine();

// Validate without reading ACF.
$report = $engine->validate( $json );

// Preview. Reads ACF, writes nothing.
$plan = $engine->plan( $json );

foreach ( $plan->changeSet->changes as $change ) {
    printf( "%s %s - %s\n", $change->type, $change->label, $change->summary() );
}

// Apply a previewed plan.
$result = $engine->apply( $plan->id, $resolutions, $confirmed );

// Or do both in one call.
$result = $engine->run( $json, null, array(), true, 'my-plugin' );

// Undo.
$engine->rollback( $result->journalId );
```

`plan()` and `apply()` are the only entry points. If a feature cannot be expressed as
one then the other, it does not belong in this plugin - that constraint is what keeps
the preview honest and the journal complete.

---

## WP-CLI

```bash
wp acfjp validate patch.json
wp acfjp plan patch.json
wp acfjp plan patch.json --format=json
wp acfjp apply patch.json
wp acfjp apply patch.json --confirm
wp acfjp apply patch.json --resolve=a1b2c3:keep_existing
wp acfjp export "Property" --file=property.json
wp acfjp groups
wp acfjp history --group=group_xxx
wp acfjp rollback 42
wp acfjp prompt --group=group_xxx --intent="add a WhatsApp field"
cat patch.json | wp acfjp apply
```

`apply` refuses a destructive batch without `--confirm`, deliberately: an unattended
script is exactly where an accidental delete does the most damage.

---

## REST

Namespace `fieldpilot-for-acf/v1`. Capability-guarded; cookie-authenticated requests
need the standard `X-WP-Nonce` header.

| Method | Route | Purpose |
|---|---|---|
| POST | `/validate` | Parse and validate. No ACF reads. |
| POST | `/plan` | Compute a ChangeSet. Writes nothing. |
| POST | `/apply` | Apply a plan by id. |
| GET | `/field-groups` | List groups with mutability. |
| GET | `/field-groups/{key}` | One group's export. |
| GET | `/history` | Journal entries. |
| POST | `/history/{id}/rollback` | Restore a prior state. |
| GET | `/schema` | The v1 JSON Schema. |
| GET | `/prompt` | A generated AI prompt. |

Success responses are `{ "ok": true, ... }`. Failures are:

```json
{
  "ok": false,
  "error": {
    "code": "FIELD_NOT_FOUND",
    "message": "Field \"contact_details\" was not found in Company Details.",
    "pointer": "/changes/contact_details",
    "suggestions": [ "contact", "company_info", "social_media" ]
  }
}
```

`code` is a contract and will not change; `message` is translated and may. Branch on
the code. The full catalogue is in `includes/Exceptions/ErrorCodes.php`.

The `suggestions` array is not decoration - it is what lets an AI handed the error
correct itself on the next attempt instead of looping.
