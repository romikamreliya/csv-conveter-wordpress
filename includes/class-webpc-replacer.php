<?php
defined( 'ABSPATH' ) || exit;

/**
 * Replaces old image paths (jpg/png) with the new .webp paths in the database:
 * post content/excerpt, post meta (Elementor JSON, page builders, ACF, Rank Math...), term meta,
 * options (theme mods, widgets, Woo email header...) and Yoast SEO indexables.
 * Serialized values are unserialized, replaced and re-serialized safely.
 */
class WebPC_Replacer {

	const SKIP_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_webpc_backup', '_webpc_stats', '_webpc_lock', '_wp_attachment_backup_sizes' );

	/**
	 * @param array $map old relative upload path => new relative upload path, e.g. '2024/05/a.jpg' => '2024/05/a.webp'.
	 * @return int[] IDs of posts whose content/meta changed.
	 */
	public static function replace( array $map ) {
		global $wpdb;

		// "uploads/2024/05/a.jpg" – the uploads folder name keeps matches specific (never touches theme assets with the same file name).
		$prefix = wp_basename( untrailingslashit( (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH ) ) );
		$prefix = $prefix ? $prefix . '/' : '';

		$pairs = array();
		$olds  = array();
		foreach ( $map as $old => $new ) {
			if ( $old === $new ) {
				continue;
			}
			$old = $prefix . ltrim( $old, '/' );
			$new = $prefix . ltrim( $new, '/' );

			$olds[]        = $old;
			$pairs[ $old ] = $new;
			// JSON escaped slashes (Elementor, Gutenberg attributes...).
			$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
		}
		if ( ! $pairs ) {
			return array();
		}

		// Longest first so "a-300x300.jpg" never gets half-replaced by a shorter key.
		uksort( $pairs, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		// One cheap LIKE on the common prefix of all files ("uploads/2024/05/photo") instead of one per size.
		$common = self::common_prefix( $olds );
		$likes  = array(
			'%' . $wpdb->esc_like( $common ) . '%',
			'%' . $wpdb->esc_like( str_replace( '/', '\\/', $common ) ) . '%',
		);

		$posts   = array();
		$options = false;

		// Posts.
		foreach ( array( 'post_content', 'post_excerpt' ) as $col ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, $col AS v FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND ( $col LIKE %s OR $col LIKE %s )", $likes ) ); // phpcs:ignore
			foreach ( $rows as $row ) {
				$new = self::replace_value( $row->v, $pairs );
				if ( $new !== $row->v ) {
					$wpdb->update( $wpdb->posts, array( $col => $new ), array( 'ID' => $row->ID ) );
					$posts[] = (int) $row->ID;
				}
			}
		}

		// Post meta.
		$skip = "'" . implode( "','", array_map( 'esc_sql', self::SKIP_META ) ) . "'";
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value AS v FROM {$wpdb->postmeta} WHERE meta_key NOT IN ($skip) AND ( meta_value LIKE %s OR meta_value LIKE %s )", $likes ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$new = self::replace_value( $row->v, $pairs );
			if ( $new !== $row->v ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => $row->meta_id ) );
				$posts[] = (int) $row->post_id;
			}
		}

		// Term meta.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, term_id, meta_value AS v FROM {$wpdb->termmeta} WHERE meta_value LIKE %s OR meta_value LIKE %s", $likes ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$new = self::replace_value( $row->v, $pairs );
			if ( $new !== $row->v ) {
				$wpdb->update( $wpdb->termmeta, array( 'meta_value' => $new ), array( 'meta_id' => $row->meta_id ) );
				wp_cache_delete( $row->term_id, 'term_meta' );
			}
		}

		// Options (theme mods, widgets, customizer, Woo email header image...).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value AS v FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name <> %s AND ( option_value LIKE %s OR option_value LIKE %s )", array_merge( array( $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', WebPC_Settings::OPTION ), $likes ) ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$new = self::replace_value( $row->v, $pairs );
			if ( $new !== $row->v ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => $new ), array( 'option_id' => $row->option_id ) );
				wp_cache_delete( $row->option_name, 'options' );
				$options = true;
			}
		}
		if ( $options ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		// Yoast SEO keeps og:image / twitter:image URLs in its own table.
		$yoast = $wpdb->prefix . 'yoast_indexable';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $yoast ) ) ) === $yoast ) {
			foreach ( array( 'open_graph_image', 'twitter_image', 'open_graph_image_meta' ) as $col ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, $col AS v FROM $yoast WHERE $col LIKE %s OR $col LIKE %s", $likes ) ); // phpcs:ignore
				foreach ( $rows as $row ) {
					$new = self::replace_value( $row->v, $pairs );
					if ( $new !== $row->v ) {
						$wpdb->update( $yoast, array( $col => $new ), array( 'id' => $row->id ) );
					}
				}
			}
		}

		$posts = array_values( array_unique( $posts ) );
		self::purge_posts( $posts );
		if ( $options ) {
			self::purge_elementor_global();
		}

		return $posts;
	}

	private static function common_prefix( array $strings ) {
		$prefix = array_shift( $strings );
		foreach ( $strings as $s ) {
			$len = min( strlen( $prefix ), strlen( $s ) );
			$i   = 0;
			while ( $i < $len && $prefix[ $i ] === $s[ $i ] ) {
				$i++;
			}
			$prefix = substr( $prefix, 0, $i );
		}
		// Must still contain at least the folder + a few chars of the file name.
		return strlen( $prefix ) >= 4 ? $prefix : '.';
	}

	private static function replace_value( $value, $pairs ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ) ); // phpcs:ignore
			if ( false === $data && 'b:0;' !== trim( $value ) ) {
				return $value; // Broken serialization – never touch.
			}
			$new = self::replace_deep( $data, $pairs );
			return $new === $data ? $value : serialize( $new ); // phpcs:ignore
		}
		return strtr( $value, $pairs );
	}

	private static function replace_deep( $data, $pairs ) {
		if ( is_string( $data ) ) {
			if ( is_serialized( $data ) ) {
				return self::replace_value( $data, $pairs );
			}
			return strtr( $data, $pairs );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::replace_deep( $v, $pairs );
			}
			return $data;
		}
		if ( $data instanceof \stdClass ) {
			$data = clone $data;
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = self::replace_deep( $v, $pairs );
			}
		}
		// Other objects are left untouched (never risk breaking unknown classes).
		return $data;
	}

	/* ------------------------------------------------------------------ */
	/* Cache purging – targeted, never a full object-cache flush           */
	/* ------------------------------------------------------------------ */

	public static function purge_posts( array $ids ) {
		foreach ( $ids as $id ) {
			// Elementor: regenerate this post's CSS + rendered element cache.
			delete_post_meta( $id, '_elementor_css' );
			delete_post_meta( $id, '_elementor_element_cache' );
			delete_post_meta( $id, '_elementor_page_assets' );

			clean_post_cache( $id );
			wp_cache_delete( $id, 'post_meta' );

			if ( function_exists( 'wc_delete_product_transients' ) && in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ) {
				wc_delete_product_transients( $id );
			}

			// Page cache plugins.
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}
			if ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $id );
			}
			do_action( 'litespeed_purge_post', $id );
			do_action( 'webpc_purge_post', $id );
		}
	}

	public static function purge_elementor_global() {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/**
	 * Called once at the end of a bulk / product run: archive pages, shop, home... show product
	 * thumbnails by ID, so their cached HTML must be refreshed too.
	 */
	public static function purge_all_page_caches() {
		self::purge_elementor_global();
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'breeze_clear_all_cache' );
		do_action( 'webpc_purge_all' );
	}
}
