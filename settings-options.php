<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Visitor Insights settings. Rendered on the extension's own Settings tab
 * (Unyson+ → Visitor Insights → Settings) and by the Extensions manager, so it
 * stays a plain options array with no side effects. Defaults here must match
 * FW_VI_Settings::defaults().
 */

$options = array(
	'box_tracking' => array(
		'type'    => 'box',
		'title'   => __( 'Tracking', 'fw' ),
		'options' => array(
			'group_tracking' => array(
				'type'    => 'group',
				'options' => array(
					'enabled'        => array(
						'type'  => 'switch',
						'label' => __( 'Collect visitor data', 'fw' ),
						'desc'  => __( 'Off pauses collection without deleting anything.', 'fw' ),
						'value' => true,
					),
					'sample_rate'    => array(
						'type'    => 'select',
						'label'   => __( 'Sample', 'fw' ),
						'desc'    => __( 'Share of page views that are tracked. Lower it on very busy sites to save database writes; reports then show the sampled numbers.', 'fw' ),
						'value'   => '100',
						'choices' => array(
							'100' => __( 'Every page view', 'fw' ),
							'50'  => __( '1 in 2', 'fw' ),
							'25'  => __( '1 in 4', 'fw' ),
							'10'  => __( '1 in 10', 'fw' ),
						),
					),
					'track_editors'  => array(
						'type'  => 'switch',
						'label' => __( 'Track logged-in editors', 'fw' ),
						'desc'  => __( 'Off by default, so your own visits while building the site do not count.', 'fw' ),
						'value' => false,
					),
					'excluded_paths' => array(
						'type'  => 'textarea',
						'label' => __( 'Pages not to track', 'fw' ),
						'desc'  => __( 'One path per line; * is a wildcard (e.g. /checkout/*). Cart, checkout and account pages are never tracked.', 'fw' ),
						'value' => '',
					),
				),
			),
		),
	),
	'box_modules'  => array(
		'type'    => 'box',
		'title'   => __( 'Modules', 'fw' ),
		'options' => array(
			'group_modules' => array(
				'type'    => 'group',
				'options' => array(
					'track_frustration' => array(
						'type'  => 'switch',
						'label' => __( 'Frustration clicks', 'fw' ),
						'desc'  => __( 'Rage clicks (rapid repeated clicks in one spot), dead clicks (nothing on the page responds) and clicks followed by a script error.', 'fw' ),
						'value' => true,
					),
					'track_errors'      => array(
						'type'  => 'switch',
						'label' => __( 'Script errors', 'fw' ),
						'desc'  => __( 'JavaScript errors visitors run into, grouped by message. Numbers and email addresses are masked before anything is sent.', 'fw' ),
						'value' => true,
					),
					'track_404'         => array(
						'type'  => 'switch',
						'label' => __( 'Missing pages (404)', 'fw' ),
						'desc'  => __( 'Addresses that returned "not found", and where the visitor came from.', 'fw' ),
						'value' => true,
					),
					'track_vitals'      => array(
						'type'  => 'switch',
						'label' => __( 'Real-visitor performance', 'fw' ),
						'desc'  => __( 'Core Web Vitals (loading, responsiveness, layout stability) measured in your visitors\' browsers.', 'fw' ),
						'value' => true,
					),
				),
			),
		),
	),
	'box_privacy'  => array(
		'type'    => 'box',
		'title'   => __( 'Privacy and consent', 'fw' ),
		'options' => array(
			'group_privacy' => array(
				'type'    => 'group',
				'options' => array(
					'consent_mode' => array(
						'type'    => 'select',
						'label'   => __( 'Consent', 'fw' ),
						'desc'    => __( 'Visitor Insights stores no cookies and no IP addresses, and keeps only anonymous totals. With a consent plugin that supports the WordPress Consent API, tracking always waits for "anonymous statistics" consent. Whether your site may track without a banner depends on where your visitors are — choose "Wait for consent" if unsure.', 'fw' ),
						'value'   => 'auto',
						'choices' => array(
							'auto'    => __( 'Follow the consent plugin; with none, track anonymously (visitors can opt out)', 'fw' ),
							'require' => __( 'Wait for consent everywhere', 'fw' ),
						),
					),
					'honor_gpc'    => array(
						'type'  => 'switch',
						'label' => __( 'Honour Global Privacy Control', 'fw' ),
						'desc'  => __( 'Do not track browsers that send the Global Privacy Control signal.', 'fw' ),
						'value' => true,
					),
				),
			),
		),
	),
	'box_data'     => array(
		'type'    => 'box',
		'title'   => __( 'Data', 'fw' ),
		'options' => array(
			'group_data' => array(
				'type'    => 'group',
				'options' => array(
					'retention_days' => array(
						'type'    => 'select',
						'label'   => __( 'Keep reports for', 'fw' ),
						'desc'    => __( 'Older days are deleted nightly.', 'fw' ),
						'value'   => '395',
						'choices' => array(
							'90'  => __( '90 days', 'fw' ),
							'180' => __( '6 months', 'fw' ),
							'395' => __( '13 months', 'fw' ),
							'730' => __( '2 years', 'fw' ),
						),
					),
					'daily_cap'      => array(
						'type'  => 'short-text',
						'label' => __( 'Daily limit per page', 'fw' ),
						'desc'  => __( 'Page views counted per page per day; anything above is ignored. Keeps a flood of fake traffic from filling the database. 0 = no limit.', 'fw' ),
						'value' => '5000',
					),
				),
			),
		),
	),
	'box_network'  => array(
		'type'    => 'box',
		'title'   => __( 'Exclusions and proxies', 'fw' ),
		'options' => array(
			'group_network' => array(
				'type'    => 'group',
				'options' => array(
					'excluded_ips'    => array(
						'type'  => 'textarea',
						'label' => __( 'Excluded IPs', 'fw' ),
						'desc'  => __( 'Visits from these are never counted. One per line: an address (203.0.113.7) or a range (203.0.113.0/24, 2001:db8::/32).', 'fw' ),
						'value' => '',
					),
					'trusted_proxies' => array(
						'type'  => 'textarea',
						'label' => __( 'Trusted proxies', 'fw' ),
						'desc'  => __( 'Only if your site sits behind a CDN or load balancer: list its addresses or ranges so the visitor address is read from the headers it adds. Leave empty otherwise.', 'fw' ),
						'value' => '',
					),
				),
			),
		),
	),
);
