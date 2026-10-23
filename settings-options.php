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
					'logged_in'      => array(
						'type'    => 'select',
						'label'   => __( 'Logged-in users', 'fw' ),
						'desc'    => __( 'Whose visits count while they are logged in. Editors are left out by default so your own visits while building the site do not count.', 'fw' ),
						'value'   => 'visitors',
						'choices' => array(
							'visitors' => __( 'Track them, except people who can edit content', 'fw' ),
							'none'     => __( 'Track nobody who is logged in', 'fw' ),
							'all'      => __( 'Track everyone, editors included', 'fw' ),
						),
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
					'track_forms'       => array(
						'type'  => 'switch',
						'label' => __( 'Form analytics', 'fw' ),
						'desc'  => __( 'Which forms visitors start, finish or give up on, and the field they were at when they left. What is typed is never recorded.', 'fw' ),
						'value' => true,
					),
					'track_search'      => array(
						'type'  => 'switch',
						'label' => __( 'Site search', 'fw' ),
						'desc'  => __( 'What visitors search for on your site, and which searches find nothing. Email addresses and long numbers are removed from search terms.', 'fw' ),
						'value' => true,
					),
					'track_moves'       => array(
						'type'  => 'switch',
						'label' => __( 'Mouse-movement maps', 'fw' ),
						'desc'  => __( 'Where the pointer lingers on desktop, shown as a heatmap mode. Positions are sampled and counted per area in the visitor\'s browser; no trail is sent. Off by default: it is the most detailed thing the statistics record, so switch it on only if your consent setup covers it.', 'fw' ),
						'value' => false,
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
	'box_recording' => array(
		'type'    => 'box',
		'title'   => __( 'Session recording', 'fw' ),
		'options' => array(
			'group_recording' => array(
				'type'    => 'group',
				'options' => array(
					'recording'         => array(
						'type'  => 'switch',
						'label' => __( 'Record visits', 'fw' ),
						'desc'  => __( 'Lets you replay individual visits: how the page moved, what was clicked and scrolled. Recording ALWAYS needs the visitor\'s opt-in consent — in the EU, the UK and elsewhere it is not covered by any exemption — so nothing is recorded until the visitor agrees. Every form field is masked in the visitor\'s browser before anything is sent, and recordings never leave this site.', 'fw' ),
						'value' => false,
					),
					'rec_consent'       => array(
						'type'    => 'select',
						'label'   => __( 'Consent comes from', 'fw' ),
						'desc'    => __( 'With a consent plugin that supports the WordPress Consent API, a visit is recorded only after the visitor accepts "statistics". Without one, choose the second option and call UnysonPlusVI.grantRecording() from your own banner when the visitor accepts.', 'fw' ),
						'value'   => 'api',
						'choices' => array(
							'api'    => __( 'A consent plugin (WordPress Consent API, "statistics")', 'fw' ),
							'manual' => __( 'My own banner, through UnysonPlusVI.grantRecording()', 'fw' ),
						),
					),
					'rec_sample'        => array(
						'type'    => 'select',
						'label'   => __( 'Record', 'fw' ),
						'value'   => '20',
						'choices' => array(
							'10'  => __( '1 in 10 consenting visits', 'fw' ),
							'20'  => __( '1 in 5 consenting visits', 'fw' ),
							'50'  => __( '1 in 2 consenting visits', 'fw' ),
							'100' => __( 'Every consenting visit', 'fw' ),
						),
					),
					'rec_only_problems' => array(
						'type'  => 'switch',
						'label' => __( 'Keep only visits with problems', 'fw' ),
						'desc'  => __( 'Recordings without a rage click, dead click or script error are deleted an hour after the visit ends. Keeps far less, which is kinder to visitors and to your disk.', 'fw' ),
						'value' => false,
					),
					'rec_mask_text'     => array(
						'type'  => 'switch',
						'label' => __( 'Hide all text', 'fw' ),
						'desc'  => __( 'Replace every text on the page with blocks in recordings, not only form fields. Use it on sites that show personal information (accounts, bookings). Add the class up-vi-mask to hide a single element, or up-vi-block to leave it out entirely.', 'fw' ),
						'value' => false,
					),
					'rec_retention'     => array(
						'type'    => 'select',
						'label'   => __( 'Keep recordings for', 'fw' ),
						'value'   => '30',
						'choices' => array(
							'7'  => __( '7 days', 'fw' ),
							'14' => __( '14 days', 'fw' ),
							'30' => __( '30 days', 'fw' ),
							'60' => __( '60 days', 'fw' ),
							'90' => __( '90 days', 'fw' ),
						),
					),
					'rec_exclude'       => array(
						'type'  => 'textarea',
						'label' => __( 'Never record', 'fw' ),
						'desc'  => __( 'Pages to leave out of recordings, one per line; * is a wildcard. Cart, checkout, account and login pages are never recorded.', 'fw' ),
						'value' => '',
					),
				),
			),
		),
	),
	'box_digest'   => array(
		'type'    => 'box',
		'title'   => __( 'Weekly email & alerts', 'fw' ),
		'options' => array(
			'group_digest' => array(
				'type'    => 'group',
				'options' => array(
					'digest'    => array(
						'type'  => 'switch',
						'label' => __( 'Send a weekly summary', 'fw' ),
						'desc'  => __( 'Every Monday morning: visits compared with the week before, top pages, goals, new problems (frustrated clicks, script errors, broken links) and performance.', 'fw' ),
						'value' => false,
					),
					'alerts'    => array(
						'type'  => 'switch',
						'label' => __( 'Alert me when something jumps', 'fw' ),
						'desc'  => __( 'An email the morning after script errors, broken links or traffic move far from the usual (compared with the week before). Nothing is sent on a normal day.', 'fw' ),
						'value' => false,
					),
					'digest_to' => array(
						'type'  => 'text',
						'label' => __( 'Send it to', 'fw' ),
						'desc'  => __( 'For the weekly email and alerts. One or more email addresses, separated by commas. Empty = the site admin email.', 'fw' ),
						'value' => '',
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
