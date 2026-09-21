<?php
/**
 * A tree built from ACF's RAW stored data, with all ACF filters disabled.
 *
 * This is the ONLY tree the diff engine and the writer are allowed to see. Two
 * independent reasons, both verified against ACF 6.8.10 source:
 *
 *  1. `acf/load_field` SYNTHESISES `sub_fields` on Group/Repeater and
 *     `parent_repeater` on repeater children. None of it is stored. Writing a
 *     loaded array back serialises that synthesis into the parent permanently.
 *
 *  2. A seamless Clone field SPLICES ITSELF OUT of `acf_get_fields()` and injects
 *     fields belonging to another field group in its place. A diff built on that
 *     read would propose deleting another group's fields.
 *
 * See docs/ARCHITECTURE-REVIEW.md B.3 and B.8.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class RawTree extends Tree {}
