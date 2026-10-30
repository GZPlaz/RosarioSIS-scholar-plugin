<?php
/**
 * New default school year handling.
 *
 * RosarioSIS has no hook for "the default school year changed" (it lives in config.inc.php),
 * so the plugin remembers the last default year it saw and acts once when it moves forward:
 * 1. archives in Scholar every course linked to an earlier school year (results are kept),
 * 2. links the new year's rolled-over course periods to fresh Scholar courses and sets them up,
 *    and, if "link new course periods" is on, also every other new course period,
 * 3. deactivates the Scholar accounts of students not enrolled in the new year (reversible).
 *
 * @package Scholar plugin
 */

/**
 * @return bool True when a year change was handled.
 */
function ScholarCheckYearChange()
{
	$current = ScholarDefaultSyear();
	$last = (int) ScholarSetting( 'LAST_SYEAR' );

	if ( ! $current )
	{
		return false;
	}

	if ( ! $last || $current < $last )
	{
		// First run, or the default year was moved back: just remember it, change nothing.
		ScholarSetting( 'LAST_SYEAR', (string) $current );

		return false;
	}

	if ( $current === $last )
	{
		return false;
	}

	ScholarLog( 'info', 'Default school year changed from ' . $last . ' to ' . $current . '.' );

	// Remember first, so an error below can't make every page load repeat the work.
	ScholarSetting( 'LAST_SYEAR', (string) $current );

	ScholarArchivePreviousYears( $current );

	if ( ScholarSetting( 'CARRY_OVER' ) === 'Y' || ScholarSetting( 'AUTO_LINK' ) === 'Y' )
	{
		ScholarLinkNewYear( $current, $last );
	}

	if ( ScholarSetting( 'DEACTIVATE_LEAVERS' ) === 'Y' )
	{
		ScholarDeactivateLeavers( $current );
	}

	return true;
}

function ScholarArchivePreviousYears( $current )
{
	$links = DBGet( "SELECT COURSE_PERIOD_ID,SCHOLAR_COURSE_ID
		FROM scholar_links
		WHERE STATUS='active'
		AND SYEAR<'" . (int) $current . "'" );

	$archived = 0;

	foreach ( (array) $links as $link )
	{
		try
		{
			ScholarApi()->patch( 'courses/' . (int) $link['SCHOLAR_COURSE_ID'], [ 'archived' => true ] );
			$archived++;
		}
		catch ( ScholarApiException $e )
		{
			ScholarLog( 'warning', 'Could not archive Scholar course #' . (int) $link['SCHOLAR_COURSE_ID'] . ': ' . $e->getMessage() );
		}

		DBUpdate( 'scholar_links', [
			'STATUS' => 'archived',
			'UPDATED_AT' => DBEscapeString( date( 'Y-m-d H:i:s' ) ),
		], [ 'COURSE_PERIOD_ID' => (int) $link['COURSE_PERIOD_ID'] ] );
	}

	ScholarLog( 'info', $archived . ' Scholar course(s) from earlier school years archived (their results are kept in Scholar).' );
}

function ScholarLinkNewYear( $current, $last )
{
	$link_all = ScholarSetting( 'AUTO_LINK' ) === 'Y';

	// New-year course periods, with the link of the course period they were rolled over from.
	$course_periods = DBGet( "SELECT cp.COURSE_PERIOD_ID,cp.TITLE,old.COURSE_PERIOD_ID AS OLD_LINKED
		FROM course_periods cp
		LEFT JOIN scholar_links old ON (old.COURSE_PERIOD_ID=cp.ROLLOVER_ID AND old.SYEAR<'" . (int) $current . "')
		WHERE cp.SYEAR='" . (int) $current . "'
		AND NOT EXISTS (SELECT 1 FROM scholar_links l WHERE l.COURSE_PERIOD_ID=cp.COURSE_PERIOD_ID)" );

	$linked = 0;

	foreach ( (array) $course_periods as $cp )
	{
		$carry = ScholarSetting( 'CARRY_OVER' ) === 'Y' && $cp['OLD_LINKED'];

		if ( ! $carry && ! $link_all )
		{
			continue;
		}

		try
		{
			ScholarLink( $cp['COURSE_PERIOD_ID'] );
			$linked++;
		}
		catch ( ScholarApiException $e )
		{
			ScholarLog( 'error', 'New school year: could not link ' . $cp['TITLE'] . ': ' . $e->getMessage() );
		}
	}

	ScholarLog( 'info', 'New school year: ' . $linked . ' course period(s) linked to new Scholar courses.' );
}

function ScholarDeactivateLeavers( $current )
{
	$enrolled = DBGet( "SELECT DISTINCT STUDENT_ID
		FROM student_enrollment
		WHERE SYEAR='" . (int) $current . "'
		AND (END_DATE IS NULL OR END_DATE>=CURRENT_DATE)" );

	$prefix = ScholarIdPrefix() . ':student:';

	$ids = [];

	foreach ( (array) $enrolled as $row )
	{
		$ids[] = $prefix . $row['STUDENT_ID'];
	}

	if ( ! $ids )
	{
		// No enrollment yet in the new year: deactivating everyone would be a mistake.
		ScholarLog( 'warning', 'New school year: no student is enrolled yet, so no Scholar account was deactivated.' );

		return;
	}

	try
	{
		$result = ScholarApi()->post( 'users/active', [ 'prefix' => $prefix, 'active_external_ids' => $ids ] );

		ScholarLog( 'info', 'New school year: ' . (int) $result['deactivated'] . ' Scholar account(s) of students who left were deactivated.' );
	}
	catch ( ScholarApiException $e )
	{
		ScholarLog( 'error', 'New school year: could not update Scholar accounts: ' . $e->getMessage() );
	}
}
