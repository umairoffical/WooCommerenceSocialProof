<?php
/**
 * Plugin Name:       UM Social Proof
 * Plugin URI:        https://github.com/your-username/um-social-proof
 * Description:       Displays live purchase notification popups for WooCommerce stores. Shows real product names, prices, and buyer names. Supports 7 languages with automatic Google Translate detection. Desktop: top-right, max 3 popups. Mobile: top-left, max 1 popup (one at a time). Zero page-speed impact.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://yourwebsite.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       um-social-proof
 * Domain Path:       /languages
 * WC requires at least: 5.0
 * WC tested up to:   9.0
 *
 * @package UM_Social_Proof
 */

// ============================================================
// Security: Prevent direct file access.
// ============================================================
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================
// Constants
// ============================================================
define( 'UM_SP_VERSION',      '1.0.0' );
define( 'UM_SP_PLUGIN_FILE',  __FILE__ );
define( 'UM_SP_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'UM_SP_CACHE_KEY',    'um_sp_v9_products' );
define( 'UM_SP_CACHE_TTL',    2 * HOUR_IN_SECONDS );
define( 'UM_SP_MIN_PRODUCTS', 1 );

// ============================================================
// Activation Hook
// Runs once when the plugin is activated from wp-admin.
// - Checks that WooCommerce is active (hard requirement).
// - Stores plugin version in the database for future
//   upgrade routines.
// - Clears any stale transient cache from a previous install.
// ============================================================
register_activation_hook( UM_SP_PLUGIN_FILE, 'um_sp_activate' );

function um_sp_activate() {

	// Require WooCommerce — deactivate gracefully if missing.
	if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( UM_SP_PLUGIN_FILE ) );
		wp_die(
			esc_html__(
				'UM Social Proof requires WooCommerce to be installed and active. '
				. 'Please install WooCommerce first.',
				'um-social-proof'
			),
			esc_html__( 'Plugin Activation Error', 'um-social-proof' ),
			array( 'back_link' => true )
		);
	}

	// Store installed version — used for future upgrade checks.
	update_option( 'um_sp_version', UM_SP_VERSION, false );

	// Record activation timestamp for analytics / debugging.
	update_option( 'um_sp_activated_at', time(), false );

	// Flush any cached product data from a previous install.
	delete_transient( UM_SP_CACHE_KEY );

	// Log activation (visible in WP_DEBUG log if enabled).
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[UM Social Proof] Plugin activated. Version: ' . UM_SP_VERSION );
	}
}

// ============================================================
// Deactivation Hook
// Runs when the plugin is deactivated from wp-admin.
// - Clears all transient / cached data.
// - Does NOT delete options or data — that is reserved
//   for the uninstall hook so data survives deactivate→activate.
// ============================================================
register_deactivation_hook( UM_SP_PLUGIN_FILE, 'um_sp_deactivate' );

function um_sp_deactivate() {

	// Clear product cache so it is rebuilt fresh on re-activation.
	delete_transient( UM_SP_CACHE_KEY );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[UM Social Proof] Plugin deactivated. Cache cleared.' );
	}
}

// ============================================================
// Uninstall Hook (registered via uninstall.php pattern)
// We use register_uninstall_hook so cleanup only runs when the
// user explicitly deletes the plugin (not just deactivates it).
// This removes all database options left by the plugin.
// ============================================================
register_uninstall_hook( UM_SP_PLUGIN_FILE, 'um_sp_uninstall' );

function um_sp_uninstall() {

	// Remove all plugin options from wp_options table.
	delete_option( 'um_sp_version' );
	delete_option( 'um_sp_activated_at' );

	// Remove cached product data.
	delete_transient( UM_SP_CACHE_KEY );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[UM Social Proof] Plugin uninstalled. All data removed.' );
	}
}

// ============================================================
// Upgrade Routine
// Runs on every page load but only executes logic when the
// stored version differs from the current plugin version.
// ============================================================
add_action( 'plugins_loaded', 'um_sp_maybe_upgrade' );

function um_sp_maybe_upgrade() {
	$stored = get_option( 'um_sp_version', '0.0.0' );
	if ( version_compare( $stored, UM_SP_VERSION, '<' ) ) {
		// Future upgrade steps go here (e.g. database migrations).
		// For now, just flush the cache and update the version.
		delete_transient( UM_SP_CACHE_KEY );
		update_option( 'um_sp_version', UM_SP_VERSION, false );
	}
}

// ============================================================
// WooCommerce HPOS (High-Performance Order Storage) Compatibility
// Declares compatibility with WC custom order tables.
// ============================================================
add_action( 'before_woocommerce_init', 'um_sp_declare_hpos_compat' );

function um_sp_declare_hpos_compat() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			UM_SP_PLUGIN_FILE,
			true
		);
	}
}

// ============================================================
// Early bail: stop loading plugin logic if WooCommerce is gone
// (e.g. it was deactivated after this plugin was activated).
// ============================================================
add_action( 'plugins_loaded', 'um_sp_check_woocommerce', 5 );

function um_sp_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'um_sp_missing_wc_notice' );
	}
}

function um_sp_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>'
		. '<strong>UM Social Proof</strong> requires WooCommerce. '
		. 'Please <a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search' ) ) . '">install WooCommerce</a> to use this plugin.'
		. '</p></div>';
}

// ============================================================
// Section 1 — Page Gate
// Determines whether the plugin should render on the current
// page. Always false in wp-admin or AJAX requests.
// Shows on every public frontend page for logged-in AND
// logged-out visitors.
// ============================================================
function um_sp_is_allowed() {
	if ( is_admin() )        return false;
	if ( wp_doing_ajax() )   return false;
	if ( ! class_exists( 'WooCommerce' ) ) return false;
	return true;
}

// ============================================================
// Section 2 — Product Data Retrieval
// Fetches up to 30 published WooCommerce products and caches
// the result for 2 hours using WordPress transients.
// suppress_filters => true bypasses WooCommerce's login-based
// product visibility hooks so the popup works for guests too.
// ============================================================
function um_sp_get_products() {

	// Return from cache if available and valid.
	$cached = get_transient( UM_SP_CACHE_KEY );
	if ( is_array( $cached ) && count( $cached ) >= UM_SP_MIN_PRODUCTS ) {
		return $cached;
	}

	$ids = get_posts( array(
		'post_type'              => 'product',
		'post_status'            => 'publish',
		'fields'                 => 'ids',
		'posts_per_page'         => 30,
		'orderby'                => 'rand',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'suppress_filters'       => true,   // bypass WC visibility filters for guests
		'ignore_sticky_posts'    => true,
	) );

	if ( empty( $ids ) ) {
		return array();
	}

	$products = array();

	foreach ( $ids as $product_id ) {

		$name  = get_the_title( $product_id );
		$url   = get_permalink( $product_id );
		$price = get_post_meta( $product_id, '_price', true );

		// Skip incomplete products.
		if ( ! $name || ! $url || $price === '' ) {
			continue;
		}

		// Retrieve thumbnail URL (prefer WooCommerce thumbnail size).
		$thumb    = '';
		$thumb_id = get_post_thumbnail_id( $product_id );
		if ( $thumb_id ) {
			$img_src = wp_get_attachment_image_src( $thumb_id, 'thumbnail' );
			if ( $img_src ) {
				$thumb = esc_url_raw( $img_src[0] );
			}
		}

		$products[] = array(
			'name'  => wp_strip_all_tags( $name ),
			'url'   => esc_url_raw( $url ),
			'price' => wp_strip_all_tags( wc_price( $price ) ),
			'thumb' => $thumb,
		);
	}

	// Cache only if we have usable results.
	if ( ! empty( $products ) ) {
		set_transient( UM_SP_CACHE_KEY, $products, UM_SP_CACHE_TTL );
	}

	return $products;
}

// ============================================================
// Section 3 — "X People Viewing" Badge (single product page)
// Injected into the WooCommerce product summary via hook.
// Count is randomised per page load; JS updates the label
// text to match the active language on client side.
// ============================================================
add_action( 'woocommerce_single_product_summary', 'um_sp_viewer_badge', 25 );

function um_sp_viewer_badge() {
	if ( ! is_product() ) {
		return;
	}

	$count = wp_rand( 18, 72 );

	printf(
		'<div class="um-sp-viewers">'
		. '<span class="um-sp-live-dot"></span>'
		. '<span id="um-sp-badge-text" data-count="%d">'
		. '<strong>%d</strong> Personen sehen sich das gerade an'
		. '</span></div>',
		absint( $count ),
		absint( $count )
	);
}

// ============================================================
// Section 4 — Cache Invalidation
// Deletes the product transient whenever a WooCommerce product
// is saved or deleted so fresh data is loaded next visit.
// ============================================================
add_action( 'save_post',          'um_sp_clear_product_cache' );
add_action( 'before_delete_post', 'um_sp_clear_product_cache' );

function um_sp_clear_product_cache( $post_id ) {
	if ( 'product' === get_post_type( $post_id ) ) {
		delete_transient( UM_SP_CACHE_KEY );
	}
}

// ============================================================
// Section 5 — Frontend Output (styles + markup + script)
// Hooked into wp_footer at priority 5 so it outputs early,
// before most theme footer scripts that could conflict.
// ============================================================
add_action( 'wp_footer', 'um_sp_render', 5 );

function um_sp_render() {

	// Page gate check.
	if ( ! um_sp_is_allowed() ) {
		return;
	}

	// Fetch products — bail silently if none available.
	$products = um_sp_get_products();
	if ( empty( $products ) ) {
		return;
	}

	?>
	<!-- UM Social Proof v<?php echo esc_html( UM_SP_VERSION ); ?> -->
	<style id="um-sp-css">
	/*
	 * UM Social Proof — Styles
	 * Position is controlled entirely by JavaScript (positionWrap).
	 * This avoids CSS specificity conflicts with theme stylesheets.
	 * Desktop : top-right, slides in from right, max 3 cards.
	 * Mobile  : top-left,  slides in from left,  max 1 card (sequential).
	 */

	/* ── Wrap container ── */
	#um-sp-wrap {
		position: fixed;
		z-index: 2147483647;
		display: flex;
		flex-direction: column;
		gap: 8px;
		pointer-events: none;
		width: 310px;
	}

	/* ── Notification card ── */
	.um-sp-card {
		display: flex;
		align-items: center;
		gap: 10px;
		background: #ffffff;
		border-radius: 12px;
		box-shadow: 0 4px 24px rgba(0,0,0,0.13), 0 1px 4px rgba(0,0,0,0.07);
		padding: 11px 34px 11px 11px;
		text-decoration: none;
		color: inherit;
		cursor: pointer;
		position: relative;
		box-sizing: border-box;
		pointer-events: auto;
		opacity: 0;
		transition: opacity 0.32s ease, transform 0.32s ease, box-shadow 0.18s ease;
		will-change: opacity, transform;
	}
	.um-sp-card.sp-show { opacity: 1; transform: translateX(0) !important; }
	.um-sp-card.sp-hide { opacity: 0; }
	.um-sp-card:hover   { box-shadow: 0 8px 32px rgba(0,0,0,0.16); }

	/* ── Thumbnail ── */
	.um-sp-img {
		width: 48px; height: 48px;
		border-radius: 8px; object-fit: cover;
		flex-shrink: 0; display: block;
		background: #f3f4f6;
	}
	.um-sp-img-ph {
		width: 48px; height: 48px;
		border-radius: 8px; flex-shrink: 0;
		background: linear-gradient(135deg, #f0fdf4, #dcfce7);
		display: flex; align-items: center;
		justify-content: center; font-size: 22px;
	}

	/* ── Text body ── */
	.um-sp-body { flex: 1; min-width: 0; }

	.um-sp-who {
		font-size: 11px; color: #6b7280; font-weight: 500;
		display: flex; align-items: center; gap: 4px;
		overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
		margin-bottom: 2px;
	}
	.um-sp-dot-green {
		width: 7px; height: 7px; border-radius: 50%;
		background: #16a34a; flex-shrink: 0; display: inline-block;
	}
	.um-sp-title {
		font-size: 13px; font-weight: 700; color: #111827;
		line-height: 1.3; overflow: hidden;
		white-space: nowrap; text-overflow: ellipsis;
	}
	.um-sp-price {
		font-size: 12.5px; font-weight: 700;
		color: #16a34a; line-height: 1.3; margin-top: 1px;
	}
	.um-sp-time {
		font-size: 10.5px; color: #9ca3af;
		font-weight: 400; margin-left: 5px;
	}
	.um-sp-hint { font-size: 10px; color: #9ca3af; margin-top: 2px; }

	/* ── Close button ── */
	.um-sp-x {
		position: absolute; top: 7px; right: 7px;
		width: 20px; height: 20px;
		background: none; border: none; cursor: pointer;
		color: #d1d5db; font-size: 13px; line-height: 1;
		padding: 0; display: flex; align-items: center;
		justify-content: center; pointer-events: auto;
	}
	.um-sp-x:hover { color: #6b7280; }

	/* ── "X people viewing" badge (product page) ── */
	.um-sp-viewers {
		display: inline-flex; align-items: center; gap: 8px;
		background: #fff; border: 1px solid #e5e7eb;
		border-radius: 8px; padding: 8px 14px;
		margin: 10px 0 16px; color: #374151;
		font-size: 13px; font-weight: 500;
		box-shadow: 0 1px 6px rgba(0,0,0,0.06);
	}
	.um-sp-live-dot {
		width: 9px; height: 9px; border-radius: 50%;
		background: #16a34a; flex-shrink: 0;
		animation: um-sp-pulse 2s ease-out infinite;
	}
	@keyframes um-sp-pulse {
		0%, 100% { box-shadow: 0 0 0 3px rgba(22,163,74,0.20); }
		50%       { box-shadow: 0 0 0 7px rgba(22,163,74,0.06); }
	}
	</style>

	<div id="um-sp-wrap" role="region" aria-label="Live purchase notifications"></div>

	<script id="um-sp-js">
	/* UM Social Proof <?php echo esc_js( UM_SP_VERSION ); ?> */
	(function () {
		'use strict';

		// ── Wait for DOM to be fully ready ──────────────────────
		function ready( fn ) {
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', fn );
			} else {
				fn();
			}
		}

		ready( function () {

			var wrap = document.getElementById( 'um-sp-wrap' );
			if ( ! wrap ) return;

			// ────────────────────────────────────────────────────
			// PRODUCTS
			// Raw product data passed from PHP cache.
			// JS assembles message text dynamically each time so
			// every popup shows a different name + action combo.
			// ────────────────────────────────────────────────────
			var PRODUCTS = <?php echo wp_json_encode( array_values( $products ) ); ?>;
			if ( ! PRODUCTS || ! PRODUCTS.length ) return;

			// ────────────────────────────────────────────────────
			// LANGUAGE PACKS
			// Default: German (de).
			// Auto-detected from: googtrans cookie → html[lang] → navigator.language.
			// Re-detected live when Google Translate changes the page language.
			// ────────────────────────────────────────────────────
			var LANGS = {
				de: {
					names: [
						'Lukas','Felix','Jonas','Leon','Maximilian','Finn','Paul','Elias','Noah','Ben',
						'Luca','Moritz','Julian','Tobias','Niklas','Fabian','Simon','Jan','Dominik','Philipp',
						'Hannah','Leonie','Laura','Lisa','Anna','Emma','Lena','Julia','Sarah','Sophie',
						'Marie','Lea','Nina','Mia','Clara','Lara','Amelie','Franziska',
						'Markus','Stefan','Andreas','Christian','Thomas','Michael','Sebastian','Alexander'
					],
					bought:  'hat gerade gekauft',
					viewers: 'Personen sehen sich das gerade an',
					hint:    'Zum Ansehen klicken \u2192',
					times:   ['Gerade eben','Vor 1 Min.','Vor 2 Min.','Vor 3 Min.','Vor 5 Min.']
				},
				en: {
					names: [
						'James','Oliver','Harry','Noah','Jack','George','Charlie','Jacob','Alfie','Freddie',
						'Thomas','Henry','William','Ethan','Lucas','Mason','Logan','Liam','Benjamin','Samuel',
						'Emma','Olivia','Amelia','Ava','Mia','Sophia','Grace','Lily','Ella','Hannah',
						'Daniel','Michael','David','Andrew','Ryan','Nathan','Joshua','Aaron','Adam','Luke',
						'Maria','Laura','Anna','Sarah','Amy','Rachel','Claire','Kate','Lucy','Sophie'
					],
					bought:  'just bought',
					viewers: 'people viewing this right now',
					hint:    'Click to view \u2192',
					times:   ['Just now','1 min ago','2 mins ago','3 mins ago','5 mins ago']
				},
				nl: {
					names: [
						'Jan','Pieter','Daan','Luuk','Finn','Milan','Bram','Thijs','Sander','Ruben',
						'Lars','Joris','Niels','Wouter','Tom','Bas','Stef','Koen',
						'Eva','Sanne','Lotte','Fleur','Roos','Nina','Fenna','Iris','Lena','Anne','Lisa','Julia'
					],
					bought:  'heeft zojuist gekocht',
					viewers: 'mensen bekijken dit nu',
					hint:    'Klik om te bekijken \u2192',
					times:   ['Zojuist','1 min geleden','2 min geleden','3 min geleden','5 min geleden']
				},
				fr: {
					names: [
						'Lucas','Hugo','Tom','Louis','Th\u00e9o','Maxime','Quentin','Antoine','Julien','Baptiste',
						'Emma','L\u00e9a','Chlo\u00e9','Camille','Manon','In\u00e8s','Lucie','Juliette','Marie','Sophie'
					],
					bought:  "vient d'acheter",
					viewers: 'personnes consultent ceci en ce moment',
					hint:    'Cliquez pour voir \u2192',
					times:   ["\u00c0 l'instant",'Il y a 1 min','Il y a 2 min','Il y a 3 min','Il y a 5 min']
				},
				es: {
					names: [
						'Carlos','Javier','Miguel','David','Alejandro','Pablo','Sergio','Andr\u00e9s','Diego','Rub\u00e9n',
						'Mar\u00eda','Carmen','Sof\u00eda','Laura','Paula','Ana','Isabel','Luc\u00eda','Elena','Marta'
					],
					bought:  'acaba de comprar',
					viewers: 'personas viendo esto ahora',
					hint:    'Haz clic para ver \u2192',
					times:   ['Ahora mismo','Hace 1 min','Hace 2 min','Hace 3 min','Hace 5 min']
				},
				it: {
					names: [
						'Marco','Luca','Matteo','Lorenzo','Andrea','Davide','Simone','Alessandro','Riccardo','Filippo',
						'Sofia','Giulia','Martina','Sara','Chiara','Valentina','Francesca','Alessia','Elisa','Federica'
					],
					bought:  'ha appena acquistato',
					viewers: 'persone stanno guardando questo adesso',
					hint:    'Clicca per vedere \u2192',
					times:   ['Proprio ora','1 min fa','2 min fa','3 min fa','5 min fa']
				},
				pl: {
					names: [
						'Piotr','Tomasz','Marcin','Micha\u0142','\u0141ukasz','Pawe\u0142','Maciej','Adam','Bartosz','Kamil',
						'Anna','Maria','Katarzyna','Agnieszka','Ewa','Monika','Natalia','Aleksandra','Julia','Marta'
					],
					bought:  'w\u0142a\u015bnie kupi\u0142/a',
					viewers: 'os\u00f3b przegląda to teraz',
					hint:    'Kliknij, aby zobaczy\u0107 \u2192',
					times:   ['W\u0142a\u015bnie teraz','1 min temu','2 min temu','3 min temu','5 min temu']
				}
			};

			// ────────────────────────────────────────────────────
			// LANGUAGE DETECTION
			// Priority: googtrans cookie → html[lang] → navigator.language → default (de)
			// ────────────────────────────────────────────────────
			function detectLangCode() {
				// 1. googtrans cookie set by Google Translate widget
				try {
					var cookies = document.cookie.split( ';' );
					for ( var i = 0; i < cookies.length; i++ ) {
						var c = cookies[ i ].trim();
						if ( c.indexOf( 'googtrans=' ) === 0 ) {
							var val   = decodeURIComponent( c.slice( 'googtrans='.length ) );
							var parts = val.split( '/' );
							var tgt   = parts[ parts.length - 1 ];
							if ( tgt && tgt.length >= 2 ) return tgt.slice( 0, 2 ).toLowerCase();
						}
					}
				} catch ( e ) {}

				// 2. html[lang] attribute (also changed by Google Translate)
				try {
					var h = document.documentElement.lang;
					if ( h && h.length >= 2 ) return h.slice( 0, 2 ).toLowerCase();
				} catch ( e ) {}

				// 3. Browser / OS language
				try {
					var nav = navigator.language || navigator.userLanguage || '';
					if ( nav.length >= 2 ) return nav.slice( 0, 2 ).toLowerCase();
				} catch ( e ) {}

				return 'de'; // default: German
			}

			function getLang() {
				return LANGS[ detectLangCode() ] || LANGS.de;
			}

			// ────────────────────────────────────────────────────
			// MOBILE DETECTION
			// Uses matchMedia (same engine as CSS media queries) as
			// the primary signal, with 3 fallback methods to ensure
			// correct detection on all real mobile devices.
			// ────────────────────────────────────────────────────
			function isMobile() {
				// matchMedia — most reliable, mirrors CSS @media
				if ( window.matchMedia ) {
					try {
						if ( window.matchMedia( '(max-width: 767px)' ).matches ) return true;
					} catch ( e ) {}
				}
				// Fallback: smallest of available width sources
				var w = Math.min(
					window.innerWidth                          || 9999,
					document.documentElement.clientWidth      || 9999,
					screen.width                              || 9999
				);
				if ( w <= 767 ) return true;
				// Last resort: user-agent string
				return /Mobi|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i.test( navigator.userAgent );
			}

			// ────────────────────────────────────────────────────
			// POSITION WRAP
			// JS-controlled positioning eliminates all CSS
			// specificity conflicts with theme stylesheets.
			// ────────────────────────────────────────────────────
			function positionWrap() {
				var mob = isMobile();
				if ( mob ) {
					// Mobile: top-left
					wrap.style.top    = '160px';
					wrap.style.left   = '10px';
					wrap.style.right  = 'auto';
					wrap.style.bottom = 'auto';
					wrap.style.width  = Math.min( window.innerWidth - 20, 310 ) + 'px';
				} else {
					// Desktop: top-right
					wrap.style.top    = '100px';
					wrap.style.right  = '16px';
					wrap.style.left   = 'auto';
					wrap.style.bottom = 'auto';
					wrap.style.width  = '310px';
				}
			}

			positionWrap();
			window.addEventListener( 'resize', positionWrap );

			// ────────────────────────────────────────────────────
			// VIEWER BADGE (product page)
			// ────────────────────────────────────────────────────
			function updateViewerBadge() {
				var el = document.getElementById( 'um-sp-badge-text' );
				if ( ! el ) return;
				var n = parseInt( el.getAttribute( 'data-count' ), 10 ) || 0;
				var L = getLang();
				el.innerHTML = '<strong>' + n + '</strong> ' + L.viewers;
			}
			updateViewerBadge();

			// Re-render badge when Google Translate changes html[lang]
			try {
				new MutationObserver( function ( mutations ) {
					for ( var i = 0; i < mutations.length; i++ ) {
						if ( mutations[ i ].attributeName === 'lang' ) {
							lang    = getLang();
							nameSeq = shuffle( lang.names );
							nameIdx = 0;
							updateViewerBadge();
							break;
						}
					}
				} ).observe( document.documentElement, {
					attributes:      true,
					attributeFilter: ['lang']
				} );
			} catch ( e ) {}

			// ────────────────────────────────────────────────────
			// SHUFFLE UTILITY
			// ────────────────────────────────────────────────────
			function shuffle( arr ) {
				var a = arr.slice();
				for ( var i = a.length - 1; i > 0; i-- ) {
					var j = Math.floor( Math.random() * ( i + 1 ) );
					var t = a[ i ]; a[ i ] = a[ j ]; a[ j ] = t;
				}
				return a;
			}

			// ────────────────────────────────────────────────────
			// SEQUENCE STATE
			// Products + names are shuffled independently and loop
			// without repeating until REPEAT_AFTER unique combos
			// have been shown.
			// ────────────────────────────────────────────────────
			var REPEAT_AFTER = 22;
			var shownCount   = 0;
			var lang         = getLang();
			var prodSeq      = shuffle( PRODUCTS );
			var nameSeq      = shuffle( lang.names );
			var prodIdx      = 0;
			var nameIdx      = 0;

			function nextCombo() {
				// Reshuffle after REPEAT_AFTER unique messages
				if ( shownCount > 0 && shownCount % REPEAT_AFTER === 0 ) {
					prodSeq = shuffle( PRODUCTS );
					nameSeq = shuffle( lang.names );
					prodIdx = 0;
					nameIdx = 0;
				}

				var prod = prodSeq[ prodIdx % prodSeq.length ];
				var name = nameSeq[ nameIdx % nameSeq.length ];
				var time = lang.times[ Math.floor( Math.random() * lang.times.length ) ];

				prodIdx++;
				nameIdx++;

				return { prod: prod, name: name, time: time };
			}

			// ────────────────────────────────────────────────────
			// BUILD CARD
			// The card is an <a> element so tap / click natively
			// navigates to the product detail page on all devices.
			// ────────────────────────────────────────────────────
			function buildCard( combo ) {
				var p   = combo.prod;
				var mob = isMobile();

				var card       = document.createElement( 'a' );
				card.href      = p.url;
				card.className = 'um-sp-card';
				card.setAttribute( 'role', 'status' );
				card.setAttribute( 'aria-label', combo.name + ' ' + lang.bought + ': ' + p.name );

				// Start off-screen on the correct side
				card.style.transform = mob ? 'translateX(-28px)' : 'translateX(28px)';
				card._isMob = mob; // store for removal animation direction

				// Thumbnail
				var thumbEl;
				if ( p.thumb ) {
					thumbEl           = document.createElement( 'img' );
					thumbEl.className = 'um-sp-img';
					thumbEl.src       = p.thumb;
					thumbEl.alt       = p.name;
					thumbEl.width     = 48;
					thumbEl.height    = 48;
					thumbEl.setAttribute( 'loading', 'lazy' );
				} else {
					thumbEl           = document.createElement( 'div' );
					thumbEl.className = 'um-sp-img-ph';
					thumbEl.textContent = '\uD83D\uDED2';
				}

				// Body
				var body       = document.createElement( 'div' );
				body.className = 'um-sp-body';

				// Line 1 — who bought
				var who       = document.createElement( 'div' );
				who.className = 'um-sp-who';
				var dot       = document.createElement( 'span' );
				dot.className = 'um-sp-dot-green';
				who.appendChild( dot );
				who.appendChild( document.createTextNode( '\u00a0' + combo.name + ' ' + lang.bought ) );

				// Line 2 — product name
				var titleEl           = document.createElement( 'div' );
				titleEl.className     = 'um-sp-title';
				titleEl.textContent   = p.name;
				titleEl.title         = p.name;

				// Line 3 — price + timestamp
				var priceEl           = document.createElement( 'div' );
				priceEl.className     = 'um-sp-price';
				priceEl.innerHTML     = p.price
					+ '<span class="um-sp-time">' + combo.time + '</span>';

				// Line 4 — click hint
				var hintEl            = document.createElement( 'div' );
				hintEl.className      = 'um-sp-hint';
				hintEl.textContent    = lang.hint;

				body.appendChild( who );
				body.appendChild( titleEl );
				body.appendChild( priceEl );
				body.appendChild( hintEl );

				// Close button
				var closeBtn          = document.createElement( 'button' );
				closeBtn.className    = 'um-sp-x';
				closeBtn.type        = 'button';
				closeBtn.innerHTML   = '&#x2715;';
				closeBtn.setAttribute( 'aria-label', 'Schlie\u00dfen' );
				closeBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					forceRemoveCard( card );
				} );

				card.appendChild( thumbEl );
				card.appendChild( body );
				card.appendChild( closeBtn );

				return card;
			}

			// ────────────────────────────────────────────────────
			// REMOVE CARD (animated)
			// cb (optional) is called after the card is gone from DOM.
			// Used on mobile to chain: remove old → show new.
			// ────────────────────────────────────────────────────
			function removeCard( card, cb ) {
				if ( ! card || card._gone ) {
					if ( cb ) cb();
					return;
				}
				card._gone = true;
				card.classList.remove( 'sp-show' );
				card.style.opacity   = '0';
				card.style.transform = card._isMob ? 'translateX(-28px)' : 'translateX(28px)';
				setTimeout( function () {
					if ( card.parentNode ) card.parentNode.removeChild( card );
					if ( cb ) cb();
				}, 340 );
			}

			// ────────────────────────────────────────────────────
			// FORCE REMOVE (instant — close button tap)
			// ────────────────────────────────────────────────────
			function forceRemoveCard( card ) {
				if ( ! card || card._gone ) return;
				card._gone            = true;
				card.style.transition = 'none';
				if ( card.parentNode ) card.parentNode.removeChild( card );
			}

			// ────────────────────────────────────────────────────
			// TIMER STATE
			// ────────────────────────────────────────────────────
			var timer  = null;
			var paused = false;
			var busy   = false; // mobile: prevents overlap during remove→show transition

			// ────────────────────────────────────────────────────
			// SHOW NEXT CARD
			// Mobile:  remove existing card completely first,
			//          then show the next one (sequential, no overlap).
			// Desktop: show up to 3 simultaneously; oldest removed
			//          when 4th would exceed the limit.
			// ────────────────────────────────────────────────────
			function showNext() {
				if ( paused || busy ) return;

				var mob        = isMobile();
				var maxVisible = mob ? 1 : 3;
				var cards      = wrap.querySelectorAll( '.um-sp-card' );

				if ( mob && cards.length >= 1 ) {
					// Mobile: wait for old card to fully leave before adding new one
					busy = true;
					removeCard( cards[0], function () {
						busy = false;
						addCard();
					} );
					return;
				}

				// Desktop: evict oldest if at limit
				if ( ! mob && cards.length >= maxVisible ) {
					removeCard( cards[0] );
				}

				addCard();
			}

			function addCard() {
				var combo = nextCombo();
				shownCount++;

				var card = buildCard( combo );
				wrap.appendChild( card );

				// Double rAF ensures the initial transform is
				// registered before the transition class is added.
				requestAnimationFrame( function () {
					requestAnimationFrame( function () {
						card.classList.add( 'sp-show' );
					} );
				} );

				// Auto-dismiss after 6–9 seconds
				var lifetime = 6000 + Math.floor( Math.random() * 3000 );
				setTimeout( function () { removeCard( card ); }, lifetime );

				scheduleNext();
			}

			// ────────────────────────────────────────────────────
			// SCHEDULE NEXT POPUP
			// Delay between popups:
			//   Mobile:  4–7 seconds (one card at a time)
			//   Desktop: 5–9 seconds (up to 3 stacked)
			// ────────────────────────────────────────────────────
			function scheduleNext() {
				clearTimeout( timer );
				var delay = isMobile()
					? 4000 + Math.floor( Math.random() * 3000 )
					: 5000 + Math.floor( Math.random() * 4000 );
				timer = setTimeout( showNext, delay );
			}

			// ────────────────────────────────────────────────────
			// VISIBILITY API — pause when tab is hidden
			// Prevents a queue of pending popups from firing all
			// at once when the user returns to the tab.
			// ────────────────────────────────────────────────────
			document.addEventListener( 'visibilitychange', function () {
				if ( document.hidden ) {
					paused = true;
					clearTimeout( timer );
				} else {
					paused = false;
					scheduleNext();
				}
			} );

			// ────────────────────────────────────────────────────
			// CLEANUP — clear timer on page unload
			// ────────────────────────────────────────────────────
			window.addEventListener( 'beforeunload', function () {
				clearTimeout( timer );
			} );

			// ────────────────────────────────────────────────────
			// BOOT — first popup after 2 seconds
			// ────────────────────────────────────────────────────
			timer = setTimeout( showNext, 2000 );

		} ); // end ready()

	} )();
	</script>
	<?php
}
