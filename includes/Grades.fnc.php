<?php
/**
 * Copy Scholar test results into the RosarioSIS gradebook.
 *
 * For each linked course period, every Scholar assessment that has results becomes one
 * gradebook assignment (in the teacher's "Scholar tests" category), and each student's counted
 * score becomes their grade. The plugin remembers the last value it wrote; if a teacher changes
 * a grade by hand in RosarioSIS, later syncs leave that grade alone.
 *
 * @package Scholar plugin
 */

/**
 * Marking period an assignment belongs to: the shortest grading period (quarter, then
 * semester, then full year) of the school year containing the date.
 */
function ScholarMarkingPeriodFor( $cp, $date )
{
	$order = db_case( [ 'MP', "'QTR'", '1', "'SEM'", '2', '3' ] );

	$mp_id = DBGetOne( "SELECT MARKING_PERIOD_ID
		FROM school_marking_periods
		WHERE SCHOOL_ID='" . (int) $cp['SCHOOL_ID'] . "'
		AND SYEAR='" . (int) $cp['SYEAR'] . "'
		AND MP IN ('QTR','SEM','FY')
		AND START_DATE<='" . DBEscapeString( $date ) . "'
		AND END_DATE>='" . DBEscapeString( $date ) . "'
		ORDER BY " . $order . ",START_DATE
		LIMIT 1" );

	if ( ! $mp_id )
	{
		// Date outside the school year: use the closest grading period.
		$mp_id = DBGetOne( "SELECT MARKING_PERIOD_ID
			FROM school_marking_periods
			WHERE SCHOOL_ID='" . (int) $cp['SCHOOL_ID'] . "'
			AND SYEAR='" . (int) $cp['SYEAR'] . "'
			AND MP IN ('QTR','SEM','FY')
			ORDER BY " . $order . ",
			" . ( $date < DBGetOne( "SELECT MIN(START_DATE) FROM school_marking_periods
				WHERE SCHOOL_ID='" . (int) $cp['SCHOOL_ID'] . "' AND SYEAR='" . (int) $cp['SYEAR'] . "'" ) ?
				"START_DATE" : "START_DATE DESC" ) . "
			LIMIT 1" );
	}

	return $mp_id ? (int) $mp_id : (int) $cp['MARKING_PERIOD_ID'];
}

/**
 * Convert an ISO 8601 UTC timestamp from Scholar to a local date (Y-m-d).
 */
function ScholarLocalDate( $iso )
{
	if ( ! $iso )
	{
		return '';
	}

	$time = strtotime( $iso );

	return $time ? date( 'Y-m-d', $time ) : '';
}

/**
 * Sync the grades of one linked course period.
 *
 * @throws ScholarApiException
 *
 * @return array [ 'assignments' => created, 'grades' => written, 'kept' => manual grades left alone,
 *               'removed' => grades taken back because their attempt was deleted in Scholar ]
 */
function ScholarSyncGrades( $course_period_id )
{
	$stats = [ 'assignments' => 0, 'grades' => 0, 'kept' => 0, 'removed' => 0 ];

	$link = ScholarLinkOf( $course_period_id );
	$cp = ScholarCoursePeriod( $course_period_id );

	if ( ! $link || ! $cp || $link['STATUS'] !== 'active' )
	{
		return $stats;
	}

	$data = ScholarApi()->get( 'courses/' . (int) $link['SCHOLAR_COURSE_ID'] . '/results' );

	$student_prefix = ScholarIdPrefix() . ':student:';

	// Students who were in this course period at any point this year.
	$scheduled = DBGet( "SELECT DISTINCT STUDENT_ID
		FROM schedule
		WHERE COURSE_PERIOD_ID='" . (int) $course_period_id . "'
		AND SYEAR='" . (int) $cp['SYEAR'] . "'", [], [ 'STUDENT_ID' ] );

	$category_id = ScholarEnsureCategory( $cp['TEACHER_ID'], $cp['COURSE_ID'] );

	foreach ( (array) $data['assessments'] as $assessment )
	{
		if ( empty( $assessment['results'] ) )
		{
			// Every attempt was deleted in Scholar: take back what this plugin wrote for them.
			$stats['removed'] += ScholarWithdrawGrades( $course_period_id, $assessment['id'], [] );

			continue;
		}

		if ( $assessment['max_score'] <= 0 )
		{
			continue;
		}

		$assignment_id = ScholarEnsureAssignment( $cp, $assessment, $category_id, $stats );

		if ( ! $assignment_id )
		{
			continue;
		}

		$scale = (float) DBGetOne( "SELECT SCALE FROM scholar_assignments
			WHERE SCHOLAR_ASSESSMENT_ID='" . (int) $assessment['id'] . "'
			AND COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );

		$present = [];

		foreach ( $assessment['results'] as $result )
		{
			if ( ! empty( $result['external_id'] ) && strpos( $result['external_id'], $student_prefix ) === 0 )
			{
				$present[ (int) substr( $result['external_id'], strlen( $student_prefix ) ) ] = true;
			}

			if ( ! empty( $result['needs_grading'] )
				|| empty( $result['external_id'] )
				|| strpos( $result['external_id'], $student_prefix ) !== 0 )
			{
				continue; // essays still being graded, or a student not from RosarioSIS
			}

			$student_id = (int) substr( $result['external_id'], strlen( $student_prefix ) );

			if ( empty( $scheduled[ $student_id ] ) )
			{
				continue;
			}

			$points = round( (float) $result['score'] * ( $scale > 0 ? $scale : 1 ), 2 );

			$written = ScholarWriteGrade( $course_period_id, $assignment_id, $student_id, $points );

			if ( $written === true )
			{
				$stats['grades']++;
			}
			elseif ( $written === 'kept' )
			{
				$stats['kept']++;
			}
		}

		$stats['removed'] += ScholarWithdrawGrades( $course_period_id, $assessment['id'], $present );
	}

	DBUpdate( 'scholar_links', [
		'GRADES_SYNCED_AT' => DBEscapeString( date( 'Y-m-d H:i:s' ) ),
		'LAST_ERROR' => '',
	], [ 'COURSE_PERIOD_ID' => (int) $course_period_id ] );

	if ( $stats['assignments'] || $stats['grades'] || $stats['removed'] )
	{
		ScholarLog( 'info', $cp['TITLE'] . ': ' . $stats['assignments'] . ' assignment(s) created, ' .
			$stats['grades'] . ' grade(s) written from Scholar' .
			( $stats['removed'] ? ', ' . $stats['removed'] . ' withdrawn' : '' ) . '.' );
	}

	return $stats;
}

/**
 * Take back grades whose Scholar result no longer exists (the attempt was deleted so the student
 * can retake the test). A grade a teacher changed by hand is left, only no longer followed.
 *
 * @param array $present Student IDs that still have a result, as keys.
 *
 * @return int Grades removed from the gradebook.
 */
function ScholarWithdrawGrades( $course_period_id, $scholar_assessment_id, $present )
{
	$assignment_id = (int) DBGetOne( "SELECT ASSIGNMENT_ID FROM scholar_assignments
		WHERE SCHOLAR_ASSESSMENT_ID='" . (int) $scholar_assessment_id . "'
		AND COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );

	if ( ! $assignment_id )
	{
		return 0;
	}

	$written = DBGet( "SELECT STUDENT_ID,POINTS FROM scholar_grades WHERE ASSIGNMENT_ID='" . $assignment_id . "'" );

	$removed = 0;

	foreach ( (array) $written as $row )
	{
		if ( isset( $present[ (int) $row['STUDENT_ID'] ] ) )
		{
			continue;
		}

		$current = DBGet( "SELECT POINTS FROM gradebook_grades
			WHERE STUDENT_ID='" . (int) $row['STUDENT_ID'] . "'
			AND ASSIGNMENT_ID='" . $assignment_id . "'
			AND COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );

		if ( ! empty( $current[1] ) && $current[1]['POINTS'] !== null && $current[1]['POINTS'] !== ''
			&& abs( (float) $current[1]['POINTS'] - (float) $row['POINTS'] ) < 0.005 )
		{
			DBQuery( "DELETE FROM gradebook_grades
				WHERE STUDENT_ID='" . (int) $row['STUDENT_ID'] . "'
				AND ASSIGNMENT_ID='" . $assignment_id . "'
				AND COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );

			$removed++;
		}

		DBQuery( "DELETE FROM scholar_grades
			WHERE ASSIGNMENT_ID='" . $assignment_id . "' AND STUDENT_ID='" . (int) $row['STUDENT_ID'] . "'" );
	}

	return $removed;
}

/**
 * Create or update the gradebook assignment mirroring a Scholar assessment.
 *
 * @return int Assignment ID, 0 when the teacher deleted it in RosarioSIS (left deleted).
 */
function ScholarEnsureAssignment( $cp, $assessment, $category_id, &$stats )
{
	$map = DBGet( "SELECT ASSIGNMENT_ID,SCALE
		FROM scholar_assignments
		WHERE SCHOLAR_ASSESSMENT_ID='" . (int) $assessment['id'] . "'
		AND COURSE_PERIOD_ID='" . (int) $cp['COURSE_PERIOD_ID'] . "'" );

	$max = (float) $assessment['max_score'];

	// Assignment points are whole numbers in RosarioSIS: fractional totals become /100.
	if ( abs( $max - round( $max ) ) < 0.0001 && $max <= 10000 )
	{
		$points = (int) round( $max );
		$scale = 1;
	}
	else
	{
		$points = 100;
		$scale = 100 / $max;
	}

	$date = ScholarLocalDate( $assessment['closes_at'] );

	if ( ! $date )
	{
		$submitted = array_filter( array_column( $assessment['results'], 'submitted_at' ) );
		sort( $submitted );
		$date = $submitted ? ScholarLocalDate( reset( $submitted ) ) : date( 'Y-m-d' );
	}

	$assigned = ScholarLocalDate( $assessment['opens_at'] );

	if ( ! $assigned || $assigned > $date )
	{
		$assigned = $date;
	}

	$title = mb_substr( $assessment['title'], 0, 100 );

	$description = 'Scholar test results (assessment #' . (int) $assessment['id'] . '). ' .
		'Grades here are filled in automatically from Scholar; a grade you change by hand is kept.';

	if ( ! empty( $map[1] ) )
	{
		$assignment_id = (int) $map[1]['ASSIGNMENT_ID'];

		$current = DBGet( "SELECT ASSIGNMENT_ID FROM gradebook_assignments
			WHERE ASSIGNMENT_ID='" . $assignment_id . "'" );

		if ( empty( $current[1] ) )
		{
			return 0; // deleted by the teacher: respect that
		}

		DBUpdate( 'gradebook_assignments', [
			'TITLE' => DBEscapeString( $title ),
			'POINTS' => ScholarNumber( $points ),
			'DUE_DATE' => DBEscapeString( $date ),
			'UPDATED_AT' => DBEscapeString( date( 'Y-m-d H:i:s' ) ),
		], [ 'ASSIGNMENT_ID' => $assignment_id ] );

		if ( abs( (float) $map[1]['SCALE'] - $scale ) > 0.000001 )
		{
			DBUpdate( 'scholar_assignments', [ 'SCALE' => ScholarNumber( $scale, 6 ) ], [
				'SCHOLAR_ASSESSMENT_ID' => (int) $assessment['id'],
				'COURSE_PERIOD_ID' => (int) $cp['COURSE_PERIOD_ID'],
			] );
		}

		return $assignment_id;
	}

	$assignment_id = (int) DBInsert( 'gradebook_assignments', [
		'STAFF_ID' => (int) $cp['TEACHER_ID'],
		'MARKING_PERIOD_ID' => ScholarMarkingPeriodFor( $cp, $date ),
		'COURSE_PERIOD_ID' => (int) $cp['COURSE_PERIOD_ID'],
		'ASSIGNMENT_TYPE_ID' => (int) $category_id,
		'TITLE' => DBEscapeString( $title ),
		'ASSIGNED_DATE' => DBEscapeString( $assigned ),
		'DUE_DATE' => DBEscapeString( $date ),
		'POINTS' => ScholarNumber( $points ),
		'DESCRIPTION' => DBEscapeString( $description ),
	], 'id' );

	DBInsert( 'scholar_assignments', [
		'SCHOLAR_ASSESSMENT_ID' => (int) $assessment['id'],
		'COURSE_PERIOD_ID' => (int) $cp['COURSE_PERIOD_ID'],
		'ASSIGNMENT_ID' => $assignment_id,
		'SCALE' => ScholarNumber( $scale, 6 ),
	] );

	$stats['assignments']++;

	return $assignment_id;
}

/**
 * Write one grade unless a teacher has changed it by hand since the last sync.
 *
 * @return true|false|string True when written, false when unchanged, 'kept' when a manual grade was kept.
 */
function ScholarWriteGrade( $course_period_id, $assignment_id, $student_id, $points )
{
	$current = DBGet( "SELECT POINTS FROM gradebook_grades
		WHERE STUDENT_ID='" . (int) $student_id . "'
		AND ASSIGNMENT_ID='" . (int) $assignment_id . "'
		AND COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );

	$last = DBGet( "SELECT POINTS FROM scholar_grades
		WHERE ASSIGNMENT_ID='" . (int) $assignment_id . "'
		AND STUDENT_ID='" . (int) $student_id . "'" );

	$now = DBEscapeString( date( 'Y-m-d H:i:s' ) );

	if ( empty( $current[1] ) )
	{
		DBInsert( 'gradebook_grades', [
			'STUDENT_ID' => (int) $student_id,
			'COURSE_PERIOD_ID' => (int) $course_period_id,
			'ASSIGNMENT_ID' => (int) $assignment_id,
			'POINTS' => ScholarNumber( $points ),
		] );
	}
	else
	{
		$current_points = $current[1]['POINTS'];

		if ( $current_points !== null && $current_points !== '' && abs( (float) $current_points - $points ) < 0.005 )
		{
			ScholarRememberGrade( $assignment_id, $student_id, $points, ! empty( $last[1] ) );

			return false;
		}

		$untouched = ( $current_points === null || $current_points === '' )
			|| ( ! empty( $last[1] ) && abs( (float) $last[1]['POINTS'] - (float) $current_points ) < 0.005 );

		if ( ! $untouched )
		{
			return 'kept';
		}

		DBUpdate( 'gradebook_grades', [
			'POINTS' => ScholarNumber( $points ),
			'UPDATED_AT' => $now,
		], [
			'STUDENT_ID' => (int) $student_id,
			'ASSIGNMENT_ID' => (int) $assignment_id,
			'COURSE_PERIOD_ID' => (int) $course_period_id,
		] );
	}

	ScholarRememberGrade( $assignment_id, $student_id, $points, ! empty( $last[1] ) );

	return true;
}

function ScholarRememberGrade( $assignment_id, $student_id, $points, $exists )
{
	if ( $exists )
	{
		DBUpdate( 'scholar_grades', [ 'POINTS' => ScholarNumber( $points ) ], [
			'ASSIGNMENT_ID' => (int) $assignment_id,
			'STUDENT_ID' => (int) $student_id,
		] );

		return;
	}

	DBInsert( 'scholar_grades', [
		'ASSIGNMENT_ID' => (int) $assignment_id,
		'STUDENT_ID' => (int) $student_id,
		'POINTS' => ScholarNumber( $points ),
	] );
}

/**
 * Locale-independent number for SQL ("12.5", never "12,5"); keeps 0 as "0".
 */
function ScholarNumber( $value, $decimals = 2 )
{
	$text = number_format( (float) $value, $decimals, '.', '' );

	return strpos( $text, '.' ) !== false ? rtrim( rtrim( $text, '0' ), '.' ) : $text;
}
