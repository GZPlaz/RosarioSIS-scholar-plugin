<?php
/**
 * Scholar plugin configuration page
 * School > Configuration > Plugins > Scholar (Configuration)
 *
 * Three sections: Courses (create / link / sync / unlink), Settings, Log.
 *
 * @package Scholar plugin
 */

// Check the script is called by the right program & plugin is activated.
if ( $_REQUEST['modname'] !== 'School_Setup/Configuration.php'
	|| empty( $RosarioPlugins['Scholar'] )
	|| $_REQUEST['modfunc'] !== 'config' )
{
	$error[] = _( 'You\'re not allowed to use this program!' );

	echo ErrorMessage( $error, 'fatal' );
}

// Message lists may not exist yet on this page.
$error = isset( $error ) ? (array) $error : [];
$warning = isset( $warning ) ? (array) $warning : [];
$note = isset( $note ) ? (array) $note : [];

$scholar_base_url = 'Modules.php?modname=' . $_REQUEST['modname'] . '&tab=plugins&modfunc=config&plugin=Scholar';

$scholar_section = isset( $_REQUEST['section'] ) && in_array( $_REQUEST['section'], [ 'courses', 'settings', 'log' ] ) ?
	$_REQUEST['section'] : ( ScholarConfigured() ? 'courses' : 'settings' );

$scholar_created_accounts = [];

/**
 * Remember accounts created with a temporary password so they can be shown once.
 */
function _scholarCollectAccounts( $roster, &$accounts )
{
	foreach ( (array) ( isset( $roster['created'] ) ? $roster['created'] : [] ) as $account )
	{
		if ( ! empty( $account['temporary_password'] ) )
		{
			$accounts[] = $account;
		}
	}
}

// ---------------------------------------------------------------- actions

$scholar_action = isset( $_REQUEST['scholar_action'] ) ? $_REQUEST['scholar_action'] : '';

if ( $scholar_action && AllowEdit() )
{
	$cp_id = isset( $_REQUEST['cp'] ) ? (int) $_REQUEST['cp'] : 0;

	try
	{
		switch ( $scholar_action )
		{
			case 'save':

				// $_POST is used (not $_REQUEST) because RosarioSIS escapes $_REQUEST values.
				$posted = isset( $_POST['scholar'] ) && is_array( $_POST['scholar'] ) ? $_POST['scholar'] : [];

				if ( isset( $posted['URL'] ) )
				{
					$url = trim( $posted['URL'] );

					if ( $url !== '' && ! filter_var( $url, FILTER_VALIDATE_URL ) )
					{
						$error[] = 'The Scholar address must be a full URL, for example https://school.example/scholar';
					}
					else
					{
						ScholarSetting( 'URL', rtrim( $url, '/' ) );
					}
				}

				if ( ! empty( $posted['TOKEN'] ) )
				{
					ScholarSetting( 'TOKEN', trim( $posted['TOKEN'] ) );
				}

				if ( isset( $posted['ACCOUNT_TYPE'] ) )
				{
					ScholarSetting( 'ACCOUNT_TYPE', $posted['ACCOUNT_TYPE'] === 'local' ? 'local' : 'ldap' );
				}

				foreach ( [ 'AUTO_LINK', 'CARRY_OVER', 'DEACTIVATE_LEAVERS' ] as $flag )
				{
					if ( isset( $posted[ $flag ] ) )
					{
						ScholarSetting( $flag, $posted[ $flag ] === 'Y' ? 'Y' : 'N' );
					}
				}

				if ( isset( $posted['SYNC_MINUTES'] ) )
				{
					ScholarSetting( 'SYNC_MINUTES', (string) max( 1, min( 1440, (int) $posted['SYNC_MINUTES'] ) ) );
				}

				if ( isset( $posted['CATEGORY'] ) && trim( $posted['CATEGORY'] ) !== '' )
				{
					ScholarSetting( 'CATEGORY', mb_substr( trim( strip_tags( $posted['CATEGORY'] ) ), 0, 100 ) );
				}

				if ( isset( $posted['ID_PREFIX'] )
					&& ! DBGetOne( "SELECT 1 FROM scholar_links LIMIT 1" ) )
				{
					// Only changeable before the first link: it is part of every id sent to Scholar.
					ScholarSetting( 'ID_PREFIX', preg_replace( '/[^a-z0-9_-]/', '', strtolower( $posted['ID_PREFIX'] ) ) );
				}

				if ( empty( $error ) )
				{
					$note[] = button( 'check' ) . '&nbsp;' . _( 'The plugin configuration has been modified.' );
				}

				break;

			case 'test':

				$ping = ScholarApi()->get( 'ping' );

				$note[] = button( 'check' ) . '&nbsp;Connected to Scholar ' . htmlspecialchars( $ping['version'] ) .
					' at ' . htmlspecialchars( $ping['institution'] ) . '.';

				break;

			case 'new_cron_key':

				ScholarSetting( 'CRON_KEY', bin2hex( random_bytes( 20 ) ) );

				break;

			case 'create':

				_scholarCollectAccounts( ScholarLink( $cp_id ), $scholar_created_accounts );

				$note[] = button( 'check' ) . '&nbsp;Course created in Scholar, linked and set up.';

				break;

			case 'link':

				$scholar_course_id = isset( $_REQUEST['scholar_course_id'] ) ? (int) $_REQUEST['scholar_course_id'] : 0;

				if ( ! $scholar_course_id )
				{
					$error[] = 'Choose the Scholar course to link.';

					break;
				}

				_scholarCollectAccounts( ScholarLink( $cp_id, $scholar_course_id ), $scholar_created_accounts );

				$note[] = button( 'check' ) . '&nbsp;Course linked to Scholar and set up.';

				break;

			case 'create_all':

				$unlinked = DBGet( "SELECT cp.COURSE_PERIOD_ID
					FROM course_periods cp
					WHERE cp.SCHOOL_ID='" . UserSchool() . "'
					AND cp.SYEAR='" . UserSyear() . "'
					AND NOT EXISTS(SELECT 1 FROM scholar_links l WHERE l.COURSE_PERIOD_ID=cp.COURSE_PERIOD_ID)" );

				$count = 0;

				foreach ( (array) $unlinked as $row )
				{
					_scholarCollectAccounts( ScholarLink( $row['COURSE_PERIOD_ID'] ), $scholar_created_accounts );
					$count++;
				}

				$note[] = button( 'check' ) . '&nbsp;' . $count . ' course period(s) created in Scholar, linked and set up.';

				break;

			case 'sync':

				if ( $cp_id )
				{
					ScholarPushRoster( $cp_id );
					$stats = ScholarSyncGrades( $cp_id );
				}
				else
				{
					$stats = ScholarSyncAll();
				}

				if ( $stats === false )
				{
					$warning[] = 'A sync is already running. Try again in a minute.';
				}
				else
				{
					$note[] = button( 'check' ) . '&nbsp;Synced: ' .
						(int) $stats['assignments'] . ' assignment(s) created, ' . (int) $stats['grades'] . ' grade(s) written' .
						( ! empty( $stats['kept'] ) ? ', ' . (int) $stats['kept'] . ' grade(s) changed by hand were kept' : '' ) . '.';
				}

				break;

			case 'unlink':

				if ( DeletePrompt( 'link to Scholar', 'Unlink', false ) )
				{
					ScholarUnlink( $cp_id, ! empty( $_REQUEST['archive'] ) );

					$note[] = button( 'check' ) . '&nbsp;Unlinked. Grades already in the gradebook are kept.';
				}
				else
				{
					// DeletePrompt() is displaying the confirmation.
					return;
				}

				break;
		}
	}
	catch ( ScholarApiException $e )
	{
		$error[] = htmlspecialchars( $e->getMessage(), ENT_QUOTES );

		ScholarLog( 'error', 'Configuration page (' . $scholar_action . '): ' . $e->getMessage() );
	}

	RedirectURL( [ 'scholar_action', 'cp', 'scholar_course_id', 'scholar', 'delete_ok', 'archive' ] );
}

// ---------------------------------------------------------------- page

$scholar_tabs = [
	'courses' => 'Courses',
	'settings' => _( 'Settings' ),
	'log' => 'Log',
];

$scholar_links = [];

foreach ( $scholar_tabs as $key => $label )
{
	$scholar_links[] = $key === $scholar_section ?
		'<b>' . $label . '</b>' :
		'<a href="' . URLEscape( $scholar_base_url . '&section=' . $key ) . '">' . $label . '</a>';
}

DrawHeader( 'Scholar: ' . implode( ' | ', $scholar_links ) );

echo ErrorMessage( $error );
echo ErrorMessage( $warning, 'warning' );
echo ErrorMessage( $note, 'note' );

if ( $scholar_created_accounts )
{
	echo '<br />';

	ListOutput(
		$scholar_created_accounts,
		[ 'USERNAME' => _( 'Username' ), 'ROLE' => _( 'Profile' ), 'TEMPORARY_PASSWORD' => _( 'Password' ) ],
		'New Scholar account (give this password to its owner; it must be changed at first sign-in)',
		'New Scholar accounts (give each password to its owner; it must be changed at first sign-in)',
		[],
		[],
		[ 'search' => false, 'save' => true ]
	);
}

if ( $scholar_section === 'settings' )
{
	_scholarSettingsSection( $scholar_base_url );
}
elseif ( $scholar_section === 'log' )
{
	_scholarLogSection();
}
elseif ( ! ScholarConfigured() )
{
	echo ErrorMessage( [ 'Enter the Scholar address and an API token in the Settings first.' ], 'warning' );
}
else
{
	_scholarCoursesSection( $scholar_base_url );
}

function _scholarSettingsSection( $base_url )
{
	echo '<form action="' . URLEscape( $base_url . '&section=settings&scholar_action=save' ) . '" method="POST">';

	DrawHeader( '', SubmitButton() );

	PopTable( 'header', 'Scholar' );

	echo '<table class="width-100p"><tr><td>' . TextInput(
		ScholarSetting( 'URL' ),
		'scholar[URL]',
		'Scholar address',
		'size=40 placeholder="https://school.example/scholar"',
		false
	) . '</td></tr>';

	$token = ScholarSetting( 'TOKEN' );

	echo '<tr><td>' . TextInput(
		'',
		'scholar[TOKEN]',
		'API token' . ( $token ? ' (set: ' . htmlspecialchars( mb_substr( $token, 0, 10 ) ) . '…, leave empty to keep)' : '' ),
		'size=40 autocomplete=off placeholder="sch_…"',
		false
	) . '<br /><span class="legend-gray">Create it in Scholar: Administration, Integrations.</span></td></tr>';

	echo '<tr><td>' . SelectInput(
		ScholarSetting( 'ACCOUNT_TYPE' ),
		'scholar[ACCOUNT_TYPE]',
		'Accounts created in Scholar',
		[
			'ldap' => 'YunoHost accounts (same username and password as YunoHost)',
			'local' => 'Local Scholar accounts (temporary password shown once)',
		],
		false,
		'',
		false
	) . '</td></tr>';

	echo '<tr><td>' . CheckboxInput(
		ScholarSetting( 'AUTO_LINK' ) === 'Y' ? 'Y' : '',
		'scholar[AUTO_LINK]',
		'Create and link every new course period in Scholar automatically',
		'',
		true
	) . '</td></tr>';

	echo '<tr><td>' . CheckboxInput(
		ScholarSetting( 'CARRY_OVER' ) === 'Y' ? 'Y' : '',
		'scholar[CARRY_OVER]',
		'At a new default school year, link the rolled-over course periods to new Scholar courses',
		'',
		true
	) . '</td></tr>';

	echo '<tr><td>' . CheckboxInput(
		ScholarSetting( 'DEACTIVATE_LEAVERS' ) === 'Y' ? 'Y' : '',
		'scholar[DEACTIVATE_LEAVERS]',
		'At a new default school year, deactivate the Scholar accounts of students who are no longer enrolled',
		'',
		true
	) . '</td></tr>';

	echo '<tr><td>' . TextInput(
		ScholarSetting( 'SYNC_MINUTES' ),
		'scholar[SYNC_MINUTES]',
		'Sync every (minutes)',
		'type=number min=1 max=1440 size=4',
		false
	) . '</td></tr>';

	echo '<tr><td>' . TextInput(
		ScholarSetting( 'CATEGORY' ),
		'scholar[CATEGORY]',
		'Gradebook category for Scholar tests',
		'size=30 maxlength=100',
		false
	) . '</td></tr>';

	$prefix_locked = (bool) DBGetOne( "SELECT 1 FROM scholar_links LIMIT 1" );

	echo '<tr><td>' . ( $prefix_locked ?
		NoInput( ScholarIdPrefix(), 'ID prefix in Scholar' ) :
		TextInput( ScholarIdPrefix(), 'scholar[ID_PREFIX]', 'ID prefix in Scholar', 'size=12 maxlength=20', false ) ) .
		'<br /><span class="legend-gray">Students appear in Scholar as ' . ScholarIdPrefix() . ':student:ID. Change it only if two RosarioSIS sites share one Scholar.</span></td></tr>';

	echo '</table>';

	PopTable( 'footer' );

	echo '<br /><div class="center">' . SubmitButton();

	if ( ScholarConfigured() )
	{
		echo ' <input type="button" value="' . AttrEscape( 'Test the connection' ) .
			'" class="onclick-ajax-link" data-link="' . URLEscape( $base_url . '&section=settings&scholar_action=test' ) . '" />';
	}

	echo '</div></form>';

	// Cron.
	echo '<br />';

	PopTable( 'header', 'Automatic sync' );

	$minutes = (int) ScholarSetting( 'SYNC_MINUTES' );

	echo '<p>Grades and rosters are synced in the background at most every ' . $minutes .
		' minute(s), right after someone loads a RosarioSIS page, so nobody waits for it.</p>';

	echo '<p>To sync even when nobody uses RosarioSIS (nights, holidays), add a cron job on the server, from the RosarioSIS folder:</p>';

	echo '<pre>*/' . max( 5, $minutes ) . ' * * * * php ' . htmlspecialchars( realpath( 'plugins/Scholar/cron.php' ) ) . '</pre>';

	$key = ScholarSetting( 'CRON_KEY' );

	if ( $key )
	{
		$cron_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://' ) .
			$_SERVER['HTTP_HOST'] . dirname( $_SERVER['SCRIPT_NAME'] ) . '/plugins/Scholar/cron.php?key=' . $key;

		echo '<p>Or call this address from any scheduler: <code>' . htmlspecialchars( rtrim( $cron_url, '/' ) ) . '</code></p>';
	}

	echo '<p><a href="' . URLEscape( $base_url . '&section=settings&scholar_action=new_cron_key' ) . '">' .
		( $key ? 'Generate a new web cron key' : 'Enable the web cron address' ) . '</a></p>';

	PopTable( 'footer' );
}

function _scholarCoursesSection( $base_url )
{
	$scholar_courses = [];

	try
	{
		$list = ScholarApi()->get( 'courses' );

		foreach ( (array) $list['courses'] as $course )
		{
			if ( empty( $course['external_id'] ) )
			{
				$scholar_courses[ $course['id'] ] = $course['code'] . ' — ' . $course['title'] .
					( $course['term'] ? ' (' . $course['term'] . ')' : '' );
			}
		}
	}
	catch ( ScholarApiException $e )
	{
		echo ErrorMessage( [ htmlspecialchars( $e->getMessage(), ENT_QUOTES ) ] );
	}

	$course_periods = DBGet( "SELECT cp.COURSE_PERIOD_ID,cp.TITLE,c.TITLE AS COURSE_TITLE,
		(SELECT COUNT(DISTINCT sch.STUDENT_ID) FROM schedule sch
			WHERE sch.COURSE_PERIOD_ID=cp.COURSE_PERIOD_ID
			AND (sch.END_DATE IS NULL OR sch.END_DATE>=CURRENT_DATE)) AS STUDENTS,
		l.SCHOLAR_COURSE_ID,l.STATUS,l.GRADES_SYNCED_AT,l.LAST_ERROR
		FROM course_periods cp
		JOIN courses c ON c.COURSE_ID=cp.COURSE_ID
		LEFT JOIN scholar_links l ON l.COURSE_PERIOD_ID=cp.COURSE_PERIOD_ID
		WHERE cp.SCHOOL_ID='" . UserSchool() . "'
		AND cp.SYEAR='" . UserSyear() . "'
		ORDER BY c.TITLE,cp.SHORT_NAME" );

	$unlinked = 0;

	foreach ( $course_periods as $i => $cp )
	{
		$url = $base_url . '&section=courses&cp=' . $cp['COURSE_PERIOD_ID'];

		if ( $cp['SCHOLAR_COURSE_ID'] )
		{
			$course_periods[ $i ]['SCHOLAR'] = 'Course #' . (int) $cp['SCHOLAR_COURSE_ID'] .
				( $cp['STATUS'] !== 'active' ? ' (' . htmlspecialchars( $cp['STATUS'] ) . ')' : '' );

			$course_periods[ $i ]['SYNCED'] = $cp['GRADES_SYNCED_AT'] ?
				ProperDateTime( $cp['GRADES_SYNCED_AT'], 'short' ) : '–';

			if ( $cp['LAST_ERROR'] )
			{
				$course_periods[ $i ]['SYNCED'] .= '<br /><span style="color:#a8231a">' . htmlspecialchars( $cp['LAST_ERROR'] ) . '</span>';
			}

			$course_periods[ $i ]['ACTIONS'] = '<a href="' . URLEscape( $url . '&scholar_action=sync' ) . '">Sync now</a> | ' .
				'<a href="' . URLEscape( $url . '&scholar_action=unlink' ) . '">Unlink</a> | ' .
				'<a href="' . URLEscape( $url . '&scholar_action=unlink&archive=1' ) . '">Unlink and archive in Scholar</a>';

			continue;
		}

		$unlinked++;

		$course_periods[ $i ]['SCHOLAR'] = '<span class="legend-gray">Not linked</span>';
		$course_periods[ $i ]['SYNCED'] = '';

		$select = '';

		if ( $scholar_courses )
		{
			$options = '<option value="">Link to an existing Scholar course…</option>';

			foreach ( $scholar_courses as $id => $label )
			{
				$options .= '<option value="' . (int) $id . '">' . htmlspecialchars( $label ) . '</option>';
			}

			$select = '<form action="' . URLEscape( $url . '&scholar_action=link' ) . '" method="POST" style="display:inline">' .
				'<select name="scholar_course_id" aria-label="Scholar course">' . $options . '</select> ' .
				SubmitButton( 'Link', '', '' ) . '</form>';
		}

		$course_periods[ $i ]['ACTIONS'] = '<a href="' . URLEscape( $url . '&scholar_action=create' ) . '">Create in Scholar</a>' .
			( $select ? ' | ' . $select : '' );
	}

	DrawHeader(
		'Course periods of ' . FormatSyear( UserSyear(), Config( 'SCHOOL_SYEAR_OVER_2_YEARS' ) ),
		( $unlinked ? '<a href="' . URLEscape( $base_url . '&section=courses&scholar_action=create_all' ) . '">' .
			'Create and link all ' . $unlinked . ' unlinked course periods</a> | ' : '' ) .
		'<a href="' . URLEscape( $base_url . '&section=courses&scholar_action=sync' ) . '">Sync everything now</a>'
	);

	ListOutput(
		$course_periods,
		[
			'TITLE' => _( 'Course Period' ),
			'STUDENTS' => _( 'Students' ),
			'SCHOLAR' => 'Scholar',
			'SYNCED' => 'Last grade sync',
			'ACTIONS' => '',
		],
		_( 'Course Period' ),
		_( 'Course Periods' ),
		[],
		[],
		[ 'save' => false ]
	);

	echo '<p class="legend-gray">Linking a course period sets it up automatically: its teacher and students are added to the Scholar course, ' .
		'a “' . htmlspecialchars( ScholarSetting( 'CATEGORY' ) ) . '” category is added to the teacher’s gradebook, and every Scholar test ' .
		'with results becomes a gradebook assignment that fills itself in.</p>';
}

function _scholarLogSection()
{
	$log = DBGet( "SELECT CREATED_AT,LEVEL,MESSAGE FROM scholar_log ORDER BY ID DESC LIMIT 300", [
		'CREATED_AT' => '_scholarLogDate',
		'LEVEL' => '_scholarLogLevel',
		'MESSAGE' => '_scholarLogMessage',
	] );

	ListOutput( $log, [ 'CREATED_AT' => _( 'Date' ), 'LEVEL' => '', 'MESSAGE' => '' ], 'Entry', 'Entries', [], [], [ 'save' => false, 'sort' => false ] );
}

function _scholarLogDate( $value )
{
	return ProperDateTime( $value, 'short' );
}

function _scholarLogLevel( $value )
{
	$colors = [ 'error' => '#a8231a', 'warning' => '#8a6500', 'info' => '#3b6620' ];

	return '<span style="color:' . ( isset( $colors[ $value ] ) ? $colors[ $value ] : 'inherit' ) . '">' . htmlspecialchars( $value ) . '</span>';
}

function _scholarLogMessage( $value )
{
	return htmlspecialchars( $value );
}
