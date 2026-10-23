<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Form analytics: for every form on the site, how many visitors start it,
 * finish it or give up — and the field they were at when they gave up.
 *
 * Collected by static/js/modules/forms.js. Nothing anyone types is recorded:
 * only that a field was focused (by its name), the order of fields, a submit,
 * and — when the visitor leaves the page with a started, unsent form — the
 * last field they focused. Search forms are left to site-search insights.
 *
 * A form is identified by the builder item it belongs to (stable across
 * edits), else by its id or name attribute, else by its position on the page.
 */
class FW_VI_Form_Analytics {

	/**
	 * @param int $days
	 *
	 * @return array forms with totals and fields, most-started first
	 */
	public static function report( $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$f = FW_VI_Installer::table( 'forms' );
		$p = FW_VI_Installer::table( 'pages' );

		$forms = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.page_id, p.path, p.post_id, f.form, MAX(f.label) label, SUM(f.starts) starts, SUM(f.submits) submits, SUM(f.abandons) abandons
			FROM {$f} f JOIN {$p} p ON p.id = f.page_id WHERE f.day BETWEEN %s AND %s
			GROUP BY f.page_id, f.form ORDER BY starts DESC LIMIT 30",
			$from, $to
		) ); // phpcs:ignore WordPress.DB

		foreach ( $forms as $form ) {
			$form->views  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) FROM ' . FW_VI_Installer::table( 'views' ) . ' WHERE page_id = %d AND day BETWEEN %s AND %s', $form->page_id, $from, $to ) ); // phpcs:ignore WordPress.DB
			$form->fields = $wpdb->get_results( $wpdb->prepare(
				'SELECT field, MAX(pos) pos, SUM(touched) touched, SUM(lastn) lastn FROM ' . FW_VI_Installer::table( 'fields' ) . '
				WHERE page_id = %d AND form = %s AND day BETWEEN %s AND %s GROUP BY field ORDER BY pos, field',
				$form->page_id, $form->form, $from, $to
			) ); // phpcs:ignore WordPress.DB
		}

		return $forms;
	}

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		$forms = self::report( $days );

		if ( ! $forms ) {
			echo '<div class="fw-vi-empty"><h2>' . esc_html__( 'No form activity yet', 'fw' ) . '</h2><p>' . esc_html__( 'As soon as a visitor starts filling in a form on a tracked page, it appears here: how many start it, how many send it, and the field where the others give up.', 'fw' ) . '</p></div>';
			return;
		}

		foreach ( $forms as $f ) :
			$starts  = (int) $f->starts;
			$submits = (int) $f->submits;
			$max     = 0;
			$worst   = '';
			foreach ( $f->fields as $fd ) {
				if ( (int) $fd->lastn > $max ) {
					$max   = (int) $fd->lastn;
					$worst = $fd->field;
				}
			}
			$title = '' !== $f->label ? $f->label : __( 'Form', 'fw' );
			?>
			<div class="fw-vi-card fw-vi-formcard">
				<h2><?php echo esc_html( $title ); ?> <small><code><?php echo esc_html( $f->path ); ?></code></small></h2>
				<div class="fw-vi-funnel-mini">
					<div><strong><?php echo esc_html( number_format_i18n( $f->views ) ); ?></strong><span><?php esc_html_e( 'page views', 'fw' ); ?></span></div>
					<div><strong><?php echo esc_html( number_format_i18n( $starts ) ); ?></strong><span><?php echo esc_html( sprintf( __( 'started (%s%% of views)', 'fw' ), $f->views ? number_format_i18n( 100 * $starts / $f->views, 1 ) : '—' ) ); ?></span></div>
					<div><strong><?php echo esc_html( number_format_i18n( $submits ) ); ?></strong><span><?php echo esc_html( sprintf( __( 'sent (%s%% of starts)', 'fw' ), $starts ? number_format_i18n( 100 * min( $submits, $starts ) / $starts, 0 ) : '—' ) ); ?></span></div>
					<div><strong><?php echo esc_html( number_format_i18n( (int) $f->abandons ) ); ?></strong><span><?php esc_html_e( 'left unsent', 'fw' ); ?></span></div>
				</div>
				<?php if ( $worst ) : ?>
					<p class="fw-vi-callout"><?php echo wp_kses( sprintf( __( 'Most visitors who gave up were at <code>%s</code>.', 'fw' ), esc_html( $worst ) ), array( 'code' => array() ) ); ?></p>
				<?php endif; ?>
				<?php if ( $f->fields ) : ?>
					<table class="widefat striped fw-vi-table">
						<thead><tr><th><?php esc_html_e( 'Field', 'fw' ); ?></th><th><?php esc_html_e( 'Reached by', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Gave up here', 'fw' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $f->fields as $fd ) : ?>
							<?php $pct = $starts ? min( 100, round( 100 * $fd->touched / $starts ) ) : 0; ?>
							<tr class="<?php echo $fd->field === $worst ? 'fw-vi-hot' : ''; ?>">
								<td><code><?php echo esc_html( $fd->field ); ?></code></td>
								<td><div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:<?php echo esc_attr( $pct ); ?>%"></span><span class="fw-vi-bar__label"><?php echo esc_html( $pct . '%' ); ?></span></div></td>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $fd->lastn ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		endforeach;
	}
}
