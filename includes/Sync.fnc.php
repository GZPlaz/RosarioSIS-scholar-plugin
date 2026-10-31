<?php
/**
 * Background synchronisation.
 *
 * Grades normally arrive the moment they change: Scholar calls webhook.php (see Webhook.fnc.php).
 * This file is the safety net and the roster side:
 * - once a minute at most, ask Scholar for the list of changes (ScholarPollChanges());
 * - every SYNC_MINUTES, a full sync: handle a new default school year, then for every active
 *   link of the default school year push the roster and pull the grades.
 * Both run after a page has been sent to the browser (no waiting for users), or from cron.php.
 *
 * @package Scholar plugin
 */

/**
 * Run a full sync now (guarded by a lock so two runs never overlap).
 *
 * @return array|false Summary, or false when another run holds the lock / not configured.
 */
function ScholarSyncAll()
{
	if ( ! ScholarConfigured() )
	{
		return false;
	}

	$lock = fopen( sys_get_temp_dir() . '/rosariosis-scholar-' . md5( __FILE__ ) . '.lock', 'c' );

	if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) )
	{
		return false;
	}

	ScholarSetting( 'LAST_SYNC', (string) time() );

	$summary = [ 'links' => 0, 'grades' => 0, 'assignments' => 0, 'errors' => 0 ];

	try
	{
		ScholarCheckYearChange();

		if ( ScholarSetting( 'MATCH_EXISTING' ) !== 'N' )
		{
			try
			{
				ScholarAdoptExisting();
			}
			catch ( ScholarApiException $e )
			{
				ScholarLog( 'error', 'Looking for existing Scholar courses: ' . $e->getMessage() );
			}
		}

		$links = DBGet( "SELECT COURSE_PERIOD_ID
			FROM scholar_links
			WHERE STATUS='active'
			AND SYEAR='" . ScholarDefaultSyear() . "'
			ORDER BY COURSE_PERIOD_ID" );

		foreach ( (array) $links as $link )
		{
			$summary['links']++;

			try
			{
				// Not while a webhook is copying this same course.
				$course_lock = ScholarLock( 'cp' . (int) $link['COURSE_PERIOD_ID'], 20 );

				if ( ! $course_lock )
				{
					continue;
				}

				try
				{
					ScholarPushRoster( $link['COURSE_PERIOD_ID'] );

					$stats = ScholarSyncGrades( $link['COURSE_PERIOD_ID'] );
				}
				finally
				{
					ScholarUnlock( $course_lock );
				}

				$summary['grades'] += $stats['grades'];
				$summary['assignments'] += $stats['assignments'];
			}
			catch ( ScholarApiException $e )
			{
				$summary['errors']++;

				DBUpdate( 'scholar_links', [
					'LAST_ERROR' => DBEscapeString( mb_substr( $e->getMessage(), 0, 500 ) ),
				], [ 'COURSE_PERIOD_ID' => (int) $link['COURSE_PERIOD_ID'] ] );

				if ( $e->status === 0 || $e->status === 401 )
				{
					// Scholar unreachable or token refused: no point trying the other courses now.
					ScholarLog( 'error', 'Sync stopped: ' . $e->getMessage() );

					break;
				}

				ScholarLog( 'error', 'Course period ' . (int) $link['COURSE_PERIOD_ID'] . ': ' . $e->getMessage() );
			}
		}
	}
	catch ( Exception $e )
	{
		$summary['errors']++;

		ScholarLog( 'error', 'Sync failed: ' . $e->getMessage() );
	}

	flock( $lock, LOCK_UN );
	fclose( $lock );

	return $summary;
}

/**
 * Called on every page: schedule a background poll / sync when one is due.
 *
 * @param bool $force Sync at the end of this request whatever the interval (schedule changes).
 */
function ScholarMaybeSync( $force = false )
{
	static $scheduled = false;

	if ( $scheduled || ! ScholarConfigured() )
	{
		return;
	}

	$interval = max( 1, (int) ScholarSetting( 'SYNC_MINUTES' ) ) * 60;

	$full = $force
		|| time() - (int) ScholarSetting( 'LAST_SYNC' ) >= $interval
		|| (int) ScholarSetting( 'LAST_SYEAR' ) !== ScholarDefaultSyear();

	$poll = time() - (int) ScholarSetting( 'LAST_POLL' ) >= SCHOLAR_POLL_SECONDS;

	if ( ! $full && ! $poll )
	{
		return;
	}

	$scheduled = true;

	register_shutdown_function( 'ScholarRunAfterResponse', $poll, $full );
}

/**
 * Shutdown function: send the page to the browser first, then poll and/or sync.
 */
function ScholarRunAfterResponse( $poll = false, $full = true )
{
	if ( function_exists( 'fastcgi_finish_request' ) )
	{
		session_write_close();
		fastcgi_finish_request();
	}

	ignore_user_abort( true );
	@set_time_limit( 600 );

	try
	{
		ScholarAutoConnect();

		if ( $poll )
		{
			ScholarPollChanges();
		}

		if ( $full )
		{
			ScholarSyncAll();
		}
	}
	catch ( Throwable $e )
	{
		// Never let a sync problem surface on a RosarioSIS page.
		error_log( 'RosarioSIS Scholar plugin: ' . $e->getMessage() );
	}
}
