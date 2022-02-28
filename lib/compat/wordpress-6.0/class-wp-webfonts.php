<?php
/**
 * Webfonts API class.
 *
 * @package Gutenberg
 */

/**
 * Class WP_Webfonts
 */
class WP_Webfonts {

	/**
	 * An array of registered webfonts.
	 *
	 * @static
	 * @access private
	 * @var array
	 */
	private static $webfonts = array();

	/**
	 * An array of actually used webfonts by the front-end.
	 * This gets populated in several `register_*` methods
	 * inside this class.
	 *
	 * @static
	 * @access private
	 * @var array
	 */
	private static $used_webfonts = array();

	/**
	 * The name of the webfont cache option name.
	 *
	 * @static
	 * @access private
	 * @var string
	 */
	private static $webfont_cache_option = 'gutenberg_used_webfonts';

	/**
	 * An array of registered providers.
	 *
	 * @static
	 * @access private
	 * @var array
	 */
	private static $providers = array();

	/**
	 * Stylesheet handle.
	 *
	 * @var string
	 */
	private $stylesheet_handle = '';

	/**
	 * Init.
	 */
	public function init() {

		// Register default providers.
		$this->register_provider( 'local', 'WP_Webfonts_Provider_Local' );

		// Register callback to generate and enqueue styles.
		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$this->stylesheet_handle = 'webfonts-footer';
			$hook                    = 'wp_print_footer_scripts';
		} else {
			$this->stylesheet_handle = 'webfonts';
			$hook                    = 'wp_enqueue_scripts';
		}

		add_action( 'init', array( $this, 'load_used_webfonts_for_current_template' ) );

		add_action( 'save_post_wp_template', array( $this, 'save_used_webfonts_for_template' ), 10, 2 );
		add_action( 'save_post_wp_template_part', array( $this, 'update_webfonts_used_by_templates' ), 10, 2 );

		add_action( $hook, array( $this, 'generate_and_enqueue_styles' ) );

		// Enqueue webfonts in the block editor.
		add_action( 'admin_init', array( $this, 'generate_and_enqueue_editor_styles' ) );
	}

	/**
	 * Set list of used fonts in the current page.
	 *
	 * @return void
	 */
	public function load_used_webfonts_for_current_template() {
		$used_webfonts = get_option( self::$webfont_cache_option, array() );

		foreach ( $used_webfonts as $template => $webfonts ) {
			add_filter(
				$template . '_template',
				function() use ( $webfonts ) {
					self::$used_webfonts = array_merge(
						self::$used_webfonts,
						$webfonts
					);
				}
			);
		}
	}

	/**
	 * Updates the fonts used by the templates.
	 * We need to do that because there's no indication on which templates uses which template parts,
	 * so we're throwing everything away and reconstructing the cache.
	 *
	 * @return void
	 */
	public function update_webfonts_used_by_templates() {
		$used_webfonts = array();

		$templates = get_block_templates( array(), 'wp_template' );

		foreach ( $templates as $template ) {
			$fonts_for_template = $this->get_fonts_from_template( $template->content );

			if ( $fonts_for_template ) {
				$used_webfonts[ $template->slug ] = $fonts_for_template;
			}
		}

		update_option( self::$webfont_cache_option, $used_webfonts );
	}

	/**
	 * Saves the fonts used by the saved template.

	 * @param integer $post_id The template ID.
	 * @param WP_Post $post The template post object.
	 * @return void
	 */
	public function save_used_webfonts_for_template( $post_id, $post ) {
		$used_webfonts = get_option( self::$webfont_cache_option, array() );

		$used_webfonts[ $post->post_name ] = $this->get_fonts_from_template( $post->post_content );

		update_option( self::$webfont_cache_option, $used_webfonts );
	}

	/**
	 * Get the list of fonts used in the template.
	 * Recursively gets the fonts used in the template parts.

	 * @param string $template_content The template content.
	 * @return array
	 */
	private function get_fonts_from_template( $template_content ) {
		$used_webfonts = array();

		$blocks = _flatten_blocks( parse_blocks( $template_content ) );

		foreach ( $blocks as $block ) {
			if ( 'core/template-part' === $block['blockName'] ) {
				$template_part           = get_block_template( get_stylesheet() . '//' . $block['attrs']['slug'], 'wp_template_part' );
				$fonts_for_template_part = $this->get_fonts_from_template( $template_part->content );

				$used_webfonts = array_merge(
					$used_webfonts,
					$fonts_for_template_part
				);
			}

			if ( isset( $block['attrs']['fontFamily'] ) ) {
				$used_webfonts[ $block['attrs']['fontFamily'] ] = 1;
			}
		}

		return $used_webfonts;
	}

	/**
	 * Get the list of fonts.
	 *
	 * @return array
	 */
	public function get_fonts() {
		return self::$webfonts;
	}

	/**
	 * Get the list of providers.
	 *
	 * @return array
	 */
	public function get_providers() {
		return self::$providers;
	}

	/**
	 * Register a webfont.
	 *
	 * @param array $font The font arguments.
	 */
	public function register_font( $font ) {
		$font = $this->validate_font( $font );
		if ( $font ) {
			$id                    = $this->get_font_id( $font );
			self::$webfonts[ $id ] = $font;
		}
	}

	/**
	 * Get the font ID.
	 *
	 * @param array $font The font arguments.
	 * @return string
	 */
	public function get_font_id( $font ) {
		return sanitize_title( "{$font['font-family']}-{$font['font-weight']}-{$font['font-style']}-{$font['provider']}" );
	}

	/**
	 * Validate a font.
	 *
	 * @param array $font The font arguments.
	 *
	 * @return array|false The validated font arguments, or false if the font is invalid.
	 */
	public function validate_font( $font ) {
		$font = wp_parse_args(
			$font,
			array(
				'provider'     => 'local',
				'font-family'  => '',
				'font-style'   => 'normal',
				'font-weight'  => '400',
				'font-display' => 'fallback',
			)
		);

		// Check the font-family.
		if ( empty( $font['font-family'] ) || ! is_string( $font['font-family'] ) ) {
			trigger_error( __( 'Webfont font family must be a non-empty string.', 'gutenberg' ) );
			return false;
		}

		// Local fonts need a "src".
		if ( 'local' === $font['provider'] ) {
			// Make sure that local fonts have 'src' defined.
			if ( empty( $font['src'] ) || ( ! is_string( $font['src'] ) && ! is_array( $font['src'] ) ) ) {
				trigger_error( __( 'Webfont src must be a non-empty string or an array of strings.', 'gutenberg' ) );
				return false;
			}
		}

		// Validate the 'src' property.
		if ( ! empty( $font['src'] ) ) {
			foreach ( (array) $font['src'] as $src ) {
				if ( empty( $src ) || ! is_string( $src ) ) {
					trigger_error( __( 'Each webfont src must be a non-empty string.', 'gutenberg' ) );
					return false;
				}
			}
		}

		// Check the font-weight.
		if ( ! is_string( $font['font-weight'] ) && ! is_int( $font['font-weight'] ) ) {
			trigger_error( __( 'Webfont font weight must be a properly formatted string or integer.', 'gutenberg' ) );
			return false;
		}

		// Check the font-display.
		if ( ! in_array( $font['font-display'], array( 'auto', 'block', 'fallback', 'swap' ), true ) ) {
			$font['font-display'] = 'fallback';
		}

		$valid_props = array(
			'ascend-override',
			'descend-override',
			'font-display',
			'font-family',
			'font-stretch',
			'font-style',
			'font-weight',
			'font-variant',
			'font-feature-settings',
			'font-variation-settings',
			'line-gap-override',
			'size-adjust',
			'src',
			'unicode-range',

			// Exceptions.
			'provider',
		);

		foreach ( $font as $prop => $value ) {
			if ( ! in_array( $prop, $valid_props, true ) ) {
				unset( $font[ $prop ] );
			}
		}

		return $font;
	}

	/**
	 * Register a provider.
	 *
	 * @param string $provider The provider name.
	 * @param string $class    The provider class name.
	 *
	 * @return bool Whether the provider was registered successfully.
	 */
	public function register_provider( $provider, $class ) {
		if ( empty( $provider ) || empty( $class ) ) {
			return false;
		}
		self::$providers[ $provider ] = $class;
		return true;
	}

	/**
	 * Filter unused webfonts based off self::$used_webfonts.
	 *
	 * @return void
	 */
	private function filter_unused_webfonts_from_providers() {
		$registered_webfonts = $this->get_fonts();

		foreach ( $registered_webfonts as $id => $webfont ) {
			$font_name = _wp_to_kebab_case( $webfont['font-family'] );

			if ( ! isset( self::$used_webfonts[ $font_name ] ) ) {
				unset( $registered_webfonts[ $id ] );
			}
		}

		self::$webfonts = $registered_webfonts;
	}

	/**
	 * Generate and enqueue webfonts styles.
	 */
	public function generate_and_enqueue_styles() {
		$this->filter_unused_webfonts_from_providers();

		// Generate the styles.
		$styles = $this->generate_styles();

		// Bail out if there are no styles to enqueue.
		if ( '' === $styles ) {
			return;
		}

		// Enqueue the stylesheet.
		wp_register_style( $this->stylesheet_handle, '' );
		wp_enqueue_style( $this->stylesheet_handle );

		// Add the styles to the stylesheet.
		wp_add_inline_style( $this->stylesheet_handle, $styles );
	}

	/**
	 * Generate and enqueue editor styles.
	 */
	public function generate_and_enqueue_editor_styles() {
		// Generate the styles.
		$styles = $this->generate_styles();

		// Bail out if there are no styles to enqueue.
		if ( '' === $styles ) {
			return;
		}

		wp_add_inline_style( 'wp-block-library', $styles );
	}

	/**
	 * Generate styles for webfonts.
	 *
	 * @since 6.0.0
	 *
	 * @return string $styles Generated styles.
	 */
	public function generate_styles() {
		$styles    = '';
		$providers = $this->get_providers();

		// Group webfonts by provider.
		$webfonts_by_provider = array();
		$registered_webfonts  = $this->get_fonts();
		foreach ( $registered_webfonts as $id => $webfont ) {
			$provider = $webfont['provider'];
			if ( ! isset( $providers[ $provider ] ) ) {
				/* translators: %s is the provider name. */
				error_log( sprintf( __( 'Webfont provider "%s" is not registered.', 'gutenberg' ), $provider ) );
				continue;
			}
			$webfonts_by_provider[ $provider ]        = isset( $webfonts_by_provider[ $provider ] ) ? $webfonts_by_provider[ $provider ] : array();
			$webfonts_by_provider[ $provider ][ $id ] = $webfont;
		}

		/*
		 * Loop through each of the providers to get the CSS for their respective webfonts
		 * to incrementally generate the collective styles for all of them.
		 */
		foreach ( $providers as $provider_id => $provider_class ) {

			// Bail out if the provider class does not exist.
			if ( ! class_exists( $provider_class ) ) {
				/* translators: %s is the provider name. */
				error_log( sprintf( __( 'Webfont provider "%s" is not registered.', 'gutenberg' ), $provider_id ) );
				continue;
			}

			$provider_webfonts = isset( $webfonts_by_provider[ $provider_id ] )
				? $webfonts_by_provider[ $provider_id ]
				: array();

			// If there are no registered webfonts for this provider, skip it.
			if ( empty( $provider_webfonts ) ) {
				continue;
			}

			/*
			 * Process the webfonts by first passing them to the provider via `set_webfonts()`
			 * and then getting the CSS from the provider.
			 */
			$provider = new $provider_class();
			$provider->set_webfonts( $provider_webfonts );
			$styles .= $provider->get_css();
		}

		return $styles;
	}
}
