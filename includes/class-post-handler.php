<?php
/**
 * Handles posting to Mastodon and the like.
 *
 * @package Share_On_Mastodon
 */

namespace Share_On_Mastodon;

/**
 * Post handler class.
 */
class Post_Handler {
	/**
	 * Array that holds this plugin's settings.
	 *
	 * @since 0.1.0
	 *
	 * @var array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Options_Handler $options_handler This plugin's `Options_Handler`.
	 */
	public function __construct( $options_handler = null ) {
		if ( null !== $options_handler ) {
			$this->options = $options_handler->get_options();
		}
	}

	/**
	 * Interacts with WordPress's Plugin API.
	 *
	 * @since 0.5.0
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		add_action( 'transition_post_status', array( $this, 'update_meta' ), 11, 3 );
		add_action( 'transition_post_status', array( $this, 'toot' ), 999, 3 );
		add_action( 'share_on_mastodon_post', array( $this, 'post_to_mastodon' ), 10, 3 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_share_on_mastodon_unlink_url', array( $this, 'unlink_url' ) );
	}

	/**
	 * Registers a new meta box.
	 *
	 * @since 0.1.0
	 */
	public function add_meta_box() {
		if ( empty( $this->options['post_types'] ) ) {
			// Sharing disabled for all post types.
			return;
		}

		$post_types = (array) $this->options['post_types'];

		// Add meta box, for those post types that are supported.
		add_meta_box(
			'share-on-mastodon',
			__( 'Share on Mastodon', 'share-on-mastodon' ),
			array( $this, 'render_meta_box' ),
			$post_types,
			'side',
			'default'
		);

		// Make `_share_on_mastodon_url` available in the REST API, too.
		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				'_share_on_mastodon',
				array(
					'single'       => true,
					'show_in_rest' => true,
					'type'         => 'string', // Well, it's a boolean, but it _is_ a string.
				)
			);

			register_post_meta(
				$post_type,
				'_share_on_mastodon_url',
				array(
					'single'       => true,
					'show_in_rest' => true,
					'type'         => 'string',
				)
			);
		}
	}

	/**
	 * Renders meta box.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( basename( __FILE__ ), 'share_on_mastodon_nonce' );

		$check = array( '', '1' );

		if ( apply_filters( 'share_on_mastodon_optin', false ) ) {
			$check = array( '1' ); // Make sharing opt-in.
		}
		?>
		<label>
			<input type="checkbox" name="share_on_mastodon" value="1" <?php checked( in_array( get_post_meta( $post->ID, '_share_on_mastodon', true ), $check, true ) ); ?>>
			<?php esc_html_e( 'Share on Mastodon', 'share-on-mastodon' ); ?>
		</label>
		<?php
		$url = get_post_meta( $post->ID, '_share_on_mastodon_url', true );

		if ( '' !== $url && false !== wp_http_validate_url( $url ) ) :
			$url_parts = wp_parse_url( $url );

			$display_url  = '<span class="screen-reader-text">' . $url_parts['scheme'] . '://';
			$display_url .= ( ! empty( $url_parts['user'] ) ? $url_parts['user'] . ( ! empty( $url_parts['pass'] ) ? ':' . $url_parts['pass'] : '' ) . '@' : '' ) . '</span>';
			$display_url .= '<span class="ellipsis">' . substr( $url_parts['host'] . $url_parts['path'], 0, 20 ) . '</span><span class="screen-reader-text">' . substr( $url_parts['host'] . $url_parts['path'], 20 ) . '</span>';
			?>
			<p class="description">
				<?php /* translators: toot URL */ ?>
				<?php printf( esc_html__( 'Shared at %s', 'share-on-mastodon' ), '<a class="url" href="' . esc_url( $url ) . '">' . $display_url . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php /* translators: "unlink" link text */ ?>
				<a href="#" class="unlink"><?php esc_html_e( 'Unlink', 'share-on-mastodon' ); ?></a>
			</p>
			<?php
		endif;
	}

	/**
	 * Deletes a post's Mastodon URL.
	 *
	 * Should only ever be called through AJAX.
	 *
	 * @since 0.6.0
	 */
	public function unlink_url() {
		if ( ! isset( $_POST['share_on_mastodon_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['share_on_mastodon_nonce'] ), basename( __FILE__ ) ) ) {
			status_header( 400 );
			esc_html_e( 'Missing or invalid nonce.', 'share-on-mastodon' );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) || ! ctype_digit( $_POST['post_id'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			status_header( 400 );
			esc_html_e( 'Missing or incorrect post ID.', 'share-on-mastodon' );
			wp_die();
		}

		if ( ! current_user_can( 'edit_post', intval( $_POST['post_id'] ) ) ) {
			status_header( 400 );
			esc_html_e( 'Insufficient rights.', 'share-on-mastodon' );
			wp_die();
		}

		// Have WordPress forget the Mastodon URL.
		if ( '' !== get_post_meta( intval( $_POST['post_id'] ), '_share_on_mastodon_url', true ) ) {
			delete_post_meta( intval( $_POST['post_id'] ), '_share_on_mastodon_url' );
		}

		wp_die();
	}

	/**
	 * Adds admin scripts and styles.
	 *
	 * @since 0.6.0
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'post-new.php' !== $hook_suffix && 'post.php' !== $hook_suffix ) {
			// Not an "Edit Post" screen.
			return;
		}

		global $post;

		if ( empty( $post ) ) {
			// Can't do much without a `$post` object.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		// Enqueue CSS and JS.
		wp_enqueue_style( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.css', dirname( __FILE__ ) ), array(), '0.6.1' );
		wp_enqueue_script( 'share-on-mastodon', plugins_url( '/assets/share-on-mastodon.js', dirname( __FILE__ ) ), array( 'jquery' ), '0.6.1', false );
		wp_localize_script(
			'share-on-mastodon',
			'share_on_mastodon_obj',
			array(
				'message' => esc_attr__( 'Forget this URL?', 'share-on-mastodon' ), // Confirmation message.
				'post_id' => $post->ID, // Pass current post ID to JS.
			)
		);
	}

	/**
	 * Handles metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status Old post status.
	 * @param string  $old_status New post status.
	 * @param WP_Post $post       Post object.
	 */
	public function update_meta( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent double posting.
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		if ( ! isset( $_POST['share_on_mastodon_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['share_on_mastodon_nonce'] ), basename( __FILE__ ) ) ) {
			// Nonce missing or invalid.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( isset( $_POST['share_on_mastodon'] ) && ! post_password_required( $post ) ) {
			// If sharing enabled and post not password-protected.
			update_post_meta( $post->ID, '_share_on_mastodon', '1' );
		} else {
			update_post_meta( $post->ID, '_share_on_mastodon', '0' );
		}
	}

	/**
	 * Schedules sharing to Mastodon.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function toot( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent accidental double posting.
			return;
		}

		if ( ! empty( $this->options['delay_sharing'] ) ) {
			// Since version 0.7.0, there's an option to "schedule" sharing
			// rather than do everything inline.
			wp_schedule_single_event(
				time() + $this->options['delay_sharing'],
				'share_on_mastodon_post',
				array( $post->ID )
			);
		} else {
			// Share immediately.
			$this->post_to_mastodon( $post->ID );
		}
	}

	/**
	 * Shares a post on Mastodon.
	 *
	 * @since 0.7.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function post_to_mastodon( $post_id ) {
		$post = get_post( $post_id );

		$is_enabled = ( '1' === get_post_meta( $post->ID, '_share_on_mastodon', true ) ? true : false );

		if ( ! apply_filters( 'share_on_mastodon_enabled', $is_enabled, $post->ID ) ) {
			// Disabled for this post.
			return;
		}

		if ( '' !== get_post_meta( $post->ID, '_share_on_mastodon_url', true ) ) {
			// Prevent duplicate toots.
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			// Status is something other than `publish`.
			return;
		}

		if ( post_password_required( $post ) ) {
			// Post is password-protected.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( empty( $this->options['mastodon_host'] ) ) {
			return;
		}

		if ( ! wp_http_validate_url( $this->options['mastodon_host'] ) ) {
			return;
		}

		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return;
		}

		$status  = wp_strip_all_tags(
			html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) // Avoid double-encoded HTML entities.
		);
		$status .= ' ' . esc_url_raw( get_permalink( $post->ID ) );

		$status = apply_filters( 'share_on_mastodon_status', $status, $post );
		$args   = apply_filters( 'share_on_mastodon_toot_args', array( 'status' => $status ), $post );

		if ( apply_filters( 'share_on_mastodon_cutoff', false ) ) {
			// May render hashtags or URLs, or unfiltered HTML, at the very end
			// of a toot unusable. Also, Mastodon may not even use a multibyte
			// check. To do: test better?
			$args['status'] = mb_substr( $args['status'], 0, 499, get_bloginfo( 'charset' ) ) . '…';
		}

		// Encode, build query string.
		$query_string = http_build_query( $args );

		// And now, images. Note that this'll have to be rewritten for the new
		// media API.
		$thumbnail = null;
		$media     = array();

		if ( has_post_thumbnail( $post->ID ) && apply_filters( 'share_on_mastodon_featured_image', true, $post ) ) {
			// Include featured image.
			$thumbnail = (int) get_post_thumbnail_id( $post->ID );
			$media[]   = $thumbnail;
		}

		if ( apply_filters( 'share_on_mastodon_attached_images', true, $post ) ) {
			// Include all attached images.
			$images = get_attached_media( 'image', $post->ID );

			if ( ! empty( $images ) && is_array( $images ) ) {
				foreach ( $images as $image ) {
					// Skip the post's featured image, which we tackle
					// separately.
					if ( ! empty( $thumbnail ) && $thumbnail === $image->ID ) {
						continue;
					}

					$media[] = $image->ID;
				}
			}
		}

		if ( ! empty( $media ) ) {
			$count = count( $media );

			if ( $count > 4 ) {
				// Limit the number of images to four.
				$count = 4;
			}

			for ( $i = 0; $i < $count; $i++ ) {
				$media_id = $this->upload_image( $media[ $i ] );

				if ( ! empty( $media_id ) ) {
					// The image got uploaded OK.
					$query_string .= '&media_ids[]=' . rawurlencode( $media_id );
				}
			}
		}

		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/statuses' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->options['mastodon_access_token'],
				),
				// Prevent WordPress from applying `http_build_query()`.
				'data_format' => 'body',
				'body'        => $query_string,
				'timeout'     => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// Decode JSON, suppressing possible formatting errors.
		$status = @json_decode( $response['body'] );

		if ( ! empty( $status->url ) && post_type_supports( $post->post_type, 'custom-fields' ) ) {
			update_post_meta( $post->ID, '_share_on_mastodon_url', $status->url );
		} else {
			// Provided debugging's enabled, let's store the (somehow faulty)
			// response.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Uploads an image and returns a (single) media ID.
	 *
	 * @since  0.5.0
	 *
	 * @param  int $image_id Image ID.
	 * @return string|null   Unique media ID, or nothing on failure.
	 */
	private function upload_image( $image_id ) {
		$image   = wp_get_attachment_image_src( $image_id, 'large' );
		$uploads = wp_upload_dir();

		if ( ! empty( $image[0] ) && 0 === strpos( $image[0], $uploads['baseurl'] ) ) {
			// Found a "large" thumbnail that lives on our own site (and not,
			// e.g., a CDN).
			$url = $image[0];
		} else {
			// Get the original image URL. Note that Mastodon has an upload
			// limit of, I believe, 8 MB.
			$url = wp_get_attachment_url( $image_id );
		}

		$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );

		if ( ! is_file( $file_path ) ) {
			// File doesn't seem to exist.
			return;
		}

		$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body = '--' . $boundary . $eol;

		if ( '' !== $alt ) {
			// Send along an image description, because accessibility.
			$body .= 'Content-Disposition: form-data; name="description";' . $eol . $eol;
			$body .= $alt . $eol;
			$body .= '--' . $boundary . $eol;
		}

		// The actual (binary) image data.
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . $eol;
		$body .= 'Content-Type: ' . mime_content_type( $file_path ) . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= '--' . $boundary . '--'; // Note the extra two hyphens at the end.

		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/api/v1/media' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->options['mastodon_access_token'],
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'data_format' => 'body',
				'body'        => $body,
				'timeout'     => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// Decode JSON, suppressing possible formatting errors.
		$media = @json_decode( $response['body'] );

		if ( ! empty( $media->id ) ) {
			return $media->id;
		}

		// Provided debugging's enabled, let's store the (somehow faulty)
		// response.
		error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
