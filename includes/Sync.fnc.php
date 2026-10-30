<?php
/**
 * Background synchronisation.
 *
 * Runs at most every SYNC_MINUTES, either after a page has been sent to the browser (no waiting
 * for users) or from cron.php. One run: handle a new default school year, then for every active
 * link of the default school year push the roster and pull the grades.
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
				ScholarPushRoster( $link['COURSE_PERIOD_ID'] );

				$stats = ScholarSyncGrades( $link['COURSE_PERIOD_ID'] );

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
 * Called on every page: schedule a background sync when one is due.
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

	$due = time() - (int) ScholarSetting( 'LAST_SYNC' ) >= $interval
		|| (int) ScholarSetting( 'LAST_SYEAR' ) !== ScholarDefaultSyear();

	if ( ! $force && ! $due )
	{
		return;
	}

	$scheduled = true;

	register_shutdown_function( 'ScholarRunAfterResponse' );
}

/**
 * Shutdown function: send the page to the browser first, then sync.
 */
function ScholarRunAfterResponse()
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
		ScholarSyncAll();
	}
	catch ( Throwable $e )
	{
		// Never let a sync problem surface on a RosarioSIS page.
		error_log( 'RosarioSIS Scholar plugin: ' . $e->getMessage() );
	}
}
