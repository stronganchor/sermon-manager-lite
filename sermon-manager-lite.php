<?php
/**
 * Plugin Name: Sermon Manager Lite
 * Plugin URI: https://stronganchortech.com
 * Description: Lightweight wpfc-compatible replacement for Sermon Manager for WordPress. Preserves legacy sermon data, URLs, core front-end output, and essential shortcodes.
 * Version: 0.1.6
 * Update URI: https://github.com/stronganchor/sermon-manager-lite
 * Author: Strong Anchor Tech
 * Author URI: https://stronganchortech.com
 * License: GPLv2 or later
 * Text Domain: sermon-manager-lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

define( 'SML_PLUGIN_FILE', __FILE__ );
define( 'SML_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SML_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function sml_should_boot_update_checker() {
	$should_boot = ! ( defined( 'SML_DISABLE_UPDATE_CHECKER' ) && SML_DISABLE_UPDATE_CHECKER );

	return (bool) apply_filters( 'sml_should_boot_update_checker', $should_boot );
}

function sml_get_update_branch() {
	$branch = defined( 'SML_UPDATE_BRANCH' ) ? trim( (string) SML_UPDATE_BRANCH ) : 'main';

	if ( '' === $branch ) {
		$branch = 'main';
	}

	return (string) apply_filters( 'sml_update_branch', $branch );
}

$sml_update_checker = null;

if ( sml_should_boot_update_checker() ) {
	$sml_update_checker = PucFactory::buildUpdateChecker(
		'https://github.com/stronganchor/sermon-manager-lite',
		__FILE__,
		'sermon-manager-lite'
	);

	$sml_update_checker->setBranch( sml_get_update_branch() );
}

if ( ! class_exists( 'SML_Plugin' ) ) {

	final class SML_Plugin {

		const VERSION = '0.2.0';
		const NONCE_ACTION = 'sml_save_sermon_meta';
		const NONCE_NAME   = '_sml_sermon_meta_nonce';

		/**
		 * @var string[]
		 */
		private $known_shortcodes = array(
			'sermons',
			'sermon_images',
			'list_podcasts',
			'list_sermons',
			'latest_series',
			'sermon_sort_fields',
			'latest_sermon',
		);

		/**
		 * @var string[]
		 */
		private $known_taxonomies = array(
			'wpfc_preacher',
			'wpfc_sermon_series',
			'wpfc_sermon_topics',
			'wpfc_bible_book',
			'wpfc_service_type',
		);

		/**
		 * @var string[]
		 */
		private $report_meta_keys = array(
			'sermon_date',
			'bible_passage',
			'sermon_description',
			'sermon_audio',
			'sermon_video',
			'sermon_notes',
			'_wpfc_sermon_duration',
			'_wpfc_sermon_size',
			'sermon_bulletin',
			'sermon_audio_id',
			'sermon_notes_id',
			'sermon_bulletin_id',
		);

		/**
		 * @var bool
		 */
		private $legacy_plugin_active = false;

		/**
		 * @var bool
		 */
		private $archive_filters_rendered = false;

		public function __construct() {
			$this->legacy_plugin_active = $this->is_legacy_plugin_active();

			add_action( 'init', array( $this, 'register_content_types' ), 5 );
			add_action( 'init', array( $this, 'register_shortcodes' ), 20 );
			add_action( 'init', array( $this, 'maybe_register_media_sizes' ), 20 );

			add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_admin_notice' ) );
			add_action( 'admin_post_sml_download_report', array( $this, 'handle_download_report' ) );

			add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
			add_action( 'save_post_wpfc_sermon', array( $this, 'save_sermon_meta' ) );

			add_action( 'pre_get_posts', array( $this, 'adjust_sermon_queries' ) );
			add_action( 'loop_start', array( $this, 'maybe_render_archive_filters' ) );
			add_action( 'loop_no_results', array( $this, 'maybe_render_archive_filters' ) );
			add_filter( 'the_content', array( $this, 'filter_sermon_content' ), 20 );

			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );

			register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
			register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
		}

		public static function activate() {
			$instance = new self();
			$instance->register_content_types();
			flush_rewrite_rules();
		}

		public static function deactivate() {
			flush_rewrite_rules();
		}

		public function maybe_show_admin_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! $this->legacy_plugin_active ) {
				return;
			}

			echo '<div class="notice notice-warning"><p>';
			echo esc_html__(
				'Sermon Manager Lite is active while the legacy Sermon Manager plugin is also active. Lite will avoid post type/taxonomy registration conflicts, but you should deactivate the legacy plugin before using Lite as the full replacement.',
				'sermon-manager-lite'
			);
			echo '</p></div>';
		}

		public function register_content_types() {
			$this->register_post_type();
			$this->register_taxonomies();
		}

		private function register_post_type() {
			if ( post_type_exists( 'wpfc_sermon' ) ) {
				return;
			}

			$archive_slug = $this->get_archive_slug();

			$labels = array(
				'name'               => __( 'Sermons', 'sermon-manager-lite' ),
				'singular_name'      => __( 'Sermon', 'sermon-manager-lite' ),
				'add_new'            => __( 'Add New', 'sermon-manager-lite' ),
				'add_new_item'       => __( 'Add New Sermon', 'sermon-manager-lite' ),
				'edit_item'          => __( 'Edit Sermon', 'sermon-manager-lite' ),
				'new_item'           => __( 'New Sermon', 'sermon-manager-lite' ),
				'view_item'          => __( 'View Sermon', 'sermon-manager-lite' ),
				'search_items'       => __( 'Search Sermons', 'sermon-manager-lite' ),
				'not_found'          => __( 'No sermons found.', 'sermon-manager-lite' ),
				'not_found_in_trash' => __( 'No sermons found in Trash.', 'sermon-manager-lite' ),
				'all_items'          => __( 'All Sermons', 'sermon-manager-lite' ),
				'menu_name'          => __( 'Sermons', 'sermon-manager-lite' ),
				'name_admin_bar'     => __( 'Sermon', 'sermon-manager-lite' ),
			);

			register_post_type(
				'wpfc_sermon',
				array(
					'labels'              => $labels,
					'public'              => true,
					'publicly_queryable'  => true,
					'show_ui'             => true,
					'show_in_menu'        => true,
					'show_in_rest'        => true,
					'has_archive'         => $archive_slug,
					'rewrite'             => array(
						'slug'       => $archive_slug,
						'with_front' => false,
					),
					'menu_position'       => 20,
					'menu_icon'           => 'dashicons-format-audio',
					'supports'            => array(
						'title',
						'editor',
						'excerpt',
						'thumbnail',
						'author',
						'revisions',
						'page-attributes',
					),
					'taxonomies'          => $this->known_taxonomies,
					'exclude_from_search' => false,
					'publicly_queryable'  => true,
					'capability_type'     => 'post',
					'map_meta_cap'        => true,
				)
			);
		}

		private function register_taxonomies() {
			$taxonomies = array(
				'wpfc_preacher' => array(
					'label_plural'   => __( 'Preachers', 'sermon-manager-lite' ),
					'label_singular' => __( 'Preacher', 'sermon-manager-lite' ),
					'slug'           => 'preacher',
				),
				'wpfc_sermon_series' => array(
					'label_plural'   => __( 'Series', 'sermon-manager-lite' ),
					'label_singular' => __( 'Series', 'sermon-manager-lite' ),
					'slug'           => 'series',
				),
				'wpfc_sermon_topics' => array(
					'label_plural'   => __( 'Topics', 'sermon-manager-lite' ),
					'label_singular' => __( 'Topic', 'sermon-manager-lite' ),
					'slug'           => 'topics',
				),
				'wpfc_bible_book' => array(
					'label_plural'   => __( 'Bible Books', 'sermon-manager-lite' ),
					'label_singular' => __( 'Bible Book', 'sermon-manager-lite' ),
					'slug'           => 'book',
				),
				'wpfc_service_type' => array(
					'label_plural'   => __( 'Service Types', 'sermon-manager-lite' ),
					'label_singular' => __( 'Service Type', 'sermon-manager-lite' ),
					'slug'           => 'service-type',
				),
			);

			foreach ( $taxonomies as $taxonomy => $config ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					continue;
				}

				register_taxonomy(
					$taxonomy,
					array( 'wpfc_sermon' ),
					array(
						'labels' => array(
							'name'          => $config['label_plural'],
							'singular_name' => $config['label_singular'],
						),
						'public'            => true,
						'publicly_queryable'=> true,
						'show_ui'           => true,
						'show_in_menu'      => true,
						'show_admin_column' => true,
						'show_in_rest'      => true,
						'hierarchical'      => false,
						'rewrite'           => array(
							'slug'       => $config['slug'],
							'with_front' => false,
						),
					)
				);
			}
		}

		public function maybe_register_media_sizes() {
			add_image_size( 'sml-sermon-thumb', 800, 450, true );
		}

		public function register_meta_boxes() {
			if ( $this->legacy_plugin_active ) {
				return;
			}

			add_meta_box(
				'sml_sermon_details',
				__( 'Sermon Details', 'sermon-manager-lite' ),
				array( $this, 'render_sermon_meta_box' ),
				'wpfc_sermon',
				'normal',
				'high'
			);
		}

		public function render_sermon_meta_box( $post ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

			$fields = $this->get_sermon_meta_values( $post->ID );
			?>
			<style>
				.sml-meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
				.sml-meta-grid .sml-full{grid-column:1 / -1}
				.sml-meta-grid label{display:block;font-weight:600;margin-bottom:4px}
				.sml-meta-grid input[type="text"],
				.sml-meta-grid input[type="url"],
				.sml-meta-grid input[type="number"],
				.sml-meta-grid textarea{width:100%}
				.sml-help{color:#666;font-size:12px}
				@media (max-width:782px){.sml-meta-grid{grid-template-columns:1fr}}
			</style>
			<div class="sml-meta-grid">
				<div>
					<label for="sml_sermon_date"><?php esc_html_e( 'Sermon Date', 'sermon-manager-lite' ); ?></label>
					<input type="text" id="sml_sermon_date" name="sml_sermon_date" value="<?php echo esc_attr( $fields['sermon_date_raw'] ); ?>" placeholder="Unix timestamp or YYYY-MM-DD" />
					<div class="sml-help"><?php esc_html_e( 'Legacy data often uses a Unix timestamp. You can also enter YYYY-MM-DD.', 'sermon-manager-lite' ); ?></div>
				</div>

				<div>
					<label for="sml_bible_passage"><?php esc_html_e( 'Bible Passage', 'sermon-manager-lite' ); ?></label>
					<input type="text" id="sml_bible_passage" name="sml_bible_passage" value="<?php echo esc_attr( $fields['bible_passage'] ); ?>" />
				</div>

				<div class="sml-full">
					<label for="sml_sermon_description"><?php esc_html_e( 'Legacy Sermon Description', 'sermon-manager-lite' ); ?></label>
					<textarea id="sml_sermon_description" name="sml_sermon_description" rows="4"><?php echo esc_textarea( $fields['sermon_description'] ); ?></textarea>
				</div>

				<div class="sml-full">
					<label for="sml_sermon_audio"><?php esc_html_e( 'Audio URL', 'sermon-manager-lite' ); ?></label>
					<input type="url" id="sml_sermon_audio" name="sml_sermon_audio" value="<?php echo esc_attr( $fields['sermon_audio'] ); ?>" />
				</div>

				<div>
					<label for="sml_wpfc_sermon_duration"><?php esc_html_e( 'Audio Duration', 'sermon-manager-lite' ); ?></label>
					<input type="text" id="sml_wpfc_sermon_duration" name="sml_wpfc_sermon_duration" value="<?php echo esc_attr( $fields['_wpfc_sermon_duration'] ); ?>" placeholder="00:42:31" />
				</div>

				<div>
					<label for="sml_wpfc_sermon_size"><?php esc_html_e( 'Audio File Size (bytes)', 'sermon-manager-lite' ); ?></label>
					<input type="text" id="sml_wpfc_sermon_size" name="sml_wpfc_sermon_size" value="<?php echo esc_attr( $fields['_wpfc_sermon_size'] ); ?>" />
				</div>

				<div class="sml-full">
					<label for="sml_sermon_video"><?php esc_html_e( 'Video URL / Embed', 'sermon-manager-lite' ); ?></label>
					<textarea id="sml_sermon_video" name="sml_sermon_video" rows="3"><?php echo esc_textarea( $fields['sermon_video'] ); ?></textarea>
				</div>

				<div class="sml-full">
					<label for="sml_sermon_notes"><?php esc_html_e( 'Notes URL', 'sermon-manager-lite' ); ?></label>
					<input type="url" id="sml_sermon_notes" name="sml_sermon_notes" value="<?php echo esc_attr( $fields['sermon_notes'] ); ?>" />
				</div>

				<div class="sml-full">
					<label for="sml_sermon_bulletin"><?php esc_html_e( 'Bulletin URL', 'sermon-manager-lite' ); ?></label>
					<input type="url" id="sml_sermon_bulletin" name="sml_sermon_bulletin" value="<?php echo esc_attr( $fields['sermon_bulletin'] ); ?>" />
				</div>

				<div>
					<label for="sml_sermon_audio_id"><?php esc_html_e( 'Audio Attachment ID', 'sermon-manager-lite' ); ?></label>
					<input type="number" id="sml_sermon_audio_id" name="sml_sermon_audio_id" value="<?php echo esc_attr( $fields['sermon_audio_id'] ); ?>" />
				</div>

				<div>
					<label for="sml_sermon_notes_id"><?php esc_html_e( 'Notes Attachment ID', 'sermon-manager-lite' ); ?></label>
					<input type="number" id="sml_sermon_notes_id" name="sml_sermon_notes_id" value="<?php echo esc_attr( $fields['sermon_notes_id'] ); ?>" />
				</div>

				<div>
					<label for="sml_sermon_bulletin_id"><?php esc_html_e( 'Bulletin Attachment ID', 'sermon-manager-lite' ); ?></label>
					<input type="number" id="sml_sermon_bulletin_id" name="sml_sermon_bulletin_id" value="<?php echo esc_attr( $fields['sermon_bulletin_id'] ); ?>" />
				</div>
			</div>
			<?php
		}

		public function save_sermon_meta( $post_id ) {
			if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( wp_is_post_revision( $post_id ) ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$sermon_date_raw = isset( $_POST['sml_sermon_date'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_sermon_date'] ) ) : '';
			$sermon_date     = $this->normalize_sermon_date_for_storage( $sermon_date_raw );

			$this->save_meta_value( $post_id, 'sermon_date', $sermon_date );
			$this->save_meta_value( $post_id, 'sermon_date_auto', $sermon_date );

			$this->save_meta_value( $post_id, 'bible_passage', isset( $_POST['sml_bible_passage'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_bible_passage'] ) ) : '' );
			$this->save_meta_value( $post_id, 'sermon_description', isset( $_POST['sml_sermon_description'] ) ? wp_kses_post( wp_unslash( $_POST['sml_sermon_description'] ) ) : '' );
			$this->save_meta_value( $post_id, 'sermon_audio', isset( $_POST['sml_sermon_audio'] ) ? esc_url_raw( wp_unslash( $_POST['sml_sermon_audio'] ) ) : '' );
			$this->save_meta_value( $post_id, 'sermon_video', isset( $_POST['sml_sermon_video'] ) ? wp_kses_post( wp_unslash( $_POST['sml_sermon_video'] ) ) : '' );
			$this->save_meta_value( $post_id, 'sermon_notes', isset( $_POST['sml_sermon_notes'] ) ? esc_url_raw( wp_unslash( $_POST['sml_sermon_notes'] ) ) : '' );
			$this->save_meta_value( $post_id, 'sermon_bulletin', isset( $_POST['sml_sermon_bulletin'] ) ? esc_url_raw( wp_unslash( $_POST['sml_sermon_bulletin'] ) ) : '' );

			$this->save_meta_value( $post_id, '_wpfc_sermon_duration', isset( $_POST['sml_wpfc_sermon_duration'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_wpfc_sermon_duration'] ) ) : '' );
			$this->save_meta_value( $post_id, '_wpfc_sermon_size', isset( $_POST['sml_wpfc_sermon_size'] ) ? sanitize_text_field( wp_unslash( $_POST['sml_wpfc_sermon_size'] ) ) : '' );

			$this->save_meta_value( $post_id, 'sermon_audio_id', isset( $_POST['sml_sermon_audio_id'] ) ? absint( $_POST['sml_sermon_audio_id'] ) : '' );
			$this->save_meta_value( $post_id, 'sermon_notes_id', isset( $_POST['sml_sermon_notes_id'] ) ? absint( $_POST['sml_sermon_notes_id'] ) : '' );
			$this->save_meta_value( $post_id, 'sermon_bulletin_id', isset( $_POST['sml_sermon_bulletin_id'] ) ? absint( $_POST['sml_sermon_bulletin_id'] ) : '' );
		}

		private function save_meta_value( $post_id, $key, $value ) {
			if ( '' === $value || null === $value ) {
				delete_post_meta( $post_id, $key );
				return;
			}

			update_post_meta( $post_id, $key, $value );
		}

		public function adjust_sermon_queries( $query ) {
			if ( is_admin() || ! $query->is_main_query() ) {
				return;
			}

			$is_relevant = (
				$query->is_post_type_archive( 'wpfc_sermon' )
				|| $query->is_tax( $this->known_taxonomies )
			);

			if ( ! $is_relevant ) {
				return;
			}

			$sort = isset( $_GET['sermon_sort'] ) ? sanitize_key( wp_unslash( $_GET['sermon_sort'] ) ) : '';

			$query->set( 'post_type', 'wpfc_sermon' );
			$query->set( 'posts_per_page', $this->get_sermon_count() );
			foreach ( $this->get_sermon_sort_args( $sort ) as $key => $value ) {
				$query->set( $key, $value );
			}

			$tax_query = $this->merge_tax_queries(
				$query->get( 'tax_query' ),
				$this->get_requested_sermon_tax_query()
			);

			if ( ! empty( $tax_query ) ) {
				$query->set( 'tax_query', $tax_query );
			}
		}

		public function maybe_render_archive_filters( $query ) {
			if ( is_admin() || $this->archive_filters_rendered ) {
				return;
			}

			if ( ! ( $query instanceof WP_Query ) || ! $query->is_main_query() ) {
				return;
			}

			if ( ! $query->is_post_type_archive( 'wpfc_sermon' ) ) {
				return;
			}

			$markup = $this->get_archive_filters_markup();
			if ( '' === $markup ) {
				return;
			}

			$this->archive_filters_rendered = true;

			echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		public function filter_sermon_content( $content ) {
			if ( is_admin() ) {
				return $content;
			}

			if ( ! is_singular( 'wpfc_sermon' ) || ! in_the_loop() || ! is_main_query() ) {
				return $content;
			}

			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return $content;
			}

			$meta_output = $this->render_single_sermon_header( $post_id );
			$media_output = $this->render_single_sermon_media( $post_id );
			$body_output  = trim( (string) $content );

			if ( '' === $body_output ) {
				$legacy_description = (string) get_post_meta( $post_id, 'sermon_description', true );
				if ( '' !== trim( $legacy_description ) ) {
					$body_output = wpautop( wp_kses_post( $legacy_description ) );
				}
			}

			$downloads = $this->render_single_sermon_downloads( $post_id );

			$wrapped = '<div class="sml-sermon-single-wrap">';
			if ( apply_filters( 'sml_render_single_sermon_title', true, $post_id ) ) {
				$wrapped .= $this->render_single_sermon_title( $post_id );
			}
			$wrapped .= $meta_output;
			$wrapped .= $media_output;
			$wrapped .= $downloads;

			if ( '' !== trim( $body_output ) ) {
				$wrapped .= '<div class="sml-sermon-body">' . $body_output . '</div>';
			}

			$wrapped .= '</div>';

			return $wrapped;
		}

		private function render_single_sermon_title( $post_id ) {
			$title = trim( wp_strip_all_tags( get_the_title( $post_id ) ) );
			if ( '' === $title ) {
				$title = __( 'Untitled Sermon', 'sermon-manager-lite' );
			}

			return '<header class="sml-sermon-single-header"><h1 class="sml-sermon-single-title">' . esc_html( $title ) . '</h1></header>';
		}

		private function render_single_sermon_header( $post_id ) {
			$items = array();

			$date = $this->get_formatted_sermon_date( $post_id );
			if ( '' !== $date ) {
				$items[] = '<div class="sml-sermon-meta-item"><strong>' . esc_html__( 'Date:', 'sermon-manager-lite' ) . '</strong> ' . esc_html( $date ) . '</div>';
			}

			$passage = (string) get_post_meta( $post_id, 'bible_passage', true );
			if ( '' !== trim( $passage ) ) {
				$items[] = '<div class="sml-sermon-meta-item"><strong>' . esc_html__( 'Passage:', 'sermon-manager-lite' ) . '</strong> ' . esc_html( $passage ) . '</div>';
			}

			$taxonomy_map = array(
				'wpfc_preacher'      => __( 'Preacher', 'sermon-manager-lite' ),
				'wpfc_sermon_series' => __( 'Series', 'sermon-manager-lite' ),
				'wpfc_sermon_topics' => __( 'Topics', 'sermon-manager-lite' ),
				'wpfc_bible_book'    => __( 'Book', 'sermon-manager-lite' ),
				'wpfc_service_type'  => __( 'Service Type', 'sermon-manager-lite' ),
			);

			foreach ( $taxonomy_map as $taxonomy => $label ) {
				$list = get_the_term_list( $post_id, $taxonomy, '', ', ', '' );
				if ( $list ) {
					$items[] = '<div class="sml-sermon-meta-item"><strong>' . esc_html( $label ) . ':</strong> ' . wp_kses_post( $list ) . '</div>';
				}
			}

			if ( empty( $items ) ) {
				return '';
			}

			return '<div class="sml-sermon-meta">' . implode( '', $items ) . '</div>';
		}

		private function render_single_sermon_media( $post_id ) {
			$output = '';

			$audio_url = (string) get_post_meta( $post_id, 'sermon_audio', true );
			if ( '' !== trim( $audio_url ) ) {
				$output .= '<div class="sml-sermon-media sml-sermon-audio">';
				$output .= '<h3>' . esc_html__( 'Listen', 'sermon-manager-lite' ) . '</h3>';
				$output .= wp_audio_shortcode(
					array(
						'src' => esc_url( $audio_url ),
					)
				);

				$duration = (string) get_post_meta( $post_id, '_wpfc_sermon_duration', true );
				$size     = (string) get_post_meta( $post_id, '_wpfc_sermon_size', true );
				$meta_bits = array();

				if ( '' !== trim( $duration ) ) {
					$meta_bits[] = esc_html__( 'Duration:', 'sermon-manager-lite' ) . ' ' . esc_html( $duration );
				}

				if ( '' !== trim( $size ) && is_numeric( $size ) ) {
					$meta_bits[] = esc_html__( 'Size:', 'sermon-manager-lite' ) . ' ' . esc_html( size_format( (int) $size ) );
				}

				if ( ! empty( $meta_bits ) ) {
					$output .= '<div class="sml-sermon-media-meta">' . esc_html( implode( ' | ', $meta_bits ) ) . '</div>';
				}

				$output .= '</div>';
			}

			$video = (string) get_post_meta( $post_id, 'sermon_video', true );
			if ( '' !== trim( $video ) ) {
				$output .= '<div class="sml-sermon-media sml-sermon-video">';
				$output .= '<h3>' . esc_html__( 'Watch', 'sermon-manager-lite' ) . '</h3>';
				$output .= $this->render_video_value( $video );
				$output .= '</div>';
			}

			return $output;
		}

		private function render_single_sermon_downloads( $post_id ) {
			$notes    = (string) get_post_meta( $post_id, 'sermon_notes', true );
			$bulletin = (string) get_post_meta( $post_id, 'sermon_bulletin', true );

			$links = array();

			if ( '' !== trim( $notes ) ) {
				$links[] = '<a class="sml-download-link" href="' . esc_url( $notes ) . '">' . esc_html__( 'Notes', 'sermon-manager-lite' ) . '</a>';
			}

			if ( '' !== trim( $bulletin ) ) {
				$links[] = '<a class="sml-download-link" href="' . esc_url( $bulletin ) . '">' . esc_html__( 'Bulletin', 'sermon-manager-lite' ) . '</a>';
			}

			if ( empty( $links ) ) {
				return '';
			}

			return '<div class="sml-sermon-downloads">' . implode( '', $links ) . '</div>';
		}

		private function render_video_value( $video ) {
			$video = trim( (string) $video );

			if ( filter_var( $video, FILTER_VALIDATE_URL ) ) {
				$oembed = wp_oembed_get( $video );
				if ( $oembed ) {
					return $oembed;
				}

				return wp_video_shortcode(
					array(
						'src' => esc_url( $video ),
					)
				);
			}

			if ( false !== stripos( $video, '<iframe' ) ) {
				return wp_kses(
					$video,
					array(
						'iframe' => array(
							'src'             => true,
							'width'           => true,
							'height'          => true,
							'frameborder'     => true,
							'allow'           => true,
							'allowfullscreen' => true,
							'loading'         => true,
							'referrerpolicy'  => true,
							'title'           => true,
						),
					)
				);
			}

			return wpautop( wp_kses_post( $video ) );
		}

		public function enqueue_frontend_styles() {
			$css = '
			.single-wpfc_sermon .entry-title:not(.sml-sermon-single-title),
			.single-wpfc_sermon .post-title:not(.sml-sermon-single-title),
			.single-wpfc_sermon .page-title:not(.sml-sermon-single-title){display:none}
			.sml-sermon-single-header{margin:0 0 1rem}
			.sml-sermon-single-title{margin:0;line-height:1.15}
			.sml-sermon-meta{margin:0 0 1.25rem;padding:1rem;border:1px solid #ddd;border-radius:8px;background:#fafafa}
			.sml-sermon-meta-item + .sml-sermon-meta-item{margin-top:.35rem}
			.sml-sermon-media{margin:0 0 1.25rem}
			.sml-sermon-media h3{margin:0 0 .5rem}
			.sml-sermon-media-meta{margin-top:.45rem;color:#666;font-size:.95rem}
			.sml-sermon-downloads{display:flex;gap:.75rem;flex-wrap:wrap;margin:0 0 1.25rem}
			.sml-download-link{display:inline-block;padding:.5rem .8rem;border:1px solid #ccc;border-radius:6px;text-decoration:none}
			.sml-sermon-loop{display:grid;gap:1.25rem}
			.sml-sermon-card{padding:1rem;border:1px solid #ddd;border-radius:8px;background:#fff}
			.sml-sermon-card-title{margin:0 0 .35rem;font-size:1.25rem;line-height:1.25}
			.sml-sermon-card-meta{margin:0 0 .55rem;color:#666;font-size:.95rem}
			.sml-sermon-card-tax{margin:.5rem 0}
			.sml-sermon-tax-chip{display:inline-block;margin:0 .35rem .35rem 0;padding:.2rem .5rem;border-radius:999px;background:#f1f1f1;font-size:.85rem}
			.sml-sermon-filter-form{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin:0 0 1.5rem}
			.sml-sermon-filter-field{min-width:180px;flex:1 1 180px}
			.sml-sermon-filter-field select{width:100%;padding:.45rem .6rem}
			.sml-sermon-filter-reset{display:inline-flex;align-items:center;padding:.45rem .75rem;border:1px solid #ccc;border-radius:6px;text-decoration:none;white-space:nowrap}
			.sml-sermon-sort-form{display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;margin:0 0 1rem}
			.sml-sermon-sort-form label{display:flex;flex-direction:column;font-weight:600}
			.sml-sermon-sort-form select,.sml-sermon-sort-form button{padding:.45rem .6rem}
			.sml-sermon-pagination{margin-top:1.25rem}
			.sml-sermon-pagination .page-numbers{display:inline-block;margin-right:.4rem}
			.sml-audit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
			.sml-audit-card{padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
			.sml-audit-card h2{margin-top:0}
			@media (max-width:782px){
				.sml-audit-grid{grid-template-columns:1fr}
			}
			';

			wp_register_style( 'sml-inline', false, array(), self::VERSION );
			wp_enqueue_style( 'sml-inline' );
			wp_add_inline_style( 'sml-inline', $css );
		}

		public function register_shortcodes() {
			add_shortcode( 'sermons', array( $this, 'shortcode_sermons' ) );
			add_shortcode( 'sermon_sort_fields', array( $this, 'shortcode_sermon_sort_fields' ) );
		}

		public function shortcode_sermon_sort_fields( $atts = array() ) {
			$atts = shortcode_atts(
				array(
					'label' => __( 'Sort Sermons', 'sermon-manager-lite' ),
				),
				$atts,
				'sermon_sort_fields'
			);

			$current_sort = isset( $_GET['sermon_sort'] ) ? sanitize_key( wp_unslash( $_GET['sermon_sort'] ) ) : 'newest';

			ob_start();
			?>
			<form method="get" class="sml-sermon-sort-form">
				<label>
					<span><?php echo esc_html( $atts['label'] ); ?></span>
					<select name="sermon_sort">
						<option value="newest" <?php selected( $current_sort, 'newest' ); ?>><?php esc_html_e( 'Newest First', 'sermon-manager-lite' ); ?></option>
						<option value="oldest" <?php selected( $current_sort, 'oldest' ); ?>><?php esc_html_e( 'Oldest First', 'sermon-manager-lite' ); ?></option>
						<option value="title_asc" <?php selected( $current_sort, 'title_asc' ); ?>><?php esc_html_e( 'Title A–Z', 'sermon-manager-lite' ); ?></option>
						<option value="title_desc" <?php selected( $current_sort, 'title_desc' ); ?>><?php esc_html_e( 'Title Z–A', 'sermon-manager-lite' ); ?></option>
					</select>
				</label>
				<?php echo $this->render_preserved_query_args( array( 'sermon_sort', 'paged' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<button type="submit"><?php esc_html_e( 'Apply', 'sermon-manager-lite' ); ?></button>
			</form>
			<?php
			return ob_get_clean();
		}

		public function shortcode_sermons( $atts = array() ) {
			$atts = shortcode_atts(
				array(
					'posts_per_page' => $this->get_sermon_count(),
					'preacher'       => '',
					'series'         => '',
					'topic'          => '',
					'book'           => '',
					'service_type'   => '',
					'pagination'     => 'true',
					'show_sort'      => 'false',
				),
				$atts,
				'sermons'
			);

			$paged = max(
				1,
				get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : ( isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 )
			);

			$sort = isset( $_GET['sermon_sort'] ) ? sanitize_key( wp_unslash( $_GET['sermon_sort'] ) ) : 'newest';

			$args = array(
				'post_type'      => 'wpfc_sermon',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $atts['posts_per_page'],
				'paged'          => $paged,
			);
			$args = array_merge( $args, $this->get_sermon_sort_args( $sort ) );

			$tax_query = $this->get_requested_sermon_tax_query( $atts );

			if ( ! empty( $tax_query ) ) {
				$args['tax_query'] = $tax_query;
			}

			$query = new WP_Query( $args );

			ob_start();

			if ( 'true' === $atts['show_sort'] ) {
				echo do_shortcode( '[sermon_sort_fields]' );
			}

			if ( $query->have_posts() ) {
				echo '<div class="sml-sermon-loop">';

				while ( $query->have_posts() ) {
					$query->the_post();

					$post_id = get_the_ID();
					$title   = get_the_title();
					if ( '' === trim( $title ) ) {
						$title = __( 'Untitled Sermon', 'sermon-manager-lite' );
					}

					echo '<article class="sml-sermon-card">';

					$thumb = get_the_post_thumbnail( $post_id, 'sml-sermon-thumb' );
					if ( $thumb ) {
						echo '<div class="sml-sermon-card-thumb"><a href="' . esc_url( get_permalink() ) . '">' . $thumb . '</a></div>';
					}

					echo '<h3 class="sml-sermon-card-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( $title ) . '</a></h3>';

					$meta_bits = array();
					$date = $this->get_formatted_sermon_date( $post_id );
					if ( '' !== $date ) {
						$meta_bits[] = $date;
					}

					$passage = (string) get_post_meta( $post_id, 'bible_passage', true );
					if ( '' !== trim( $passage ) ) {
						$meta_bits[] = $passage;
					}

					if ( ! empty( $meta_bits ) ) {
						echo '<div class="sml-sermon-card-meta">' . esc_html( implode( ' | ', $meta_bits ) ) . '</div>';
					}

					echo $this->render_taxonomy_chips( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

					$summary = $this->get_sermon_summary( $post_id );
					if ( '' !== $summary ) {
						echo '<div class="sml-sermon-card-summary">' . wp_kses_post( wpautop( $summary ) ) . '</div>';
					}

					echo '<p><a href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Read / Listen', 'sermon-manager-lite' ) . '</a></p>';

					echo '</article>';
				}

				echo '</div>';

				if ( 'true' === $atts['pagination'] && $query->max_num_pages > 1 ) {
					$big = 999999999;
					$pagination = paginate_links(
						array(
							'base'      => str_replace( $big, '%#%', esc_url( add_query_arg( 'paged', $big ) ) ),
							'format'    => '',
							'current'   => max( 1, $paged ),
							'total'     => (int) $query->max_num_pages,
							'type'      => 'plain',
							'prev_text' => __( '« Previous', 'sermon-manager-lite' ),
							'next_text' => __( 'Next »', 'sermon-manager-lite' ),
						)
					);

					if ( $pagination ) {
						echo '<nav class="sml-sermon-pagination">' . wp_kses_post( $pagination ) . '</nav>';
					}
				}
			} else {
				echo '<p>' . esc_html__( 'No sermons found.', 'sermon-manager-lite' ) . '</p>';
			}

			wp_reset_postdata();

			return ob_get_clean();
		}

		private function render_preserved_query_args( $exclude = array() ) {
			$output = '';

			foreach ( $this->get_preserved_query_args( $exclude ) as $key => $value ) {
				$output .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
			}

			return $output;
		}

		private function get_archive_filters_markup() {
			$archive_link = get_post_type_archive_link( 'wpfc_sermon' );
			if ( ! $archive_link ) {
				return '';
			}

			$filters         = $this->get_sermon_filter_map();
			$current_filters = $this->get_requested_sermon_filters();
			$exclude_keys    = array_merge( array_keys( $filters ), array( 'paged' ) );
			$fields          = array();

			foreach ( $filters as $key => $config ) {
				$terms = $this->get_taxonomy_filter_terms( $config['taxonomy'] );

				if ( empty( $terms ) && '' === $current_filters[ $key ] ) {
					continue;
				}

				$field_id = 'sml-sermon-filter-' . $key;
				$options  = '<option value="">' . esc_html( $config['label'] ) . '</option>';

				foreach ( $terms as $term ) {
					$options .= '<option value="' . esc_attr( $term->slug ) . '"' . selected( $current_filters[ $key ], $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
				}

				$fields[] = '<label class="sml-sermon-filter-field" for="' . esc_attr( $field_id ) . '"><span class="screen-reader-text">' . esc_html( $config['label'] ) . '</span><select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $key ) . '" onchange="this.form.submit()">' . $options . '</select></label>';
			}

			if ( empty( $fields ) ) {
				return '';
			}

			$reset_url = add_query_arg(
				$this->get_preserved_query_args( $exclude_keys ),
				$archive_link
			);

			ob_start();
			?>
			<form method="get" action="<?php echo esc_url( $archive_link ); ?>" class="sml-sermon-filter-form" aria-label="<?php esc_attr_e( 'Filter sermons', 'sermon-manager-lite' ); ?>">
				<?php echo implode( '', $fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->render_preserved_query_args( $exclude_keys ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<noscript>
					<button type="submit"><?php esc_html_e( 'Apply Filters', 'sermon-manager-lite' ); ?></button>
				</noscript>
				<?php if ( $this->has_active_sermon_filters( $current_filters ) ) : ?>
					<a class="sml-sermon-filter-reset" href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Reset Filters', 'sermon-manager-lite' ); ?></a>
				<?php endif; ?>
			</form>
			<?php

			return ob_get_clean();
		}

		private function get_sermon_filter_map() {
			return array(
				'preacher' => array(
					'taxonomy' => 'wpfc_preacher',
					'label'    => __( 'Preacher', 'sermon-manager-lite' ),
				),
				'series' => array(
					'taxonomy' => 'wpfc_sermon_series',
					'label'    => __( 'Series', 'sermon-manager-lite' ),
				),
				'topic' => array(
					'taxonomy' => 'wpfc_sermon_topics',
					'label'    => __( 'Topic', 'sermon-manager-lite' ),
				),
				'book' => array(
					'taxonomy' => 'wpfc_bible_book',
					'label'    => __( 'Book', 'sermon-manager-lite' ),
				),
				'service_type' => array(
					'taxonomy' => 'wpfc_service_type',
					'label'    => __( 'Service Type', 'sermon-manager-lite' ),
				),
			);
		}

		private function get_requested_sermon_filters( $defaults = array() ) {
			$filters = array();

			foreach ( $this->get_sermon_filter_map() as $key => $config ) {
				$value = '';

				if ( isset( $_GET[ $key ] ) && ! is_array( $_GET[ $key ] ) ) {
					$value = sanitize_title( wp_unslash( $_GET[ $key ] ) );
				} elseif ( isset( $defaults[ $key ] ) && '' !== (string) $defaults[ $key ] ) {
					$value = sanitize_title( (string) $defaults[ $key ] );
				}

				$filters[ $key ] = $value;
			}

			return $filters;
		}

		private function get_requested_sermon_tax_query( $defaults = array() ) {
			$tax_query = array();
			$filters   = $this->get_requested_sermon_filters( $defaults );

			foreach ( $this->get_sermon_filter_map() as $key => $config ) {
				if ( '' === $filters[ $key ] ) {
					continue;
				}

				$tax_query[] = array(
					'taxonomy' => $config['taxonomy'],
					'field'    => 'slug',
					'terms'    => $filters[ $key ],
				);
			}

			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}

			return $tax_query;
		}

		private function merge_tax_queries( $existing_query, $additional_query ) {
			if ( empty( $existing_query ) ) {
				return $additional_query;
			}

			if ( empty( $additional_query ) ) {
				return $existing_query;
			}

			return array(
				'relation' => 'AND',
				$existing_query,
				$additional_query,
			);
		}

		private function get_sermon_sort_args( $sort ) {
			switch ( $sort ) {
				case 'title_asc':
					return array(
						'orderby' => 'title',
						'order'   => 'ASC',
					);

				case 'title_desc':
					return array(
						'orderby' => 'title',
						'order'   => 'DESC',
					);

				case 'oldest':
					return array(
						'meta_key' => 'sermon_date',
						'orderby'  => 'meta_value_num',
						'order'    => 'ASC',
					);

				case 'newest':
				default:
					return array(
						'meta_key' => 'sermon_date',
						'orderby'  => 'meta_value_num',
						'order'    => 'DESC',
					);
			}
		}

		private function get_taxonomy_filter_terms( $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);

			if ( is_wp_error( $terms ) ) {
				return array();
			}

			return $terms;
		}

		private function get_preserved_query_args( $exclude = array() ) {
			$args = array();

			foreach ( $_GET as $key => $value ) {
				$key = (string) $key;

				if ( in_array( $key, $exclude, true ) || is_array( $value ) ) {
					continue;
				}

				$args[ $key ] = sanitize_text_field( wp_unslash( $value ) );
			}

			return $args;
		}

		private function has_active_sermon_filters( $filters ) {
			foreach ( $filters as $value ) {
				if ( '' !== (string) $value ) {
					return true;
				}
			}

			return false;
		}

		private function render_taxonomy_chips( $post_id ) {
			$taxonomies = array(
				'wpfc_preacher',
				'wpfc_sermon_series',
				'wpfc_sermon_topics',
				'wpfc_bible_book',
				'wpfc_service_type',
			);

			$chips = array();

			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}

				foreach ( $terms as $term ) {
					$chips[] = '<span class="sml-sermon-tax-chip">' . esc_html( $term->name ) . '</span>';
				}
			}

			if ( empty( $chips ) ) {
				return '';
			}

			return '<div class="sml-sermon-card-tax">' . implode( '', $chips ) . '</div>';
		}

		private function get_sermon_summary( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return '';
			}

			if ( '' !== trim( (string) $post->post_excerpt ) ) {
				return $post->post_excerpt;
			}

			if ( '' !== trim( (string) $post->post_content ) ) {
				return wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
			}

			$legacy_description = (string) get_post_meta( $post_id, 'sermon_description', true );
			if ( '' !== trim( $legacy_description ) ) {
				return wp_trim_words( wp_strip_all_tags( $legacy_description ), 30 );
			}

			return '';
		}

		private function get_archive_slug() {
			$slug = (string) get_option( 'sermonmanager_archive_slug', 'sermons' );
			$slug = sanitize_title( $slug );

			if ( '' === $slug ) {
				$slug = 'sermons';
			}

			return $slug;
		}

		private function get_sermon_count() {
			$count = absint( get_option( 'sermonmanager_sermon_count', 10 ) );
			if ( $count < 1 ) {
				$count = 10;
			}
			return $count;
		}

		private function get_formatted_sermon_date( $post_id ) {
			$raw = get_post_meta( $post_id, 'sermon_date', true );

			if ( '' === $raw || null === $raw ) {
				return '';
			}

			if ( is_numeric( $raw ) ) {
				return wp_date( get_option( 'date_format' ), (int) $raw );
			}

			$timestamp = strtotime( (string) $raw );
			if ( false !== $timestamp ) {
				return wp_date( get_option( 'date_format' ), $timestamp );
			}

			return (string) $raw;
		}

		private function normalize_sermon_date_for_storage( $raw ) {
			$raw = trim( (string) $raw );

			if ( '' === $raw ) {
				return '';
			}

			if ( ctype_digit( $raw ) ) {
				return $raw;
			}

			$timestamp = strtotime( $raw );
			if ( false !== $timestamp ) {
				return (string) $timestamp;
			}

			return $raw;
		}

		private function get_sermon_meta_values( $post_id ) {
			return array(
				'sermon_date_raw'        => (string) get_post_meta( $post_id, 'sermon_date', true ),
				'bible_passage'          => (string) get_post_meta( $post_id, 'bible_passage', true ),
				'sermon_description'     => (string) get_post_meta( $post_id, 'sermon_description', true ),
				'sermon_audio'           => (string) get_post_meta( $post_id, 'sermon_audio', true ),
				'sermon_video'           => (string) get_post_meta( $post_id, 'sermon_video', true ),
				'sermon_notes'           => (string) get_post_meta( $post_id, 'sermon_notes', true ),
				'sermon_bulletin'        => (string) get_post_meta( $post_id, 'sermon_bulletin', true ),
				'_wpfc_sermon_duration'  => (string) get_post_meta( $post_id, '_wpfc_sermon_duration', true ),
				'_wpfc_sermon_size'      => (string) get_post_meta( $post_id, '_wpfc_sermon_size', true ),
				'sermon_audio_id'        => (string) get_post_meta( $post_id, 'sermon_audio_id', true ),
				'sermon_notes_id'        => (string) get_post_meta( $post_id, 'sermon_notes_id', true ),
				'sermon_bulletin_id'     => (string) get_post_meta( $post_id, 'sermon_bulletin_id', true ),
			);
		}

		private function is_legacy_plugin_active() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			return is_plugin_active( 'sermon-manager-for-wordpress/sermons.php' );
		}

		public function register_admin_page() {
			add_management_page(
				__( 'Sermon Manager Lite', 'sermon-manager-lite' ),
				__( 'Sermon Manager Lite', 'sermon-manager-lite' ),
				'manage_options',
				'sermon-manager-lite',
				array( $this, 'render_admin_page' )
			);
		}

		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'sermon-manager-lite' ) );
			}

			$report = $this->generate_report();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Sermon Manager Lite', 'sermon-manager-lite' ); ?></h1>

				<div class="sml-audit-grid">
					<div class="sml-audit-card">
						<h2><?php esc_html_e( 'Compatibility Summary', 'sermon-manager-lite' ); ?></h2>
						<p><strong><?php esc_html_e( 'Legacy plugin active:', 'sermon-manager-lite' ); ?></strong> <?php echo esc_html( $this->legacy_plugin_active ? 'yes' : 'no' ); ?></p>
						<p><strong><?php esc_html_e( 'Archive slug:', 'sermon-manager-lite' ); ?></strong> <?php echo esc_html( $this->get_archive_slug() ); ?></p>
						<p><strong><?php esc_html_e( 'Configured sermons per page:', 'sermon-manager-lite' ); ?></strong> <?php echo esc_html( (string) $this->get_sermon_count() ); ?></p>
						<p><strong><?php esc_html_e( 'Archive URL:', 'sermon-manager-lite' ); ?></strong> <?php echo esc_html( home_url( '/' . $this->get_archive_slug() . '/' ) ); ?></p>
					</div>

					<div class="sml-audit-card">
						<h2><?php esc_html_e( 'Actions', 'sermon-manager-lite' ); ?></h2>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( home_url( '/' . $this->get_archive_slug() . '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open Sermon Archive', 'sermon-manager-lite' ); ?></a>
						</p>
						<p>
							<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sml_download_report' ), 'sml_download_report' ) ); ?>"><?php esc_html_e( 'Download TXT Report', 'sermon-manager-lite' ); ?></a>
						</p>
					</div>
				</div>

				<h2 style="margin-top:24px;"><?php esc_html_e( 'Diagnostic Report', 'sermon-manager-lite' ); ?></h2>
				<textarea readonly="readonly" spellcheck="false" style="width:100%;min-height:720px;font-family:Consolas,Monaco,monospace;"><?php echo esc_textarea( $report ); ?></textarea>
			</div>
			<?php
		}

		public function handle_download_report() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'sermon-manager-lite' ) );
			}

			check_admin_referer( 'sml_download_report' );

			$report = $this->generate_report();
			$site   = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
			$file   = 'sermon-manager-lite-report-' . $site . '-' . gmdate( 'Ymd-His' ) . '.txt';

			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $file );
			echo $report; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		private function generate_report() {
			$lines   = array();
			$lines[] = 'SERMON MANAGER LITE REPORT';
			$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
			$lines[] = 'Home URL: ' . home_url( '/' );
			$lines[] = 'Site Name: ' . get_bloginfo( 'name' );
			$lines[] = 'WP Version: ' . get_bloginfo( 'version' );
			$lines[] = 'Theme: ' . $this->get_theme_label();
			$lines[] = 'PHP Version: ' . PHP_VERSION;
			$lines[] = 'Lite Version: ' . self::VERSION;
			$lines[] = 'Legacy plugin active: ' . ( $this->legacy_plugin_active ? 'yes' : 'no' );
			$lines[] = str_repeat( '=', 80 );

			$lines = array_merge( $lines, $this->section_plugin_status() );
			$lines = array_merge( $lines, $this->section_content_counts() );
			$lines = array_merge( $lines, $this->section_taxonomy_details() );
			$lines = array_merge( $lines, $this->section_meta_usage() );
			$lines = array_merge( $lines, $this->section_shortcode_usage() );
			$lines = array_merge( $lines, $this->section_menu_and_permalink_usage() );
			$lines = array_merge( $lines, $this->section_options_snapshot() );
			$lines = array_merge( $lines, $this->section_summary() );

			return implode( "\n", $lines );
		}

		private function section_plugin_status() {
			$lines   = array();
			$lines[] = '';
			$lines[] = '[PLUGIN STATUS]';
			$lines[] = 'Archive slug option: ' . $this->get_archive_slug();
			$lines[] = 'Sermon count option: ' . $this->get_sermon_count();
			$lines[] = 'Post type wpfc_sermon registered: ' . ( post_type_exists( 'wpfc_sermon' ) ? 'yes' : 'no' );

			foreach ( $this->known_taxonomies as $taxonomy ) {
				$lines[] = 'Taxonomy ' . $taxonomy . ' registered: ' . ( taxonomy_exists( $taxonomy ) ? 'yes' : 'no' );
			}

			return $lines;
		}

		private function section_content_counts() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[CONTENT COUNTS]';

			$post_status_counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_status, COUNT(*) AS qty
					 FROM {$wpdb->posts}
					 WHERE post_type = %s
					 GROUP BY post_status
					 ORDER BY qty DESC",
					'wpfc_sermon'
				),
				ARRAY_A
			);

			if ( empty( $post_status_counts ) ) {
				$lines[] = 'No wpfc_sermon posts found.';
				return $lines;
			}

			$total = 0;
			foreach ( $post_status_counts as $row ) {
				$total += (int) $row['qty'];
			}

			$lines[] = 'Total wpfc_sermon posts: ' . $total;

			foreach ( $post_status_counts as $row ) {
				$lines[] = ' - ' . $row['post_status'] . ': ' . (int) $row['qty'];
			}

			$private_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_type = %s
					 AND post_status = %s",
					'wpfc_sermon',
					'private'
				)
			);

			$future_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_type = %s
					 AND post_status = %s",
					'wpfc_sermon',
					'future'
				)
			);

			$content_nonempty = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->posts}
					 WHERE post_type = %s
					 AND post_content IS NOT NULL
					 AND post_content <> ''",
					'wpfc_sermon'
				)
			);

			$lines[] = 'Private sermons: ' . $private_count;
			$lines[] = 'Scheduled sermons: ' . $future_count;
			$lines[] = 'Sermons with non-empty post_content: ' . $content_nonempty;

			return $lines;
		}

		private function section_taxonomy_details() {
			$lines   = array();
			$lines[] = '';
			$lines[] = '[TAXONOMY DETAILS]';

			foreach ( $this->known_taxonomies as $taxonomy ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					$lines[] = $taxonomy . ': not registered';
					continue;
				}

				$tax_obj    = get_taxonomy( $taxonomy );
				$term_count = wp_count_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);

				$top_terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'number'     => 10,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);

				$lines[] = $taxonomy . ':';
				$lines[] = ' - label: ' . $this->clean_line( $tax_obj->label ?? '' );
				$lines[] = ' - rewrite slug: ' . $this->format_rewrite_slug( $tax_obj->rewrite ?? false );
				$lines[] = ' - total terms: ' . (int) $term_count;
				$lines[] = ' - top terms: ' . $this->format_terms_for_report( $top_terms );
			}

			return $lines;
		}

		private function section_meta_usage() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[META USAGE]';

			$total_posts = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
					'wpfc_sermon'
				)
			);

			if ( $total_posts < 1 ) {
				$lines[] = 'No sermon posts found.';
				return $lines;
			}

			foreach ( $this->report_meta_keys as $meta_key ) {
				$used_on = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT pm.post_id)
						 FROM {$wpdb->postmeta} pm
						 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE p.post_type = %s
						 AND pm.meta_key = %s
						 AND pm.meta_value IS NOT NULL
						 AND pm.meta_value <> ''",
						'wpfc_sermon',
						$meta_key
					)
				);

				$sample = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT pm.meta_value
						 FROM {$wpdb->postmeta} pm
						 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE p.post_type = %s
						 AND pm.meta_key = %s
						 AND pm.meta_value IS NOT NULL
						 AND pm.meta_value <> ''
						 LIMIT 1",
						'wpfc_sermon',
						$meta_key
					)
				);

				$lines[] = $meta_key . ': used on ' . $used_on . ' / ' . $total_posts . ' sermons'
					. ( null !== $sample ? ' | sample: ' . $this->short_sample_value( $sample ) : '' );
			}

			return $lines;
		}

		private function section_shortcode_usage() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[SHORTCODE USAGE]';

			foreach ( $this->known_shortcodes as $shortcode ) {
				$pattern = '%[' . $wpdb->esc_like( $shortcode ) . '%';

				$post_hits = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_type, post_status
						 FROM {$wpdb->posts}
						 WHERE post_content LIKE %s
						 AND post_type IN ('post','page','wp_block','wp_template','wp_template_part')
						 AND post_status NOT IN ('trash','auto-draft')
						 ORDER BY post_type, post_title",
						$pattern
					),
					ARRAY_A
				);

				$lines[] = '[' . $shortcode . ']';
				$lines[] = ' - content hits: ' . count( $post_hits );

				foreach ( array_slice( $post_hits, 0, 20 ) as $hit ) {
					$lines[] = '   * ' . $hit['post_type'] . ' #' . (int) $hit['ID'] . ' [' . $hit['post_status'] . '] ' . $this->clean_line( $hit['post_title'] );
				}
			}

			return $lines;
		}

		private function section_menu_and_permalink_usage() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[MENU / URL / PERMALINK USAGE]';
			$lines[] = 'Archive link for wpfc_sermon: ' . ( get_post_type_archive_link( 'wpfc_sermon' ) ? get_post_type_archive_link( 'wpfc_sermon' ) : 'not available' );

			$menu_items = $wpdb->get_results(
				"SELECT ID, post_title
				 FROM {$wpdb->posts}
				 WHERE post_type = 'nav_menu_item'
				 AND post_status NOT IN ('trash','auto-draft')",
				ARRAY_A
			);

			$matched_menus = array();

			foreach ( $menu_items as $item ) {
				$url = get_post_meta( (int) $item['ID'], '_menu_item_url', true );
				if ( ! is_string( $url ) ) {
					continue;
				}

				if (
					false !== strpos( $url, 'post_type=wpfc_sermon' )
					|| false !== strpos( $url, '/' . $this->get_archive_slug() )
					|| false !== strpos( $url, 'wpfc_sermon' )
				) {
					$matched_menus[] = 'nav_menu_item #' . (int) $item['ID'] . ': ' . $this->clean_line( $item['post_title'] ) . ' => ' . $url;
				}
			}

			if ( empty( $matched_menus ) ) {
				$lines[] = 'Menu items pointing to sermon archives: none found';
			} else {
				$lines[] = 'Menu items pointing to sermon archives:';
				foreach ( $matched_menus as $row ) {
					$lines[] = ' - ' . $row;
				}
			}

			$sample_urls = get_posts(
				array(
					'post_type'      => 'wpfc_sermon',
					'posts_per_page' => 10,
					'post_status'    => array( 'publish', 'private', 'future', 'draft' ),
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$lines[] = 'Sample sermon permalinks:';
			if ( empty( $sample_urls ) ) {
				$lines[] = ' - none';
			} else {
				foreach ( $sample_urls as $post ) {
					$lines[] = ' - #' . (int) $post->ID . ' [' . $post->post_status . '] ' . get_permalink( $post );
				}
			}

			return $lines;
		}

		private function section_options_snapshot() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[OPTIONS SNAPSHOT]';

			$option_rows = $wpdb->get_results(
				"SELECT option_name, option_value
				 FROM {$wpdb->options}
				 WHERE option_name LIKE 'sm\_%'
				    OR option_name LIKE 'wpfc\_%'
				    OR option_name LIKE '%sermon%'
				 ORDER BY option_name
				 LIMIT 150",
				ARRAY_A
			);

			if ( empty( $option_rows ) ) {
				$lines[] = 'No obviously related options found.';
				return $lines;
			}

			foreach ( $option_rows as $row ) {
				$lines[] = $row['option_name'] . ' = ' . $this->short_sample_value( maybe_serialize( maybe_unserialize( $row['option_value'] ) ), 140 );
			}

			return $lines;
		}

		private function section_summary() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[SUMMARY]';

			$total_sermons = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
					'wpfc_sermon'
				)
			);

			$audio_sermons = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id)
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.post_type = %s
					 AND pm.meta_key = %s
					 AND pm.meta_value <> ''",
					'wpfc_sermon',
					'sermon_audio'
				)
			);

			$video_sermons = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id)
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.post_type = %s
					 AND pm.meta_key = %s
					 AND pm.meta_value <> ''",
					'wpfc_sermon',
					'sermon_video'
				)
			);

			$lines[] = 'Total sermons detected: ' . $total_sermons;
			$lines[] = 'Audio sermons detected: ' . $audio_sermons;
			$lines[] = 'Video sermons detected: ' . $video_sermons;
			$lines[] = 'Shortcodes implemented by Lite: [sermons], [sermon_sort_fields]';
			$lines[] = 'Archive slug in use: ' . $this->get_archive_slug();

			return $lines;
		}

		private function get_theme_label() {
			$theme = wp_get_theme();
			return $theme->get( 'Name' ) . ' (' . $theme->get_stylesheet() . ')';
		}

		private function format_rewrite_slug( $rewrite ) {
			if ( empty( $rewrite ) || ! is_array( $rewrite ) ) {
				return 'none';
			}

			return isset( $rewrite['slug'] ) ? (string) $rewrite['slug'] : 'default';
		}

		private function format_terms_for_report( $terms ) {
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return 'none';
			}

			$parts = array();

			foreach ( $terms as $term ) {
				$parts[] = $term->name . ' (' . (int) $term->count . ')';
			}

			return implode( ', ', $parts );
		}

		private function clean_line( $value ) {
			$value = wp_strip_all_tags( (string) $value );
			$value = preg_replace( '/\s+/', ' ', $value );
			return trim( $value );
		}

		private function short_sample_value( $value, $limit = 90 ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}

			$value = $this->clean_line( (string) $value );

			if ( strlen( $value ) > $limit ) {
				$value = substr( $value, 0, $limit ) . '...';
			}

			return $value;
		}
	}

	new SML_Plugin();
}
