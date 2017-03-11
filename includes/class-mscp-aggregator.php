<?php
/**
 * This is the class that does all the heavy lifting
 * and the actual crossposting of the posts
 *
 * @author OzTheGreat
 * @since  0.0.1
 * @package wpartisan-multisite-crossposter
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	 exit;
}

class MSCP_Aggregator {

	/**
	 * __construct function
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		$this->hooks();
	}

	/**
	 * Register hooks here.
	 *
	 * @access public
	 * @return void
	 */
	public function hooks() {
		add_action( 'template_redirect',            array( $this, 'post_redirect' ), 10, 0 );
		add_action( 'save_post',                    array( $this, 'schedule_post_aggregation' ), 999, 3 );
		add_action( 'mscp_aggregate_post',          array( $this, 'aggregate_post' ), 10, 2 );
		add_action( 'mscp_aggregate_post_taxonomy', array( $this, 'aggregate_post_taxonomy' ), 10, 2 );
		add_action( 'trash_post',                   array( $this, 'delete_post_aggregates' ), 10, 1 );
		add_action( 'before_delete_post',           array( $this, 'delete_post_aggregates' ), 10, 1 );
		add_action( 'mscp_post_blogs_saved',        array( $this, 'sync_post_aggregates' ), 10, 3 );
		add_action( 'mscp_aggregate_post_deletion', array( $this, 'aggregate_post_deletion' ), 10, 3 );

		add_filter( 'post_link',                            array( $this, 'post_link' ), 10, 2 );
		add_filter( 'mscp_schedule_post_aggregation_blogs', array( $this, 'check_user_blogs_permissions' ), 10, 2 );
	}

	/**
	 * Redirect aggregated posts to the original post.
	 *
	 * @access public
	 * @return void
	 */
	public function post_redirect() {

		// Get the original permalink (if any).
		$original_permalink = get_post_meta( get_the_ID(), '_aggregator_permalink', true );

		// If empty then no permalink.
		if ( empty ( $original_permalink ) ) {
			return;
		}

		/**
		 * Allow plugins and themes to stop Aggregator from redirecting.
		 *
		 * @var bool $should_redirect Whether (true) or not (false) we should actually perform a redirect.
		 * @var string $original_permalink The permalink of the original post.
		 */
		$should_redirect = apply_filters( 'mucp_should_redirect', true, $original_permalink );

		// Only redirect individual posts, when told to.
		if ( is_single() && $should_redirect ) {
			wp_redirect( $original_permalink, 301 );
			exit;
		}

	}

	/**
	 * Hooks the WP post_link filter to provide the original
	 * permalink (stored in post meta) when a permalink
	 * is requested from the index blog.
	 *
	 * @access public
	 * @param  string $permalink The permalink.
	 * @param  object $post A WP Post object.
	 * @return string A permalink.
	 **/
	public function post_link( $permalink, $post ) {

		if ( $original_permalink = get_post_meta( $post->ID, '_aggregator_permalink', true ) ) {
			return $original_permalink;
		}

		return $permalink;
	}

	/**
	 * Takes a post and aggregates it to all the required blogs.
	 *
	 * @access public
	 * @param  int     $post_id
	 * @param  object  $post
	 * @param  boolean $update
	 * @return null
	 */
	public function schedule_post_aggregation( $post_id, $post, $update = false ) {

		// If this is just a revision, don't do anything.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Bail early if it's a crossposted post.
		if ( get_post_meta( $post_id, '_aggregator_orig_post_id', true ) || get_post_meta( $post_id, '_aggregator_orig_blog_id', true ) ) {
			return;
		}

		// Unhook this function so it doesn't loop infinitely.
		remove_action( 'save_post', array( $this, __FUNCTION__ ), 10, 3 );

		if ( $blogs = get_post_meta( $post_id, '_mscp_blogs', true ) ) {

			/**
			 * You can fitler the blogs here before the CRON is scheduled.
			 * Reason for this is so you have access to the current user as
			 * you wouldn't if it was inside the CRON.
			 *
			 * Check the user blog permissions here.
			 * @var array   $blogs
			 * @var WP_Post $post
			 */
			$blogs = apply_filters( 'mscp_schedule_post_aggregation_blogs', $blogs, $post );

			// Schedule a new sync if one isn't already scheduled.
			if ( ! wp_next_scheduled( 'mscp_aggregate_post', array( $blogs, $post ) ) ) {
				wp_schedule_single_event( time(), 'mscp_aggregate_post', array( $blogs, $post ) );
			}

			// Ping the cron on the portal site to trigger term import now.
			// Only if WP CRON is enabled.
			if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
				wp_remote_get(
					get_home_url( get_current_blog_id(), 'wp-cron.php' ),
					array(
						'blocking' => false,
					)
				);
			}

		}

		// Re-hook this function.
		add_action( 'save_post', array( $this, __FUNCTION__ ), 10, 3 );
	}

	/**
	 * Takes a post and aggregates it to all the required blogs.
	 * Run in the background so as not to slow everything down.
	 *
	 * @access public
	 * @param  array  $blogs The blogs to crosspost the post to.
	 * @param  object $original_post The post to crosspost.
	 * @return void
	 */
	public function aggregate_post( $blogs, $original_post ) {

		// Unhook these functions so it doesn't loop infinitely.
		remove_action( 'save_post', array( $this, 'schedule_post_aggregation' ), 999, 3 );
		remove_filter( 'post_link', array( $this, 'post_link' ), 10, 2 );

		// Setup the post data for the post that will get aggregated.
		$post_data = $this->prepare_post_data( $original_post );

		// Setup the meta fields for the post that will get aggregated.
		$post_meta = $this->prepare_post_meta_data( $original_post );

		// Setup the taxonomies for the post that will get aggregated.
		$post_taxonomy = $this->prepare_post_taxonomy( $original_post );

		// Get the featured image if there is one.
		$featured_image = $this->prepare_featured_image( $original_post );

		$original_blog_id = get_current_blog_id();

		// Cycle through all the blogs to aggregate it to.
		foreach ( $blogs as $blog_id ) {

			// Just double-check it's an int so we don't get any nasty errors.
			if ( ! intval( $blog_id ) ) {
				continue;
			}

			// It should never be, but just check the sync site isn't the current site.
			// That'd be horrific (probably).
			if ( $blog_id == $original_blog_id ) {
				continue;
			}

			// Make sure the destination site exists! A good thing to be sure of.
			$blog = get_blog_details( $blog_id, true );

			if ( false === $blog || empty( $blog ) ) {
				continue;
			}

			switch_to_blog( $blog_id );

			/**
			 * Just incase you want to filter the post data based on a blog ID.
			 * @var object $post
			 */
			$post_data = apply_filters( 'mscp_aggregated_post_data-' . $blog_id, $post_data );

			/**
			 * Just incase you want to filter the post meta based on a blog ID.
			 * @var object $post
			 */
			$post_meta = apply_filters( 'mscp_aggregated_post_meta-' . $blog_id, $post_meta );

			/**
			 * Just incase you want to filter the post taxonomy based on a blog ID.
			 * @var object $post
			 */
			$post_taxonomy = apply_filters( 'mscp_aggregated_post_taxonomy-' . $blog_id, $post_taxonomy );

			/**
			 * Just incase you want to filter the post featured image based on a blog ID.
			 * @var object $post
			 */
			$featured_image = apply_filters( 'mscp_aggregated_post_featured_image-' . $blog_id, $featured_image );

			// Set the metadata on the post object. WordPress >= 4.4.
			$post_data->meta_input = $post_meta;

			// Check if the post has been previously crossposted or not.
			$aggregated_query = $this->get_aggregated_posts_query( $original_blog_id, $original_post->ID );

			// Update if it exists otherwise insert.
			if ( $aggregated_query->have_posts() ) {
				$post_data->ID = $aggregated_query->posts[0];

				// Clear all the old meta on the aggregated post.
				$target_meta_data = get_post_meta( $post_data->ID );

				// Loop through and remove them all.
				foreach ( $target_meta_data as $meta_key => $meta_rows ) {
					delete_post_meta( $post_data->ID, $meta_key );
				}

				$aggregated_post_id = wp_update_post( $post_data, true );
			} else {
				$aggregated_post_id = wp_insert_post( $post_data, true );
			}

			// Check if it's been inserted or not.
			if ( ! is_wp_error( $aggregated_post_id ) ) {

				// Featured Image.
				if ( $featured_image ) {

					// Because of the various CDN plugins around we can't just
					// use media_handle_upload. We have to actually retrieve the
					// image then re-upload it.
					$image = wp_remote_get( $featured_image->url );

					if ( 200 === wp_remote_retrieve_response_code( $image ) ) {

						// Upload the image to this site's upload dir.
						$attachment = wp_upload_bits( basename( $featured_image->path ), null, $image['body'], date( "Y-m", strtotime( $image['headers']['last-modified'] ) ) );

						if ( empty( $attachment['error'] ) ) {

							// Get the filetype.
							$filetype = wp_check_filetype( basename( $attachment['file'] ), null );

							$filename = $attachment['file'];

							// Setup the attachment data.
							$attachment_data = array(
								'post_mime_type' => $filetype['type'],
								'post_title'     => $featured_image->post_title,
								'post_excerpt'   => $featured_image->post_excerpt,
								'post_content'   => '',
								'post_status'    => 'inherit',
							);

							// Insert the new attachment data with our basic information.
							$attachment_id = wp_insert_attachment( $attachment_data, $filename, $aggregated_post_id );

							// Check the wp_generate_attachment_metadata() function exists.
							if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
								require_once ( ABSPATH . 'wp-admin/includes/image.php' );
							}

							// Generate the raw iamge meta data.
							$attachment_data = wp_generate_attachment_metadata( $attachment_id, $filename );

							// Save the image meta data to the new attachment.
							wp_update_attachment_metadata( $attachment_id,  $attachment_data );

							// Set as the feature image.
							set_post_thumbnail( $aggregated_post_id, $attachment_id );
						} else {
							error_log( "MSCP: Error uploading attachment. post_id = {$aggregated_post_id}, blog_id = $blog_id", 0 );
						}

					}

				}

				// Taxonomy.

				// switch_to_blog() doesn't populate taxonomies and functions so
				// we can't really import them from this blog. Schedule and event on
				// the actual blog to do just this.
				if ( ! wp_next_scheduled( 'mscp_aggregate_post_taxonomy', array( $aggregated_post_id, $post_taxonomy ) ) ) {
					wp_schedule_single_event( time(), 'mscp_aggregate_post_taxonomy', array( $aggregated_post_id, $post_taxonomy ) );
				}

				// Ping the cron on the portal site to trigger term import now.
				// Only if WP CRON is enabled.
				if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
					wp_remote_get(
						get_home_url( $blog_id, 'wp-cron.php' ),
						array(
							'blocking' => false,
						)
					);
				}

			}

			restore_current_blog();
		}

	}

	/**
	 * Import all Taxonomies for an aggregated post.
	 *
	 * @access public
	 * @return void
	 */
	public function aggregate_post_taxonomy( $post_id, $post_taxonomy ) {

		foreach ( $post_taxonomy as $taxonomy => $terms ) {

			// Make sure the taxonomy exists before importing.
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			// Storage for terms of this taxonomy that will be imported.
			$target_terms = array();

			// Go thruogh each term
			foreach ( $terms as $slug => $name ) {

				// Get the term if it exists...
				if ( $term = get_term_by( 'name', $name, $taxonomy ) ) {
					$term_id = $term->term_id;

				// ...otherwise, create it.
				} else {

					$result = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );

					if ( ! is_wp_error( $result ) ) {
						$term_id = $result[ 'term_id' ];
					} else {
						$term_id = 0; // Couldn't create term for some reason.
					}
				}

				// Add the term to our import array.
				$target_terms[] = absint( $term_id );
			}

			// Import the terms for this taxonomy.
			wp_set_object_terms( $post_id, $target_terms, $taxonomy );
		}
	}

	/**
	 * When a source post is deleted / trashed schedule all its aggregated
	 * posts to be deleted.
	 *
	 * @access public
	 * @param  int $origin_post_id The ID of the source post.
	 * @return void
	 */
	public function delete_post_aggregates( $origin_post_id ) {
		if ( $blogs = get_post_meta( $origin_post_id, '_mscp_blogs', true ) ) {
			$this->schedule_post_deletion( $blogs, $origin_post_id, get_current_blog_id() );
		}
	}

	/**
	 * When a source post is deleted cycle though everysite
	 * it's been crossposted to and delete it there as well.
	 *
	 * @access public
	 * @param  WP_Post $post The post being deleted.
	 * @param  array   $new_blog_ids The sites it should be crossposted to.
	 * @param  array   $old_blog_ids The sites it's curerntly crossposted to.
	 * @return void
	 */
	public function sync_post_aggregates( $post, $new_blog_ids, $old_blog_ids ) {
		if ( $blogs = array_diff( $old_blog_ids, $new_blog_ids ) ) {
			$this->schedule_post_deletion( $blogs, $post->ID, get_current_blog_id() );
		}
	}

	/**
	 * Schedules a post to be deleted on all the blogs provided.
	 *
	 * @access public
	 * @param  array $blogs Array of blogs to crosspost it to.
	 * @param  int   $origin_post_id ID of the source post.
	 * @param  int   $origin_blog_id ID of the source blog.
	 * @return void
	 */
	public function schedule_post_deletion( $blogs, $origin_post_id, $origin_blog_id ) {

		wp_schedule_single_event( time(), 'mscp_aggregate_post_deletion', array( $blogs, $origin_post_id, $origin_blog_id ) );

		// Ping the cron on the portal site to trigger the delete.
		// Only if WP CRON is enabled.
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			wp_remote_get(
				get_home_url( get_current_blog_id(), 'wp-cron.php' ),
				array(
					'blocking' => false,
				)
			);
		}
	}

	/**
	 * Deletes all post aggregates on all blogs.
	 *
	 * @access public
	 * @param  mixed $blogs          An interger or array of blog ids to remove the post from.
	 * @param  int   $origin_post_id The post ID to remove.
	 * @return void
	 */
	public function aggregate_post_deletion( $blogs, $origin_post_id, $origin_blog_id ) {

		if ( ! is_array( $blogs ) ) {
			$blogs = array( $blogs );
		}

		/**
		 * Just incase you want to filter the aggregated posts
		 * before they're deleted on the blogs.
		 * @var array $blogs
		 * @var int   $origin_post_id
		 * @var int   $origin_blog_id
		 */
		$blogs = apply_filters( 'mscp_post_deletion_blogs', $blogs, $origin_post_id, $origin_blog_id );

		foreach ( $blogs as $blog_id ) 	{

			if ( $blog_id === $origin_blog_id ) {
				continue;
			}

			switch_to_blog( $blog_id );

			$query = $this->get_aggregated_posts_query( $origin_blog_id, $origin_post_id );

			// If there are posts, get the ID of the first one and delete it.
			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}
			}

			restore_current_blog();
		}

	}

	/**
	 * Returns the aggregated post ID from a blog.
	 *
	 * @access public
	 * @param  int $origin_blog_id ID of the source blog.
	 * @param  int $origin_post_id ID of the source post.
	 * @return WP_Query
	 */
	public function get_aggregated_posts_query( $origin_blog_id, $origin_post_id ) {
		// Build a query, checking for the relevant meta data.
		$args = array(
			'post_type'              => 'any',
			'post_status'            => array( 'publish', 'pending', 'draft', 'future', 'private', 'trash' ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'meta_query'             => array(
				'relation' => 'AND',
				array(
					'key'   => '_aggregator_orig_post_id',
					'value' => $origin_post_id,
					'type'  => 'numeric'
				),
				array(
					'key'   => '_aggregator_orig_blog_id',
					'value' => $origin_blog_id,
					'type'  => 'numeric',
				)
			),
		);

		/**
		 * Filter the WP_Query arguments should you wish.
		 *
		 * @var array WP_Query arguments.
		 * @var int   $origin_post_id ID of the source blog.
		 * @var int   $origin_blog_id ID of the source post.
		 */
		$args = apply_filters( 'mscp_get_aggregated_posts_query', $args, $origin_blog_id, $origin_post_id );

		return new WP_Query( $args );
	}

	/**
	 * Takes the source post and clones it ready for crossposting.
	 * Unsets fields that may not be necessary.
	 *
	 * @access public
	 * @param  object $original_post The source post.
	 * @return void
	 */
	public function prepare_post_data( $original_post ) {

		// Ensure it's a post object.
		$original_post = get_post( $original_post );

		$post_data = clone $original_post;
		unset( $post_data->ID );

		// Remove post_tag and category as they're covered later on with other taxonomies.
		unset( $post_data->tags_input );
		unset( $post_data->post_category );

		/**
		 * Alter the post data before syncing.
		 *
		 * Allows plugins or themes to modify the main post data due to be pushed to the portal site.
		 * @var array $post_data Array of post data, such as title and content.
		 * @var int $post_id The ID of the original post.
		 */
		$post_data = apply_filters( 'mscp_prepare_post_data', $post_data, $original_post->ID );

		return $post_data;
	}

	/**
	 * Takes the source post's meta data and clones it ready for crossposting.
	 * Unsets fields that may not be necessary.
	 *
	 * @access public
	 * @param  object $post The source post object.
	 * @return void
	 */
	public function prepare_post_meta_data( $post ) {

		// Get all the meta date for this post.
		$meta_data = (array) get_metadata( 'post', $post->ID, null, true );

		// Re-key it to take account of arrays.
		foreach ( $meta_data as $key => $value ) {
			$meta_data[ $key ] = count( $value ) == 1 ? maybe_unserialize( $value[0] ) : array_map( 'maybe_unserialize', $value );
		}

		// These are all post specific, we don't want any of them.
		unset( $meta_data['_thumbnail_id'] ); // Remove thumbnail.
		unset( $meta_data['_edit_last'] ); // Related to edit lock, should be individual to translations.
		unset( $meta_data['_edit_lock'] ); // The edit lock, should be individual to translations.
		unset( $meta_data['_bbl_default_text_direction'] ); // The text direction, should be individual to translations.
		unset( $meta_data['_wp_trash_meta_status'] );
		unset( $meta_data['_wp_trash_meta_time'] );

		// No point clogging up the DB with these.
		unset( $meta_data['_pingme'] );
		unset( $meta_data['_encloseme'] );
		unset( $meta_data['_post_restored_from'] );
		unset( $meta_data['_yoast_wpseo_content_score'] );

		// Just incase.
		unset( $meta_data['_mscp_blogs'] );

		// Add our special Aggregator meta data.
		$meta_data[ '_aggregator_permalink' ] = get_permalink( $post->ID );
		$meta_data[ '_aggregator_orig_post_id' ] = $post->ID;
		$meta_data[ '_aggregator_orig_blog_id' ] = get_current_blog_id();

		/**
		 * Filter the meta data that's crossposted.
		 * @var array  $meta_data
		 * @var object $post
		 */
		$meta_data = apply_filters( 'mscp_prepare_post_meta_data', $meta_data, $post );

		return $meta_data;
	}

	/**
	 * Takes the source post's taxonomy, clones them and gets them ready
	 * for crossposting. Unsets any that may not be necessary.
	 *
	 * @access public
	 * @param  WP_Post $post
	 * @return void
	 */
	public function prepare_post_taxonomy( WP_Post $post ) {
		$taxonomies = get_object_taxonomies( $post );

		// Prepare to store the taxonomy terms.
		$terms = array();

		// Check each taxonomy.
		foreach ( $taxonomies as $taxonomy ) {

			$terms[ $taxonomy ] = array();

			// Get the terms from this taxonomy attached to the post.
			$tax_terms = wp_get_object_terms( $post->ID, $taxonomy );

			// Add each of the attached terms to our new array.
			foreach ( $tax_terms as & $term ) {
				$terms[ $taxonomy ][ $term->slug ] = $term->name;
			}

		}

		/**
		 * Filter the terms that are getting crossposted.
		 * @var array  $terms
		 * @var object $post
		 */
		$terms = apply_filters( 'mscp_prepare_post_taxonomy', $terms, $post );

		return $terms;
	}

	/**
	 * Grab the featured image for the source post, if one exists.
	 *
	 * @access public
	 * @param  int         $post ID of the post to be synced.
	 * @return bool|string Returns false if no thumbnail exists, else the image URL.
	 */
	public function prepare_featured_image( $post ) {

		$attachment = false;

		// Check if there's a featured image.
		if ( has_post_thumbnail( $post->ID ) ) {

			// Get the ID of the featured image.
			$thumb_id = get_post_thumbnail_id( $post->ID );

			$attachment = get_post( $thumb_id );

			// We need to add some fields before we switch blog.
			// Get the full URl.
			$attachment->url = wp_get_attachment_url( $attachment->ID );

			// Get the path.
			$attachment->path = get_attached_file( $attachment->ID );
		}

		/**
		 * Filter the featured image attachment that's getting crossposted.
		 * @var array  $attachment
		 * @var object $post
		 */
		$attachment = apply_filters( 'mscp_prepare_post_featured_image', $attachment, $post );

		return $attachment;
	}

	/**
	 * Checks the user can edit posts on the blogs provided.
	 *
	 * @access public
	 * @param  array $blogs
	 * @param  object $post The post being deleted / edited.
	 * @return array
	 */
	public function check_user_blogs_permissions( $blogs, $post ) {

		foreach ( $blogs as $key => $blog_id ) {
			if ( ! current_user_can_for_blog( $blog_id, 'edit_posts' ) ) {
				unset( $blogs[ $key ] );
			}
		}

		return $blogs;
	}

}
