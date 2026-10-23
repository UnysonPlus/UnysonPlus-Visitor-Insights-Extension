<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Annotations: short dated notes — "new hero", "spring sale", "moved to new
 * host" — drawn as markers on the Overview chart, so a change in the numbers
 * can be read against what was done on the site. Kept in one option (at most
 * 300 notes, oldest dropped first).
 */
class FW_VI_Annotations {

	const OPT = 'fw_ext_vi_annotations';
	const MAX = 300;

	/**
	 * @return array [ {id, date, note, user} ], newest first
	 */
	public static function all() {
		$all = (array) get_option( self::OPT, array() );

		usort( $all, function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		} );

		return $all;
	}

	/**
	 * @param int $days
	 *
	 * @return array date => [ note, … ] within the report range
	 */
	public static function in_range( $days ) {
		list( $from, $to ) = FW_VI_Reports::range( $days );
		$out               = array();

		foreach ( self::all() as $a ) {
			if ( $a['date'] >= $from && $a['date'] <= $to ) {
				$out[ $a['date'] ][] = $a['note'];
			}
		}

		return $out;
	}

	/**
	 * @param string $action
	 *
	 * @return string|null
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by FW_VI_Admin_Page
		$all = (array) get_option( self::OPT, array() );

		if ( 'note_add' === $action ) {
			$date = isset( $_POST['note_date'] ) ? sanitize_text_field( wp_unslash( $_POST['note_date'] ) ) : '';
			$note = isset( $_POST['note_text'] ) ? sanitize_text_field( wp_unslash( $_POST['note_text'] ) ) : '';

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || '' === $note ) {
				return __( 'A note needs a date and some text.', 'fw' );
			}

			$all[] = array(
				'id'   => strtolower( wp_generate_password( 8, false, false ) ),
				'date' => $date,
				'note' => mb_substr( $note, 0, 140 ),
				'user' => get_current_user_id(),
			);

			usort( $all, function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			} );

			update_option( self::OPT, array_slice( $all, 0, self::MAX ), false );

			return __( 'Note added to the chart.', 'fw' );
		}

		if ( 'note_delete' === $action ) {
			$id  = isset( $_POST['note'] ) ? sanitize_key( wp_unslash( $_POST['note'] ) ) : '';
			$all = array_values( array_filter( $all, function ( $a ) use ( $id ) {
				return $a['id'] !== $id;
			} ) );
			update_option( self::OPT, $all, false );

			return __( 'Note removed.', 'fw' );
		}
		// phpcs:enable

		return null;
	}

	/**
	 * The notes of the period, and a one-line form to add one.
	 *
	 * @param int      $days
	 * @param callable $form_open
	 */
	public static function render_form( $days, $form_open ) {
		list( $from, $to ) = FW_VI_Reports::range( $days );
		$notes             = array_filter( self::all(), function ( $a ) use ( $from, $to ) {
			return $a['date'] >= $from && $a['date'] <= $to;
		} );
		?>
		<details class="fw-vi-card fw-vi-notes" <?php echo $notes ? 'open' : ''; ?>>
			<summary><strong><?php esc_html_e( 'Notes on the chart', 'fw' ); ?></strong> <span class="description"><?php esc_html_e( '— mark what you changed on the site, to see its effect', 'fw' ); ?></span></summary>
			<?php if ( $notes ) : ?>
				<ul>
					<?php foreach ( $notes as $a ) : ?>
						<li>
							<span class="fw-vi-note-dot"></span>
							<strong><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $a['date'] . ' 12:00:00' ) ) ); ?></strong> — <?php echo esc_html( $a['note'] ); ?>
							<form method="post" style="display:inline"><?php call_user_func( $form_open, 'note_delete' ); ?><input type="hidden" name="note" value="<?php echo esc_attr( $a['id'] ); ?>"><button class="button-link button-link-delete"><?php esc_html_e( 'Remove', 'fw' ); ?></button></form>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form method="post" class="fw-vi-form">
				<?php call_user_func( $form_open, 'note_add' ); ?>
				<label><?php esc_html_e( 'Date', 'fw' ); ?> <input type="date" name="note_date" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></label>
				<label><?php esc_html_e( 'Note', 'fw' ); ?> <input type="text" name="note_text" class="regular-text" maxlength="140" required placeholder="<?php esc_attr_e( 'e.g. New hero section, spring sale started', 'fw' ); ?>"></label>
				<button class="button"><?php esc_html_e( 'Add note', 'fw' ); ?></button>
			</form>
		</details>
		<?php
	}
}
