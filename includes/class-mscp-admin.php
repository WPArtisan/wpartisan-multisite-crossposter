<?php
/**
 * Add a meta box to the post screen.
 * Allows slecting of a sites to crossposting to.
 *
 * @author OzTheGreat
 * @since  0.0.1
 * @package wpartisan-multisite-crossposter
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	 exit;
}

class MSCP_Admin {

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
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ), 10, 1 );
		add_action( 'add_meta_boxes',        array( $this, 'register_meta_boxes' ), 10, 0 );
		add_action( 'save_post',             array( $this, 'save_mscp_meta_box' ), 10, 3 );
		add_action( 'load-post.php',         array( $this, 'load_post_edit' ), 10, 0 );
		add_action( 'load-post-new.php',     array( $this, 'load_post_edit' ), 10, 0 );

		add_action( 'profile_update',        array( $this, 'clear_user_blog_cache' ), 10, 1 );
		add_action( 'set_user_role',         array( $this, 'clear_user_blog_cache' ), 10, 1 );

		add_filter( 'post_row_actions',      array( $this, 'post_row_actions' ), 9999, 2 );
		add_filter( 'page_row_actions',      array( $this, 'post_row_actions' ), 9999, 2 );
	}

	/**
	 * Load admin JS scripts.
	 *
	 * @access public
	 * @param  string $hook The unique page id it's runnign on.
	 * @return void
	 */
	public function scripts( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script( 'select2', plugins_url( 'assets/select2/js/select2.min.js', dirname( __FILE__ ) ), false, '4.0.1', true );
			wp_enqueue_script( 'mscp', plugins_url( 'assets/mscp-admin.js', dirname( __FILE__ ) ), false, '4.0.1', true );
		}
	}

	/**
	 * Load admin JS scripts.
	 *
	 * @access public
	 * @param  string $hook
	 * @return void
	 */
	public function styles( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'select2', plugins_url( 'assets/select2/css/select2.min.css', dirname( __FILE__ ) ), false, '4.0.1' );
		}
	}

	/**
	 * Stop pushed posts from being edited on a portal site.
	 *
	 * When an attempt is made to edit a post on a portal site that has been pushed from elsewhere,
	 * the user is told to take a running jump and given a link to go do so.
	 *
	 * @access public
	 * @return void
	 */
	public function load_post_edit() {
		// Get the id of the post currently being edited.
		$post_id = filter_input( INPUT_GET, 'post', FILTER_VALIDATE_INT );

		// Check if the it's been crossposted or not and get the original blog id.
		if ( $orig_blog_id = get_post_meta( $post_id, '_aggregator_orig_blog_id', true ) ) {
			// Get the original post id.
			$orig_post_id = get_post_meta( $post_id, '_aggregator_orig_post_id', true );
			// Get the original blog details.
			$blog_details = get_blog_details( array( 'blog_id' => $orig_blog_id ) );
			// Get the addess of the original blog and set the url scheme.
			$blog_address = set_url_scheme( get_blogaddress_by_id( $orig_blog_id ) );
			// Construct the edit URL for the post on the original site.
			$edit_url = add_query_arg( array(
				'action' => 'edit',
				'post'   => absint( $orig_post_id ),
			), trailingslashit( $blog_address ) . 'wp-admin/post.php' );

			// Construct the link to the original post.
			$edit_link = '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit Post', 'multisite-crossposter' ) . '</a>';
			// Construct the error message.
			$message = sprintf( __( 'Sorry, you must edit this post from the %1$s site: %2$s', 'multisite-crossposter' ), $blog_details->blogname, $edit_link );
			// Die with a message.
			wp_die( $message );
		}

	}

	/**
	 * Remove all but the "view" action link on synced posts.
	 *
	 * We don't want folks to edit synced posts on a portal site, so we want to remove the
	 * relevant action links from the posts table.
	 *
	 * @access public
	 * @param  array   $actions Action links array for filtering.
	 * @param  WP_Post $post WP_Post object representing the post being displayed.
	 * @return array   Filtered array of actions.
	 */
	public function post_row_actions( $actions, $post ) {

		if ( $orig_blog_id = get_post_meta( $post->ID, '_aggregator_orig_blog_id', true ) ) {
			foreach ( $actions as $key => $action ) {
				if ( 'view' != $key ) {
					unset( $actions[ $key ] );
				}
			}
		}

		return $actions;
	}

	/**
	 * Register the custom meta box for the posts.
	 *
	 * @access public
	 * @return void
	 */
	public function register_meta_boxes() {

		/**
		 * Use this filter to change what post types to show the crossposter on.
		 * Defaults to `null` which is everywhere, posts, pages & custom post types.
		 * @var null
		 */
		$post_types = apply_filters( 'mscp_meta_box_post_types', null );

		add_meta_box(
			'multisite-crossposter',
			esc_html__( 'Multisite Crossposter', 'multisite-crossposter' ),
			array( $this, 'mscp_meta_box_callback' ),
			$post_types,
			'side',
			'low',
			null
		);
	}

	/**
	 * Show the crossposting meta box html.
	 *
	 * @access public
	 * @param  WP_Post $post Current post object being edited.
	 * @return void
	 */
	public function mscp_meta_box_callback( $post ) {

		$blogs = $this->get_user_blogs();

		// Remove the current blog from the select.
		unset( $blogs[ get_current_blog_id() ] );

		/**
		 * Use this fitler to adjust the blogs displayed
		 * in the metabox select field.
		 *
		 * @var array  Full list of blogs.
		 * @var object $post The current post being edited.
		 */
		$blogs = apply_filters( 'mscp_meta_box_blogs', $blogs, $post );

		// Get any previously selected blogs.
		$selected = (array) get_post_meta( $post->ID, '_mscp_blogs', true );

		/**
		 * A filter for all the current selected blgos to crosspost to.
		 *
		 * @var array  $selected Currently selected blog.
		 * @var array  $blogs All blogs in the network.
		 * @var object $post The current post being edited.
		 */
		$selected = apply_filters( 'mscp_meta_box_selected', $selected, $blogs, $post );

		/**
		 * An action fired at the start of the meta box.
		 */
		do_action( 'mscp_meta_box_header' );
		?>

		<p><?php esc_html_e( 'Select any blogs in your network you wish to crosspost this post to:', 'multisite-crossposter' ) ;?></p>

		<select name="mscp_blogs[]" id="mscp" class="full-width" multiple>
			<?php foreach ( $blogs as $blog ) : ?>
				<option value="<?php echo absint( $blog->userblog_id ); ?>"<?php selected( true, in_array( $blog->userblog_id, $selected ) ); ?>>
					<?php echo esc_html( $blog->blogname ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<?php wp_nonce_field( 'mscp_nonce', 'mscp_nonce_field' ); ?>

		<?php
		/**
		 * An action fired at the end of the meta box.
		 */
		do_action( 'mscp_meta_box_footer' );
	}

	/**
	 * When saving a post check to see if they want it crossposted.
	 *
	 * @access public
	 * @param  int     $post_id ID of the current post being saved.
	 * @param  WP_Post $post Current post object to save the meta to.
	 * @param  true    $update Whether it's an update or a new insert.
	 * @return void
	 */
	public function save_mscp_meta_box( $post_id, $post, $update ) {

		// Bail early if it's a crossposted post.
		if ( get_post_meta( $post_id, '_aggregator_orig_post_id', true ) || get_post_meta( $post_id, '_aggregator_orig_blog_id', true ) ) {
			return;
		}

		// Get nonce.
		$nonce = filter_input( INPUT_POST, 'mscp_nonce_field', FILTER_SANITIZE_STRING );

		// Verfiy nonce.
		if ( ! wp_verify_nonce( $nonce, 'mscp_nonce' ) ) {
			return;
		}

		// Check if user has permissions to save data.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check if not an autosave.
		if ( wp_is_post_autosave( $post_id ) ) {
		 	return;
		}

		// Check it's not a revision.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Get the previous crossposted blogs.
		$old_blog_ids = (array) get_post_meta( $post_id, '_mscp_blogs', true );

		// Get, valdiate & sanitize.
		$blog_ids = (array) filter_input( INPUT_POST, 'mscp_blogs', FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY );

		// Clean it up a bit. Remove duplicates and empty values
		$blog_ids = array_filter( array_unique( $blog_ids ) );

		/**
		 * Filter the selected blog IDs before saving.
		 *
		 * @var array  Array of blogs IDs being saved.
		 * @var int    ID of the current post.
		 * @var object The current post object.
		 * @var bool   Whether it's an update or new post.
		 * @var array  The old blogs IDs.
		 */
		$blog_ids = apply_filters( 'mscp_save_meta_box_data', $blog_ids, $post_id, $post, $update, $old_blog_ids );

		// Save the new crossposted blogs to the post meta.
		// Or remove it altogether if there are none.
		if ( ! empty( $blog_ids ) ) {
			update_post_meta( $post_id, '_mscp_blogs', $blog_ids );
		} else {
			delete_post_meta( $post_id, '_mscp_blogs' );
		}

		/**
		 * Use this action to do stuff straight after the meta has been updated.
		 * We use this hook to remove posts from any blogs that have been unchecked.
		 *
		 * @var object $post     The current post object.
		 * @var array  $blog_ids Array of blog IDs to crosspost the post to.
		 */
		do_action( 'mscp_post_blogs_saved', $post, $blog_ids, $old_blog_ids );
	}

	/**
	 * Returns all sites that the current user can post to.
	 *
	 * Results are cached, this is a slow query.
	 *
	 * @access public
	 * @return array  All blogs a user is a member of.
	 */
	public function get_user_blogs() {

		$user_id = get_current_user_id();

		// Check the cache first
		if ( $user_blogs = get_site_transient( 'mscp_user_blogs_' . $user_id ) )
			return $user_blogs;

		// get_blogs_of_user() doesn't work for super admins. Have to construct manually.
		if ( is_super_admin( $user_id ) ) {

			// get_sites() was added in 4.6.
			if ( function_exists( 'get_sites' ) && class_exists( 'WP_Site_Query' ) ) {

				$blogs = get_sites( array( 'orderby'=> array( 'domain', 'path' ), 'fields' => 'ids', 'spam' => false, 'archived' => false, 'deleted' => false, 'number' => false ) );

			// Pre 4.6 use wp_get_sites().
			} else {

				$blogs = wp_get_sites( array( 'spam' => false, 'archived' => false, 'deleted' => false, 'number' => false, 'limit' => false ) );

				$blogs = wp_list_pluck( $blogs, 'blog_id' );

			}

			$user_blogs = array();

			foreach ( $blogs as $blog ) {

				$blog = get_blog_details( $blog );

				$user_blogs[ $blog->blog_id ] = (object) array(
					'userblog_id' => $blog->blog_id,
					'blogname'    => $blog->blogname,
					'domain'      => $blog->domain,
					'path'        => $blog->path,
					'site_id'     => $blog->site_id,
					'siteurl'     => $blog->siteurl,
					'archived'    => $blog->archived,
					'mature'      => $blog->mature,
					'spam'        => $blog->spam,
					'deleted'     => $blog->deleted,
				);
			}

		} else {
			// If not superadmin check permissions.
			$user_blogs = get_blogs_of_user( $user_id );

			// Cycle through each blog and add it to the output if the user can post on it.
			foreach ( $user_blogs as $key => $user_blog ) {
				if ( ! current_user_can_for_blog( $user_blog->userblog_id, 'edit_posts' ) )
					unset( $user_blogs[ $key ] );
			}
		}

		/**
		 * Filter the blogs that are displayed for crossposting.
		 *
		 * @var array
		 */
		$user_blogs = apply_filters( 'mscp_user_blogs', $user_blogs, $user_id );

		// Cache the results.
		set_site_transient( 'mscp_user_blogs_' . $user_id, $user_blogs, WEEK_IN_SECONDS );

		return $user_blogs;
	}

	/**
	 * Whenever a user's role is changed or a user is updated clear the blogs cache.
	 *
	 * @access public
	 * @param  int $user_id ID of the userr to clear the cache for.
	 * @return void
	 */
	public function clear_user_blog_cache( $user_id ) {
		delete_site_transient( 'mscp_user_blogs_' . absint( $user_id ) );
	}

}
