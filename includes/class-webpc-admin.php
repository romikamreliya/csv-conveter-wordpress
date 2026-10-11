<?php
defined( 'ABSPATH' ) || exit;

class WebPC_Admin {

	const SLUG = 'webp-converter';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'option_page_capability_webpc_settings_group', array( __CLASS__, 'cap' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_filter( 'plugin_action_links_' . plugin_basename( WEBPC_FILE ), array( __CLASS__, 'action_links' ) );

		// AJAX.
		foreach ( array( 'queue', 'convert', 'restore', 'product_images', 'stats', 'finish' ) as $a ) {
			add_action( 'wp_ajax_webpc_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}

		// Media library list column + attachment modal field.
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column_content' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_field' ), 10, 2 );

		// WooCommerce product screens.
		add_action( 'add_meta_boxes', array( __CLASS__, 'product_metabox' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'product_row_action' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'product_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_product_bulk' ), 10, 3 );
	}

	/**
	 * Plugin page, bulk + product conversion, settings: shop managers / admins only.
	 */
	public static function cap() {
		return apply_filters( 'webpc_capability', class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' );
	}

	/**
	 * Converting / restoring one image from the media library (plus an edit_post check per image).
	 */
	public static function media_cap() {
		return apply_filters( 'webpc_media_capability', 'upload_files' );
	}

	/* ------------------------------------------------------------------ */
	/* Menu / assets                                                       */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_menu_page( 'WebP Converter', 'WebP Converter', self::cap(), self::SLUG, array( __CLASS__, 'page' ), 'dashicons-format-image', 58 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ) . '">Settings</a>' );
		return $links;
	}

	public static function register_settings() {
		register_setting( 'webpc_settings_group', WebPC_Settings::OPTION, array(
			'sanitize_callback' => array( 'WebPC_Settings', 'sanitize' ),
		) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$ours   = 'toplevel_page_' . self::SLUG === $hook;
		$media  = $screen && in_array( $screen->id, array( 'upload', 'attachment' ), true );
		$prod   = $screen && ( in_array( $screen->id, array( 'product', 'edit-product' ), true ) || 'post' === $screen->base ); // media modal on edit screens

		if ( ! $ours && ! $media && ! $prod ) {
			return;
		}

		wp_enqueue_style( 'webpc-admin', WEBPC_URL . 'assets/admin.css', array(), WEBPC_VERSION );
		$deps = array( 'jquery' );
		if ( $ours && wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			$deps[] = 'wc-enhanced-select';
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
		wp_enqueue_script( 'webpc-admin', WEBPC_URL . 'assets/admin.js', $deps, WEBPC_VERSION, true );
		wp_localize_script( 'webpc-admin', 'WEBPC', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'webpc' ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Main page                                                           */
	/* ------------------------------------------------------------------ */

	public static function page() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'bulk'; // phpcs:ignore
		$tabs = array( 'bulk' => 'Bulk Convert', 'product' => 'Single Product', 'settings' => 'Settings' );
		if ( ! class_exists( 'WooCommerce' ) ) {
			unset( $tabs['product'] );
		}
		?>
		<div class="wrap webpc-wrap">
			<h1>WebP Converter &amp; Compressor</h1>
			<?php settings_errors(); ?>
			<?php self::engine_notice(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="webpc-tab">
				<?php
				if ( 'settings' === $tab ) {
					self::tab_settings();
				} elseif ( 'product' === $tab && isset( $tabs['product'] ) ) {
					self::tab_product();
				} else {
					self::tab_bulk();
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function engine_notice() {
		$engines = WebPC_Converter::available_engines();
		if ( empty( $engines ) ) {
			echo '<div class="notice notice-error"><p><strong>No WebP engine found.</strong> Install <code>cwebp</code> (libwebp), Imagick with WebP, or PHP GD with WebP support.</p></div>';
		}
	}

	private static function tab_bulk() {
		$s     = self::library_stats();
		$queue = array();
		if ( ! empty( $_GET['queue'] ) ) { // phpcs:ignore
			$queue = array_filter( (array) get_transient( 'webpc_q_' . get_current_user_id() ) );
			delete_transient( 'webpc_q_' . get_current_user_id() );
			echo '<div class="notice notice-info"><p>' . count( $queue ) . ' JPG/PNG image(s) from the selected products are queued – conversion starts automatically.</p></div>';
		}
		?>
		<div class="webpc-cards">
			<div class="webpc-card"><span class="num" data-stat="pending"><?php echo (int) $s['pending']; ?></span><span>JPG / PNG left</span></div>
			<div class="webpc-card"><span class="num" data-stat="converted"><?php echo (int) $s['converted']; ?></span><span>Converted to WebP</span></div>
			<div class="webpc-card"><span class="num" data-stat="saved"><?php echo esc_html( size_format( $s['saved'], 1 ) ?: '0 B' ); ?></span><span>Space saved</span></div>
			<div class="webpc-card"><span class="num" data-stat="percent"><?php echo (int) $s['percent']; ?>%</span><span>Average reduction</span></div>
		</div>

		<div class="webpc-box">
			<h2>Bulk convert</h2>
			<p>Every JPG/PNG (full image + all thumbnail sizes) is converted to WebP, compressed, and every live URL is replaced (posts, products, Elementor, options).</p>
			<p>
				<label><input type="radio" name="webpc_scope" value="all" checked> All media images</label>&nbsp;&nbsp;
				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
					<label><input type="radio" name="webpc_scope" value="products"> Only WooCommerce product images</label>
				<?php endif; ?>
			</p>
			<p>
				<button class="button button-primary button-hero" id="webpc-bulk-start" data-queue="<?php echo esc_attr( wp_json_encode( array_map( 'intval', $queue ) ) ); ?>">Start Conversion</button>
				<button class="button button-hero" id="webpc-bulk-stop" style="display:none">Pause</button>
			</p>
			<?php self::progress_markup( 'bulk' ); ?>
		</div>
		<?php
	}

	private static function tab_product() {
		$pid = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore
		$auto = ! empty( $_GET['autostart'] ); // phpcs:ignore
		?>
		<div class="webpc-box">
			<h2>Convert images of a single product</h2>
			<p>Select a product – only its images (featured, gallery, variation images and images inside the description) will be converted, and their paths replaced.</p>
			<p>
				<select id="webpc-product" class="wc-product-search" style="width:420px" data-placeholder="Search for a product…" data-action="woocommerce_json_search_products" data-allow_clear="true">
					<?php if ( $pid && ( $p = wc_get_product( $pid ) ) ) : ?>
						<option value="<?php echo (int) $pid; ?>" selected><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				</select>
				<button class="button button-primary" id="webpc-product-convert" disabled>Convert this product's images</button>
			</p>
			<div id="webpc-product-images" class="webpc-grid" data-autostart="<?php echo $auto ? '1' : '0'; ?>"></div>
			<?php self::progress_markup( 'product' ); ?>
		</div>
		<?php
	}

	private static function progress_markup( $ctx ) {
		?>
		<div class="webpc-progress" id="webpc-progress-<?php echo esc_attr( $ctx ); ?>" style="display:none">
			<div class="webpc-bar"><span></span></div>
			<p class="webpc-progress-text"></p>
			<ul class="webpc-log"></ul>
		</div>
		<?php
	}

	private static function tab_settings() {
		$o       = WebPC_Settings::get();
		$engines = WebPC_Converter::available_engines();
		$name    = WebPC_Settings::OPTION;
		?>
		<form method="post" action="options.php" class="webpc-box">
			<?php settings_fields( 'webpc_settings_group' ); ?>
			<table class="form-table">
				<tr>
					<th>WebP quality</th>
					<td>
						<input type="range" min="30" max="100" name="<?php echo esc_attr( $name ); ?>[quality]" value="<?php echo (int) $o['quality']; ?>" oninput="this.nextElementSibling.textContent=this.value">
						<strong><?php echo (int) $o['quality']; ?></strong>
						<p class="description">Recommended <strong>75–82</strong>: up to 80–95% smaller with no visible loss of clarity. Sharp-YUV + best compression method is always used.</p>
					</td>
				</tr>
				<tr>
					<th>Max dimensions (full image)</th>
					<td>
						<input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[max_width]" value="<?php echo (int) $o['max_width']; ?>" class="small-text"> ×
						<input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[max_height]" value="<?php echo (int) $o['max_height']; ?>" class="small-text"> px
						<p class="description">Big camera photos (e.g. 2 MB+, 4000px+) are scaled down to fit. <code>0</code> = keep original dimensions. Thumbnail sizes are never resized.</p>
					</td>
				</tr>
				<tr>
					<th>PNG mode</th>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[png_mode]">
							<option value="lossy" <?php selected( $o['png_mode'], 'lossy' ); ?>>Lossy (smallest, transparency kept)</option>
							<option value="lossless" <?php selected( $o['png_mode'], 'lossless' ); ?>>Lossless (pixel perfect – logos / text)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Engine</th>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[engine]">
							<option value="auto" <?php selected( $o['engine'], 'auto' ); ?>>Auto (best available)</option>
							<?php foreach ( array( 'cwebp' => 'cwebp (libwebp binary)', 'imagick' => 'Imagick', 'gd' => 'GD' ) as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['engine'], $k ); ?> <?php disabled( ! in_array( $k, $engines, true ) ); ?>><?php echo esc_html( $l . ( in_array( $k, $engines, true ) ? '' : ' – not available' ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Available on this server: <strong><?php echo esc_html( $engines ? implode( ', ', $engines ) : 'none' ); ?></strong><?php echo WebPC_Converter::cwebp_binary() ? ' &nbsp;(cwebp: <code>' . esc_html( WebPC_Converter::cwebp_binary() ) . '</code>)' : ''; ?></p>
					</td>
				</tr>
				<tr>
					<th>Custom cwebp path</th>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[cwebp_path]" value="<?php echo esc_attr( $o['cwebp_path'] ); ?>" placeholder="/usr/local/bin/cwebp"></td>
				</tr>
				<tr>
					<th>Options</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[replace_urls]" value="1" <?php checked( $o['replace_urls'] ); ?>> Replace live image paths (.jpg/.png → .webp) in posts, products, Elementor data, meta &amp; options</label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[keep_backup]" value="1" <?php checked( $o['keep_backup'] ); ?>> Keep original files as backup (<code>uploads/<?php echo esc_html( WebPC_Attachment::BACKUP_DIR ); ?>/</code>) – allows one-click restore</label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_upload]" value="1" <?php checked( $o['auto_upload'] ); ?>> Automatically convert new uploads to WebP</label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[redirect_old]" value="1" <?php checked( $o['redirect_old'] ); ?>> Redirect old .jpg/.png URLs to the new .webp (safety net for page cache, CDN, Google Images, social shares, hard-coded theme CSS)</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Stats                                                               */
	/* ------------------------------------------------------------------ */

	public static function library_stats() {
		global $wpdb;
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png')" );
		$rows    = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_webpc_stats'" );
		$orig    = 0;
		$new     = 0;
		foreach ( $rows as $r ) {
			$s     = maybe_unserialize( $r );
			$orig += (int) ( $s['orig'] ?? 0 );
			$new  += (int) ( $s['new'] ?? 0 );
		}
		return array(
			'pending'   => $pending,
			'converted' => count( $rows ),
			'saved'     => max( 0, $orig - $new ),
			'percent'   => $orig ? round( ( 1 - $new / $orig ) * 100 ) : 0,
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	private static function guard( $cap = null ) {
		check_ajax_referer( 'webpc', 'nonce' );
		if ( ! current_user_can( $cap ? $cap : self::cap() ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
	}

	public static function ajax_queue() {
		self::guard();
		global $wpdb;
		$scope = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'all';

		if ( 'products' === $scope ) {
			$thumbs  = $wpdb->get_col( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND p.post_type IN ('product','product_variation')" );
			$gallery = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''" );
			$ids     = $thumbs;
			foreach ( $gallery as $g ) {
				$ids = array_merge( $ids, explode( ',', $g ) );
			}
			$ids = array_unique( array_filter( array_map( 'intval', $ids ) ) );
			$ids = $ids ? $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png') AND ID IN (" . implode( ',', $ids ) . ') ORDER BY ID DESC' ) : array();
		} else {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png') ORDER BY ID DESC" );
		}

		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	public static function ajax_convert() {
		self::guard( self::media_cap() );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$title = get_the_title( $id );
		$res   = WebPC_Attachment::convert( $id );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array(
				'id'      => $id,
				'title'   => $title,
				'skipped' => 'webpc_already' === $res->get_error_code(),
				'message' => $res->get_error_message(),
			) );
		}

		wp_send_json_success( array(
			'id'      => $id,
			'title'   => $title,
			'html'    => self::status_html( $id ),
			'thumb'   => wp_get_attachment_image_url( $id, 'thumbnail' ),
			'message' => self::savings_text( $res ),
		) );
	}

	public static function ajax_restore() {
		self::guard( self::media_cap() );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$res = WebPC_Attachment::restore( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'id' => $id, 'html' => self::status_html( $id ) ) );
	}

	public static function ajax_product_images() {
		self::guard();
		$pid = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$out = array();
		foreach ( WebPC_Attachment::product_image_ids( $pid ) as $id ) {
			$mime  = get_post_mime_type( $id );
			$out[] = array(
				'id'        => $id,
				'title'     => get_the_title( $id ),
				'thumb'     => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'mime'      => $mime,
				'size'      => size_format( (int) @filesize( get_attached_file( $id ) ), 1 ),
				'convert'   => WebPC_Attachment::is_convertible( $id ),
				'html'      => self::status_html( $id ),
			);
		}
		wp_send_json_success( array( 'images' => $out ) );
	}

	public static function ajax_finish() {
		self::guard();
		WebPC_Replacer::purge_all_page_caches();
		wp_send_json_success();
	}

	public static function ajax_stats() {
		self::guard();
		$s          = self::library_stats();
		$s['saved'] = size_format( $s['saved'], 1 ) ?: '0 B';
		wp_send_json_success( $s );
	}

	/* ------------------------------------------------------------------ */
	/* Status UI shared by media list / modal / product box                */
	/* ------------------------------------------------------------------ */

	private static function pct( $from, $to ) {
		return $from ? (int) round( ( 1 - $to / $from ) * 100 ) : 0;
	}

	/**
	 * "Main image" = the file WordPress shows as "File size"; "all sizes" = main + every thumbnail.
	 */
	public static function savings_lines( $s ) {
		$lines = array();
		if ( isset( $s['main_orig'], $s['main_new'] ) ) {
			$lines['main'] = sprintf( 'Main image: %s → %s (−%d%%)', size_format( $s['main_orig'], 1 ), size_format( $s['main_new'], 1 ), self::pct( $s['main_orig'], $s['main_new'] ) );
		}
		$lines['all'] = sprintf( 'All %d sizes: %s → %s (−%d%%)', $s['files'], size_format( $s['orig'], 1 ), size_format( $s['new'], 1 ), self::pct( $s['orig'], $s['new'] ) );
		return $lines;
	}

	public static function savings_text( $s ) {
		return implode( ' · ', self::savings_lines( $s ) );
	}

	public static function status_html( $id ) {
		$mime = get_post_mime_type( $id );
		$html = '<div class="webpc-status" data-id="' . (int) $id . '">';

		if ( WebPC_Attachment::is_converted( $id ) ) {
			$lines = self::savings_lines( WebPC_Attachment::stats( $id ) );
			$html .= '<span class="webpc-badge ok">WebP</span>';
			if ( isset( $lines['main'] ) ) {
				$html .= ' <strong class="webpc-main">' . esc_html( $lines['main'] ) . '</strong>';
			}
			$html .= '<br><small>' . esc_html( $lines['all'] ) . '</small>';
			if ( WebPC_Attachment::has_backup( $id ) ) {
				$html .= '<br><a href="#" class="webpc-restore" data-id="' . (int) $id . '">Restore original</a>';
			}
		} elseif ( 'image/webp' === $mime ) {
			$html .= '<span class="webpc-badge ok">WebP</span>';
		} elseif ( WebPC_Attachment::is_convertible( $id ) ) {
			$html .= '<span class="webpc-badge">' . esc_html( strtoupper( str_replace( 'image/', '', $mime ) ) ) . '</span> ';
			$html .= '<a href="#" class="button button-small webpc-convert" data-id="' . (int) $id . '">Convert to WebP</a>';
		} else {
			$html .= '<span class="webpc-muted">—</span>';
		}

		return $html . '</div>';
	}

	public static function media_column( $cols ) {
		$cols['webpc'] = 'WebP';
		return $cols;
	}

	public static function media_column_content( $col, $id ) {
		if ( 'webpc' === $col && wp_attachment_is_image( $id ) && current_user_can( self::media_cap() ) ) {
			echo self::status_html( $id ); // phpcs:ignore
		}
	}

	public static function attachment_field( $fields, $post ) {
		if ( wp_attachment_is_image( $post->ID ) && current_user_can( self::media_cap() ) ) {
			$fields['webpc'] = array(
				'label' => 'WebP',
				'input' => 'html',
				'html'  => self::status_html( $post->ID ),
			);
		}
		return $fields;
	}

	/* ------------------------------------------------------------------ */
	/* WooCommerce product screens                                         */
	/* ------------------------------------------------------------------ */

	public static function product_metabox() {
		if ( current_user_can( self::cap() ) ) {
			add_meta_box( 'webpc-product', 'WebP Images', array( __CLASS__, 'product_metabox_html' ), 'product', 'side', 'low' );
		}
	}

	public static function product_metabox_html( $post ) {
		$ids     = WebPC_Attachment::product_image_ids( $post->ID );
		$pending = array_values( array_filter( $ids, array( 'WebPC_Attachment', 'is_convertible' ) ) );
		echo '<p>' . count( $ids ) . ' image(s) found, <strong>' . count( $pending ) . '</strong> JPG/PNG left.</p>';
		if ( $pending ) {
			echo '<p><button type="button" class="button button-primary webpc-product-box-btn" data-ids="' . esc_attr( implode( ',', $pending ) ) . '">Convert product images to WebP</button></p>';
			echo '<div class="webpc-progress" style="display:none"><div class="webpc-bar"><span></span></div><p class="webpc-progress-text"></p><ul class="webpc-log"></ul></div>';
		} else {
			echo '<p><span class="webpc-badge ok">All WebP</span></p>';
		}
	}

	public static function product_row_action( $actions, $post ) {
		if ( 'product' === $post->post_type && current_user_can( self::cap() ) ) {
			$url                    = admin_url( 'admin.php?page=' . self::SLUG . '&tab=product&autostart=1&product_id=' . $post->ID );
			$actions['webpc']       = '<a href="' . esc_url( $url ) . '">Convert images to WebP</a>';
		}
		return $actions;
	}

	public static function product_bulk_action( $actions ) {
		$actions['webpc_convert'] = 'Convert images to WebP';
		return $actions;
	}

	/**
	 * Bulk action on the products list: collects the images and hands them to the AJAX runner
	 * (one image per request – never hits PHP max_execution_time on shared hosting).
	 */
	public static function handle_product_bulk( $redirect, $action, $post_ids ) {
		if ( 'webpc_convert' !== $action || ! current_user_can( self::cap() ) ) {
			return $redirect;
		}
		$ids = array();
		foreach ( $post_ids as $pid ) {
			$ids = array_merge( $ids, array_filter( WebPC_Attachment::product_image_ids( $pid ), array( 'WebPC_Attachment', 'is_convertible' ) ) );
		}
		set_transient( 'webpc_q_' . get_current_user_id(), array_values( array_unique( array_map( 'intval', $ids ) ) ), HOUR_IN_SECONDS );
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=bulk&queue=1' );
	}
}
