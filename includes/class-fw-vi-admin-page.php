<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Unyson+ → Visitor Insights.
 *
 * Tabs: Overview (traffic), Pages, Heatmaps, Live, Settings. House
 * conventions as Short Links: a submenu of `fw-extensions` placed through
 * `fw_unysonplus_admin_submenu_order`; actions on `load-{hook}` then a
 * redirect; native `nav-tab-wrapper` tabs; settings rendered by the options
 * framework; charts are inline SVG.
 *
 * Opening any tab first folds pending buffer files into the tables, so the
 * numbers are current even when WP-Cron is not.
 */
class FW_VI_Admin_Page {

	const PARENT_SLUG = 'fw-extensions';
	const PAGE_SLUG   = 'fw-visitor-insights';
	const NONCE       = 'fw_vi_action';
	const NOTICE      = 'fw_ext_vi_notice_';
	const RANGES      = array( 7, 30, 90, 365 );

	/** @var FW_Extension_Visitor_Insights */
	private $ext;

	/** @var string|null */
	private $hook = null;

	/**
	 * @param FW_Extension_Visitor_Insights $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
		add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
		add_filter( 'fw_ext_manager_settings_url', array( $this, '_filter_manager_settings_url' ), 10, 2 );
		add_action( 'admin_init', array( $this, '_redirect_manager_settings' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Menu, URLs, assets
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! FW_VI_Settings::can_view() ) {
			return;
		}

		$this->hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Visitor Insights', 'fw' ),
			__( 'Visitor Insights', 'fw' ),
			(string) apply_filters( 'fw_ext_visitor_insights_view_cap', 'manage_options' ),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook ) {
			add_action( 'load-' . $this->hook, array( $this, '_action_load' ) );
			add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );
		}
	}

	/**
	 * @internal
	 */
	public function _filter_submenu_order( $order ) {
		if ( ! in_array( self::PAGE_SLUG, (array) $order, true ) ) {
			$order[] = self::PAGE_SLUG;
		}

		return $order;
	}

	/**
	 * @internal
	 */
	public function _filter_manager_settings_url( $url, $name ) {
		return 'visitor-insights' === $name ? self::url( 'settings' ) : $url;
	}

	/**
	 * @internal
	 */
	public function _redirect_manager_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only redirect
		if ( isset( $_GET['page'], $_GET['sub-page'], $_GET['extension'] )
			&& 'fw-extensions' === $_GET['page'] && 'extension' === $_GET['sub-page'] && 'visitor-insights' === $_GET['extension']
			&& 'GET' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			wp_safe_redirect( self::url( 'settings' ) );
			exit;
		}
		// phpcs:enable
	}

	/**
	 * @param string $tab
	 * @param array  $args
	 *
	 * @return string
	 */
	public static function url( $tab = '', array $args = array() ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( '' !== $tab && 'overview' !== $tab ) {
			$args['tab'] = $tab;
		}

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * @return string
	 */
	private function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'settings' === $tab && ! current_user_can( 'manage_options' ) ) {
			return 'overview';
		}

		return in_array( $tab, array( 'overview', 'pages', 'heatmaps', 'live', 'settings' ), true ) ? $tab : 'overview';
	}

	/**
	 * @return int
	 */
	private function days() {
		$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $days, self::RANGES, true ) ? $days : 30;
	}

	/**
	 * @internal
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}

		$ver = $this->ext->manifest->get_version();

		if ( 'settings' === $this->tab() ) {
			fw()->backend->enqueue_options_static( $this->ext->get_settings_options() );
		}

		wp_enqueue_style( 'fw-vi-admin', $this->ext->get_uri( '/static/css/admin.css' ), array(), $ver );
		wp_enqueue_script( 'fw-vi-admin', $this->ext->get_uri( '/static/js/admin/admin.js' ), array(), $ver, true );
		wp_localize_script( 'fw-vi-admin', 'UPVIA', array(
			'live'  => esc_url_raw( rest_url( FW_VI_REST::NS . '/live' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'l10n'  => array(
				'reach'     => __( 'Reached by', 'fw' ),
				'time'      => __( 'Avg time in view', 'fw' ),
				'section'   => __( 'Section', 'fw' ),
				'none'      => __( 'No data for this page, device and period yet.', 'fw' ),
				'views'     => __( '%s page views', 'fw' ),
				'missing'   => __( '%s clicks landed on elements that are no longer on this page.', 'fw' ),
				'noSection' => __( 'This page has no builder sections.', 'fw' ),
				'visitors'  => __( 'page views in the last 5 minutes', 'fw' ),
				'quiet'     => __( 'Nobody right now.', 'fw' ),
			),
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Actions
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_load() {
		FW_VI_Rollup::run();

		$action = isset( $_POST['fw_vi_action'] ) ? sanitize_key( wp_unslash( $_POST['fw_vi_action'] ) ) : '';

		if ( '' === $action || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( 'save_settings' === $action ) {
			$before = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
			$values = array_merge( $before, fw_get_options_values_from_input( $this->ext->get_settings_options() ) );

			fw_set_db_ext_settings_option( $this->ext->get_name(), null, $values );
			FW_VI_Settings::flush();
			FW_VI_Collector::maybe_write_config( true );

			$this->notice( 'success', __( 'Settings saved. If your site uses a page cache, clear it so cached pages pick up the change.', 'fw' ) );
			$this->redirect( self::url( 'settings' ) );
		}

		if ( 'remove_data' === $action ) {
			$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';

			if ( 'DELETE' !== strtoupper( $confirm ) ) {
				$this->notice( 'error', __( 'Type DELETE to confirm removing all Visitor Insights data.', 'fw' ) );
				$this->redirect( self::url( 'settings' ) );
			}

			foreach ( FW_VI_Collector::buffer_files() as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}

			FW_VI_Installer::uninstall();
			FW_VI_Installer::install();

			$this->notice( 'success', __( 'All Visitor Insights data was removed. Collection continues from now.', 'fw' ) );
			$this->redirect( self::url( 'settings' ) );
		}

		if ( 'retest' === $action ) {
			FW_VI_Collector::maybe_write_config( true );
			$this->notice( 'success', __( 'Collector checked.', 'fw' ) );
			$this->redirect( self::url( 'settings' ) );
		}
	}

	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	private function notice( $type, $message ) {
		set_transient( self::NOTICE . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	public function render_page() {
		if ( ! FW_VI_Settings::can_view() ) {
			wp_die( esc_html__( 'You are not allowed to view Visitor Insights.', 'fw' ) );
		}

		$tab  = $this->tab();
		$tabs = array(
			'overview' => __( 'Overview', 'fw' ),
			'pages'    => __( 'Pages', 'fw' ),
			'heatmaps' => __( 'Heatmaps', 'fw' ),
			'live'     => __( 'Live', 'fw' ),
		);

		if ( current_user_can( 'manage_options' ) ) {
			$tabs['settings'] = __( 'Settings', 'fw' );
		}
		?>
		<div class="wrap fw-vi">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Visitor Insights', 'fw' ); ?></h1>
			<?php if ( in_array( $tab, array( 'overview', 'pages' ), true ) ) : ?>
				<?php $this->render_range( $tab ); ?>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php $this->render_notice(); ?>

			<h2 class="nav-tab-wrapper" style="margin:.6em 0 1.4em">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $tab ) {
				case 'pages':
					$this->render_pages();
					break;
				case 'heatmaps':
					$this->render_heatmaps();
					break;
				case 'live':
					$this->render_live();
					break;
				case 'settings':
					$this->render_settings();
					break;
				default:
					$this->render_overview();
			}
			?>
		</div>
		<?php
	}

	private function render_notice() {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		if ( $notice ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice['type'] ), wp_kses_post( $notice['message'] ) );
		}

		if ( get_option( 'fw_ext_vi_stale_buffer' ) > time() - 2 * DAY_IN_SECONDS && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Some visitor data waited more than a day to be processed and was discarded. WP-Cron may not be running on this site; reports still update whenever you open this screen.', 'fw' ) . '</p></div>';
		}
	}

	private function render_range( $tab ) {
		$days = $this->days();
		echo '<span class="fw-vi-range">';
		foreach ( self::RANGES as $d ) {
			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url( self::url( $tab, array( 'days' => $d ) ) ),
				$d === $days ? 'is-active' : '',
				esc_html( 365 === $d ? __( '12 months', 'fw' ) : sprintf( _n( '%d day', '%d days', $d, 'fw' ), $d ) )
			);
		}
		echo '</span>';
	}

	private function render_empty() {
		?>
		<div class="fw-vi-empty">
			<h2><?php esc_html_e( 'No visits recorded yet', 'fw' ); ?></h2>
			<p><?php esc_html_e( 'Visitor Insights is collecting. Your own visits are not counted while you are logged in as an editor, so open your site in a private window to see the first page view appear here within a minute or two.', 'fw' ); ?></p>
			<p class="description"><?php echo esc_html( 'direct' === get_option( FW_VI_Collector::OPT_ENDPOINT ) ? __( 'Collector: direct (fast path).', 'fw' ) : __( 'Collector: REST fallback (the direct collector is not reachable on this server).', 'fw' ) ); ?></p>
		</div>
		<?php
	}

	private function render_overview() {
		$days   = $this->days();
		$totals = FW_VI_Reports::totals( $days );

		if ( ! $totals['views'] ) {
			$this->render_empty();
			return;
		}

		$tiles = array(
			array( __( 'Page views', 'fw' ), number_format_i18n( $totals['views'] ) ),
			array( __( 'Unique visitors', 'fw' ), number_format_i18n( $totals['uniques'] ) ),
			array( __( 'Avg time on page', 'fw' ), self::duration( $totals['engaged'] ) ),
			array( __( 'Pages viewed', 'fw' ), number_format_i18n( $totals['pages'] ) ),
		);
		?>
		<div class="fw-vi-tiles">
			<?php foreach ( $tiles as $t ) : ?>
				<div class="fw-vi-tile"><span><?php echo esc_html( $t[0] ); ?></span><strong><?php echo esc_html( $t[1] ); ?></strong></div>
			<?php endforeach; ?>
		</div>

		<div class="fw-vi-card"><?php self::chart( FW_VI_Reports::series( $days ) ); ?></div>

		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Top pages', 'fw' ); ?></h2>
			<?php $this->pages_table( FW_VI_Reports::top_pages( $days, 10 ) ); ?>
			<p><a href="<?php echo esc_url( self::url( 'pages', array( 'days' => $days ) ) ); ?>"><?php esc_html_e( 'All pages →', 'fw' ); ?></a></p>
		</div>

		<div class="fw-vi-grid">
			<?php
			$labels = array(
				'ref' => __( 'Referrers', 'fw' ),
				'us'  => __( 'Campaign sources', 'fw' ),
				'uc'  => __( 'Campaigns', 'fw' ),
				'dev' => __( 'Devices', 'fw' ),
				'br'  => __( 'Browsers', 'fw' ),
				'os'  => __( 'Operating systems', 'fw' ),
				'cc'  => __( 'Countries', 'fw' ),
				'um'  => __( 'Campaign mediums', 'fw' ),
			);
			$names  = array( 'd' => __( 'Desktop', 'fw' ), 't' => __( 'Tablet', 'fw' ), 'm' => __( 'Mobile', 'fw' ) );

			foreach ( $labels as $dim => $label ) {
				$rows = FW_VI_Reports::breakdown( $dim, $days, 8 );

				if ( ! $rows && in_array( $dim, array( 'us', 'uc', 'um', 'cc' ), true ) ) {
					continue;
				}

				$sum = max( 1, array_sum( $rows ) );
				echo '<div class="fw-vi-card fw-vi-break"><h2>' . esc_html( $label ) . '</h2>';

				if ( ! $rows ) {
					echo '<p class="description">' . esc_html( 'ref' === $dim ? __( 'All visits so far came directly or from this site.', 'fw' ) : __( 'Nothing yet.', 'fw' ) ) . '</p>';
				}

				foreach ( $rows as $val => $n ) {
					$name = 'dev' === $dim && isset( $names[ $val ] ) ? $names[ $val ] : $val;
					printf(
						'<div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:%1$s%%"></span><span class="fw-vi-bar__label">%2$s</span><span class="fw-vi-bar__n">%3$s</span></div>',
						esc_attr( round( 100 * $n / $sum, 1 ) ),
						esc_html( $name ),
						esc_html( number_format_i18n( $n ) )
					);
				}
				echo '</div>';
			}
			?>
		</div>
		<?php
	}

	private function render_pages() {
		$days = $this->days();
		$rows = FW_VI_Reports::top_pages( $days, 200 );

		if ( ! $rows ) {
			$this->render_empty();
			return;
		}

		echo '<div class="fw-vi-card">';
		$this->pages_table( $rows );
		echo '</div>';
	}

	/**
	 * @param array $rows from FW_VI_Reports::top_pages()
	 */
	private function pages_table( array $rows ) {
		?>
		<table class="widefat striped fw-vi-table">
			<thead><tr>
				<th><?php esc_html_e( 'Page', 'fw' ); ?></th>
				<th class="num"><?php esc_html_e( 'Views', 'fw' ); ?></th>
				<th class="num"><?php esc_html_e( 'Unique', 'fw' ); ?></th>
				<th class="num"><?php esc_html_e( 'Avg time', 'fw' ); ?></th>
				<th class="num" title="<?php esc_attr_e( 'Share of page views that scrolled at least half-way down', 'fw' ); ?>"><?php esc_html_e( 'Read half', 'fw' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td>
						<?php if ( '' !== $r->title ) : ?><strong><?php echo esc_html( $r->title ); ?></strong><br><?php endif; ?>
						<a href="<?php echo esc_url( FW_VI_Reports::url( $r->path ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $r->path ); ?></code></a>
					</td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r->views ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r->uniques ) ); ?></td>
					<td class="num"><?php echo esc_html( self::duration( $r->engaged ) ); ?></td>
					<td class="num"><?php echo null === $r->half ? '—' : esc_html( $r->half . '%' ); ?></td>
					<td class="num"><a class="button button-small" href="<?php echo esc_url( self::url( 'heatmaps', array( 'path' => $r->path ) ) ); ?>"><?php esc_html_e( 'Heatmap', 'fw' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_heatmaps() {
		$pages = FW_VI_Reports::top_pages( 365, 100 );
		$path  = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $path ) {
			$path = $pages ? $pages[0]->path : (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		}

		$path = '/' . ltrim( $path, '/' );
		?>
		<div class="fw-vi-hm" data-src="<?php echo esc_url( FW_VI_Heatmaps::view_url( $path, true ) ); ?>">
			<form class="fw-vi-hm__bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="heatmaps">
				<label><span class="screen-reader-text"><?php esc_html_e( 'Page', 'fw' ); ?></span>
					<select name="path" onchange="this.form.submit()">
						<?php if ( ! wp_list_filter( $pages, array( 'path' => $path ) ) ) : ?>
							<option value="<?php echo esc_attr( $path ); ?>" selected><?php echo esc_html( $path ); ?></option>
						<?php endif; ?>
						<?php foreach ( $pages as $p ) : ?>
							<option value="<?php echo esc_attr( $p->path ); ?>" <?php selected( $p->path, $path ); ?>>
								<?php echo esc_html( ( '' !== $p->title ? $p->title . ' — ' : '' ) . $p->path . ' (' . number_format_i18n( $p->views ) . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<span class="fw-vi-seg" data-name="mode" role="group" aria-label="<?php esc_attr_e( 'Map', 'fw' ); ?>">
					<button type="button" data-value="clicks" class="is-active"><?php esc_html_e( 'Clicks', 'fw' ); ?></button>
					<button type="button" data-value="scroll"><?php esc_html_e( 'Scroll reach', 'fw' ); ?></button>
					<button type="button" data-value="attention"><?php esc_html_e( 'Attention', 'fw' ); ?></button>
					<button type="button" data-value="elements"><?php esc_html_e( 'Elements', 'fw' ); ?></button>
				</span>

				<span class="fw-vi-seg" data-name="device" role="group" aria-label="<?php esc_attr_e( 'Device', 'fw' ); ?>">
					<button type="button" data-value="d" class="is-active"><span class="dashicons dashicons-desktop"></span> <?php esc_html_e( 'Desktop', 'fw' ); ?></button>
					<button type="button" data-value="t"><span class="dashicons dashicons-tablet"></span> <?php esc_html_e( 'Tablet', 'fw' ); ?></button>
					<button type="button" data-value="m"><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Mobile', 'fw' ); ?></button>
				</span>

				<label><span class="screen-reader-text"><?php esc_html_e( 'Period', 'fw' ); ?></span>
					<select data-name="days">
						<?php foreach ( self::RANGES as $d ) : ?>
							<option value="<?php echo (int) $d; ?>" <?php selected( 30, $d ); ?>><?php echo esc_html( 365 === $d ? __( 'Last 12 months', 'fw' ) : sprintf( __( 'Last %d days', 'fw' ), $d ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<a class="button" href="<?php echo esc_url( FW_VI_Heatmaps::view_url( $path, false ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open full size', 'fw' ); ?></a>
			</form>

			<p class="fw-vi-hm__info" aria-live="polite"></p>

			<div class="fw-vi-hm__stage"><iframe title="<?php esc_attr_e( 'Heatmap', 'fw' ); ?>" loading="eager"></iframe></div>

			<div class="fw-vi-card fw-vi-hm__sections">
				<h2><?php esc_html_e( 'Sections', 'fw' ); ?></h2>
				<p class="description"><?php esc_html_e( 'How many page views reached each builder section, and how long it stayed in view (at least half of it visible, tab in front).', 'fw' ); ?></p>
				<div class="fw-vi-hm__table"></div>
			</div>
		</div>
		<?php
	}

	private function render_live() {
		?>
		<div class="fw-vi-card fw-vi-live" data-live>
			<p class="fw-vi-live__count"><strong>–</strong> <span><?php esc_html_e( 'page views in the last 5 minutes', 'fw' ); ?></span></p>
			<div class="fw-vi-live__pages"></div>
			<p class="description"><?php esc_html_e( 'Updates every 15 seconds. Visits arrive within about a minute.', 'fw' ); ?></p>
		</div>
		<?php
	}

	private function render_settings() {
		$schema   = $this->ext->get_settings_options();
		$values   = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		$endpoint = get_option( FW_VI_Collector::OPT_ENDPOINT );
		$consent  = function_exists( 'wp_has_consent' );
		?>
		<div class="fw-vi-card fw-vi-status">
			<h2><?php esc_html_e( 'Status', 'fw' ); ?></h2>
			<ul>
				<li><?php echo 'direct' === $endpoint
					? '<span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Collector: direct. Visits are recorded without loading WordPress.', 'fw' )
					: '<span class="dashicons dashicons-info"></span> ' . esc_html__( 'Collector: REST fallback. The direct collector is not reachable on this server, so visits are recorded through the WordPress REST API (slower, still complete).', 'fw' ); ?></li>
				<li><?php echo $consent
					? '<span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'A consent plugin using the WordPress Consent API is active. Tracking waits for "anonymous statistics" consent.', 'fw' )
					: '<span class="dashicons dashicons-info"></span> ' . esc_html__( 'No consent plugin using the WordPress Consent API is active. The Consent setting below decides.', 'fw' ); ?></li>
				<li><span class="dashicons dashicons-shield"></span> <?php echo wp_kses( __( 'Let visitors opt out with the <code>[visitor_insights_optout]</code> shortcode, e.g. on your privacy policy page.', 'fw' ), array( 'code' => array() ) ); ?></li>
			</ul>
			<form method="post" action="<?php echo esc_url( self::url( 'settings' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_vi_action" value="retest" />
				<button type="submit" class="button button-small"><?php esc_html_e( 'Check the collector again', 'fw' ); ?></button>
			</form>
		</div>

		<form method="post" action="<?php echo esc_url( self::url( 'settings' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_vi_action" value="save_settings" />
			<?php echo fw()->backend->render_options( $schema, $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button></p>
		</form>

		<div class="fw-vi-danger">
			<h2><?php esc_html_e( 'Remove all data', 'fw' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Deletes every report, heatmap and section statistic. Deactivating the extension never does this. This cannot be undone.', 'fw' ); ?></p>
			<form method="post" action="<?php echo esc_url( self::url( 'settings' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_vi_action" value="remove_data" />
				<label><?php esc_html_e( 'Type DELETE to confirm:', 'fw' ); ?> <input type="text" name="confirm" class="regular-text" autocomplete="off" /></label>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Remove all Visitor Insights data', 'fw' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * @param float $seconds
	 *
	 * @return string
	 */
	private static function duration( $seconds ) {
		$s = (int) round( $seconds );

		return $s >= 60 ? sprintf( '%dm %02ds', intdiv( $s, 60 ), $s % 60 ) : $s . 's';
	}

	/**
	 * Views per day, uniques as the darker inner bar. Each day carries a
	 * <title> with its numbers; the chart has a text alternative.
	 *
	 * @param array $series [ {date, views, uniques} ]
	 */
	private static function chart( array $series ) {
		$n     = count( $series );
		$max   = max( 1, max( wp_list_pluck( $series, 'views' ) ) );
		$scale = self::nice( $max );
		$w     = 1000;
		$h     = 220;
		$pl    = 44;
		$pb    = 24;
		$slot  = ( $w - $pl ) / max( 1, $n );
		$bar   = max( 1, min( 28, $slot * 0.72 ) );
		$total = array_sum( wp_list_pluck( $series, 'views' ) );
		$every = max( 1, (int) ceil( $n / 8 ) );
		$label = sprintf( __( 'Page views per day: %1$s over %2$d days, highest %3$s in a day.', 'fw' ), number_format_i18n( $total ), $n, number_format_i18n( $max ) );
		?>
		<svg class="fw-vi-chart" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) ( $h + $pb ); ?>" role="img" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php for ( $g = 0; $g <= 4; $g++ ) : ?>
				<?php $y = $h - ( $h - 10 ) * $g / 4; ?>
				<line class="fw-vi-chart__grid" x1="<?php echo (int) $pl; ?>" x2="<?php echo (int) $w; ?>" y1="<?php echo esc_attr( $y ); ?>" y2="<?php echo esc_attr( $y ); ?>" />
				<text class="fw-vi-chart__axis" x="<?php echo (int) ( $pl - 8 ); ?>" y="<?php echo esc_attr( $y + 4 ); ?>" text-anchor="end"><?php echo esc_html( number_format_i18n( $scale * $g / 4 ) ); ?></text>
			<?php endfor; ?>
			<?php foreach ( $series as $i => $d ) : ?>
				<?php
				$x  = $pl + $slot * $i + ( $slot - $bar ) / 2;
				$vh = ( $h - 10 ) * $d['views'] / $scale;
				$uh = ( $h - 10 ) * min( $d['uniques'], $d['views'] ) / $scale;
				$ts = strtotime( $d['date'] . ' 12:00:00' );
				?>
				<g>
					<title><?php echo esc_html( date_i18n( get_option( 'date_format' ), $ts ) . ': ' . sprintf( __( '%1$s views, %2$s unique visitors', 'fw' ), number_format_i18n( $d['views'] ), number_format_i18n( $d['uniques'] ) ) ); ?></title>
					<rect class="fw-vi-chart__hit" x="<?php echo esc_attr( $pl + $slot * $i ); ?>" y="0" width="<?php echo esc_attr( $slot ); ?>" height="<?php echo (int) $h; ?>" />
					<?php if ( $d['views'] ) : ?>
						<rect class="fw-vi-chart__bar" x="<?php echo esc_attr( $x ); ?>" y="<?php echo esc_attr( $h - $vh ); ?>" width="<?php echo esc_attr( $bar ); ?>" height="<?php echo esc_attr( $vh ); ?>" rx="2" />
						<rect class="fw-vi-chart__bar fw-vi-chart__bar--u" x="<?php echo esc_attr( $x ); ?>" y="<?php echo esc_attr( $h - $uh ); ?>" width="<?php echo esc_attr( $bar ); ?>" height="<?php echo esc_attr( $uh ); ?>" rx="2" />
					<?php endif; ?>
				</g>
				<?php if ( 0 === $i % $every ) : ?>
					<text class="fw-vi-chart__axis" x="<?php echo esc_attr( $pl + $slot * $i + $slot / 2 ); ?>" y="<?php echo (int) ( $h + 17 ); ?>" text-anchor="middle"><?php echo esc_html( date_i18n( 'M j', $ts ) ); ?></text>
				<?php endif; ?>
			<?php endforeach; ?>
		</svg>
		<p class="fw-vi-chart__legend">
			<span class="fw-vi-swatch"></span><?php esc_html_e( 'Page views', 'fw' ); ?>
			<span class="fw-vi-swatch fw-vi-swatch--u"></span><?php esc_html_e( 'Unique visitors', 'fw' ); ?>
		</p>
		<?php
	}

	/**
	 * A round axis top whose quarters are round numbers.
	 *
	 * @param int $max
	 *
	 * @return int
	 */
	private static function nice( $max ) {
		$quarter = max( 1, $max / 4 );
		$mag     = pow( 10, floor( log10( $quarter ) ) );

		foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) {
			$step = $m * $mag;
			if ( $step >= $quarter && floor( $step ) === $step ) {
				return (int) ( $step * 4 );
			}
		}

		return (int) ceil( $quarter ) * 4;
	}
}
