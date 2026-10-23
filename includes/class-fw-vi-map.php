<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The visitor map: countries shaded by page views and cities as dots, drawn
 * as inline SVG from an outline shipped with the extension
 * (static/data/world.json — Natural Earth 1:110m, public domain, through
 * world-atlas; equirectangular, built by static/data/build-world.cjs). No map
 * service is called.
 *
 * All channels: the daily totals (sources dims `cc` and `ge`, which carries a
 * city's coordinates rounded to about 10 km). One channel: the visit log
 * (kept for the "Keep visit paths" period), because the daily totals do not
 * split countries by channel.
 */
class FW_VI_Map {

	/**
	 * @return array|null { w, h, n, s, c: cc => path, names: cc => name }
	 */
	public static function world() {
		static $world = null;

		if ( null === $world ) {
			$file  = dirname( __DIR__ ) . '/static/data/world.json';
			$world = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $world ? $world : null;
	}

	/**
	 * @param int    $days
	 * @param string $ch channel key, or ''
	 *
	 * @return array { countries: cc => n, cities: [ { label, lat, lon, n } ] }
	 */
	public static function data( $days, $ch = '' ) {
		global $wpdb;

		$countries = array();
		$cities    = array();

		if ( '' === $ch ) {
			$countries = array_map( 'intval', FW_VI_Reports::breakdown( 'cc', $days, 250 ) );
			foreach ( FW_VI_Reports::breakdown( 'ge', $days, 400 ) as $v => $n ) {
				$p = explode( '|', (string) $v );
				$c = explode( ',', (string) ( $p[3] ?? '' ) );
				if ( 2 === count( $c ) ) {
					$cities[] = array( 'label' => implode( ', ', array_filter( array( $p[2] ?? '', $p[1] ?? '', $p[0] ?? '' ) ) ), 'lat' => (float) $c[0], 'lon' => (float) $c[1], 'n' => (int) $n );
				}
			}
		} else {
			list( $from, $to ) = FW_VI_Reports::range( $days );
			$t = FW_VI_Installer::table( 'visits' );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT cc, SUM(pages) n FROM {$t} WHERE ch = %s AND cc <> '' AND day BETWEEN %s AND %s GROUP BY cc", $ch, $from, $to ) ) as $r ) { // phpcs:ignore WordPress.DB
				$countries[ $r->cc ] = (int) $r->n;
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT cc, region, city, ll, SUM(pages) n FROM {$t} WHERE ch = %s AND ll <> '' AND day BETWEEN %s AND %s GROUP BY cc, region, city, ll ORDER BY n DESC LIMIT 400", $ch, $from, $to ) ) as $r ) { // phpcs:ignore WordPress.DB
				$c        = explode( ',', $r->ll );
				$cities[] = array( 'label' => implode( ', ', array_filter( array( $r->city, $r->region, $r->cc ) ) ), 'lat' => (float) $c[0], 'lon' => (float) ( $c[1] ?? 0 ), 'n' => (int) $r->n );
			}
		}

		// The same city under slightly different coordinates is one dot.
		$merged = array();
		foreach ( $cities as $c ) {
			if ( isset( $merged[ $c['label'] ] ) ) {
				$merged[ $c['label'] ]['n'] += $c['n'];
			} else {
				$merged[ $c['label'] ] = $c;
			}
		}
		$cities = array_values( $merged );
		usort( $cities, function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		} );

		arsort( $countries );

		return array( 'countries' => $countries, 'cities' => $cities );
	}

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		$world = self::world();
		$ch    = isset( $_GET['ch'] ) ? sanitize_key( wp_unslash( $_GET['ch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ch    = isset( FW_VI_Channels::labels()[ $ch ] ) ? $ch : '';
		$data  = self::data( $days, $ch );

		if ( ! $world ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The map outline (static/data/world.json) is missing from the extension.', 'fw' ) . '</p></div>';
			return;
		}

		$names = $world['names'];
		$max   = max( 1, $data['countries'] ? max( $data['countries'] ) : 1 );
		$cmax  = max( 1, $data['cities'] ? $data['cities'][0]['n'] : 1 );
		$x     = function ( $lon ) use ( $world ) {
			return round( ( $lon + 180 ) / 360 * $world['w'], 1 );
		};
		$y     = function ( $lat ) use ( $world ) {
			return round( ( $world['n'] - max( $world['s'], min( $world['n'], $lat ) ) ) / 360 * $world['w'], 1 );
		};
		?>
		<form method="get" class="fw-vi-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( FW_VI_Admin_Page::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="map">
			<input type="hidden" name="days" value="<?php echo esc_attr( $days ); ?>">
			<label><?php esc_html_e( 'Visits from', 'fw' ); ?>
				<select name="ch" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All channels', 'fw' ); ?></option>
					<?php foreach ( FW_VI_Channels::labels() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ch, $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php if ( '' !== $ch ) : ?>
				<span class="fw-vi-muted"><?php printf( esc_html__( 'From the visit log, which keeps %d days.', 'fw' ), (int) FW_VI_Settings::get()['visits_days'] ); ?></span>
			<?php endif; ?>
		</form>

		<div class="fw-vi-card fw-vi-map">
			<svg viewBox="0 0 <?php echo (int) $world['w']; ?> <?php echo (int) $world['h']; ?>" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Map of page views by country: %d countries, %d cities.', 'fw' ), count( $data['countries'] ), count( $data['cities'] ) ) ); ?>">
				<?php foreach ( $world['c'] as $cc => $d ) : ?>
					<?php
					$n     = (int) ( $data['countries'][ $cc ] ?? 0 );
					$shade = $n ? 1 + (int) min( 4, floor( 5 * sqrt( $n / $max ) - 0.0001 ) ) : 0;
					?>
					<path class="fw-vi-map__c fw-vi-map__c--<?php echo (int) $shade; ?>" d="<?php echo esc_attr( $d ); ?>" data-tip="<?php echo esc_attr( ( $names[ $cc ] ?? $cc ) . ': ' . sprintf( _n( '%s page view', '%s page views', $n, 'fw' ), number_format_i18n( $n ) ) ); ?>"></path>
				<?php endforeach; ?>
				<?php foreach ( array_reverse( array_slice( $data['cities'], 0, 300 ) ) as $c ) : ?>
					<circle class="fw-vi-map__dot" cx="<?php echo esc_attr( $x( $c['lon'] ) ); ?>" cy="<?php echo esc_attr( $y( $c['lat'] ) ); ?>" r="<?php echo esc_attr( round( 2 + 8 * sqrt( $c['n'] / $cmax ), 1 ) ); ?>" data-tip="<?php echo esc_attr( $c['label'] . ': ' . sprintf( _n( '%s page view', '%s page views', $c['n'], 'fw' ), number_format_i18n( $c['n'] ) ) ); ?>"></circle>
				<?php endforeach; ?>
			</svg>
			<div class="fw-vi-map__tip" hidden></div>
			<p class="fw-vi-map__legend">
				<span><?php esc_html_e( 'Fewer', 'fw' ); ?></span>
				<?php for ( $i = 1; $i <= 5; $i++ ) : ?><i class="fw-vi-map__c--<?php echo (int) $i; ?>"></i><?php endfor; ?>
				<span><?php esc_html_e( 'More page views', 'fw' ); ?></span>
				<span class="fw-vi-muted"><?php esc_html_e( 'Dots are cities. Coordinates are kept to about 10 km.', 'fw' ); ?></span>
			</p>
		</div>

		<div class="fw-vi-grid fw-vi-grid--2">
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Countries', 'fw' ); ?></h2>
				<?php if ( ! $data['countries'] ) : ?>
					<p class="description"><?php esc_html_e( 'No countries recorded in this period.', 'fw' ); ?></p>
				<?php endif; ?>
				<?php foreach ( array_slice( $data['countries'], 0, 15, true ) as $cc => $n ) : ?>
					<div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:<?php echo esc_attr( round( 100 * $n / $max, 1 ) ); ?>%"></span><span class="fw-vi-bar__label"><?php echo esc_html( $names[ $cc ] ?? $cc ); ?></span><span class="fw-vi-bar__n"><?php echo esc_html( number_format_i18n( $n ) ); ?></span></div>
				<?php endforeach; ?>
			</div>
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Cities', 'fw' ); ?></h2>
				<?php if ( ! $data['cities'] ) : ?>
					<p class="description">
						<?php
						echo esc_html( FW_VI_Geo_Admin::file() || ! FW_VI_Settings::get()['track_city']
							? __( 'No cities recorded in this period.', 'fw' )
							: __( 'Cities need a city database or CDN location headers. Download the free database under Settings → City database.', 'fw' ) );
						?>
					</p>
				<?php endif; ?>
				<?php foreach ( array_slice( $data['cities'], 0, 15 ) as $c ) : ?>
					<div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:<?php echo esc_attr( round( 100 * $c['n'] / $cmax, 1 ) ); ?>%"></span><span class="fw-vi-bar__label"><?php echo esc_html( $c['label'] ); ?></span><span class="fw-vi-bar__n"><?php echo esc_html( number_format_i18n( $c['n'] ) ); ?></span></div>
				<?php endforeach; ?>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Map outline: Natural Earth (public domain).', 'fw' ); ?></p>
		<?php
	}
}
