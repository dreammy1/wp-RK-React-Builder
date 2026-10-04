<?php
/**
 * AI copy rewriting over MCP: make a kit's text about the buyer's own business.
 *
 *   rkb_ai_brief       the business profile (site name, business details, and the owner's "AI brief")
 *   rkb_copy_extract   every piece of editable wording, per page / template / reusable block, with its limits
 *   rkb_copy_apply     write rewritten wording back as DRAFTS (never live), checked, with a dry run
 *   prompt rewrite-site-copy   the ready-made instructions an assistant follows
 *
 * Safety: only props that are plain text in the block specs can be read or written; links, images, colours and
 * identifiers are never touched. List-style texts (one item per line, fields separated by "|") must keep the same
 * number of lines, and any field that is an address, path, phone/email link or colour must stay as it was. Each
 * changed document is validated as a whole and saved through the normal draft route (permissions, revision check,
 * history). Nothing is published.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_COPY_MAX_EDITS' ) ) { define( 'RK_BUILDER_COPY_MAX_EDITS', 300 ); }

/* ------------------------------------------------------------------ *
 * The owner's brief
 * ------------------------------------------------------------------ */

function rk_builder_ai_brief_fields() {
	return array( 'about' => 600, 'audience' => 300, 'tone' => 120, 'offers' => 600, 'notes' => 600 );
}

/** @return array<string,string> */
function rk_builder_ai_brief() {
	$o   = get_option( 'rk_builder_ai_brief', array() );
	$o   = is_array( $o ) ? $o : array();
	$out = array();
	foreach ( rk_builder_ai_brief_fields() as $k => $max ) { $out[ $k ] = isset( $o[ $k ] ) && is_string( $o[ $k ] ) ? rk_builder_substr( $o[ $k ], 0, $max ) : ''; }
	return $out;
}

function rk_builder_ai_brief_save( $in ) {
	$cur = rk_builder_ai_brief();
	foreach ( rk_builder_ai_brief_fields() as $k => $max ) {
		if ( is_array( $in ) && array_key_exists( $k, $in ) && is_string( $in[ $k ] ) ) {
			$cur[ $k ] = rk_builder_substr( trim( (string) preg_replace( "/[ \t]+/", ' ', wp_strip_all_tags( $in[ $k ] ) ) ), 0, $max );
		}
	}
	update_option( 'rk_builder_ai_brief', $cur, false );
	return $cur;
}

/** Everything an assistant needs to know about the business. */
function rk_builder_ai_brief_tool( array $args = array() ) {
	return array(
		'site'         => array( 'name' => (string) get_option( 'blogname', '' ), 'tagline' => (string) get_option( 'blogdescription', '' ), 'url' => home_url( '/' ) ),
		'organization' => rk_builder_seo_organization(),
		'brief'        => rk_builder_ai_brief(),
		'note'         => 'Use only these facts about the business. Anything that is not here (prices, years in business, awards, licences, statistics, reviews, staff names) must not be invented: keep the original wording for it or ask the owner.',
	);
}

/* ------------------------------------------------------------------ *
 * Reading the wording
 * ------------------------------------------------------------------ */

/** A value that must stay as written: a web address, a path, an anchor, a phone / email link or a colour. */
function rk_builder_copy_is_locked_field( $v ) {
	$v = trim( (string) $v );
	return 1 === preg_match( '#^(https?://|/|\#|tel:|mailto:|data:)#i', $v ) || 1 === preg_match( '/^#[0-9a-fA-F]{3,8}\z/', $v );
}

/** Texts made of lines and "|" fields (cards, photos, links…): their shape is part of the design. */
function rk_builder_copy_is_structured( $v ) {
	return false !== strpos( (string) $v, "\n" ) || false !== strpos( (string) $v, '|' );
}

/** @return array<string,array{max:int,min:int}> the plain-text props of a block type */
function rk_builder_copy_text_props( $type ) {
	$specs = rk_builder_block_specs();
	$out   = array();
	foreach ( isset( $specs[ $type ] ) ? $specs[ $type ] : array() as $name => $spec ) {
		if ( isset( $spec['t'] ) && 'text' === $spec['t'] ) { $out[ $name ] = array( 'max' => (int) $spec['max'], 'min' => (int) $spec['min'] ); }
	}
	return $out;
}

/** @return array list of { blockId, type, fields:[{prop, text, max, structured?}] } for the blocks that carry wording */
function rk_builder_copy_blocks( array $blocks ) {
	$out = array();
	foreach ( $blocks as $b ) {
		if ( ! is_array( $b ) || ! isset( $b['id'], $b['type'], $b['props'] ) || ! is_array( $b['props'] ) ) { continue; }
		$fields = array();
		foreach ( rk_builder_copy_text_props( $b['type'] ) as $prop => $lim ) {
			if ( ! isset( $b['props'][ $prop ] ) || ! is_string( $b['props'][ $prop ] ) || '' === trim( $b['props'][ $prop ] ) ) { continue; }
			$row = array( 'prop' => $prop, 'text' => $b['props'][ $prop ], 'max' => $lim['max'] );
			if ( rk_builder_copy_is_structured( $b['props'][ $prop ] ) ) { $row['structured'] = true; }
			$fields[] = $row;
		}
		if ( $fields ) { $out[] = array( 'blockId' => (string) $b['id'], 'type' => (string) $b['type'], 'fields' => $fields ); }
	}
	return $out;
}

/** All documents that carry wording, in a stable order: pages, then templates, then reusable blocks. */
function rk_builder_copy_documents( $scope = 'all' ) {
	$docs = array();
	if ( 'all' === $scope || 'pages' === $scope ) {
		$ids = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => RK_BUILDER_MAX_TRANSFER_PAGES, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'meta_key' => '_rk_layout_draft', 'meta_compare' => 'EXISTS' ) );
		foreach ( $ids as $id ) { $docs[] = array( 'kind' => 'page', 'id' => (int) $id ); }
	}
	if ( 'all' === $scope || 'templates' === $scope ) {
		foreach ( rk_builder_tpl_all() as $t ) { $docs[] = array( 'kind' => 'template', 'id' => (int) $t->ID ); }
	}
	if ( 'all' === $scope || 'reusables' === $scope ) {
		foreach ( rk_builder_reusable_list() as $r ) { $docs[] = array( 'kind' => 'reusable', 'id' => (int) $r['id'] ); }
	}
	return $docs;
}

/** One document as the assistant sees it, or null. */
function rk_builder_copy_document( $kind, $id, $with_seo ) {
	if ( 'reusable' === $kind ) {
		foreach ( rk_builder_reusable_list() as $r ) {
			if ( (int) $r['id'] !== (int) $id ) { continue; }
			$blocks = rk_builder_copy_blocks( array( array( 'id' => 'block', 'type' => $r['block']['type'], 'props' => $r['block']['props'] ) ) );
			return array( 'kind' => 'reusable', 'id' => (int) $r['id'], 'title' => (string) $r['name'], 'blocks' => $blocks );
		}
		return null;
	}
	$post = get_post( (int) $id );
	if ( ! $post || ( 'page' === $kind && 'page' !== $post->post_type ) ) { return null; }
	$layout = rk_builder_get_draft_layout( (int) $id );
	$doc    = array(
		'kind' => $kind, 'id' => (int) $id, 'title' => rk_builder_plain( $post->post_title ), 'status' => (string) $post->post_status,
		'revision' => rk_builder_get_revision( (int) $id ), 'blocks' => rk_builder_copy_blocks( $layout['blocks'] ),
	);
	if ( 'page' === $kind ) {
		$doc['slug'] = (string) $post->post_name;
		if ( $with_seo ) { $s = rk_builder_seo_read( (int) $id ); $doc['seo'] = array( 'title' => isset( $s['title'] ) ? $s['title'] : '', 'description' => isset( $s['description'] ) ? $s['description'] : '' ); }
	}
	return $doc;
}

/** Tool: rkb_copy_extract. */
function rk_builder_copy_extract( array $a ) {
	$scope = isset( $a['scope'] ) && in_array( $a['scope'], array( 'all', 'pages', 'templates', 'reusables' ), true ) ? $a['scope'] : 'all';
	$seo   = ! empty( $a['includeSeo'] );
	$out   = array();
	if ( isset( $a['id'] ) && is_numeric( $a['id'] ) ) {
		$kind = isset( $a['kind'] ) && in_array( $a['kind'], array( 'page', 'template', 'reusable' ), true ) ? $a['kind'] : 'page';
		$d    = rk_builder_copy_document( $kind, (int) $a['id'], $seo );
		return null === $d ? array( 'code' => 'rk_not_found', 'message' => 'No such document.' ) : array( 'documents' => array( $d ), 'total' => 1, 'next' => null, 'help' => rk_builder_copy_help() );
	}
	$list   = rk_builder_copy_documents( $scope );
	$offset = isset( $a['offset'] ) && is_numeric( $a['offset'] ) ? max( 0, (int) $a['offset'] ) : 0;
	$limit  = isset( $a['limit'] ) && is_numeric( $a['limit'] ) ? max( 1, min( 25, (int) $a['limit'] ) ) : 8;
	foreach ( array_slice( $list, $offset, $limit ) as $ref ) {
		$d = rk_builder_copy_document( $ref['kind'], $ref['id'], $seo );
		if ( null !== $d ) { $out[] = $d; }
	}
	$next = $offset + $limit < count( $list ) ? $offset + $limit : null;
	return array( 'documents' => $out, 'total' => count( $list ), 'next' => $next, 'help' => rk_builder_copy_help() );
}

function rk_builder_copy_help() {
	return 'Rewrite "text" values and send them back with rkb_copy_apply as {kind, id, blockId, prop, text}. Never exceed "max". For "structured" texts keep the same number of lines and leave every address, path, tel:/mailto: link and colour exactly as it is; only the wording around them changes. Pass offset=next to read the next documents.';
}

/* ------------------------------------------------------------------ *
 * Writing the wording
 * ------------------------------------------------------------------ */

/** Plain text: no tags; one line unless the original is a list. */
function rk_builder_copy_clean_text( $t, $structured ) {
	$t = wp_strip_all_tags( (string) $t );
	$t = str_replace( "\r", '', $t );
	if ( $structured ) {
		$lines = array_map( function ( $l ) { return trim( (string) preg_replace( "/[ \t]+/", ' ', $l ) ); }, explode( "\n", $t ) );
		return implode( "\n", $lines );
	}
	return trim( (string) preg_replace( '/\s+/u', ' ', $t ) );
}

/** Why $new may not replace $old in a list-style text, or ''. */
function rk_builder_copy_structure_problem( $old, $new ) {
	$ol = explode( "\n", (string) $old );
	$nl = explode( "\n", (string) $new );
	if ( count( $ol ) !== count( $nl ) ) { return 'This is a list with ' . count( $ol ) . ' line(s); keep the same number of lines.'; }
	foreach ( $ol as $i => $line ) {
		$of = explode( '|', $line );
		$nf = explode( '|', $nl[ $i ] );
		if ( count( $of ) !== count( $nf ) ) { return 'Line ' . ( $i + 1 ) . ' must keep its ' . count( $of ) . ' field(s) separated by |.'; }
		foreach ( $of as $j => $f ) {
			if ( rk_builder_copy_is_locked_field( $f ) && trim( $f ) !== trim( $nf[ $j ] ) ) { return 'Line ' . ( $i + 1 ) . ', field ' . ( $j + 1 ) . ' is an address or link and must stay as it was.'; }
		}
	}
	return '';
}

/**
 * Apply edits (all for one document's blocks) to a layout.
 *
 * @param array $blocks the document's blocks
 * @param array $edits  list of { blockId, prop, text }
 * @return array{blocks:array,applied:int,skipped:array}
 */
function rk_builder_copy_patch_blocks( array $blocks, array $edits ) {
	$applied = 0;
	$skipped = array();
	foreach ( $edits as $e ) {
		$bid  = isset( $e['blockId'] ) && is_string( $e['blockId'] ) ? $e['blockId'] : '';
		$prop = isset( $e['prop'] ) && is_string( $e['prop'] ) ? $e['prop'] : '';
		$why  = function ( $m ) use ( $bid, $prop, &$skipped ) { $skipped[] = array( 'blockId' => $bid, 'prop' => $prop, 'reason' => $m ); };
		if ( ! isset( $e['text'] ) || ! is_string( $e['text'] ) ) { $why( 'text must be a string' ); continue; }
		$idx = null;
		foreach ( $blocks as $i => $b ) { if ( is_array( $b ) && isset( $b['id'] ) && (string) $b['id'] === $bid ) { $idx = $i; break; } }
		if ( null === $idx ) { $why( 'No block with that id in this document.' ); continue; }
		$props = rk_builder_copy_text_props( $blocks[ $idx ]['type'] );
		if ( ! isset( $props[ $prop ] ) ) { $why( 'This prop is not editable wording (links, images, colours and settings cannot be changed here).' ); continue; }
		$old = isset( $blocks[ $idx ]['props'][ $prop ] ) && is_string( $blocks[ $idx ]['props'][ $prop ] ) ? $blocks[ $idx ]['props'][ $prop ] : '';
		$structured = rk_builder_copy_is_structured( $old );
		$new = rk_builder_copy_clean_text( $e['text'], $structured );
		if ( rk_builder_strlen( $new ) > $props[ $prop ]['max'] ) { $why( 'Too long: at most ' . $props[ $prop ]['max'] . ' characters.' ); continue; }
		if ( rk_builder_strlen( $new ) < $props[ $prop ]['min'] ) { $why( 'Too short: at least ' . $props[ $prop ]['min'] . ' character(s).' ); continue; }
		if ( $structured ) {
			$p = rk_builder_copy_structure_problem( $old, $new );
			if ( '' !== $p ) { $why( $p ); continue; }
		}
		if ( $new === $old ) { continue; }
		$blocks[ $idx ]['props'][ $prop ] = $new;
		$applied++;
	}
	return array( 'blocks' => $blocks, 'applied' => $applied, 'skipped' => $skipped );
}

/** Tool: rkb_copy_apply. */
function rk_builder_copy_apply( array $a ) {
	$edits = isset( $a['edits'] ) && is_array( $a['edits'] ) ? $a['edits'] : array();
	$seo   = isset( $a['seo'] ) && is_array( $a['seo'] ) ? $a['seo'] : array();
	$dry   = ! empty( $a['dryRun'] );
	if ( count( $edits ) + count( $seo ) > RK_BUILDER_COPY_MAX_EDITS ) { return array( 'code' => 'rk_mcp_bad_arguments', 'message' => 'Send at most ' . RK_BUILDER_COPY_MAX_EDITS . ' edits per call; split them by document.' ); }
	if ( ! $edits && ! $seo ) { return array( 'code' => 'rk_mcp_bad_arguments', 'message' => 'Nothing to apply: send edits [{kind,id,blockId,prop,text}] and/or seo [{id,title,description}].' ); }

	$groups  = array();
	$skipped = array();
	foreach ( $edits as $e ) {
		$kind = is_array( $e ) && isset( $e['kind'] ) && in_array( $e['kind'], array( 'page', 'template', 'reusable' ), true ) ? $e['kind'] : '';
		$id   = is_array( $e ) && isset( $e['id'] ) && is_numeric( $e['id'] ) ? (int) $e['id'] : 0;
		if ( '' === $kind || $id < 1 ) { $skipped[] = array( 'reason' => 'Each edit needs kind (page, template or reusable) and id.' ); continue; }
		$groups[ $kind . ':' . $id ][] = $e;
	}
	$docs    = array();
	$applied = 0;
	foreach ( $groups as $key => $list ) {
		list( $kind, $id ) = explode( ':', $key );
		$id    = (int) $id;
		$entry = array( 'kind' => $kind, 'id' => $id, 'applied' => 0, 'saved' => false );
		if ( 'reusable' === $kind ) {
			$res = null;
			foreach ( rk_builder_reusable_list() as $r ) { if ( (int) $r['id'] === $id ) { $res = $r; } }
			if ( null === $res ) { $entry['error'] = 'No such reusable block.'; $docs[] = $entry; continue; }
			$patch = rk_builder_copy_patch_blocks( array( array( 'id' => 'block', 'type' => $res['block']['type'], 'props' => $res['block']['props'] ) ), $list );
			foreach ( $patch['skipped'] as $s ) { $skipped[] = array_merge( array( 'kind' => $kind, 'id' => $id ), $s ); }
			$entry['applied'] = $patch['applied'];
			if ( $patch['applied'] > 0 && ! $dry ) {
				$req = new WP_REST_Request( 'POST', '/' . RK_BUILDER_NS . '/builder/reusables/' . $id );
				$req->set_header( 'Content-Type', 'application/json' );
				$req->set_body( wp_json_encode( array( 'block' => array( 'type' => $res['block']['type'], 'props' => $patch['blocks'][0]['props'] ) ) ) );
				$resp = rest_do_request( $req );
				if ( $resp->is_error() ) { $d = $resp->get_data(); $entry['error'] = isset( $d['message'] ) ? (string) $d['message'] : 'Could not save.'; $entry['applied'] = 0; }
				else { $entry['saved'] = true; }
			}
			$applied += $entry['applied'];
			$docs[] = $entry;
			continue;
		}
		$post = get_post( $id );
		if ( ! $post || ( 'page' === $kind && 'page' !== $post->post_type ) ) { $entry['error'] = 'No such ' . $kind . '.'; $docs[] = $entry; continue; }
		if ( ! current_user_can( 'edit_post', $id ) ) { $entry['error'] = 'This account may not edit it.'; $docs[] = $entry; continue; }
		$layout = rk_builder_get_draft_layout( $id );
		$patch  = rk_builder_copy_patch_blocks( $layout['blocks'], $list );
		foreach ( $patch['skipped'] as $s ) { $skipped[] = array_merge( array( 'kind' => $kind, 'id' => $id ), $s ); }
		$entry['applied'] = $patch['applied'];
		if ( $patch['applied'] > 0 ) {
			$layout['blocks'] = $patch['blocks'];
			$problems = rk_builder_validate_layout( $layout, rk_builder_allowed_image_hosts() );
			if ( $problems ) {
				$entry['error']   = 'The changed page would not be valid: ' . $problems[0]['path'] . ' ' . $problems[0]['message'];
				$entry['applied'] = 0;
			} elseif ( ! $dry ) {
				$req = new WP_REST_Request( 'POST', '/' . RK_BUILDER_NS . '/builder/layout/' . $id );
				$req->set_header( 'Content-Type', 'application/json' );
				$req->set_body( wp_json_encode( array( 'status' => 'draft', 'layout' => $layout, 'expectedRevision' => rk_builder_get_revision( $id ) ) ) );
				$resp = rest_do_request( $req );
				if ( $resp->is_error() ) { $d = $resp->get_data(); $entry['error'] = isset( $d['message'] ) ? (string) $d['message'] : 'Could not save.'; $entry['applied'] = 0; }
				else { $entry['saved'] = true; $r = $resp->get_data(); $entry['revision'] = isset( $r['revision'] ) ? (int) $r['revision'] : null; }
			}
		}
		$applied += $entry['applied'];
		$docs[] = $entry;
	}

	$seo_done = array();
	foreach ( $seo as $s ) {
		$id = is_array( $s ) && isset( $s['id'] ) && is_numeric( $s['id'] ) ? (int) $s['id'] : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'page' !== $post->post_type ) { $skipped[] = array( 'kind' => 'seo', 'id' => $id, 'reason' => 'seo edits are for pages: send the page id.' ); continue; }
		if ( ! current_user_can( 'edit_post', $id ) ) { $skipped[] = array( 'kind' => 'seo', 'id' => $id, 'reason' => 'This account may not edit it.' ); continue; }
		$cur = rk_builder_seo_read( $id );
		$in  = array();
		foreach ( array( 'title' => 200, 'description' => 400 ) as $f => $max ) {
			if ( isset( $s[ $f ] ) && is_string( $s[ $f ] ) ) {
				$v = rk_builder_copy_clean_text( $s[ $f ], false );
				if ( rk_builder_strlen( $v ) > $max ) { $skipped[] = array( 'kind' => 'seo', 'id' => $id, 'prop' => $f, 'reason' => 'Too long: at most ' . $max . ' characters.' ); continue; }
				$in[ $f ] = $v;
			}
		}
		if ( ! $in ) { continue; }
		if ( ! $dry ) { rk_builder_seo_write( $id, array_merge( $cur, rk_builder_seo_clean( $in ) ) ); }
		$seo_done[] = array( 'id' => $id, 'fields' => array_keys( $in ), 'saved' => ! $dry );
		$applied += count( $in );
	}

	return array(
		'dryRun'    => $dry,
		'applied'   => $applied,
		'documents' => $docs,
		'seo'       => $seo_done,
		'skipped'   => $skipped,
		'note'      => $dry ? 'Dry run: nothing was saved. Send the same edits without dryRun to save them as drafts.' : 'Saved as drafts only; the live site is unchanged. The owner reviews them in the editor and publishes (rkb_publish) when happy.',
	);
}

/* ------------------------------------------------------------------ *
 * The prompt
 * ------------------------------------------------------------------ */

function rk_builder_ai_rewrite_prompt( array $a = array() ) {
	$brief = rk_builder_ai_brief();
	$name  = isset( $a['business'] ) && is_string( $a['business'] ) && '' !== trim( $a['business'] ) ? trim( $a['business'] ) : (string) get_option( 'blogname', '' );
	$tone  = isset( $a['tone'] ) && is_string( $a['tone'] ) && '' !== trim( $a['tone'] ) ? trim( $a['tone'] ) : $brief['tone'];
	$lang  = isset( $a['language'] ) && is_string( $a['language'] ) && '' !== trim( $a['language'] ) ? trim( $a['language'] ) : '';
	$lines = array(
		'Rewrite the text of this website so it is about ' . ( '' !== $name ? '"' . $name . '"' : 'the owner\'s own business' ) . ' instead of the demo business it was built from. The design, layout, links and pictures stay exactly as they are.',
		'',
		'Facts about the business (from the owner): call rkb_ai_brief first and use only what it says.',
	);
	foreach ( array( 'about' => 'About', 'audience' => 'Customers', 'offers' => 'Services / products', 'notes' => 'Also' ) as $k => $label ) {
		if ( '' !== $brief[ $k ] ) { $lines[] = '- ' . $label . ': ' . $brief[ $k ]; }
	}
	if ( '' !== $tone ) { $lines[] = '- Tone: ' . $tone; }
	if ( '' !== $lang ) { $lines[] = '- Write in: ' . $lang; }
	$lines = array_merge( $lines, array(
		'',
		'How to work:',
		'1. rkb_ai_brief and rkb_overview, to learn the business and the site.',
		'2. rkb_copy_extract (includeSeo true), a few documents at a time (use offset / the "next" value) until every page, template (header, footer) and reusable block has been read.',
		'3. Rewrite each text for this business. Keep its job (a headline stays a headline), keep the length close to the original and never above "max". Keep list-style ("structured") texts to the same number of lines and fields, and leave every address, path, tel:/mailto: link and colour exactly as it is.',
		'4. Use only real facts from the brief. Do not invent prices, years in business, awards, licences, statistics, reviews or names. If a sentence depends on a fact you do not have, keep the original wording for it and list it for the owner at the end.',
		'5. Also give every page an SEO title (about 55 characters) and description (about 150) through the "seo" part of rkb_copy_apply.',
		'6. Call rkb_copy_apply with dryRun true first, fix anything it skips, then call it again without dryRun. This saves drafts only.',
		'7. Finish with a short report: what changed, what you left alone and why, and what the owner must check. Do not publish; the owner reviews the drafts and publishes.',
	) );
	return implode( "\n", $lines );
}

/** MCP prompts/list entries. */
function rk_builder_ai_prompts() {
	return array(
		array(
			'name'        => 'rewrite-site-copy',
			'title'       => 'Rewrite this site for my business',
			'description' => 'Rewrite every page, header, footer and reusable block so the wording is about your business. Saves drafts only.',
			'arguments'   => array(
				array( 'name' => 'business', 'description' => 'Your business name (defaults to the site name)', 'required' => false ),
				array( 'name' => 'tone', 'description' => 'For example friendly, premium, plain-spoken', 'required' => false ),
				array( 'name' => 'language', 'description' => 'Language to write in (defaults to the site\'s)', 'required' => false ),
			),
		),
	);
}
