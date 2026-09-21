<?php
/**
 * A tree built from ACF's FILTERED data - what ACF actually renders to an editor.
 *
 * Correct, and only correct, for showing a human what their site looks like:
 * the Dashboard, the target picker, the "current state" column of a preview.
 *
 * NEVER pass one of these to the Comparator or the Writer. It contains fields ACF
 * synthesised at read time and, where Clone is involved, fields belonging to other
 * field groups. The type split is what stops that happening by accident.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class ResolvedTree extends Tree {}
