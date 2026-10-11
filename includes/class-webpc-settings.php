<?php
defined( 'ABSPATH' ) || exit;

class WebPC_Settings {

	const OPTION = 'webpc_settings';

	public static function defaults() {
		return array(
			'quality'      => 80,     // 75-85 is the sweet spot: big savings, no visible loss.
			'max_width'    => 2560,   // Huge camera photos (2MB+) are scaled down to this. 0 = never resize.
			'max_height'   => 2560,
			'png_mode'     => 'lossy', // lossy | lossless
			'engine'       => 'auto',  // auto | cwebp | imagick | gd
			'cwebp_path'   => '',
			'keep_backup'  => 1,
			'auto_upload'  => 0,
			'replace_urls' => 1,
			'redirect_old' => 1,
		);
	}

	public static function get( $key = null ) {
		$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return null === $key ? $opts : ( $opts[ $key ] ?? null );
	}

	public static function sanitize( $input ) {
		$d   = self::defaults();
		$out = array();

		$out['quality']      = min( 100, max( 30, absint( $input['quality'] ?? $d['quality'] ) ) );
		$out['max_width']    = absint( $input['max_width'] ?? 0 );
		$out['max_height']   = absint( $input['max_height'] ?? 0 );
		$out['png_mode']     = in_array( $input['png_mode'] ?? '', array( 'lossy', 'lossless' ), true ) ? $input['png_mode'] : $d['png_mode'];
		$out['engine']       = in_array( $input['engine'] ?? '', array( 'auto', 'cwebp', 'imagick', 'gd' ), true ) ? $input['engine'] : 'auto';
		$out['cwebp_path']   = sanitize_text_field( $input['cwebp_path'] ?? '' );
		// Only ever allow a binary actually named cwebp (cwebp.exe on Windows).
		if ( $out['cwebp_path'] && ! preg_match( '/^cwebp(\.exe)?$/i', basename( $out['cwebp_path'] ) ) ) {
			$out['cwebp_path'] = '';
		}
		$out['keep_backup']  = empty( $input['keep_backup'] ) ? 0 : 1;
		$out['auto_upload']  = empty( $input['auto_upload'] ) ? 0 : 1;
		$out['replace_urls'] = empty( $input['replace_urls'] ) ? 0 : 1;
		$out['redirect_old'] = empty( $input['redirect_old'] ) ? 0 : 1;

		return $out;
	}
}
