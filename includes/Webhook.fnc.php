<?php
/**
 * Instant updates from Scholar.
 *
 * Scholar writes down every change that can alter a grade (a test submitted, an essay graded,
 * an answer key corrected, a score overridden, an answer sheet scanned, an attempt deleted…) and
 * tells this plugin right away with a signed request to webhook.php. The plugin then copies that
 * course's grades into the gradebook, within seconds, with nobody having to open a page.
 *
 * Two safety nets mean a missed request only delays things:
 * - ScholarPollChanges() asks Scholar for the changes list (cheap: one small request) at most
 *   once a minute, from page loads and cron;
 * - the full sync still runs every SYNC_MINUTES.
 *
 * @package Scholar plugin
 */

/**
 * Take a named lock; waits up to $wait seconds.
 *
 * @return resource|false Lock handle for ScholarUnlock(), false when it stayed busy.
 */
function ScholarLock( $name, $wait = 0 )
{
	$handle = @fopen( sys_get_temp_dir() . '/rosariosis-scholar-' . md5( __FILE__ ) . '-' . $name . '.lock', 'c' );

	if ( ! $handle )
	{
		return false;
	}

	$deadline = time() + (int) $wait;

	while ( ! flock( $handle, LOCK_EX | LOCK_NB ) )
	{
		if ( time() >= $deadline )
		{
			fclose( $handle );

			return false;
		}

		usleep( 200000 );
	}

	return $handle;
}

function ScholarUnlock( $handle )
{
	if ( $handle )
	{
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
}

/**
 * The address Scholar should call: this site's plugins/<folder>/webhook.php, or the address
 * typed under "Address Scholar uses to reach RosarioSIS" (different from the public one when
 * RosarioSIS is behind a login portal, or Scholar reaches it over a private network).
 */
function ScholarWebhookAddress()
{
	$custom = trim( ScholarSetting( 'CALLBACK_URL' ) );

	if ( $custom !== '' )
	{
		return $custom;
	}

	if ( empty( $_SERVER['HTTP_HOST'] ) || PHP_SAPI === 'cli' )
	{
		return ScholarSetting( 'WEBHOOK_URL' ); // the one found the last time somebody used the web page
	}

	global $RosarioURL;

	if ( ! empty( $RosarioURL ) )
	{
		$base = rtrim( $RosarioURL, '/' );
	}
	else
	{
		$https = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' )
			|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' );

		$dir = rtrim( str_replace( '\\', '/', dirname( $_SERVER['SCRIPT_NAME'] ) ), '/' );

		$base = ( $https ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $dir;
	}

	return $base . '/plugins/' . rawurlencode( basename( dirname( __DIR__ ) ) ) . '/webhook.php';
}

/**
 * Tell Scholar where to send changes. Scholar answers with the secret that signs them.
 *
 * @throws ScholarApiException
 *
 * @return string The address registered.
 */
function ScholarRegisterWebhook()
{
	$url = ScholarWebhookAddress();

	if ( $url === '' )
	{
		throw new ScholarApiException( 'The address of this RosarioSIS is not known yet: open the plugin Settings in a browser.' );
	}

	$answer = ScholarApi()->put( 'webhook', [ 'url' => $url ] );

	if ( empty( $answer['secret'] ) )
	{
		throw new ScholarApiException( 'Scholar did not return a signing secret. Is Scholar up to date (1.8.0 or later)?' );
	}

	ScholarSetting( 'WEBHOOK_SECRET', $answer['secret'] );
	ScholarSetting( 'WEBHOOK_URL', $url );
	ScholarSetting( 'WEBHOOK_REGISTERED', (string) time() );

	// Changes made from now on come through the webhook or the list; older ones through the full sync.
	if ( ScholarSetting( 'LAST_EVENT' ) === '' )
	{
		$feed = ScholarApi()->get( 'changes?limit=1' );

		ScholarSetting( 'LAST_EVENT', (string) (int) $feed['head'] );
	}

	return $url;
}

/**
 * Connect instant updates by itself when a plugin that was set up before they existed is upgraded:
 * the first page load afterwards registers this site's address with Scholar (at most once an hour).
 */
function ScholarAutoConnect()
{
	if ( PHP_SAPI === 'cli'
		|| ! ScholarConfigured()
		|| ScholarSetting( 'WEBHOOK_SECRET' ) !== ''
		|| time() - (int) ScholarSetting( 'WEBHOOK_TRIED' ) < 3600 )
	{
		return;
	}

	ScholarSetting( 'WEBHOOK_TRIED', (string) time() );

	$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';

	if ( ScholarSetting( 'CALLBACK_URL' ) === '' && ( $host === '' || preg_match( '/^(localhost|127\\.|\\[::1\\])/i', $host ) ) )
	{
		return; // an address only this computer can use is no use to Scholar
	}

	try
	{
		ScholarLog( 'info', 'Instant updates connected: Scholar will call ' . ScholarRegisterWebhook() . '.' );
	}
	catch ( ScholarApiException $e )
	{
		ScholarLog( 'warning', 'Instant updates could not be connected yet (' . $e->getMessage() . '). Grades still arrive every few minutes.' );
	}
}

/**
 * Ask Scholar to send a signed test request to this site and report if it got through.
 *
 * @throws ScholarApiException
 *
 * @return array [ ok, message ]
 */
function ScholarTestWebhook()
{
	$answer = ScholarApi()->post( 'webhook/test', new stdClass() );

	return [ ! empty( $answer['ok'] ), isset( $answer['message'] ) ? (string) $answer['message'] : '' ];
}

/**
 * Check a request from Scholar: HMAC-SHA256 of "<timestamp>.<body>" with the shared secret, and a
 * timestamp within five minutes (so a recorded request can't be replayed later).
 *
 * @return string '' when valid, otherwise the reason it is refused.
 */
function ScholarVerifyRequest( $body )
{
	$secret = ScholarSetting( 'WEBHOOK_SECRET' );

	if ( $secret === '' )
	{
		return 'not connected';
	}

	$stamp = isset( $_SERVER['HTTP_X_SCHOLAR_TIMESTAMP'] ) ? (string) $_SERVER['HTTP_X_SCHOLAR_TIMESTAMP'] : '';
	$signature = isset( $_SERVER['HTTP_X_SCHOLAR_SIGNATURE'] ) ? (string) $_SERVER['HTTP_X_SCHOLAR_SIGNATURE'] : '';

	if ( ! ctype_digit( $stamp ) || abs( time() - (int) $stamp ) > 300 )
	{
		return 'timestamp missing or too far from this server\'s clock';
	}

	$expected = 'sha256=' . hash_hmac( 'sha256', $stamp . '.' . $body, $secret );

	if ( ! hash_equals( $expected, $signature ) )
	{
		return 'signature does not match';
	}

	return '';
}

/**
 * Courses already brought up to date, per external id: the newest Scholar change id included.
 * Lets polling skip what the webhook has just handled.
 */
function ScholarHandled( $external_id = null, $event_id = null )
{
	$handled = json_decode( ScholarSetting( 'HANDLED' ), true );
	$handled = is_array( $handled ) ? $handled : [];

	if ( $external_id === null )
	{
		return $handled;
	}

	$handled[ $external_id ] = max( (int) $event_id, isset( $handled[ $external_id ] ) ? (int) $handled[ $external_id ] : 0 );

	if ( count( $handled ) > 500 )
	{
		$handled = array_slice( $handled, -250, null, true );
	}

	ScholarSetting( 'HANDLED', json_encode( $handled ) );

	return $handled;
}

/**
 * Copy the grades of one linked course period, waiting for any other sync of it to finish.
 *
 * @throws ScholarApiException
 *
 * @return array|false Sync statistics, false when the course is not linked here (or stayed busy).
 */
function ScholarSyncCourseGrades( $course_period_id, $wait = 30 )
{
	$link = ScholarLinkOf( $course_period_id );

	if ( ! $link || $link['STATUS'] !== 'active' )
	{
		return false;
	}

	$lock = ScholarLock( 'cp' . (int) $course_period_id, $wait );

	if ( ! $lock )
	{
		return false;
	}

	try
	{
		return ScholarSyncGrades( $course_period_id );
	}
	finally
	{
		ScholarUnlock( $lock );
	}
}

/**
 * A change in a Scholar course: bring the matching course period up to date.
 *
 * @throws ScholarApiException
 *
 * @return array|false Statistics, or false when the course isn't one of ours.
 */
function ScholarHandleChange( $external_id, $event_id = 0 )
{
	$prefix = ScholarIdPrefix() . ':cp:';

	if ( strpos( (string) $external_id, $prefix ) !== 0 || ! ctype_digit( substr( $external_id, strlen( $prefix ) ) ) )
	{
		return false;
	}

	$stats = ScholarSyncCourseGrades( (int) substr( $external_id, strlen( $prefix ) ) );

	if ( $stats !== false )
	{
		ScholarHandled( $external_id, $event_id );
	}

	return $stats;
}

/**
 * Ask Scholar what changed since last time and bring those courses up to date.
 *
 * @return array|false [ 'events' => n, 'courses' => n, 'grades' => n, 'errors' => n ]; false when
 *                     another poll is running or the plugin isn't set up.
 */
function ScholarPollChanges()
{
	if ( ! ScholarConfigured() )
	{
		return false;
	}

	$lock = ScholarLock( 'poll', 0 );

	if ( ! $lock )
	{
		return false;
	}

	ScholarSetting( 'LAST_POLL', (string) time() );

	$summary = [ 'events' => 0, 'courses' => 0, 'grades' => 0, 'errors' => 0 ];

	try
	{
		$cursor = ScholarSetting( 'LAST_EVENT' );

		if ( $cursor === '' )
		{
			$feed = ScholarApi()->get( 'changes?limit=1' );

			ScholarSetting( 'LAST_EVENT', (string) (int) $feed['head'] );

			return $summary;
		}

		for ( $page = 0; $page < 10; $page++ )
		{
			$feed = ScholarApi()->get( 'changes?after=' . (int) $cursor );

			if ( empty( $feed['events'] ) )
			{
				break;
			}

			$newest = [];

			foreach ( $feed['events'] as $event )
			{
				$summary['events']++;

				$newest[ $event['external_id'] ] = max( (int) $event['id'], isset( $newest[ $event['external_id'] ] ) ? $newest[ $event['external_id'] ] : 0 );

				$cursor = max( (int) $cursor, (int) $event['id'] );
			}

			$handled = ScholarHandled();

			foreach ( $newest as $external_id => $event_id )
			{
				if ( isset( $handled[ $external_id ] ) && $handled[ $external_id ] >= $event_id )
				{
					continue; // the webhook already did this one
				}

				try
				{
					$stats = ScholarHandleChange( $external_id, $event_id );

					if ( $stats !== false )
					{
						$summary['courses']++;
						$summary['grades'] += $stats['grades'];
					}
				}
				catch ( ScholarApiException $e )
				{
					$summary['errors']++;

					ScholarLog( 'error', 'Change in ' . $external_id . ': ' . $e->getMessage() );

					if ( $e->status === 0 || $e->status === 401 )
					{
						throw $e; // Scholar unreachable: try everything again next time
					}
				}
			}

			ScholarSetting( 'LAST_EVENT', (string) $cursor );

			if ( empty( $feed['more'] ) )
			{
				break;
			}
		}
	}
	catch ( ScholarApiException $e )
	{
		$summary['errors']++;

		ScholarSetting( 'LAST_POLL_ERROR', mb_substr( $e->getMessage(), 0, 300 ) );

		return $summary;
	}
	finally
	{
		ScholarUnlock( $lock );
	}

	ScholarSetting( 'LAST_POLL_ERROR', '' );

	return $summary;
}

/**
 * Plain-language state of the instant updates, for the Settings page.
 *
 * @return array [ level (ok|warn|off), text ]
 */
function ScholarWebhookStatus()
{
	if ( ! ScholarConfigured() )
	{
		return [ 'off', 'Enter the Scholar address and token first.' ];
	}

	if ( ScholarSetting( 'WEBHOOK_SECRET' ) === '' )
	{
		return [ 'warn', 'Not connected yet. Click "Connect instant updates". Until then, grades arrive when the periodic sync runs.' ];
	}

	$last = (int) ScholarSetting( 'LAST_PUSH' );
	$poll_error = ScholarSetting( 'LAST_POLL_ERROR' );

	if ( $poll_error !== '' )
	{
		return [ 'warn', 'Scholar could not be reached: ' . $poll_error ];
	}

	return [
		'ok',
		'Connected. ' . ( $last ?
			'Last update received from Scholar ' . ScholarAgo( $last ) . '.' :
			'Waiting for the first update from Scholar (click "Connect instant updates" to check the connection).' ),
	];
}

function ScholarAgo( $time )
{
	$seconds = max( 0, time() - (int) $time );

	if ( $seconds < 90 )
	{
		return 'just now';
	}

	if ( $seconds < 5400 )
	{
		return round( $seconds / 60 ) . ' minutes ago';
	}

	if ( $seconds < 172800 )
	{
		return round( $seconds / 3600 ) . ' hours ago';
	}

	return round( $seconds / 86400 ) . ' days ago';
}
