<?php
/**
 * The visualizer block (form + preview). Mirrors client/src/blocks/visualizer/View.tsx and options.ts.
 * Behaviour (upload preview, validation, generation, quota, lead capture) comes from the inline site script.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The choices, in display order. Mirrors VIZ_GROUPS and VIZ_GROUPS_AFTER_STYLE. */
function rk_builder_viz_option_sets() {
	return array(
		'roomType'         => array( 'legend' => 'Room type', 'options' => array(
			'kitchen' => 'Kitchen', 'living_room' => 'Living room', 'hallway' => 'Hallway', 'bedroom' => 'Bedroom', 'office' => 'Office', 'retail_gym' => 'Retail / gym',
		) ),
		'projectType'      => array( 'legend' => 'Project type', 'options' => array(
			'new_installation' => 'New installation', 'refinish_existing_floor' => 'Refinish existing floor', 'sandless_refresh' => 'Sandless refresh',
			'commercial_sports' => 'Commercial / sports', 'deck' => 'Deck', 'cabinets' => 'Cabinets',
		) ),
		'preferredStyle'   => array( 'legend' => 'Preferred style', 'options' => array(
			'light_natural' => 'Light / natural', 'warm_traditional' => 'Warm / traditional', 'gray_weathered' => 'Gray / weathered', 'dark_modern' => 'Dark / modern', 'custom' => 'Custom',
		) ),
		'woodSpecies'      => array( 'legend' => 'Wood species', 'options' => array(
			'oak' => 'Oak', 'maple' => 'Maple', 'hickory' => 'Hickory', 'mixed_unsure' => 'Mixed / Unsure', 'existing_floor' => 'Existing Floor',
		) ),
		'floorDirection'   => array( 'legend' => 'Floor direction', 'options' => array(
			'parallel' => 'Parallel', 'perpendicular' => 'Perpendicular', 'diagonal' => 'Diagonal', 'herringbone' => 'Herringbone', 'existing_direction' => 'Existing Direction', 'unsure' => 'Unsure',
		) ),
		'finishPreference' => array( 'legend' => 'Finish preference', 'options' => array(
			'bona_traffic_hd' => 'Bona Traffic HD', 'rubio_monocoat' => 'Rubio Monocoat', 'polyurethane' => 'Polyurethane', 'unsure' => 'Unsure',
		) ),
		'sheen'            => array( 'legend' => 'Sheen', 'options' => array(
			'matte' => 'Matte', 'satin' => 'Satin', 'semi_gloss' => 'Semi-gloss', 'unsure' => 'Unsure',
		) ),
	);
}

/** "Peoria Heights" => "peoria_heights". Mirrors vizSlug(). */
function rk_builder_viz_slug( $label ) {
	$s = strtolower( trim( (string) $label ) );
	$s = preg_replace( '/[^a-z0-9]+/', '_', $s );
	return trim( (string) $s, '_' );
}

/** The service cities a block offers: slug => label. */
function rk_builder_viz_cities( $source ) {
	$out = array();
	foreach ( rk_builder_lines( $source, 20 ) as $label ) { $out[ rk_builder_viz_slug( $label ) ] = $label; }
	return $out;
}

function rk_builder_viz_group_html( $name, $legend, array $options ) {
	$html = '<fieldset class="pf-viz-group"><legend>' . rk_builder_h( $legend ) . '</legend><div>';
	foreach ( $options as $value => $label ) {
		$html .= '<label class="pf-chip"><input type="radio" name="' . rk_builder_h( $name ) . '" value="' . rk_builder_h( $value ) . '"/><span>' . rk_builder_h( $label ) . '</span></label>';
	}
	return $html . '</div></fieldset>';
}

function rk_builder_render_visualizer( array $p, array $context = array() ) {
	$sets   = rk_builder_viz_option_sets();
	$cities = rk_builder_viz_cities( $p['cities'] );
	$href   = rk_builder_safe_href( $p['ctaHref'] );
	$html   = '<section ' . rk_builder_root_attrs( 'visualizer', 'pf-section pf-viz' ) . '><div class="pf-wrap pf-viz-grid">';
	$html  .= '<form class="pf-viz-form" noValidate="" data-viz-form="">';
	$html  .= '<label class="pf-viz-drop"><span class="pf-viz-drop-title">Upload a room photo</span><span class="pf-viz-hint" aria-live="polite" data-viz-hint="">Your photo stays in this browser preview until you generate a visualization.</span><input type="file" accept="image/jpeg,image/png,image/webp" name="image"/></label>';
	$html  .= '<p class="pf-viz-error" role="alert" hidden="" data-viz-error=""></p>';
	foreach ( array( 'roomType', 'projectType', 'preferredStyle' ) as $n ) { $html .= rk_builder_viz_group_html( $n, $sets[ $n ]['legend'], $sets[ $n ]['options'] ); }
	$html  .= '<label class="pf-viz-field" hidden="" data-viz-custom="">Describe the style you want<textarea name="customStyleDescription" maxLength="500" placeholder="Warm medium-brown oak with a natural, matte finish"></textarea></label>';
	foreach ( array( 'woodSpecies', 'floorDirection', 'finishPreference', 'sheen' ) as $n ) { $html .= rk_builder_viz_group_html( $n, $sets[ $n ]['legend'], $sets[ $n ]['options'] ); }
	if ( $cities ) { $html .= rk_builder_viz_group_html( 'serviceCity', 'Service city (optional)', $cities ); }
	$html  .= '<label class="pf-viz-field">Approximate square footage (optional)<input type="number" min="0" inputMode="numeric" name="squareFootage"/></label>';
	$html  .= '<p class="pf-viz-quota" aria-live="polite" data-viz-quota=""></p>';
	$html  .= '<button type="submit" class="pf-btn dark" data-viz-submit="">' . rk_builder_h( $p['submitLabel'] ) . rk_builder_arrow_right_icon() . '</button></form>';
	$html  .= '<aside class="pf-viz-aside"><div class="pf-viz-stage"><img class="pf-viz-img" alt="" hidden="" data-viz-img=""/><p class="pf-viz-empty" data-viz-empty="">Upload a room photo to preview your space here.</p><span class="pf-viz-badge" data-viz-badge="">Approximate color preview</span><div class="pf-viz-busy" hidden="" data-viz-busy=""><span>Creating your floor visualization…</span></div></div>';
	$html  .= '<div class="pf-viz-done" hidden="" data-viz-done=""><p>Your visualization concept is ready. This is an AI-assisted concept to help you explore ideas — not an exact rendering, a guaranteed color match, or a construction-ready plan.</p><div class="pf-viz-orig"><img alt="Your original uploaded room photo, unchanged" data-viz-orig=""/><span>Original photo</span></div></div>';
	if ( '' !== $p['ctaLabel'] && '' !== $href ) {
		$html .= '<div class="pf-viz-talk"><p>Want a real sample in your lighting?</p><a class="pf-more" href="' . $href . '">' . rk_builder_h( $p['ctaLabel'] ) . rk_builder_arrow_icon() . '</a></div>';
	}
	$html  .= '</aside>';
	$html  .= '<dialog class="pf-modal pf-viz-lead" aria-label="Unlock one more visualization" data-viz-lead=""><button type="button" class="pf-modal-x" aria-label="Close" data-modal-close="">×</button>';
	$html  .= '<form class="pf-viz-leadform" noValidate="" data-viz-leadform=""><h2>Unlock one more visualization</h2><p>Share your contact details and we will unlock another free visualization.</p>';
	$html  .= '<label class="pf-viz-field">Name<input type="text" maxLength="120" autoComplete="name" name="name"/></label>';
	$html  .= '<label class="pf-viz-field">Email<input type="email" autoComplete="email" name="email"/></label>';
	$html  .= '<label class="pf-viz-field">Phone (optional)<input type="tel" maxLength="30" autoComplete="tel" name="phone"/></label>';
	$html  .= '<p class="pf-viz-error" role="alert" hidden="" data-viz-lead-error=""></p>';
	$html  .= '<button type="submit" class="pf-btn dark">Unlock my visualization' . rk_builder_arrow_right_icon() . '</button></form></dialog>';
	return $html . '</div></section>';
}
