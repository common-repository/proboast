<?php
/**
 * @package ProBoast
 * ProBoast plugin functionalities.
 */
class ProBoast {

	/**
	 * Validates that current WP version is greater or equal to minimum required version.
	 */
	public static function check_version() {
		if ( version_compare( $GLOBALS['wp_version'], PROBOAST__MINIMUM_WP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			self::plugin_not_compatible_message();
		}
	}

	/**
	 * Attached by register_activation_hook()
	 */
	public static function plugin_activation() {
		self::check_version();
	}

	/**
	 * Validates that current WP version is greater or equal to minimum required version.
	 */
	public static function plugin_not_compatible_message() {
		wp_die( esc_html( '<strong>' . sprintf( 'ProBoast %s requires WordPress %s or higher.', PROBOAST_VERSION, PROBOAST__MINIMUM_WP_VERSION ) . '</strong> ' . sprintf( 'Please <a href="%1$s">upgrade WordPress</a> to a current version.', 'https://wordpress.org/support/article/updating-wordpress/' ) ) );
	}


	/**
	 * Adds proboast_photo_album custom post type.
	 */
	public static function custom_post_type() {

		// Add a photo album post type.
		register_post_type(
			'proboast_photo_album',
			array(
				'labels'       => array(
					'name'          => 'Photo Albums',
					'singular_name' => 'Photo Album',
				),
				'public'       => true,
				'rewrite'      => array(
					'slug' => 'album',
				),
				'show_in_rest' => true,
				'supports'     => array(
					'title',
					'editor',
					'thumbnail',
					'custom-fields',
				),
				'has_archive'  => true,
			)
		);
	}

	/**
	 * Initialized REST API endpoint.
	 * Attached by add_action('rest_api_init').
	 */
	public static function rest_api_init() {
		register_rest_route(
			'/proboast/v1',
			'/webhooks',
			array(
				'methods'  => 'POST',
				'callback' => array( 'ProBoast', 'proboast_rest_api_webhooks_processor' ),
			)
		);
	}

	/**
	 * Uploads an image from ProBoast remote URL.
	 *
	 * @param string $image_url The remote URL.
	 * @param string $caption Caption to store with the image.
	 *
	 * @return int $attach_id The attachment id.
	 */
	private static function upload_image( $image_url, $caption = null ) {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$upload_directory = wp_upload_dir();

		$retrieve_image_response = wp_remote_get( $image_url );
		$image_data              = wp_remote_retrieve_body( $retrieve_image_response );

		$filename = basename( $image_url );

		// Explode filename to eliminate GET parameters at end of URL.
		$exploded_filename = explode( '?', $filename );
		$filename          = $exploded_filename[0];

		if ( wp_mkdir_p( $upload_directory['path'] ) ) {
			$file = $upload_directory['path'] . '/' . $filename;
		} else {
			$file = $upload_directory['basedir'] . '/' . $filename;
		}

		$proboast_plugin_options = get_option( 'proboast_plugin_options' );
		if ( ! empty( $proboast_plugin_options['use_php_file_put_contents'] ) ) {

			/**
			 * A website may need to rely on PHP's file_put_contents if images
			 * are not properly uploading. Another resolution is to define the FS_METHOD
			 * in wp-config.php. If that approach is desired, insert: define('FS_METHOD', 'direct');
			 * in wp-config.php.
			 */
			file_put_contents( $file, $image_data );
		} else {
			$wp_filesystem->put_contents( $file, $image_data );
		}

		$wp_filetype = wp_check_filetype( $filename, null );

		$attachment = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $file );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;

	}

	/**
	 * Generates a Gutenberg core/gallery markup.
	 *
	 * @param array  $ids An array of attachment ids to include.
	 * @param string $columns The number of columns.
	 *
	 * @return string $output The Gutenberg block markup as a string.
	 */
	public static function generate_gutenberg_gallery_markup( array $ids, string $columns ) {

		// Open with Gutenberg markup <figure><ul>.
		$output = '<figure class="wp-block-gallery columns-' . $columns . ' is-cropped"><ul class="blocks-gallery-grid">';

		// Loop through each image and append Gutenberg tag markup.
		foreach ( $ids as $id ) {

			// Set image url variables to be used in image tag.
			$image = wp_get_attachment_image_src( $id, 'full' );
			$url   = $image[0];

			// Append Gutenberg markup.
			$output .= '<li class="blocks-gallery-item">';
			$output .= '<figure>';
			$output .= '<img src="' . $url . '" alt="" data-id="' . $id . '" data-full-url="' . $url . '" data-link="' . $url . '" class="wp-image-' . $id . '"/>';
			$output .= '</figure>';
			$output .= '</li>';

		}

		// Close the Gutenberg <ul><figure> tags.
		$output .= '</ul></figure>';

		// Return Gutenberg markup as string.
		return $output;

	}

	/**
	 * Generates a Gutenberg core/gallery array.
	 *
	 * @param array $image_ids An array of attachment ids to include.
	 *
	 * @return array $output The Gutenberg block as a array.
	 */
	public static function generate_gutenberg_gallery_array( array $image_ids ) {
		$count   = count( $image_ids );
		$columns = 2;
		if ( 1 === $count ) {
			$columns = 1;
		}

		$output['blockName']        = 'core/gallery';
		$output['attrs']['ids']     = $image_ids;
		$output['attrs']['linkTo']  = 'file';
		$output['attrs']['columns'] = $columns;
		$output['innerBlocks']      = array();

		$html                   = self::generate_gutenberg_gallery_markup( $image_ids, $columns );
		$output['innerHTML']    = $html;
		$output['innerContent'] = array( $html );

		return $output;
	}

	/**
	 * Generates a Gutenberg core/gallery array.
	 *
	 * @param WP_REST_Request $request The $request object.
	 *
	 * @return WP_REST_Response|WP_Error The response (200+) or error.
	 */
	public static function proboast_rest_api_webhooks_processor( WP_REST_Request $request ) {

		// Get command type.
		$command = $request->get_param( 'command-type' );

		switch ( $command ) {

			case 'connect-website':
				$proboast_plugin_options = get_option( 'proboast_plugin_options' );

				// Push Authorization Token.
				$proboast_site_uuid = ( ! empty( $proboast_plugin_options['proboast_authorization_token'] ) )
				? $proboast_plugin_options['proboast_authorization_token']
				: null;

				// Site UUID.
				$proboast_site_uuid = ( ! empty( $proboast_plugin_options['proboast_site_uuid'] ) )
				? $proboast_plugin_options['proboast_site_uuid']
				: null;

				// Custom salt.
				$proboast_authorization_token_salt = ( ! empty( $proboast_plugin_options['proboast_authorization_token_salt'] ) )
				? $proboast_plugin_options['proboast_authorization_token_salt']
				: null;

				// ProBoast code.
				$proboast_code = ( ! empty( $proboast_plugin_options['proboast_code'] ) )
				? $proboast_plugin_options['proboast_code']
				: null;

				$request_code = $request->get_param( 'code' );
				$request_uuid = $request->get_param( 'uuid' );

				if ( ( ! empty( $request_code ) && ( $request_code === $proboast_code ) )
				&& ( ! empty( $proboast_site_uuid ) && ( $proboast_site_uuid === $request_uuid ) ) ) {

					$string_to_hash = $proboast_plugin_options['proboast_authorization_token_salt'] . $proboast_code . time();
					$proboast_plugin_options['proboast_authorization_token']              = hash( 'sha256', $string_to_hash );
					$proboast_plugin_options['proboast_authorization_token_last_updated'] = time();
					update_option( 'proboast_plugin_options', $proboast_plugin_options );

					$data = array( 'push-authorization-token' => $proboast_plugin_options['proboast_authorization_token'] );

					// Create the response object.
					$response = new WP_REST_Response( $data );

					// Add a custom status code.
					$response->set_status( 200 );

					return $response;

				}

				break;

			case 'create-album':
				// Confirm access.
				$proboast_plugin_options  = get_option( 'proboast_plugin_options' );
				$push_authorization_token = $request->get_param( 'push-authorization-token' );
				if (
				! empty( $proboast_plugin_options['proboast_authorization_token'] )
				&& ( $proboast_plugin_options['proboast_authorization_token'] === $push_authorization_token )
				) {

					// Set album variables.
					$album_uuid = $request->get_param( 'album-uuid' );
					$title      = $request->get_param( 'title' );

					// Confirm that album does not yet exist.
					$existing_photo_albums = get_posts(
						array(
							'post_type'  => 'proboast_photo_album',
							'meta_key'   => 'album-uuid',
							'meta_value' => $album_uuid,
							'guid'       => $album_uuid,
						)
					);

					if ( empty( $existing_photo_albums ) ) {

						/**
						 * Create album.
						 * See https://developer.wordpress.org/reference/functions/wp_insert_post/
						 */
						$postarr = array(
							'post_type'   => 'proboast_photo_album',
							'post_title'  => $title,
							'meta_input'  => array(
								'album-uuid' => $album_uuid,
							),
							'guid'        => $album_uuid,
							'post_status' => 'publish',
						);
						$post_id = wp_insert_post( $postarr );

						if ( ! empty( $post_id ) ) {

							$data = array( 'album-uuid' => $post_id );

							// Create the response object.
							$response = new WP_REST_Response( $data );

							// Add a custom status code.
							$response->set_status( 200 );

						}

						return $response;

					}

					return new WP_Error( 'failure' );

				}

				break;

			case 'create-image':
				$album_uuid = $request->get_param( 'album-uuid' );
				$image_url  = $request->get_param( 'image-url' );

				$album_search = get_posts(
					array(
						'post_type'  => 'proboast_photo_album',
						'meta_query' => 'album-uuid',
						'meta_value' => $album_uuid,
					)
				);

				if ( ! empty( $album_search ) ) {

					$attachment_id      = self::upload_image( $image_url );
					$image              = wp_get_attachment_image_src( $attachment_id, 'full' );
					$image_url          = $image[0];
					$image_preferred_id = $attachment_id;

					if ( ! empty( $attachment_id ) ) {

						$album_id = $album_search[0]->ID;
						$post     = get_post( $album_id );
						$blocks   = parse_blocks( $post->post_content );

						$gallery_discovered = false;

						foreach ( $blocks as $block_key => $block ) {

							if ( 'core/gallery' === $block['blockName'] ) {

								$block['attrs']['ids'][] = $attachment_id;

								$gallery_array        = self::generate_gutenberg_gallery_array( $block['attrs']['ids'] );
								$blocks[ $block_key ] = $gallery_array;

								// Found a gallery to append to.
								$gallery_discovered = true;

							}
						}

						if ( ! $gallery_discovered ) {

							// Create a new gallery.
							$gallery_array = self::generate_gutenberg_gallery_array( array( $attachment_id ) );
							$blocks[]      = $gallery_array;

						}

						$post_content = serialize_blocks( $blocks );

						$postarr = array(
							'ID'           => $album_id,
							'post_content' => $post_content,
						);

						$wp_update_post_response = wp_update_post( $postarr );

					}
				}

				if ( ! empty( $wp_update_post_response ) ) {

					$data = array(
						'image-preferred-id' => $image_preferred_id,
						'image-url'          => $image_url,
					);

					// Create the response object.
					$response = new WP_REST_Response( $data );

					// Add a custom status code.
					$response->set_status( 200 );

					return $response;

				} else {

					// return error.
					return new WP_Error( 'failure' );

				}

				break;

			case 'delete-image':
				$album_uuid         = $request->get_param( 'album-uuid' );
				$image_uuid         = $request->get_param( 'image-uuid' );
				$image_url          = $request->get_param( 'image-url' );
				$image_preferred_id = $request->get_param( 'image-preferred-id' );

				$album_search = get_posts(
					array(
						'post_type'  => 'proboast_photo_album',
						'meta_query' => 'album-uuid',
						'meta_value' => $album_uuid,
					)
				);

				if ( ! empty( $album_search ) ) {

					$album_id = $album_search[0]->ID;
					$post     = get_post( $album_id );
					$blocks   = parse_blocks( $post->post_content );

					$gallery_discovered = false;

					foreach ( $blocks as $block_key => $block ) {

						if ( 'core/gallery' === $block['blockName'] ) {

							$key = array_search( $image_preferred_id, $block['attrs']['ids'], true );

							if ( false !== $key ) {
								unset( $block['attrs']['ids'][ $key ] );
							}

							$gallery_array        = self::generate_gutenberg_gallery_array( $block['attrs']['ids'] );
							$blocks[ $block_key ] = $gallery_array;

							// Found a gallery to append to.
							$gallery_discovered = true;

						}
					}

					if ( $gallery_discovered ) {

						$post_content = serialize_blocks( $blocks );

						$postarr = array(
							'ID'           => $album_id,
							'post_content' => $post_content,
						);

						// Update the post and insert reformatted blocks.
						$wp_update_post_response = wp_update_post( $postarr );

						// Delete the image.
						wp_delete_attachment( $image_preferred_id );

					}
				}

				if ( $wp_update_post_response ) {

					$data = array(
						'deleted' => true,
					);

					// Create the response object.
					$response = new WP_REST_Response( $data );

					// Add a custom status code.
					$response->set_status( 200 );

					return $response;

				} else {

					// return error.
					return new WP_Error( 'failure' );

				}

				break;

		}

		return new WP_Error( 'failure' );

	}

	/**
	 * Register settings for plugin admin form.
	 * Attached by add_action('admin_init').
	 */
	public static function register_settings() {

		register_setting(
			'proboast_plugin_options',
			'proboast_plugin_options',
			array( 'ProBoast', 'proboast_plugin_options_validate' )
		);

		add_settings_section(
			'proboast_settings',
			'ProBoast Settings',
			array( 'ProBoast', 'plugin_section_text' ),
			'proboast_plugin'
		);

		add_settings_field(
			'proboast_authorization_token',
			'ProBoast Authorization Token',
			array( 'ProBoast', 'proboast_authorization_token' ),
			'proboast_plugin',
			'proboast_settings'
		);

		add_settings_field(
			'proboast_authorization_token_salt',
			'ProBoast Authorization Token Salt (use a custom phrase)',
			array( 'ProBoast', 'proboast_authorization_token_salt' ),
			'proboast_plugin',
			'proboast_settings'
		);

		add_settings_field(
			'proboast_site_uuid',
			'ProBoast Site UUID',
			array( 'ProBoast', 'proboast_site_uuid' ),
			'proboast_plugin',
			'proboast_settings'
		);

		add_settings_field(
			'proboast_code',
			'ProBoast Code (used for website connection)',
			array( 'ProBoast', 'proboast_code' ),
			'proboast_plugin',
			'proboast_settings'
		);

		add_settings_field(
			'use_php_file_put_contents',
			'Use PHP\'s file_put_contents()',
			array( 'ProBoast', 'use_php_file_put_contents' ),
			'proboast_plugin',
			'proboast_settings'
		);
	}

	/**
	 * Renders a admin notices area at top of options form.
	 */
	public static function general_admin_notice() {
		global $pagenow;
		$proboast_plugin_options = get_option( 'proboast_plugin_options' );

		if ( 'options-general.php' === $pagenow ) {

			if ( ! empty( $proboast_plugin_options['messages'] ) ) {
				$messages = '';

				// Loop through messages.
				foreach ( $proboast_plugin_options['messages'] as $key => $message ) {
					$messages .= '<div class="notice notice-' . $message['type'] . ' is-dismissible"><p>' . $message['message'] . '</p></div>';
					if ( ! empty( $message['destroy'] ) ) {
						unset( $proboast_plugin_options['messages'][ $key ] );
					}
				}

				echo esc_html( $messages );
				update_option( 'proboast_plugin_options', $proboast_plugin_options );
			}
		}

	}

	/**
	 * Adds a message to proboast_plugin_options in options storage.
	 *
	 * @param string $message The message to display.
	 * @param string $type The message type: 'success', 'warning', 'error', 'notice'.
	 * @param bool   $destroy TRUE: message is destroyed after display.
	 * @param array  $options The options array to use/return.
	 *
	 * @return array|TRUE Options array, or TRUE otherwise if options updated.
	 */
	public static function log_notice( string $message, string $type = null, bool $destroy = null, array $options = null ) {
		$type                  = ( ! empty( $type ) )
		? $type
		: 'notice';
		$destroy               = ( ! empty( $destroy ) )
		? $destroy
		: true;
		$options               = ( ! empty( $options ) )
		? $options
		: get_option( 'proboast_plugin_options' );
		$options['messages'][] = array(
			'message' => $message,
			'type'    => $type,
			'destroy' => $destroy,
		);
		if ( ! empty( $options ) ) {
			return $options;
		} else {
			update_option( 'proboast_plugin_options', $options );
			return true;
		}
	}

	/**
	 * Echos header text for register_settings form.
	 */
	public function plugin_section_text() {
		echo '<p>Configure ProBoast here.</p>';
		$options = get_option( 'proboast_plugin_options' );
		if ( ! empty( $options['proboast_authorization_token_last_updated'] ) ) {

			// Site is connected to ProBoast.com.
			$message = '<p style="color: green">Successfully connected to ProBoast.com last on ' . gmdate( 'F j, Y \a\t g:i A', $options['proboast_authorization_token_last_updated'] ) . '.</p>';
			echo wp_kses(
				$message,
				array(
					'p'      => array(
						'style' => true,
					),
					'strong' => true,
				)
			);

		} else {

			// Site is not yet connected to ProBoast.com.
			echo '<p style="color: orange">Not yet connected to ProBoast.com.</p>';
		}
	}

	/**
	 * Echos authorization token field for register_settings form.
	 */
	public function proboast_authorization_token() {
		$options = get_option( 'proboast_plugin_options' );
		$output  = "<input class='large-text ltr' id='proboast_authorization_token' name='proboast_plugin_options[proboast_authorization_token]' type='text' value='";
		$output .= esc_attr( $options['proboast_authorization_token'] );
		$output .= "' />";
		$output .= '<div><span class="description"><strong>This field should be automatically set upon a successful connection with ProBoast.com.</strong> ProBoast.com will send this "ProBoast Authorization Token" along with all web hooks and compare against the value in this field to confirm that the web hook command is valid.</span></div>';
		echo wp_kses(
			$output,
			array(
				'div'    => true,
				'span'   => array(
					'class' => true,
				),
				'strong' => true,
				'input'  => array(
					'class' => true,
					'id'    => true,
					'name'  => true,
					'type'  => true,
					'value' => true,
				),
			)
		);
	}

	/**
	 * Echos authorization token salt field for register_settings form.
	 */
	public function proboast_authorization_token_salt() {
		$options = get_option( 'proboast_plugin_options' );
		$output  = "<input class='large-text ltr' id='proboast_authorization_token_salt' name='proboast_plugin_options[proboast_authorization_token_salt]' type='text' value='";
		$output .= esc_attr( $options['proboast_authorization_token_salt'] );
		$output .= "' />";
		$output .= '<div><span class="description"><strong>Please set this field.</strong> Use any unique phrase here. This phrase will be combined with the "code" and timestamp then hashed at SHA-256 to generate the secure "Authorization Token".</span></div>';
		echo wp_kses(
			$output,
			array(
				'div'    => true,
				'span'   => array(
					'class' => true,
				),
				'strong' => true,
				'input'  => array(
					'class' => true,
					'id'    => true,
					'name'  => true,
					'type'  => true,
					'value' => true,
				),
			)
		);

	}

	/**
	 * Echos ProBoast site uuid field for register_settings form.
	 */
	public function proboast_site_uuid() {
		$options = get_option( 'proboast_plugin_options' );
		$output  = "<input class='regular-text ltr' id='proboast_authorization_token' name='proboast_plugin_options[proboast_site_uuid]' type='text' value='";
		$output .= esc_attr( $options['proboast_site_uuid'] );
		$output .= "' />";
		$output .= '<div><span class="description"><strong>Please set this field.</strong> Enter the value displayed for your website at <a href="https://proboast.com/my-websites" target="_blank">https://proboast.com/my-websites</a>.</span></div>';
		echo wp_kses(
			$output,
			array(
				'div'    => true,
				'a'      => array(
					'href'   => true,
					'target' => true,
				),
				'span'   => array(
					'class' => true,
				),
				'strong' => true,
				'input'  => array(
					'class' => true,
					'id'    => true,
					'name'  => true,
					'type'  => true,
					'value' => true,
				),
			)
		);
	}

	/**
	 * Echos ProBoast code field for register_settings form.
	 */
	public function proboast_code() {

		$options = get_option( 'proboast_plugin_options' );
		$code    = ( ! empty( $options['proboast_code'] ) )
		? $options['proboast_code']
		: random_int( 100000, 999999 );

		$output  = "<input id='proboast_code' name='proboast_plugin_options[proboast_code]' type='text' value='";
		$output .= esc_attr( $code );
		$output .= "' />";
		$output .= '<div><span class="description"><strong>Please set this field. If empty, a value is automatically entered.</strong> This "code" is used to validate a successful and authorized initial connection between ProBoast.com and this website.</span></div>';
		echo wp_kses(
			$output,
			array(
				'div'    => true,
				'span'   => array(
					'class' => true,
				),
				'strong' => true,
				'input'  => array(
					'class' => true,
					'id'    => true,
					'name'  => true,
					'type'  => true,
					'value' => true,
				),
			)
		);

	}

	/**
	 * Echos ProBoast code field for register_settings form.
	 */
	public function use_php_file_put_contents() {
		$options = get_option( 'proboast_plugin_options' );
		$checked = ( ! empty( $options['use_php_file_put_contents'] ) )
		? 'checked'
		: '';
		$output  = "<input id='use_php_file_put_contents' name='proboast_plugin_options[use_php_file_put_contents]' type='checkbox' value='1' $checked />";
		$output .= '<div><span class="description">If images are not being properly saved, there may be an issue with the file system method. When this checkbox is checked, 
			the plugin will use PHP\'s default file_put_contents() method.</div>';
		echo wp_kses(
			$output,
			array(
				'div'    => true,
				'span'   => array(
					'class' => true,
				),
				'strong' => true,
				'input'  => array(
					'checked' => true,
					'class'   => true,
					'id'      => true,
					'name'    => true,
					'type'    => true,
					'value'   => true,
				),
			)
		);

	}

	/**
	 * The input validation callback for register_settings form.
	 *
	 * @param array $options The inputs validated.
	 *
	 * @return $options The inputs validated.
	 */
	private static function proboast_plugin_options_validate( array $options ) {

		// Original option values.
		$proboast_plugin_options = get_option( 'proboast_plugin_options' );

		// if proboast_authorization_token_last_updated set, set again so not lost.
		if ( ! empty( $proboast_plugin_options['proboast_authorization_token_last_updated'] ) ) {
			$options['proboast_authorization_token_last_updated'] = $proboast_plugin_options['proboast_authorization_token_last_updated'];
		}

		// Validate proboast_code field.
		if ( empty( $options['proboast_authorization_token_salt'] ) ) {
			$options                                      = self::log_notice( '<strong>"ProBoast Authorization Token Salt" should be set.</strong> You are not fully secure unless it is. A random one has been generated.', 'warning', false, $options );
			$options['proboast_authorization_token_salt'] = md5( time() . random_int( 100000, 999999 ) );
		}

		// Validate proboast_code field.
		$options['proboast_site_uuid'] = trim( $options['proboast_site_uuid'] );
		if ( empty( $options['proboast_site_uuid'] ) ) {
			$options = self::log_notice( '<strong>"ProBoast Site UUID" must be set.</strong> You are not able to connect to ProBoast until it is.', 'error', false, $options );
		}

		// If proboast_site_uuid changes, unset successful connection message.
		if ( $options['proboast_site_uuid'] !== $proboast_plugin_options['proboast_site_uuid'] ) {
			unset( $options['proboast_authorization_token_last_updated'] );
			$options = self::log_notice( '<strong>"ProBoast Site UUID" changed.</strong> Please reconnect site at https://proboast.com.', 'notice', false, $options );
		}

		// Validate proboast_code field.
		$options['proboast_code'] = trim( $options['proboast_code'] );
		if ( strlen( $options['proboast_code'] ) < 6 ) {
			$options['proboast_code'] = random_int( 100000, 999999 );
			$options                  = self::log_notice( '<strong>"ProBoast Code" must be at least 6 digits.</strong> A new, random, code has been set.', 'error', false, $options );
		}

		return $options;

	}


	/**
	 * Adds settings page.
	 * Attached by add_action('admin_menu').
	 */
	public static function add_settings_page() {

		add_options_page(
			'ProBoast Configuration',
			'ProBoast',
			'manage_options',
			'proboast-config',
			array( 'ProBoast', 'proboast_render_plugin_settings_page' )
		);

	}

	/**
	 * Renders the plugin settings page.
	 */
	public static function proboast_render_plugin_settings_page() { ?>
	<form action="options.php" method="post">
		<?php
			settings_fields( 'proboast_plugin_options' );
			do_settings_sections( 'proboast_plugin' );
		?>
		<input name="submit" class="button button-primary" type="submit" value="<?php esc_attr_e( 'Save' ); ?>" />
	</form>
		<?php

	}

}
