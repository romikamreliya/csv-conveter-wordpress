<?php
defined( 'ABSPATH' ) || exit;

/**
 * Converts a whole attachment (full image + every generated size) to WebP,
 * updates WP metadata, replaces live URLs and finally moves originals to a backup folder.
 */
class WebPC_Attachment {

	const MIMES      = array( 'image/jpeg', 'image/png' );
	const BACKUP_DIR = 'webpc-backup';

	public static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 999, 3 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
		add_action( 'init', array( __CLASS__, 'redirect_old_url' ), 0 );
	}

	/* ------------------------------------------------------------------ */
	/* Status helpers                                                      */
	/* ------------------------------------------------------------------ */

	public static function is_convertible( $id ) {
		return in_array( get_post_mime_type( $id ), self::MIMES, true );
	}

	public static function is_converted( $id ) {
		return (bool) get_post_meta( $id, '_webpc_stats', true );
	}

	public static function stats( $id ) {
		$s = get_post_meta( $id, '_webpc_stats', true );
		if ( ! is_array( $s ) ) {
			return array();
		}

		// Images converted with v1.1 or older: work out the main image sizes after the fact.
		if ( ! isset( $s['main_orig'] ) ) {
			$backup = get_post_meta( $id, '_webpc_backup', true );
			if ( is_array( $backup ) && ! empty( $backup['linked_to'] ) ) {
				$backup = get_post_meta( (int) $backup['linked_to'], '_webpc_backup', true );
			}
			$orig = 0;
			if ( is_array( $backup ) && ! empty( $backup['attached_file'] ) ) {
				$orig = (int) ( $backup['meta']['filesize'] ?? 0 );
				$copy = self::basedir() . '/' . self::BACKUP_DIR . '/' . $backup['attached_file'];
				if ( ! $orig && file_exists( $copy ) ) {
					$orig = (int) filesize( $copy );
				}
			}
			$file = get_attached_file( $id, true );
			if ( $orig && $file && file_exists( $file ) ) {
				$s['main_orig'] = $orig;
				$s['main_new']  = (int) filesize( $file );
			}
		}
		return $s;
	}

	public static function has_backup( $id ) {
		$b = get_post_meta( $id, '_webpc_backup', true );
		return is_array( $b ) && ! empty( $b['backup'] ) && empty( $b['linked_to'] );
	}

	private static function lock( $id ) {
		if ( add_post_meta( $id, '_webpc_lock', time(), true ) ) {
			return true;
		}
		// Stale lock (crashed request) older than 5 minutes.
		if ( (int) get_post_meta( $id, '_webpc_lock', true ) < time() - 300 ) {
			delete_post_meta( $id, '_webpc_lock' );
			return (bool) add_post_meta( $id, '_webpc_lock', time(), true );
		}
		return false;
	}

	private static function unlock( $id ) {
		delete_post_meta( $id, '_webpc_lock' );
	}

	private static function basedir() {
		return wp_normalize_path( untrailingslashit( wp_get_upload_dir()['basedir'] ) );
	}

	/**
	 * Other attachment posts pointing at the very same file (WPML / Polylang media translations, duplicates).
	 */
	private static function linked_ids( $id, $rel_file ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d",
			$rel_file,
			$id
		) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Convert                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array|WP_Error
	 */
	public static function convert( $id ) {
		$id = (int) $id;
		if ( ! self::lock( $id ) ) {
			return new WP_Error( 'webpc_busy', 'This image is already being converted.' );
		}
		try {
			$res = self::do_convert( $id );
		} catch ( \Throwable $e ) {
			$res = new WP_Error( 'webpc_exception', $e->getMessage() );
		}
		self::unlock( $id );
		return $res;
	}

	private static function do_convert( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'webpc_missing', 'Attachment not found.' );
		}

		$mime = get_post_mime_type( $id );
		if ( 'image/webp' === $mime ) {
			return new WP_Error( 'webpc_already', 'Already WebP.' );
		}
		if ( ! in_array( $mime, self::MIMES, true ) ) {
			return new WP_Error( 'webpc_type', 'Only JPG / PNG images can be converted.' );
		}

		$basedir  = self::basedir();
		$rel_file = get_post_meta( $id, '_wp_attached_file', true );
		$file     = get_attached_file( $id, true );
		$meta     = wp_get_attachment_metadata( $id, true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'webpc_nofile', 'Original file is missing on disk (offloaded to S3/CDN?).' );
		}

		$file = wp_normalize_path( $file );
		if ( 0 !== strpos( $file, $basedir . '/' ) ) {
			return new WP_Error( 'webpc_outside', 'File is outside the uploads folder – skipped.' );
		}

		$dir     = dirname( $file );
		$rel_dir = ltrim( substr( $dir, strlen( $basedir ) ), '/' );
		$rel_dir = $rel_dir ? $rel_dir . '/' : '';

		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'webpc_perm', 'Uploads folder is not writable: ' . $rel_dir );
		}

		// Collect every physical file: basename => role.
		$files = array( wp_basename( $file ) => 'full' );
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && ! isset( $files[ $size['file'] ] ) ) {
					$files[ $size['file'] ] = 'size';
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) && ! isset( $files[ $meta['original_image'] ] ) ) {
			$files[ $meta['original_image'] ] = 'original';
		}

		$settings = WebPC_Settings::get();
		$created  = array(); // old basename => new basename
		$results  = array();
		$orig_tot = 0;
		$new_tot  = 0;
		$engine   = '';

		// 1) Create all WebP files next to the originals (originals untouched so far).
		foreach ( $files as $name => $role ) {
			$src = $dir . '/' . $name;
			if ( ! file_exists( $src ) ) {
				continue; // Missing size – nothing to serve anyway.
			}

			$new_name = self::webp_name( $dir, $name, $created );
			$res      = WebPC_Converter::convert( $src, $dir . '/' . $new_name, array(
				'quality'    => $settings['quality'],
				'max_width'  => $settings['max_width'],
				'max_height' => $settings['max_height'],
				'resize'     => ( 'size' !== $role ),
			) );

			if ( is_wp_error( $res ) ) {
				foreach ( $created as $n ) {
					@unlink( $dir . '/' . $n );
				}
				return $res;
			}

			$created[ $name ] = $new_name;
			$results[ $name ] = $res;
			$orig_tot        += (int) filesize( $src );
			$new_tot         += $res['size'];
			$engine           = $res['engine'];
		}

		$main = wp_basename( $file );
		if ( ! isset( $created[ $main ] ) ) {
			return new WP_Error( 'webpc_failed', 'Main image could not be converted.' );
		}
		$main_orig = (int) filesize( $file ); // The size WordPress shows as "File size".

		// 2) Build new metadata.
		$new_meta             = $meta;
		$new_meta['file']     = $rel_dir . $created[ $main ];
		$new_meta['width']    = $results[ $main ]['width'];
		$new_meta['height']   = $results[ $main ]['height'];
		$new_meta['filesize'] = $results[ $main ]['size'];
		if ( ! empty( $new_meta['sizes'] ) ) {
			foreach ( $new_meta['sizes'] as $k => $size ) {
				if ( isset( $created[ $size['file'] ] ) ) {
					$new_meta['sizes'][ $k ]['file']      = $created[ $size['file'] ];
					$new_meta['sizes'][ $k ]['mime-type'] = 'image/webp';
					$new_meta['sizes'][ $k ]['filesize']  = $results[ $size['file'] ]['size'];
				}
			}
		}
		if ( ! empty( $new_meta['original_image'] ) && isset( $created[ $new_meta['original_image'] ] ) ) {
			$new_meta['original_image'] = $created[ $new_meta['original_image'] ];
		}

		$linked = self::linked_ids( $id, $rel_file );
		$backup = array(
			'attached_file' => $rel_file,
			'meta'          => $meta,
			'mime'          => $mime,
			'guid'          => $post->guid,
			'rel_dir'       => $rel_dir,
			'files'         => $created,
			'backup'        => ! empty( $settings['keep_backup'] ),
			'replaced'      => ! empty( $settings['replace_urls'] ),
			'linked'        => array(),
		);

		// 3) Point the attachment (and any WPML/Polylang copies) to the WebP files.
		global $wpdb;
		foreach ( array_merge( array( $id ), $linked ) as $aid ) {
			$apost = get_post( $aid );
			if ( $aid !== $id ) {
				$backup['linked'][ $aid ] = array(
					'meta' => wp_get_attachment_metadata( $aid, true ),
					'mime' => $apost->post_mime_type,
					'guid' => $apost->guid,
				);
			}
			update_post_meta( $aid, '_wp_attached_file', $rel_dir . $created[ $main ] );
			wp_update_attachment_metadata( $aid, $new_meta );
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_mime_type' => 'image/webp',
					'guid'           => self::swap_guid( $apost->guid, $created ),
				),
				array( 'ID' => $aid )
			);
			clean_attachment_cache( $aid );
			clean_post_cache( $aid );
		}
		update_post_meta( $id, '_webpc_backup', $backup );

		// 4) Replace live paths everywhere.
		$replaced = array();
		if ( $backup['replaced'] ) {
			$map = array();
			foreach ( $created as $old => $new ) {
				$map[ $rel_dir . $old ] = $rel_dir . $new;
			}
			$replaced = WebPC_Replacer::replace( $map );
		}

		// 5) Only now move originals away – if anything above had crashed, the old files would still be served.
		$bdir = $basedir . '/' . self::BACKUP_DIR . '/' . rtrim( $rel_dir, '/' );
		if ( $backup['backup'] ) {
			wp_mkdir_p( $bdir );
			self::protect_backup_dir( $basedir . '/' . self::BACKUP_DIR );
		}
		foreach ( array_keys( $created ) as $name ) {
			$src = $dir . '/' . $name;
			if ( $backup['backup'] ) {
				if ( ! @rename( $src, $bdir . '/' . $name ) && @copy( $src, $bdir . '/' . $name ) ) {
					@unlink( $src );
				}
			} else {
				@unlink( $src );
			}
		}

		$stats = array(
			'main_orig' => $main_orig,
			'main_new'  => $results[ $main ]['size'],
			'orig'     => $orig_tot,
			'new'      => $new_tot,
			'files'    => count( $created ),
			'engine'   => $engine,
			'replaced' => count( $replaced ),
			'time'     => time(),
		);
		update_post_meta( $id, '_webpc_stats', $stats );
		foreach ( $linked as $aid ) {
			update_post_meta( $aid, '_webpc_stats', $stats );
			update_post_meta( $aid, '_webpc_backup', array( 'linked_to' => $id ) );
		}

		do_action( 'webpc_converted', $id, $stats );

		return $stats;
	}

	/**
	 * Pick a free .webp name next to the source (handles image.jpg + image.png in the same folder).
	 */
	private static function webp_name( $dir, $name, $taken ) {
		$info = pathinfo( $name );
		$base = $info['filename'];
		$cand = $base . '.webp';
		$i    = 1;
		while ( file_exists( $dir . '/' . $cand ) || in_array( $cand, $taken, true ) ) {
			$cand = $base . '-' . strtolower( $info['extension'] ) . ( $i > 1 ? '-' . $i : '' ) . '.webp';
			$i++;
		}
		return $cand;
	}

	private static function swap_guid( $guid, $created ) {
		foreach ( $created as $old => $new ) {
			if ( '/' . $old === substr( $guid, -strlen( '/' . $old ) ) ) {
				return substr( $guid, 0, -strlen( $old ) ) . $new;
			}
		}
		return $guid;
	}

	/**
	 * Backups folder must not be browsable.
	 */
	private static function protect_backup_dir( $dir ) {
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Restore                                                             */
	/* ------------------------------------------------------------------ */

	public static function restore( $id ) {
		$id     = (int) $id;
		$backup = get_post_meta( $id, '_webpc_backup', true );
		if ( is_array( $backup ) && ! empty( $backup['linked_to'] ) ) {
			return self::restore( (int) $backup['linked_to'] );
		}
		if ( ! is_array( $backup ) || empty( $backup['backup'] ) ) {
			return new WP_Error( 'webpc_nobackup', 'No backup available for this image.' );
		}
		if ( ! self::lock( $id ) ) {
			return new WP_Error( 'webpc_busy', 'This image is being processed right now.' );
		}

		$basedir = self::basedir();
		$rel_dir = $backup['rel_dir'];
		$dir     = $basedir . '/' . rtrim( $rel_dir, '/' );
		$bdir    = $basedir . '/' . self::BACKUP_DIR . '/' . rtrim( $rel_dir, '/' );

		foreach ( $backup['files'] as $old => $new ) {
			if ( ! file_exists( $bdir . '/' . $old ) && ! file_exists( $dir . '/' . $old ) ) {
				self::unlock( $id );
				return new WP_Error( 'webpc_nobackup', sprintf( 'Backup file missing: %s', $old ) );
			}
		}

		// 1) Originals back in place (WebP files stay until the end, so nothing is ever broken).
		foreach ( $backup['files'] as $old => $new ) {
			if ( file_exists( $bdir . '/' . $old ) && ! @rename( $bdir . '/' . $old, $dir . '/' . $old ) && @copy( $bdir . '/' . $old, $dir . '/' . $old ) ) {
				@unlink( $bdir . '/' . $old );
			}
		}

		// 2) Database.
		global $wpdb;
		$all = array( $id => array( 'meta' => $backup['meta'], 'mime' => $backup['mime'], 'guid' => $backup['guid'] ) ) + (array) ( $backup['linked'] ?? array() );
		foreach ( $all as $aid => $data ) {
			update_post_meta( $aid, '_wp_attached_file', $backup['attached_file'] );
			wp_update_attachment_metadata( $aid, $data['meta'] );
			$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $data['mime'], 'guid' => $data['guid'] ), array( 'ID' => $aid ) );
			delete_post_meta( $aid, '_webpc_backup' );
			delete_post_meta( $aid, '_webpc_stats' );
			clean_attachment_cache( $aid );
			clean_post_cache( $aid );
		}

		if ( $backup['replaced'] ?? WebPC_Settings::get( 'replace_urls' ) ) {
			$map = array();
			foreach ( $backup['files'] as $old => $new ) {
				$map[ $rel_dir . $new ] = $rel_dir . $old;
			}
			WebPC_Replacer::replace( $map );
		}

		// 3) Remove the WebP files.
		foreach ( $backup['files'] as $old => $new ) {
			@unlink( $dir . '/' . $new );
		}

		self::unlock( $id );
		do_action( 'webpc_restored', $id );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Hooks                                                               */
	/* ------------------------------------------------------------------ */

	public static function on_upload( $metadata, $id, $context = 'create' ) {
		if ( 'create' !== $context || ! WebPC_Settings::get( 'auto_upload' ) || ! self::is_convertible( $id ) ) {
			return $metadata;
		}
		// Persist the freshly generated sizes first so convert() sees them.
		wp_update_attachment_metadata( $id, $metadata );
		$res = self::convert( $id );
		if ( is_wp_error( $res ) ) {
			return $metadata;
		}
		return wp_get_attachment_metadata( $id, true );
	}

	public static function on_delete( $id ) {
		$backup = get_post_meta( $id, '_webpc_backup', true );
		if ( ! is_array( $backup ) || empty( $backup['backup'] ) || empty( $backup['files'] ) ) {
			return;
		}
		// Translations still using the same file keep the backup.
		if ( self::linked_ids( $id, get_post_meta( $id, '_wp_attached_file', true ) ) ) {
			return;
		}
		$bdir = self::basedir() . '/' . self::BACKUP_DIR . '/' . rtrim( $backup['rel_dir'], '/' );
		foreach ( array_keys( $backup['files'] ) as $old ) {
			@unlink( $bdir . '/' . $old );
		}
	}

	/**
	 * Safety net: anything still requesting the old .jpg/.png (page cache, CDN, Google Images,
	 * social shares, hard-coded theme CSS) is redirected to the new .webp instead of a 404.
	 * Works whenever missing upload files are routed to WordPress (Apache/LiteSpeed default .htaccess).
	 */
	public static function redirect_old_url() {
		if ( ! WebPC_Settings::get( 'redirect_old' ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore
		if ( ! preg_match( '/\.(jpe?g|png)$/i', $path ) ) {
			return;
		}

		$uploads = wp_get_upload_dir();
		$base    = untrailingslashit( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) );
		$path    = rawurldecode( $path );
		if ( 0 !== strpos( $path, $base . '/' ) ) {
			return;
		}
		$rel = substr( $path, strlen( $base ) + 1 );
		if ( false !== strpos( $rel, '..' ) || file_exists( $uploads['basedir'] . '/' . $rel ) ) {
			return;
		}

		$info = pathinfo( $rel );
		$dir  = ( '.' === $info['dirname'] ) ? '' : $info['dirname'] . '/';
		foreach ( array( $info['filename'] . '-' . strtolower( $info['extension'] ) . '.webp', $info['filename'] . '.webp' ) as $cand ) {
			if ( file_exists( $uploads['basedir'] . '/' . $dir . $cand ) ) {
				wp_redirect( $uploads['baseurl'] . '/' . $dir . $cand, (int) apply_filters( 'webpc_redirect_status', 302 ) ); // phpcs:ignore
				exit;
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* WooCommerce product helpers                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * All image attachment IDs used by a product: featured, gallery, variations, description images.
	 */
	public static function product_image_ids( $product_id ) {
		$ids = array();

		if ( function_exists( 'wc_get_product' ) && ( $product = wc_get_product( $product_id ) ) ) {
			$ids[] = $product->get_image_id();
			$ids   = array_merge( $ids, $product->get_gallery_image_ids() );

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $vid ) {
					$ids[] = get_post_thumbnail_id( $vid );
				}
			}
			$content = $product->get_description() . ' ' . $product->get_short_description();
		} else {
			$ids[]   = get_post_thumbnail_id( $product_id );
			$content = (string) get_post_field( 'post_content', $product_id );
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $src ) {
				$aid = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $src ) );
				if ( $aid ) {
					$ids[] = $aid;
				}
			}
		}

		$ids = array_unique( array_filter( array_map( 'intval', $ids ) ) );
		return array_values( array_filter( $ids, 'wp_attachment_is_image' ) );
	}
}
