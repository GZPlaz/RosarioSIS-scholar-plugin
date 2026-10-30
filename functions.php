<?php
/**
 * Scholar plugin for RosarioSIS
 *
 * Links RosarioSIS course periods to Scholar (assessment & standards-mastery platform) courses,
 * keeps their rosters in step, copies Scholar test results into the RosarioSIS gradebook,
 * and tidies up when a new default school year starts.
 *
 * @package Scholar plugin
 */

define( 'SCHOLAR_PLUGIN_VERSION', '1.0.1' );

// The plugin works from any folder name (RosarioSIS names a plugin after its folder).
require_once __DIR__ . '/includes/Client.php';
require_once __DIR__ . '/includes/Settings.fnc.php';
require_once __DIR__ . '/includes/Courses.fnc.php';
require_once __DIR__ . '/includes/Grades.fnc.php';
require_once __DIR__ . '/includes/Year.fnc.php';
require_once __DIR__ . '/includes/Sync.fnc.php';

/**
 * Run plugin work from a hook without ever breaking the RosarioSIS page.
 */
function ScholarSafely( $callback, $context )
{
	global $note;

	try
	{
		return call_user_func( $callback );
	}
	catch ( Exception $e )
	{
		ScholarLog( 'error', $context . ': ' . $e->getMessage() );

		$note[] = 'Scholar: ' . htmlspecialchars( $e->getMessage(), ENT_QUOTES ) .
			' (' . _( 'see the Scholar plugin log' ) . ')';
	}

	return null;
}

/**
 * New course period: create & link it in Scholar when "link new course periods" is on.
 */
function ScholarHookCreateCoursePeriod()
{
	global $note;

	if ( ! ScholarConfigured()
		|| ScholarSetting( 'AUTO_LINK' ) !== 'Y'
		|| empty( $_REQUEST['course_period_id'] ) )
	{
		return;
	}

	$course_period_id = (int) $_REQUEST['course_period_id'];

	ScholarSafely( function () use ( $course_period_id, &$note )
	{
		ScholarLink( $course_period_id );

		$note[] = button( 'check' ) . '&nbsp;Scholar: course created, linked and set up.';
	}, 'Link new course period ' . $course_period_id );
}

/**
 * Course period edited (title, teacher…): update the Scholar course and its roster.
 */
function ScholarHookUpdateCoursePeriod()
{
	if ( ! ScholarConfigured() || empty( $_REQUEST['course_period_id'] ) )
	{
		return;
	}

	$course_period_id = (int) $_REQUEST['course_period_id'];

	$link = ScholarLinkOf( $course_period_id );

	if ( ! $link || $link['STATUS'] !== 'active' )
	{
		return;
	}

	ScholarSafely( function () use ( $course_period_id, $link )
	{
		$payload = ScholarCoursePayload( ScholarCoursePeriod( $course_period_id ) );

		unset( $payload['external_id'], $payload['description'] );

		ScholarApi()->patch( 'courses/' . (int) $link['SCHOLAR_COURSE_ID'], $payload );

		$cp = ScholarCoursePeriod( $course_period_id );

		ScholarPushRoster( $course_period_id );

		// The teacher may have changed: make sure the new one has the category.
		ScholarEnsureCategory( $cp['TEACHER_ID'], $cp['COURSE_ID'] );
	}, 'Update course period ' . $course_period_id );
}

/**
 * Course period deleted: archive its Scholar course (results stay in Scholar) and unlink.
 */
function ScholarHookDeleteCoursePeriod()
{
	if ( ! ScholarConfigured() || empty( $_REQUEST['course_period_id'] ) )
	{
		return;
	}

	$course_period_id = (int) $_REQUEST['course_period_id'];

	if ( ScholarLinkOf( $course_period_id ) )
	{
		ScholarSafely( function () use ( $course_period_id )
		{
			ScholarUnlink( $course_period_id, true );
		}, 'Delete course period ' . $course_period_id );
	}
}

/**
 * Students scheduled or dropped: refresh rosters right after this page is sent.
 */
function ScholarHookScheduleChanged()
{
	ScholarMaybeSync( true );
}

/**
 * Every page: run the periodic sync in the background when it is due.
 */
function ScholarHookFooter()
{
	ScholarMaybeSync( false );
}

// Hooks (functions must exist before add_action() registers them).
add_action( 'Scheduling/Courses.php|create_course_period', 'ScholarHookCreateCoursePeriod' );
add_action( 'Scheduling/Courses.php|update_course_period', 'ScholarHookUpdateCoursePeriod' );
add_action( 'Scheduling/Courses.php|delete_course_period', 'ScholarHookDeleteCoursePeriod' );

add_action( 'Scheduling/MassSchedule.php|schedule_student', 'ScholarHookScheduleChanged' );
add_action( 'Scheduling/MassDrops.php|drop_student', 'ScholarHookScheduleChanged' );
add_action( 'Scheduling/Schedule.php|drop_student', 'ScholarHookScheduleChanged' );
add_action( 'Scheduling/Schedule.php|schedule_student', 'ScholarHookScheduleChanged' );
add_action( 'Scheduling/Scheduler.php|schedule_student', 'ScholarHookScheduleChanged' );

add_action( 'School_Setup/Rollover.php|rollover_after', 'ScholarHookScheduleChanged' );

add_action( 'Warehouse.php|footer', 'ScholarHookFooter' );
