<?php
/**
 * Plugin Name: Img Formats
 * Description: Modern image formats and a hard size cap for uploads. Content images become AVIF, featured images become WebP (AVIF is not supported by some social/messaging crawlers), and every generated file is re-encoded until it fits under a size limit (default 80 KB). Works with Advanced Media Offloader.
 * Version: 2.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 *
 * How it works
 *  1. New JPEG/PNG uploads: the main file and every sub-size are written as AVIF. Uploads that are already AVIF
 *     get AVIF sub-sizes (WordPress would otherwise emit JPEG).
 *  2. When an image becomes a post's featured image (`_thumbnail_id`), its main file and all sub-sizes are rebuilt
 *     as WebP. If Advanced Media Offloader already pushed the AVIF objects to the bucket they are deleted, and if
 *     the local original was removed ("full cloud migration") it is fetched back temporarily from the public domain.
 *  3. Size cap (new in 2.0): after metadata generation every AVIF/WebP file larger than the limit is re-encoded with
 *     a binary search on quality (down to a floor) and, if that is still too big, downscaled in 15% steps down to a
 *     minimum width. Width, height and filesize are updated in the attachment metadata.
 *  4. The large `original_image` copy WordPress keeps after converting is deleted (before it is offloaded) unless
 *     IMGF_KEEP_ORIGINAL is set; later featured-image conversions then start from the AVIF main file.
 *
 * Optional constants for wp-config.php:
 *   define( 'IMGF_MAX_KB', 80 );            // size limit per file in KB, 0 = no limit
 *   define( 'IMGF_AVIF_QUALITY', 60 );      // starting quality for AVIF
 *   define( 'IMGF_WEBP_QUALITY', 80 );      // starting quality for WebP
 *   define( 'IMGF_MIN_AVIF_QUALITY', 35 );  // quality floor for AVIF before downscaling
 *   define( 'IMGF_MIN_WEBP_QUALITY', 55 );  // quality floor for WebP before downscaling
 *   define( 'IMGF_MIN_WIDTH', 480 );        // never downscale below this width (px)
 *   define( 'IMGF_MAX_WIDTH', 1200 );       // downscale uploads wider than this (px) before anything else, 0 = off
 *   define( 'IMGF_ONLY_SIZES', 'my_thumb' );// comma list: only generate these sub-sizes (others fall back to the closest one), '' = all
 *   define( 'IMGF_KEEP_ORIGINAL', true );   // keep WordPress's `original_image` (default: it is deleted after conversion)
 *   define( 'IMGF_DISABLED', true );        // switch the plugin off without removing it
 *
 * Tip: IMGF_MAX_WIDTH + IMGF_ONLY_SIZES turn every image into two files (a main file and one thumbnail), which is also
 * what makes uploads fast, because WordPress otherwise renders every registered size.
 *
 * WP-CLI: `wp imgf shrink [--id=<id>] [--dry-run]` applies the cap to existing attachments whose files are local.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IMGF_VERSION   = '2.0.0';
const IMGF_FLAG_META = '_imgf_webp'; // marks an attachment already converted to WebP as a featured image

function imgf_enabled(): bool {
	return ! ( defined( 'IMGF_DISABLED' ) && IMGF_DISABLED );
}

function imgf_const( string $name, int $default ): int {
	return defined( $name ) ? (int) constant( $name ) : $default;
}

function imgf_quality( string $mime ): ?int {
	if ( 'image/avif' === $mime ) {
		return imgf_const( 'IMGF_AVIF_QUALITY', 60 );
	}
	if ( 'image/webp' === $mime ) {
		return imgf_const( 'IMGF_WEBP_QUALITY', 80 );
	}
	return null;
}

function imgf_min_quality( string $mime ): int {
	return 'image/avif' === $mime ? imgf_const( 'IMGF_MIN_AVIF_QUALITY', 35 ) : imgf_const( 'IMGF_MIN_WEBP_QUALITY', 55 );
}

function imgf_max_bytes(): int {
	return max( 0, imgf_const( 'IMGF_MAX_KB', 80 ) ) * 1024;
}

/** Target format: AVIF by default, WebP while a featured image is being processed. */
function imgf_target_mime(): string {
	return ! empty( $GLOBALS['imgf_force_webp'] ) ? 'image/webp' : 'image/avif';
}

/** Can the server encode this format (Imagick)? If not, the original format is left untouched. */
function imgf_can_encode( string $mime ): bool {
	static $cache = array();
	if ( ! isset( $cache[ $mime ] ) ) {
		$cache[ $mime ] = function_exists( 'wp_image_editor_supports' )
			&& wp_image_editor_supports( array( 'mime_type' => $mime ) );
	}
	return $cache[ $mime ];
}

/* 1) Output format (main file + sub-sizes).
 * With ImageMagick 6.9 + libheif an AVIF file is detected as "image/heic" and WordPress maps HEIC to JPEG by default,
 * so heic/heif must be mapped too, otherwise the sub-sizes of an AVIF upload come out as JPEG. */
add_filter(
	'image_editor_output_format',
	function ( $map, $filename, $mime ) {
		$sources = array( 'image/jpeg', 'image/png', 'image/avif', 'image/heic', 'image/heif' );
		if ( ! imgf_enabled() || ! in_array( $mime, $sources, true ) ) {
			return $map;
		}
		$target = imgf_target_mime();
		if ( imgf_can_encode( $target ) ) {
			$map[ $mime ] = $target;
		}
		return $map;
	},
	10,
	3
);

/* 2) Starting quality per format. */
add_filter(
	'wp_editor_set_quality',
	function ( $quality, $mime ) {
		$q = imgf_enabled() ? imgf_quality( (string) $mime ) : null;
		return $q ?: $quality;
	},
	10,
	2
);

/* 3) Featured image -> rebuild every size as WebP. */
function imgf_on_thumbnail_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( '_thumbnail_id' !== $meta_key || ! imgf_enabled() ) {
		return;
	}
	$attachment_id = (int) $meta_value;
	if ( $attachment_id > 0 ) {
		imgf_convert_to_webp( $attachment_id );
	}
}
add_action( 'added_post_meta', 'imgf_on_thumbnail_meta', 10, 4 );
add_action( 'updated_post_meta', 'imgf_on_thumbnail_meta', 10, 4 );

function imgf_convert_to_webp( int $attachment_id ): void {
	static $running = array();
	if ( isset( $running[ $attachment_id ] ) || get_post_meta( $attachment_id, IMGF_FLAG_META, true ) ) {
		return;
	}
	if ( ! wp_attachment_is_image( $attachment_id ) || ! imgf_can_encode( 'image/webp' ) ) {
		return;
	}
	$current = get_attached_file( $attachment_id );
	if ( $current && 'webp' === strtolower( pathinfo( $current, PATHINFO_EXTENSION ) ) ) {
		update_post_meta( $attachment_id, IMGF_FLAG_META, 1 ); // already WebP
		return;
	}
	// Since WP 6.7 the "full" image is converted too (main file becomes .avif, the source stays in original_image).
	// Rebuild from the ORIGINAL file so the main file and every sub-size come out as WebP.
	$source     = wp_get_original_image_path( $attachment_id );
	$downloaded = false;
	if ( $source && ! file_exists( $source ) ) {
		$downloaded = imgf_fetch_from_cloud( $attachment_id, $source ); // local copy was removed: fetch it back
	}
	if ( ! $source || ! file_exists( $source ) ) {
		error_log( "Img Formats: skipping #$attachment_id, no source file (local or bucket)" );
		return;
	}
	$source_mime = wp_check_filetype( $source )['type'];
	if ( ! in_array( $source_mime, array( 'image/jpeg', 'image/png', 'image/avif' ), true ) ) {
		return; // GIF/WebP/other sources are left alone
	}
	$old = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $old ) ) {
		return;
	}
	$running[ $attachment_id ] = true;

	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Collect the local AVIF files (main + sub-sizes) to delete once the WebP set has been created.
	$dir       = dirname( $current );
	$old_files = array();
	if ( $current && 'avif' === strtolower( pathinfo( $current, PATHINFO_EXTENSION ) ) ) {
		$old_files[] = $current;
	}
	foreach ( (array) ( $old['sizes'] ?? array() ) as $s ) {
		if ( ! empty( $s['file'] ) && ! empty( $s['mime-type'] ) && 'image/avif' === $s['mime-type'] ) {
			$old_files[] = $dir . '/' . $s['file'];
		}
	}

	// Bucket key = relative path inside uploads (when no path prefix is used). The `advmo_path` meta is only the DIRECTORY.
	$offloaded = (bool) get_post_meta( $attachment_id, 'advmo_offloaded', true );
	$cloud_dir = '';
	if ( $offloaded && $current ) {
		$rel       = dirname( _wp_relative_upload_path( $current ) );
		$cloud_dir = ( '.' === $rel || '' === $rel ) ? '' : trailingslashit( $rel );
	}

	$GLOBALS['imgf_force_webp'] = true;
	try {
		$new = wp_generate_attachment_metadata( $attachment_id, $source );
	} finally {
		unset( $GLOBALS['imgf_force_webp'] );
	}

	if ( is_array( $new ) && ! empty( $new['sizes'] ) ) {
		wp_update_attachment_metadata( $attachment_id, $new );
		update_post_meta( $attachment_id, IMGF_FLAG_META, 1 );
		$keep = array_map( 'wp_normalize_path', array( (string) get_attached_file( $attachment_id ) ) );
		foreach ( (array) $new['sizes'] as $s ) {
			if ( ! empty( $s['file'] ) ) {
				$keep[] = wp_normalize_path( $dir . '/' . $s['file'] );
			}
		}
		$norm_old = array_map( 'wp_normalize_path', $old_files );
		if ( ! in_array( wp_normalize_path( $source ), $norm_old, true ) ) {
			$keep[] = wp_normalize_path( $source ); // a separate original (JPEG/PNG) is kept; an AVIF source is replaced by WebP
		}
		$cloud_keys = array();
		foreach ( array_unique( $old_files ) as $f ) {
			if ( in_array( wp_normalize_path( $f ), $keep, true ) ) {
				continue;
			}
			if ( $offloaded ) {
				$cloud_keys[] = $cloud_dir . basename( $f );
			}
			if ( is_file( $f ) ) {
				wp_delete_file( $f ); // only the AVIF copies that were replaced by WebP
			}
		}
		if ( $offloaded && $cloud_keys ) {
			imgf_delete_cloud_keys( $cloud_keys );
		}
	} else {
		error_log( "Img Formats: WebP rebuild failed for #$attachment_id" );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $old ); // roll the metadata back
		update_attached_file( $attachment_id, $current );
	}
	if ( $downloaded && is_file( $source ) ) {
		wp_delete_file( $source ); // only the temporary copy fetched from the bucket
	}
	unset( $running[ $attachment_id ] );
}

/* 4) Size cap. Runs before Advanced Media Offloader (priority 99) so the final, smaller files are the ones uploaded. */
add_filter( 'wp_generate_attachment_metadata', 'imgf_enforce_size_cap', 50, 2 );

function imgf_enforce_size_cap( $metadata, $attachment_id ) {
	$cap = imgf_max_bytes();
	if ( ! imgf_enabled() || ! $cap || ! is_array( $metadata ) || ! $attachment_id ) {
		return $metadata;
	}
	$main = get_attached_file( $attachment_id );
	if ( ! $main ) {
		return $metadata;
	}
	$dir = dirname( $main );
	// Main file.
	$res = imgf_shrink_file( $main, $cap );
	if ( $res ) {
		$metadata['width']    = $res['width'];
		$metadata['height']   = $res['height'];
		$metadata['filesize'] = $res['size'];
	}
	// Sub-sizes.
	foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $s ) {
		if ( empty( $s['file'] ) ) {
			continue;
		}
		$res = imgf_shrink_file( $dir . '/' . $s['file'], $cap );
		if ( $res ) {
			$metadata['sizes'][ $name ]['width']    = $res['width'];
			$metadata['sizes'][ $name ]['height']   = $res['height'];
			$metadata['sizes'][ $name ]['filesize'] = $res['size'];
		}
	}
	// Drop the large original kept by WordPress so it is never offloaded / stored.
	if ( ! ( defined( 'IMGF_KEEP_ORIGINAL' ) && IMGF_KEEP_ORIGINAL ) && ! empty( $metadata['original_image'] ) ) {
		$orig = $dir . '/' . $metadata['original_image'];
		if ( wp_normalize_path( $orig ) !== wp_normalize_path( $main ) && is_file( $orig ) ) {
			wp_delete_file( $orig );
		}
		unset( $metadata['original_image'] );
	}
	return $metadata;
}

/* Advanced Media Offloader reads the not-yet-saved metadata while uploading; tell it not to look for the deleted original. */
add_filter(
	'advmo_should_upload_original_image',
	function ( $upload ) {
		return ( defined( 'IMGF_KEEP_ORIGINAL' ) && IMGF_KEEP_ORIGINAL ) ? $upload : false;
	}
);

/**
 * Re-encode an AVIF/WebP file until it is <= $cap bytes (binary search on quality, then 15% downscale steps).
 * Returns array( width, height, size ) when the file was replaced, null when nothing changed.
 */
function imgf_shrink_file( string $path, int $cap ): ?array {
	if ( ! is_file( $path ) || filesize( $path ) <= $cap ) {
		return null;
	}
	$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	$mime = array(
		'avif' => 'image/avif',
		'webp' => 'image/webp',
	)[ $ext ] ?? '';
	if ( '' === $mime || ! imgf_can_encode( $mime ) ) {
		return null;
	}
	$editor = wp_get_image_editor( $path );
	if ( is_wp_error( $editor ) ) {
		return null;
	}
	$q_max = (int) imgf_quality( $mime );
	$q_min = min( $q_max, imgf_min_quality( $mime ) );
	$min_w = imgf_const( 'IMGF_MIN_WIDTH', 480 );
	$tmp   = $path . '.imgf-tmp.' . $ext;
	$best  = null; // smallest-quality-loss attempt that fits: array( size, width, height )
	$last  = null; // smallest attempt seen (fallback when the cap cannot be reached)
	$try   = function ( int $q ) use ( $editor, $tmp, $mime, $cap, &$best, &$last ) {
		$editor->set_quality( $q );
		$saved = $editor->save( $tmp, $mime );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return null;
		}
		$size = (int) filesize( $saved['path'] );
		$info = array( 'size' => $size, 'width' => (int) $saved['width'], 'height' => (int) $saved['height'] );
		if ( ! $last || $size < $last['size'] ) {
			copy( $saved['path'], $tmp . '.last' );
			$last = $info;
		}
		if ( $size <= $cap ) {
			copy( $saved['path'], $tmp . '.best' );
			$best = $info;
		}
		return $size;
	};

	// Quality alone (start -> floor) shrinks a file by roughly 2x; if the file is much larger, downscale first.
	$ratio = filesize( $path ) / $cap;
	if ( $ratio > 2.0 ) {
		$cur = $editor->get_size();
		$nw  = (int) floor( $cur['width'] * max( 0.35, min( 1.0, sqrt( 2.0 / $ratio ) ) ) );
		if ( $nw >= $min_w && $nw < $cur['width'] ) {
			$editor->resize( $nw, null, false );
		}
	}
	for ( $round = 0; $round < 6; $round++ ) {
		$probe = $try( $q_min ); // worst quality we accept at this scale
		if ( null === $probe ) {
			break;
		}
		if ( $probe <= $cap ) {
			$lo = $q_min + 1; // it fits: look for the highest quality that still fits
			$hi = $q_max;
			while ( $lo <= $hi ) {
				$mid = intdiv( $lo + $hi, 2 );
				$sz  = $try( $mid );
				if ( null === $sz ) {
					break;
				}
				if ( $sz <= $cap ) {
					$lo = $mid + 1;
				} else {
					$hi = $mid - 1;
				}
			}
			break;
		}
		$cur = $editor->get_size();
		$nw  = (int) floor( $cur['width'] * max( 0.5, min( 0.9, sqrt( $cap / $probe ) * 0.95 ) ) );
		if ( $nw < $min_w || $nw >= $cur['width'] ) {
			break; // cannot go smaller; fall back to the smallest attempt
		}
		$editor->resize( $nw, null, false );
	}

	$result = null;
	$pick   = $best ? $tmp . '.best' : ( $last && $last['size'] < filesize( $path ) ? $tmp . '.last' : '' );
	$info   = $best ?: $last;
	if ( $pick && is_file( $pick ) && $info ) {
		$perms = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( @rename( $pick, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@chmod( $path, $perms ? ( $perms & 0666 ) : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$result = array(
				'width'  => $info['width'],
				'height' => $info['height'],
				'size'   => (int) filesize( $path ),
			);
		}
	}
	foreach ( array( $tmp, $tmp . '.best', $tmp . '.last' ) as $f ) {
		if ( is_file( $f ) ) {
			@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	return $result;
}

/* 5) Optional size policy: downscale on upload and generate only the listed sub-sizes. */
function imgf_only_sizes(): array {
	if ( ! defined( 'IMGF_ONLY_SIZES' ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', explode( ',', (string) IMGF_ONLY_SIZES ) ), 'strlen' ) );
}

add_filter(
	'intermediate_image_sizes_advanced',
	function ( $sizes ) {
		$only = imgf_only_sizes();
		return ( imgf_enabled() && $only ) ? array_intersect_key( $sizes, array_flip( $only ) ) : $sizes;
	},
	99
);

add_filter(
	'big_image_size_threshold',
	function ( $threshold ) {
		return ( imgf_enabled() && imgf_const( 'IMGF_MAX_WIDTH', 0 ) > 0 ) ? false : $threshold; // no separate "-scaled" copy
	},
	20
);

add_filter(
	'wp_handle_upload',
	function ( $upload ) {
		$max = imgf_const( 'IMGF_MAX_WIDTH', 0 );
		if ( ! imgf_enabled() || $max <= 0 || empty( $upload['file'] ) || ! preg_match( '#^image/(jpeg|png|webp|avif)$#', $upload['type'] ?? '' ) ) {
			return $upload;
		}
		$info = @getimagesize( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $info && $info[0] <= $max ) {
			return $upload;
		}
		$editor = wp_get_image_editor( $upload['file'] );
		if ( is_wp_error( $editor ) ) {
			return $upload;
		}
		$size = $editor->get_size();
		if ( $size['width'] > $max ) {
			$editor->resize( $max, null, false );
			$editor->save( $upload['file'] );
		}
		return $upload;
	},
	20
);

/* A theme asks for a size that was not generated: serve the smallest existing size that is at least as wide, else the main file. */
add_filter(
	'image_downsize',
	function ( $out, $id, $size ) {
		if ( $out !== false || ! imgf_enabled() || ! imgf_only_sizes() || ! is_string( $size ) || 'full' === $size ) {
			return $out;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || isset( $meta['sizes'][ $size ] ) ) {
			return false;
		}
		global $_wp_additional_image_sizes;
		$want = 0;
		if ( isset( $_wp_additional_image_sizes[ $size ]['width'] ) ) {
			$want = (int) $_wp_additional_image_sizes[ $size ]['width'];
		} elseif ( in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ), true ) ) {
			$want = (int) get_option( $size . '_size_w' );
		}
		if ( $want <= 0 ) {
			$want = 1; // height-only sizes: any generated size will do
		}
		$pick = null;
		foreach ( $meta['sizes'] as $s ) {
			if ( ! empty( $s['file'] ) && (int) $s['width'] >= $want && ( ! $pick || (int) $s['width'] < (int) $pick['width'] ) ) {
				$pick = $s;
			}
		}
		$url = wp_get_attachment_url( $id );
		if ( ! $pick || ! $url ) {
			return false; // nothing wide enough: WordPress falls back to the main file
		}
		return array( path_join( dirname( $url ), $pick['file'] ), (int) $pick['width'], (int) $pick['height'], true );
	},
	5,
	3
);

/** Delete leftover objects from the bucket (uses S3_Provider::deleteObjects of Advanced Media Offloader). */
function imgf_delete_cloud_keys( array $keys ): void {
	$keys = array_values( array_unique( array_filter( $keys, 'strlen' ) ) );
	if ( ! $keys || ! class_exists( '\Advanced_Media_Offloader\Integrations\Cloudflare_R2' ) ) {
		return;
	}
	$settings = get_option( 'advmo_settings', array() );
	if ( ! empty( $settings['path_prefix_active'] ) || ! empty( $settings['object_versioning'] ) ) {
		error_log( 'Img Formats: bucket cleanup skipped because a path prefix / object versioning is enabled' );
		return;
	}
	try {
		$provider = new \Advanced_Media_Offloader\Integrations\Cloudflare_R2();
		$provider->deleteObjects( $keys );
	} catch ( \Throwable $e ) {
		error_log( 'Img Formats: bucket cleanup failed: ' . $e->getMessage() );
	}
}

/** Fetch the original file back from the public bucket domain into its local path. */
function imgf_fetch_from_cloud( int $attachment_id, string $local_path ): bool {
	if ( ! get_post_meta( $attachment_id, 'advmo_offloaded', true ) || ! class_exists( '\Advanced_Media_Offloader\Integrations\Cloudflare_R2' ) ) {
		return false;
	}
	$settings = get_option( 'advmo_settings', array() );
	if ( ! empty( $settings['path_prefix_active'] ) || ! empty( $settings['object_versioning'] ) ) {
		return false; // the key cannot be derived from the path
	}
	try {
		$domain = ( new \Advanced_Media_Offloader\Integrations\Cloudflare_R2() )->getDomain();
	} catch ( \Throwable $e ) {
		return false;
	}
	if ( ! $domain ) {
		return false;
	}
	$key = _wp_relative_upload_path( $local_path );
	$url = $domain . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$tmp  = wp_tempnam( basename( $local_path ) );
	$resp = wp_remote_get( $url, array( 'timeout' => 60, 'stream' => true, 'filename' => $tmp, 'redirection' => 3 ) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) || ! is_file( $tmp ) || ! filesize( $tmp ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		error_log( "Img Formats: download of $url failed" );
		return false;
	}
	wp_mkdir_p( dirname( $local_path ) );
	if ( ! @rename( $tmp, $local_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! @copy( $tmp, $local_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	@chmod( $local_path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return true;
}

/* WP-CLI: wp imgf shrink [--id=<id>] [--dry-run] */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'imgf shrink',
		function ( $args, $assoc ) {
			global $wpdb;
			$cap = imgf_max_bytes();
			if ( ! $cap ) {
				WP_CLI::error( 'IMGF_MAX_KB is 0 (no limit).' );
			}
			$ids = isset( $assoc['id'] )
				? array( (int) $assoc['id'] )
				: $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/avif','image/webp') ORDER BY ID" );
			$dry = ! empty( $assoc['dry-run'] );
			$changed = 0;
			$missing = 0;
			foreach ( $ids as $id ) {
				$meta = wp_get_attachment_metadata( (int) $id );
				$file = get_attached_file( (int) $id, true );
				if ( ! is_array( $meta ) || ! $file ) {
					continue;
				}
				$dir     = dirname( $file );
				$targets = array( $file );
				foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
					$targets[] = $dir . '/' . $s['file'];
				}
				$over = 0;
				foreach ( $targets as $t ) {
					if ( ! is_file( $t ) ) {
						++$missing;
					} elseif ( filesize( $t ) > $cap ) {
						++$over;
					}
				}
				if ( ! $over ) {
					continue;
				}
				if ( $dry ) {
					WP_CLI::log( "#$id: $over file(s) over the limit" );
					continue;
				}
				$new = imgf_enforce_size_cap( $meta, (int) $id );
				if ( $new !== $meta ) {
					wp_update_attachment_metadata( (int) $id, $new );
					++$changed;
				}
			}
			WP_CLI::success( "Attachments updated: $changed; files not available locally: $missing" );
		}
	);
}
