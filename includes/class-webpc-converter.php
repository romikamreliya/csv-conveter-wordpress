<?php
defined( 'ABSPATH' ) || exit;

/**
 * Low level JPG/PNG -> WebP conversion.
 *
 * Engines (tried in this order when "auto"):
 *  - cwebp   : Google's official encoder binary (best quality / size ratio).
 *  - imagick : ImageMagick with WebP delegate.
 *  - gd      : PHP GD compiled with WebP support.
 */
class WebPC_Converter {

	const TYPES = array( IMAGETYPE_JPEG, IMAGETYPE_PNG );

	/* ------------------------------------------------------------------ */
	/* Engine detection                                                    */
	/* ------------------------------------------------------------------ */

	public static function available_engines() {
		$engines = array();
		if ( self::cwebp_binary() ) {
			$engines[] = 'cwebp';
		}
		if ( self::imagick_ok() ) {
			$engines[] = 'imagick';
		}
		if ( self::gd_ok() ) {
			$engines[] = 'gd';
		}
		return $engines;
	}

	public static function exec_enabled() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'exec', $disabled, true );
	}

	public static function cwebp_binary() {
		static $bin = null;
		if ( null !== $bin ) {
			return $bin ? $bin : false;
		}
		$bin = '';
		if ( ! self::exec_enabled() ) {
			return false;
		}

		$candidates = array_filter( array(
			preg_match( '/^cwebp(\.exe)?$/i', basename( (string) WebPC_Settings::get( 'cwebp_path' ) ) ) ? WebPC_Settings::get( 'cwebp_path' ) : '',
			'/usr/bin/cwebp',
			'/usr/local/bin/cwebp',
			'/opt/homebrew/bin/cwebp',
			'/Applications/XAMPP/xamppfiles/bin/cwebp',
			'/opt/lampp/bin/cwebp',
		) );

		foreach ( $candidates as $c ) {
			if ( @is_file( $c ) && @is_executable( $c ) ) {
				$bin = $c;
				break;
			}
		}

		if ( ! $bin && '\\' !== DIRECTORY_SEPARATOR ) {
			$out = array();
			@exec( 'command -v cwebp 2>/dev/null', $out, $rc );
			if ( 0 === $rc && ! empty( $out[0] ) ) {
				$bin = trim( $out[0] );
			}
		}

		if ( $bin ) {
			$out = array();
			@exec( escapeshellarg( $bin ) . ' -version 2>&1', $out, $rc );
			if ( 0 !== $rc ) {
				$bin = '';
			}
		}

		return $bin ? $bin : false;
	}

	public static function imagick_ok() {
		return extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) && ! empty( Imagick::queryFormats( 'WEBP' ) );
	}

	public static function gd_ok() {
		if ( ! function_exists( 'imagewebp' ) || ! function_exists( 'gd_info' ) ) {
			return false;
		}
		$info = gd_info();
		return ! empty( $info['WebP Support'] );
	}

	/* ------------------------------------------------------------------ */
	/* Conversion                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Convert one image file to WebP.
	 *
	 * @param string $src  Source JPG/PNG path.
	 * @param string $dest Destination .webp path.
	 * @param array  $args quality, max_width, max_height, lossless, resize (bool).
	 * @return array|WP_Error { engine, width, height, size }
	 */
	public static function convert( $src, $dest, $args = array() ) {
		$args = wp_parse_args( $args, array(
			'quality'    => (int) WebPC_Settings::get( 'quality' ),
			'max_width'  => (int) WebPC_Settings::get( 'max_width' ),
			'max_height' => (int) WebPC_Settings::get( 'max_height' ),
			'lossless'   => null,
			'resize'     => false,
		) );

		$info = @getimagesize( $src );
		if ( ! $info || ! in_array( $info[2], self::TYPES, true ) ) {
			return new WP_Error( 'webpc_type', sprintf( 'Not a JPG/PNG image: %s', basename( $src ) ) );
		}

		$type = $info[2];
		$w    = (int) $info[0];
		$h    = (int) $info[1];

		if ( null === $args['lossless'] ) {
			$args['lossless'] = ( IMAGETYPE_PNG === $type && 'lossless' === WebPC_Settings::get( 'png_mode' ) );
		}

		$orientation = 1;
		if ( IMAGETYPE_JPEG === $type && function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $src );
			if ( ! empty( $exif['Orientation'] ) ) {
				$orientation = (int) $exif['Orientation'];
			}
		}
		if ( $orientation >= 5 && $orientation <= 8 ) {
			list( $w, $h ) = array( $h, $w );
		}

		// Target size (only the main/full image gets resized).
		$tw = $w;
		$th = $h;
		if ( $args['resize'] ) {
			$scale = 1;
			if ( $args['max_width'] > 0 && $w > $args['max_width'] ) {
				$scale = min( $scale, $args['max_width'] / $w );
			}
			if ( $args['max_height'] > 0 && $h > $args['max_height'] ) {
				$scale = min( $scale, $args['max_height'] / $h );
			}
			if ( $scale < 1 ) {
				$tw = max( 1, (int) round( $w * $scale ) );
				$th = max( 1, (int) round( $h * $scale ) );
			}
		}

		wp_raise_memory_limit( 'image' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$job = array(
			'src'         => $src,
			'dest'        => $dest,
			'type'        => $type,
			'orientation' => $orientation,
			'tw'          => $tw,
			'th'          => $th,
			'resize'      => ( $tw !== $w || $th !== $h ),
			'quality'     => (int) $args['quality'],
			'lossless'    => (bool) $args['lossless'],
		);

		$engines = self::engine_order();
		if ( empty( $engines ) ) {
			return new WP_Error( 'webpc_no_engine', 'No WebP engine available. Install cwebp, Imagick (with WebP) or GD (with WebP).' );
		}

		$errors = array();
		foreach ( $engines as $engine ) {
			$res = self::run_engine( $engine, $job );
			if ( is_wp_error( $res ) ) {
				$errors[] = $engine . ': ' . $res->get_error_message();
				@unlink( $dest );
				continue;
			}

			clearstatcache( true, $dest );
			if ( ! file_exists( $dest ) || ! filesize( $dest ) ) {
				$errors[] = $engine . ': empty output';
				continue;
			}

			// If WebP ended up bigger than the original, try once more a bit lower – still visually clean.
			$orig_size = filesize( $src );
			if ( ! $job['lossless'] && ! $job['resize'] && filesize( $dest ) >= $orig_size && $job['quality'] > 65 ) {
				$retry            = $job;
				$retry['quality'] = max( 65, $job['quality'] - 12 );
				$retry['dest']    = $dest . '.tmp.webp';
				if ( ! is_wp_error( self::run_engine( $engine, $retry ) ) && file_exists( $retry['dest'] ) ) {
					clearstatcache();
					if ( filesize( $retry['dest'] ) && filesize( $retry['dest'] ) < filesize( $dest ) ) {
						@rename( $retry['dest'], $dest );
					}
				}
				@unlink( $retry['dest'] );
				clearstatcache( true, $dest );
			}

			$out = @getimagesize( $dest );
			return array(
				'engine' => $engine,
				'width'  => $out ? (int) $out[0] : $tw,
				'height' => $out ? (int) $out[1] : $th,
				'size'   => (int) filesize( $dest ),
			);
		}

		return new WP_Error( 'webpc_failed', implode( ' | ', $errors ) );
	}

	private static function engine_order() {
		$available = self::available_engines();
		$preferred = WebPC_Settings::get( 'engine' );
		if ( 'auto' !== $preferred && in_array( $preferred, $available, true ) ) {
			$available = array_values( array_unique( array_merge( array( $preferred ), $available ) ) );
		}
		return $available;
	}

	private static function run_engine( $engine, $job ) {
		try {
			switch ( $engine ) {
				case 'cwebp':
					return self::via_cwebp( $job );
				case 'imagick':
					return self::via_imagick( $job );
				case 'gd':
					return self::via_gd( $job );
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'webpc_engine', $e->getMessage() );
		}
		return new WP_Error( 'webpc_engine', 'Unknown engine' );
	}

	/* ---------------------------- cwebp -------------------------------- */

	private static function via_cwebp( $job ) {
		$bin   = self::cwebp_binary();
		$input = $job['src'];
		$tmp   = '';

		// cwebp ignores EXIF orientation, so pre-rotate with GD (lossless PNG temp file).
		if ( $job['orientation'] > 1 && function_exists( 'imagecreatefromjpeg' ) ) {
			$im = self::gd_load( $job['src'], $job['type'] );
			if ( $im ) {
				$im  = self::gd_orient( $im, $job['orientation'] );
				$tmp = wp_tempnam( 'webpc' ) . '.png';
				imagepng( $im, $tmp, 1 );
				$input = $tmp;
			}
		}

		$build = function ( $sharp ) use ( $bin, $job, $input ) {
			$cmd = escapeshellarg( $bin );
			if ( $job['lossless'] ) {
				$cmd .= ' -lossless -q 100 -m 6';
			} else {
				$cmd .= ' -q ' . (int) $job['quality'] . ' -m 6';
				$cmd .= $sharp ? ' -sharp_yuv' : '';
				$cmd .= IMAGETYPE_PNG === $job['type'] ? ' -alpha_q 100' : '';
			}
			// Keep the ICC colour profile (iPhone / Adobe RGB photos would look washed out without it).
			$cmd .= ' -mt -metadata icc -quiet';
			if ( $job['resize'] ) {
				$cmd .= ' -resize ' . (int) $job['tw'] . ' ' . (int) $job['th'];
			}
			return $cmd . ' ' . escapeshellarg( $input ) . ' -o ' . escapeshellarg( $job['dest'] ) . ' 2>&1';
		};

		$out = array();
		exec( $build( true ), $out, $rc );
		if ( 0 !== $rc ) {
			// Very old cwebp builds don't know -sharp_yuv.
			$out = array();
			exec( $build( false ), $out, $rc );
		}

		if ( $tmp ) {
			@unlink( $tmp );
			@unlink( substr( $tmp, 0, -4 ) );
		}

		if ( 0 !== $rc ) {
			return new WP_Error( 'webpc_cwebp', trim( implode( ' ', $out ) ) ?: 'cwebp exit code ' . $rc );
		}
		return true;
	}

	/* --------------------------- Imagick ------------------------------- */

	private static function via_imagick( $job ) {
		$im = new Imagick( $job['src'] );

		switch ( $job['orientation'] ) {
			case 2: $im->flopImage(); break;
			case 3: $im->rotateImage( 'none', 180 ); break;
			case 4: $im->flipImage(); break;
			case 5: $im->rotateImage( 'none', 90 ); $im->flopImage(); break;
			case 6: $im->rotateImage( 'none', 90 ); break;
			case 7: $im->rotateImage( 'none', -90 ); $im->flopImage(); break;
			case 8: $im->rotateImage( 'none', -90 ); break;
		}
		if ( method_exists( $im, 'setImageOrientation' ) ) {
			$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
		}

		if ( $job['resize'] ) {
			$im->resizeImage( $job['tw'], $job['th'], Imagick::FILTER_LANCZOS, 1 );
		}

		// Strip EXIF/XMP but keep the ICC colour profile.
		$icc = null;
		try {
			$icc = $im->getImageProfile( 'icc' );
		} catch ( \Exception $e ) {
			$icc = null;
		}
		$im->stripImage();
		if ( $icc ) {
			$im->profileImage( 'icc', $icc );
		}
		$im->setImageFormat( 'webp' );
		$im->setOption( 'webp:method', '6' );
		if ( $job['lossless'] ) {
			$im->setOption( 'webp:lossless', 'true' );
			$im->setImageCompressionQuality( 100 );
		} else {
			$im->setImageCompressionQuality( $job['quality'] );
			$im->setOption( 'webp:alpha-quality', '100' );
			$im->setOption( 'webp:use-sharp-yuv', 'true' );
		}

		$ok = $im->writeImage( $job['dest'] );
		$im->clear();
		return $ok ? true : new WP_Error( 'webpc_imagick', 'Imagick write failed' );
	}

	/* ------------------------------ GD --------------------------------- */

	private static function via_gd( $job ) {
		$im = self::gd_load( $job['src'], $job['type'] );
		if ( ! $im ) {
			return new WP_Error( 'webpc_gd', 'GD could not read image' );
		}
		$im = self::gd_orient( $im, $job['orientation'] );

		if ( $job['resize'] ) {
			$dst = imagecreatetruecolor( $job['tw'], $job['th'] );
			imagealphablending( $dst, false );
			imagesavealpha( $dst, true );
			imagefill( $dst, 0, 0, imagecolorallocatealpha( $dst, 0, 0, 0, 127 ) );
			imagecopyresampled( $dst, $im, 0, 0, 0, 0, $job['tw'], $job['th'], imagesx( $im ), imagesy( $im ) );
			$im = $dst;
		}

		$q  = $job['lossless'] ? ( defined( 'IMG_WEBP_LOSSLESS' ) ? IMG_WEBP_LOSSLESS : 100 ) : $job['quality'];
		$ok = imagewebp( $im, $job['dest'], $q );
		return $ok ? true : new WP_Error( 'webpc_gd', 'imagewebp failed' );
	}

	private static function gd_load( $src, $type ) {
		$im = false;
		if ( IMAGETYPE_JPEG === $type && function_exists( 'imagecreatefromjpeg' ) ) {
			$im = @imagecreatefromjpeg( $src );
		} elseif ( IMAGETYPE_PNG === $type && function_exists( 'imagecreatefrompng' ) ) {
			$im = @imagecreatefrompng( $src );
			if ( $im ) {
				if ( function_exists( 'imagepalettetotruecolor' ) ) {
					imagepalettetotruecolor( $im );
				}
				imagealphablending( $im, false );
				imagesavealpha( $im, true );
			}
		}
		return $im;
	}

	private static function gd_orient( $im, $orientation ) {
		$transparent = imagecolorallocatealpha( $im, 0, 0, 0, 127 );
		switch ( $orientation ) {
			case 2: imageflip( $im, IMG_FLIP_HORIZONTAL ); break;
			case 3: $im = imagerotate( $im, 180, $transparent ); break;
			case 4: imageflip( $im, IMG_FLIP_VERTICAL ); break;
			case 5: $im = imagerotate( $im, 270, $transparent ); imageflip( $im, IMG_FLIP_HORIZONTAL ); break;
			case 6: $im = imagerotate( $im, 270, $transparent ); break;
			case 7: $im = imagerotate( $im, 90, $transparent ); imageflip( $im, IMG_FLIP_HORIZONTAL ); break;
			case 8: $im = imagerotate( $im, 90, $transparent ); break;
		}
		return $im;
	}
}
