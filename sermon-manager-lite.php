<?php
/**
 * Plugin Name: Sermon Manager Lite Audit
 * Plugin URI: https://stronganchortech.com
 * Description: Audit companion for legacy Sermon Manager sites. Generates a copy/pasteable report showing how the site is currently using Sermon Manager so a future Sermon Manager Lite replacement can be built safely.
 * Version: 0.1.0
 * Author: Strong Anchor Tech
 * Author URI: https://stronganchortech.com
 * License: GPLv2 or later
 * Text Domain: sermon-manager-lite-audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SMLA_Audit_Plugin' ) ) {

	final class SMLA_Audit_Plugin {

		const VERSION = '0.1.0';

		/**
		 * Known Sermon Manager shortcodes gathered from public plugin docs plus one newer shortcode.
		 *
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
		 * Known Sermon Manager taxonomies.
		 *
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
		 * Known/likely sermon meta keys from legacy usage.
		 *
		 * @var string[]
		 */
		private $known_meta_keys = array(
			'sermon_date',
			'bible_passage',
			'sermon_description',
			'sermon_audio',
			'sermon_video',
			'sermon_notes',
			'_wpfc_sermon_duration',
			'_wpfc_sermon_size',
		);

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
			add_action( 'admin_post_smla_download_report', array( $this, 'handle_download_report' ) );
		}

		public function register_admin_page() {
			add_management_page(
				'Sermon Manager Lite Audit',
				'Sermon Manager Lite Audit',
				'manage_options',
				'sermon-manager-lite-audit',
				array( $this, 'render_admin_page' )
			);
		}

		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'sermon-manager-lite-audit' ) );
			}

			$report = $this->generate_report();
			?>
			<div class="wrap">
				<h1>Sermon Manager Lite Audit</h1>
				<p>This page inspects how the current site is using the legacy Sermon Manager plugin so a compatible Sermon Manager Lite replacement can be built safely.</p>

				<p>
					<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=smla_download_report' ), 'smla_download_report' ) ); ?>">Download TXT Report</a>
				</p>

				<textarea readonly="readonly" spellcheck="false" style="width:100%;min-height:700px;font-family:Consolas,Monaco,monospace;"><?php echo esc_textarea( $report ); ?></textarea>
			</div>
			<?php
		}

		public function handle_download_report() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'sermon-manager-lite-audit' ) );
			}

			check_admin_referer( 'smla_download_report' );

			$report = $this->generate_report();
			$site   = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
			$file   = 'sermon-manager-lite-audit-' . $site . '-' . gmdate( 'Ymd-His' ) . '.txt';

			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $file );
			echo $report; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		/**
		 * Main report generator.
		 *
		 * @return string
		 */
		private function generate_report() {
			$lines   = array();
			$lines[] = 'SERMON MANAGER LITE AUDIT REPORT';
			$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
			$lines[] = 'Home URL: ' . home_url( '/' );
			$lines[] = 'Site Name: ' . get_bloginfo( 'name' );
			$lines[] = 'WP Version: ' . get_bloginfo( 'version' );
			$lines[] = 'Theme: ' . $this->get_theme_label();
			$lines[] = 'PHP Version: ' . PHP_VERSION;
			$lines[] = str_repeat( '=', 80 );

			$lines = array_merge( $lines, $this->section_plugin_status() );
			$lines = array_merge( $lines, $this->section_content_counts() );
			$lines = array_merge( $lines, $this->section_taxonomy_details() );
			$lines = array_merge( $lines, $this->section_meta_usage() );
			$lines = array_merge( $lines, $this->section_shortcode_usage() );
			$lines = array_merge( $lines, $this->section_template_overrides() );
			$lines = array_merge( $lines, $this->section_theme_code_refs() );
			$lines = array_merge( $lines, $this->section_menu_and_permalink_usage() );
			$lines = array_merge( $lines, $this->section_options_snapshot() );
			$lines = array_merge( $lines, $this->section_summary() );

			return implode( "\n", $lines );
		}

		/**
		 * SECTION: Plugin status
		 *
		 * @return array
		 */
		private function section_plugin_status() {
			$lines   = array();
			$lines[] = '';
			$lines[] = '[PLUGIN STATUS]';

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugins            = get_plugins();
			$sermon_plugin_file = $this->find_sermon_manager_plugin_file( $plugins );
			$is_active          = false;
			$plugin_data        = array();

			if ( $sermon_plugin_file ) {
				$plugin_data = $plugins[ $sermon_plugin_file ];

				if ( ! function_exists( 'is_plugin_active' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$is_active = is_plugin_active( $sermon_plugin_file );
			}

			$lines[] = 'Sermon Manager plugin file: ' . ( $sermon_plugin_file ? $sermon_plugin_file : 'NOT FOUND' );
			$lines[] = 'Sermon Manager active: ' . ( $is_active ? 'yes' : 'no' );

			if ( ! empty( $plugin_data ) ) {
				$lines[] = 'Sermon Manager name: ' . $this->clean_line( $plugin_data['Name'] ?? '' );
				$lines[] = 'Sermon Manager version: ' . $this->clean_line( $plugin_data['Version'] ?? '' );
				$lines[] = 'Sermon Manager author: ' . $this->clean_line( wp_strip_all_tags( $plugin_data['Author'] ?? '' ) );
			}

			$lines[] = 'Post type wpfc_sermon registered: ' . ( post_type_exists( 'wpfc_sermon' ) ? 'yes' : 'no' );

			foreach ( $this->known_taxonomies as $taxonomy ) {
				$lines[] = 'Taxonomy ' . $taxonomy . ' registered: ' . ( taxonomy_exists( $taxonomy ) ? 'yes' : 'no' );
			}

			return $lines;
		}

		/**
		 * SECTION: CPT + content counts
		 *
		 * @return array
		 */
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

			$lines[] = 'Private sermons: ' . $private_count;
			$lines[] = 'Scheduled sermons: ' . $future_count;

			$content_nonempty = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_type = %s
					 AND post_content IS NOT NULL
					 AND post_content <> ''",
					'wpfc_sermon'
				)
			);

			$lines[] = 'Sermons with non-empty post_content: ' . $content_nonempty;

			return $lines;
		}

		/**
		 * SECTION: Taxonomy details
		 *
		 * @return array
		 */
		private function section_taxonomy_details() {
			$lines   = array();
			$lines[] = '';
			$lines[] = '[TAXONOMY DETAILS]';

			foreach ( $this->known_taxonomies as $taxonomy ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					$lines[] = $taxonomy . ': not registered';
					continue;
				}

				$tax_obj      = get_taxonomy( $taxonomy );
				$term_count   = wp_count_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);
				$top_terms    = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'number'     => 10,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				$lines[]      = $taxonomy . ':';
				$lines[]      = ' - label: ' . $this->clean_line( $tax_obj->label ?? '' );
				$lines[]      = ' - public: ' . ( ! empty( $tax_obj->public ) ? 'yes' : 'no' );
				$lines[]      = ' - hierarchical: ' . ( ! empty( $tax_obj->hierarchical ) ? 'yes' : 'no' );
				$lines[]      = ' - rewrite slug: ' . $this->format_rewrite_slug( $tax_obj->rewrite ?? false );
				$lines[]      = ' - total terms: ' . (int) $term_count;
				$lines[]      = ' - top terms: ' . $this->format_terms_for_report( $top_terms );
			}

			return $lines;
		}

		/**
		 * SECTION: Meta usage
		 *
		 * @return array
		 */
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

			foreach ( $this->known_meta_keys as $meta_key ) {
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
					. ( $sample !== null ? ' | sample: ' . $this->short_sample_value( $sample ) : '' );
			}

			$top_meta = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_key, COUNT(*) AS qty
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.post_type = %s
					 GROUP BY pm.meta_key
					 ORDER BY qty DESC
					 LIMIT 25",
					'wpfc_sermon'
				),
				ARRAY_A
			);

			$lines[] = 'Top 25 sermon meta keys:';
			if ( empty( $top_meta ) ) {
				$lines[] = ' - none';
			} else {
				foreach ( $top_meta as $row ) {
					$lines[] = ' - ' . $row['meta_key'] . ': ' . (int) $row['qty'];
				}
			}

			return $lines;
		}

		/**
		 * SECTION: shortcode usage in posts/pages/widgets/options.
		 *
		 * @return array
		 */
		private function section_shortcode_usage() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[SHORTCODE USAGE]';

			foreach ( $this->known_shortcodes as $shortcode ) {
				$pattern    = '%[' . $wpdb->esc_like( $shortcode ) . '%';
				$post_hits  = $wpdb->get_results(
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
				$option_hits = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_name
						 FROM {$wpdb->options}
						 WHERE option_value LIKE %s
						 ORDER BY option_name",
						$pattern
					),
					ARRAY_A
				);

				$lines[] = '[' . $shortcode . ']';
				$lines[] = ' - content hits: ' . count( $post_hits );
				foreach ( array_slice( $post_hits, 0, 20 ) as $hit ) {
					$lines[] = '   * ' . $hit['post_type'] . ' #' . (int) $hit['ID'] . ' [' . $hit['post_status'] . '] ' . $this->clean_line( $hit['post_title'] );
				}
				if ( count( $post_hits ) > 20 ) {
					$lines[] = '   * ... ' . ( count( $post_hits ) - 20 ) . ' more';
				}

				$lines[] = ' - option/widget hits: ' . count( $option_hits );
				foreach ( array_slice( $option_hits, 0, 20 ) as $hit ) {
					$lines[] = '   * option: ' . $hit['option_name'];
				}
				if ( count( $option_hits ) > 20 ) {
					$lines[] = '   * ... ' . ( count( $option_hits ) - 20 ) . ' more';
				}
			}

			return $lines;
		}

		/**
		 * SECTION: theme template overrides / sermon.css.
		 *
		 * @return array
		 */
		private function section_template_overrides() {
			$lines   = array();
			$lines[] = '';
			$lines[] = '[THEME TEMPLATE OVERRIDES]';

			$stylesheet_dir = get_stylesheet_directory();
			$theme_files    = $this->get_theme_files_recursive( $stylesheet_dir );

			$interesting = array(
				'archive-wpfc_sermon.php',
				'single-wpfc_sermon.php',
				'taxonomy-wpfc_preacher.php',
				'taxonomy-wpfc_sermon_series.php',
				'taxonomy-wpfc_sermon_topics.php',
				'taxonomy-wpfc_bible_book.php',
				'taxonomy-wpfc_service_type.php',
				'sermon.css',
			);

			foreach ( $interesting as $needle ) {
				$found = false;
				foreach ( $theme_files as $relative ) {
					if ( basename( $relative ) === $needle ) {
						$lines[] = 'Found theme override/file: ' . $relative;
						$found   = true;
					}
				}
				if ( ! $found ) {
					$lines[] = 'Not found: ' . $needle;
				}
			}

			$view_refs = array();
			foreach ( $theme_files as $relative ) {
				if ( stripos( $relative, 'sermon' ) !== false || stripos( $relative, 'wpfc' ) !== false ) {
					$view_refs[] = $relative;
				}
			}

			$lines[] = 'Other theme files with "sermon" or "wpfc" in filename:';
			if ( empty( $view_refs ) ) {
				$lines[] = ' - none';
			} else {
				foreach ( array_slice( $view_refs, 0, 50 ) as $file ) {
					$lines[] = ' - ' . $file;
				}
				if ( count( $view_refs ) > 50 ) {
					$lines[] = ' - ... ' . ( count( $view_refs ) - 50 ) . ' more';
				}
			}

			return $lines;
		}

		/**
		 * SECTION: theme/plugin code references to Sermon Manager internals.
		 *
		 * @return array
		 */
		private function section_theme_code_refs() {
			$lines   = array();
			$lines[] = '';
			$lines[] = '[CODE REFERENCES IN THE ACTIVE THEME]';

			$theme_dir = get_stylesheet_directory();
			$files     = $this->get_theme_files_recursive( $theme_dir, array( 'php', 'css', 'js', 'html', 'txt' ) );

			$needles = array(
				'wpfc_sermon',
				'wpfc_preacher',
				'wpfc_sermon_series',
				'wpfc_sermon_topics',
				'wpfc_bible_book',
				'wpfc_service_type',
				'[sermons',
				'[sermon_images',
				'[list_podcasts',
				'[list_sermons',
				'[latest_series',
				'[sermon_sort_fields',
				'[latest_sermon',
				'sm_',
				'SermonManager',
			);

			$matches = array();

			foreach ( $files as $relative ) {
				$absolute = trailingslashit( $theme_dir ) . $relative;
				$content  = @file_get_contents( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( ! is_string( $content ) || $content === '' ) {
					continue;
				}

				$found_needles = array();
				foreach ( $needles as $needle ) {
					if ( strpos( $content, $needle ) !== false ) {
						$found_needles[] = $needle;
					}
				}

				if ( ! empty( $found_needles ) ) {
					$matches[ $relative ] = $found_needles;
				}
			}

			if ( empty( $matches ) ) {
				$lines[] = 'No obvious Sermon Manager references found in active theme files.';
				return $lines;
			}

			foreach ( $matches as $file => $found_needles ) {
				$lines[] = $file . ' => ' . implode( ', ', $found_needles );
			}

			return $lines;
		}

		/**
		 * SECTION: menu/permalink/archive patterns.
		 *
		 * @return array
		 */
		private function section_menu_and_permalink_usage() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[MENU / URL / PERMALINK USAGE]';

			$archive_link = get_post_type_archive_link( 'wpfc_sermon' );
			$lines[]      = 'Archive link for wpfc_sermon: ' . ( $archive_link ? $archive_link : 'not available' );

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
					strpos( $url, 'post_type=wpfc_sermon' ) !== false
					|| strpos( $url, '/sermons' ) !== false
					|| strpos( $url, 'wpfc_sermon' ) !== false
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

		/**
		 * SECTION: relevant options snapshot.
		 *
		 * @return array
		 */
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

		/**
		 * SECTION: summary
		 *
		 * @return array
		 */
		private function section_summary() {
			global $wpdb;

			$lines   = array();
			$lines[] = '';
			$lines[] = '[SUMMARY FOR SECOND PASS]';

			$total_sermons = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
					'wpfc_sermon'
				)
			);

			$shortcode_hits = 0;
			foreach ( $this->known_shortcodes as $shortcode ) {
				$pattern = '%[' . $wpdb->esc_like( $shortcode ) . '%';

				$shortcode_hits += (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*)
						 FROM {$wpdb->posts}
						 WHERE post_content LIKE %s
						 AND post_type IN ('post','page','wp_block','wp_template','wp_template_part')
						 AND post_status NOT IN ('trash','auto-draft')",
						$pattern
					)
				);
			}

			$lines[] = 'Total sermons detected: ' . $total_sermons;
			$lines[] = 'Known shortcode placements detected: ' . $shortcode_hits;
			$lines[] = 'Theme contains single/archive/template references: ' . ( $this->theme_appears_sermon_aware() ? 'yes' : 'no' );
			$lines[] = 'Recommended next step: build compatibility layer first (CPT, taxonomies, meta boxes, archive/single support, and only the shortcodes actually detected on this site).';

			return $lines;
		}

		/**
		 * Locate the Sermon Manager plugin entry.
		 *
		 * @param array $plugins Plugins array from get_plugins().
		 * @return string
		 */
		private function find_sermon_manager_plugin_file( $plugins ) {
			foreach ( $plugins as $plugin_file => $plugin_data ) {
				$name = strtolower( (string) ( $plugin_data['Name'] ?? '' ) );
				$text = strtolower( (string) ( $plugin_data['TextDomain'] ?? '' ) );

				if (
					strpos( $plugin_file, 'sermon-manager' ) !== false
					|| strpos( $name, 'sermon manager' ) !== false
					|| strpos( $text, 'sermon-manager' ) !== false
				) {
					return $plugin_file;
				}
			}

			return '';
		}

		/**
		 * @return string
		 */
		private function get_theme_label() {
			$theme = wp_get_theme();
			return $theme->get( 'Name' ) . ' (' . $theme->get_stylesheet() . ')';
		}

		/**
		 * @param mixed $rewrite
		 * @return string
		 */
		private function format_rewrite_slug( $rewrite ) {
			if ( empty( $rewrite ) || ! is_array( $rewrite ) ) {
				return 'none';
			}

			return isset( $rewrite['slug'] ) ? (string) $rewrite['slug'] : 'default';
		}

		/**
		 * @param array|WP_Error $terms
		 * @return string
		 */
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

		/**
		 * @param string $base_dir
		 * @param string[] $extensions
		 * @return string[]
		 */
		private function get_theme_files_recursive( $base_dir, $extensions = array() ) {
			$results = array();

			if ( ! is_dir( $base_dir ) ) {
				return $results;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$base_dir,
					FilesystemIterator::SKIP_DOTS
				)
			);

			$base_dir = wp_normalize_path( $base_dir );

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				$path = wp_normalize_path( $file_info->getPathname() );
				$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

				if ( ! empty( $extensions ) && ! in_array( $ext, $extensions, true ) ) {
					continue;
				}

				$relative   = ltrim( str_replace( $base_dir, '', $path ), '/' );
				$results[]  = $relative;
			}

			sort( $results );

			return $results;
		}

		/**
		 * @param string $value
		 * @return string
		 */
		private function clean_line( $value ) {
			$value = wp_strip_all_tags( (string) $value );
			$value = preg_replace( '/\s+/', ' ', $value );
			return trim( $value );
		}

		/**
		 * @param mixed $value
		 * @param int   $limit
		 * @return string
		 */
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

		/**
		 * @return bool
		 */
		private function theme_appears_sermon_aware() {
			$theme_dir = get_stylesheet_directory();
			$files     = $this->get_theme_files_recursive( $theme_dir, array( 'php', 'css', 'js', 'html', 'txt' ) );

			foreach ( $files as $relative ) {
				if (
					basename( $relative ) === 'archive-wpfc_sermon.php'
					|| basename( $relative ) === 'single-wpfc_sermon.php'
					|| basename( $relative ) === 'sermon.css'
					|| stripos( $relative, 'sermon' ) !== false
					|| stripos( $relative, 'wpfc' ) !== false
				) {
					return true;
				}
			}

			return false;
		}
	}

	new SMLA_Audit_Plugin();
}
