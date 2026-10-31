<?php
/**
 * Linking RosarioSIS course periods to Scholar courses, and setting them up.
 *
 * A link is one row of scholar_links: a course period ↔ a Scholar course. Linking (whether by
 * creating a new Scholar course or choosing an existing one) automatically:
 * 1. tags the Scholar course with the course period's external id,
 * 2. pushes the teacher(s) and currently scheduled students to Scholar,
 * 3. creates the "Scholar tests" assignment category in the teacher's gradebook,
 * 4. imports the grades of any assessment already taken in Scholar.
 *
 * @package Scholar plugin
 */

/**
 * Course period with its course and school year details.
 *
 * @return array|false
 */
function ScholarCoursePeriod( $course_period_id )
{
	$cp = DBGet( "SELECT cp.COURSE_PERIOD_ID,cp.SYEAR,cp.SCHOOL_ID,cp.COURSE_ID,cp.TITLE,cp.SHORT_NAME,
		cp.MARKING_PERIOD_ID,cp.TEACHER_ID,cp.SECONDARY_TEACHER_ID,cp.ROLLOVER_ID,
		c.TITLE AS COURSE_TITLE,c.SHORT_NAME AS COURSE_SHORT_NAME
		FROM course_periods cp,courses c
		WHERE c.COURSE_ID=cp.COURSE_ID
		AND cp.COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );

	return empty( $cp[1] ) ? false : $cp[1];
}

/**
 * @return array|false The scholar_links row of a course period.
 */
function ScholarLinkOf( $course_period_id )
{
	$link = DBGet( "SELECT COURSE_PERIOD_ID,SCHOLAR_COURSE_ID,SYEAR,SCHOOL_ID,STATUS,ROSTER_SYNCED_AT,GRADES_SYNCED_AT,LAST_ERROR
		FROM scholar_links
		WHERE COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );

	return empty( $link[1] ) ? false : $link[1];
}

function ScholarCourseExternalId( $course_period_id )
{
	return ScholarIdPrefix() . ':cp:' . (int) $course_period_id;
}

/**
 * Scholar course fields for a course period.
 */
function ScholarCoursePayload( $cp )
{
	$course_title = ParseMLField( $cp['COURSE_TITLE'] );

	$short = $cp['COURSE_SHORT_NAME'] ? $cp['COURSE_SHORT_NAME'] : 'C' . $cp['COURSE_ID'];

	$code = mb_substr( $short . '-' . $cp['SHORT_NAME'], 0, 40 );

	$mp_title = DBGetOne( "SELECT TITLE FROM school_marking_periods
		WHERE MARKING_PERIOD_ID='" . (int) $cp['MARKING_PERIOD_ID'] . "'" );

	$term = FormatSyear( $cp['SYEAR'], Config( 'SCHOOL_SYEAR_OVER_2_YEARS' ) ) .
		( $mp_title ? ', ' . $mp_title : '' );

	return [
		'code' => $code,
		'title' => mb_substr( $course_title, 0, 200 ),
		'term' => mb_substr( $term, 0, 60 ),
		'description' => 'Linked to RosarioSIS course period: ' . $cp['TITLE'],
		'external_id' => ScholarCourseExternalId( $cp['COURSE_PERIOD_ID'] ),
	];
}

/**
 * Every course in Scholar (archived ones too), read once per request.
 *
 * @throws ScholarApiException
 */
function ScholarScholarCourses( $refresh = false )
{
	static $courses = null;

	if ( $courses === null || $refresh )
	{
		$list = ScholarApi()->get( 'courses?include_archived=1' );

		$courses = (array) $list['courses'];
	}

	return $courses;
}

function ScholarNormalizeCode( $code )
{
	return mb_strtolower( preg_replace( '/\s+/u', ' ', trim( (string) $code ) ) );
}

/**
 * Is a Scholar course free to be linked to a course period? It is when it carries no tag from a
 * connected system, or a tag of this plugin that points to a course period that no longer exists
 * here (a RosarioSIS that was reinstalled or reset).
 */
function ScholarCourseIsFree( $course )
{
	if ( empty( $course['external_id'] ) )
	{
		return true;
	}

	$prefix = ScholarIdPrefix() . ':cp:';

	if ( strpos( $course['external_id'], $prefix ) !== 0 )
	{
		return false; // belongs to another system
	}

	$other = substr( $course['external_id'], strlen( $prefix ) );

	return ! ctype_digit( $other )
		|| ! DBGetOne( "SELECT 1 FROM course_periods WHERE COURSE_PERIOD_ID='" . (int) $other . "'" );
}

/**
 * The Scholar course that already exists for a course period, if there is a sure one:
 * 1. a course tagged with this course period's id ("rosario:cp:7"), or
 * 2. a free, not archived course with the very code the plugin would give a new one
 *    (course short name, a dash, course period short name: "G121-1st G121").
 *
 * @param array      $cp      Course period (ScholarCoursePeriod()).
 * @param array|null $courses Scholar courses (default: all).
 *
 * @return array|false [ 'id' => Scholar course id, 'how' => 'tag'|'code' ]
 */
function ScholarFindExisting( $cp, $courses = null )
{
	$courses = $courses === null ? ScholarScholarCourses() : $courses;

	$expected = ScholarCourseExternalId( $cp['COURSE_PERIOD_ID'] );

	foreach ( $courses as $course )
	{
		if ( $course['external_id'] === $expected )
		{
			return [ 'id' => (int) $course['id'], 'how' => 'tag' ];
		}
	}

	$payload = ScholarCoursePayload( $cp );

	$want = ScholarNormalizeCode( $payload['code'] );

	$found = [];

	foreach ( $courses as $course )
	{
		if ( empty( $course['archived'] )
			&& ScholarNormalizeCode( $course['code'] ) === $want
			&& ScholarCourseIsFree( $course ) )
		{
			$found[] = (int) $course['id'];
		}
	}

	return count( $found ) === 1 ? [ 'id' => $found[0], 'how' => 'code' ] : false;
}

/**
 * Link every unlinked course period (default school year, or the given one) to the Scholar course
 * that already exists for it. Course periods whose match is ambiguous (two course periods
 * wanting one Scholar course) are left for a person to decide.
 *
 * @throws ScholarApiException
 *
 * @return array [ 'linked' => n, 'ambiguous' => n, 'accounts' => roster results ]
 */
function ScholarAdoptExisting( $syear = null )
{
	$result = [ 'linked' => 0, 'ambiguous' => 0, 'accounts' => [] ];

	$unlinked = DBGet( "SELECT cp.COURSE_PERIOD_ID
		FROM course_periods cp
		LEFT JOIN scholar_links l ON l.COURSE_PERIOD_ID=cp.COURSE_PERIOD_ID
		WHERE l.COURSE_PERIOD_ID IS NULL
		AND cp.SYEAR='" . (int) ( $syear ? $syear : ScholarDefaultSyear() ) . "'
		ORDER BY cp.COURSE_PERIOD_ID" );

	if ( ! $unlinked )
	{
		return $result;
	}

	$courses = ScholarScholarCourses( true );

	$wanted = [];

	foreach ( $unlinked as $row )
	{
		$cp = ScholarCoursePeriod( $row['COURSE_PERIOD_ID'] );

		$match = $cp ? ScholarFindExisting( $cp, $courses ) : false;

		if ( $match )
		{
			$wanted[ $match['id'] ][] = (int) $row['COURSE_PERIOD_ID'];
		}
	}

	foreach ( $wanted as $scholar_course_id => $course_periods )
	{
		if ( count( $course_periods ) > 1 )
		{
			$result['ambiguous'] += count( $course_periods );

			continue;
		}

		try
		{
			$result['accounts'][] = ScholarLink( $course_periods[0], $scholar_course_id );

			$result['linked']++;
		}
		catch ( ScholarApiException $e )
		{
			if ( $e->status === 0 || $e->status === 401 )
			{
				throw $e;
			}

			ScholarLog( 'error', 'Linking course period ' . $course_periods[0] . ' to existing Scholar course ' . $scholar_course_id . ': ' . $e->getMessage() );
		}
	}

	if ( $result['linked'] )
	{
		ScholarLog( 'info', 'Found ' . $result['linked'] . ' existing Scholar course(s) for course periods and linked them.' );
	}

	return $result;
}

/**
 * Link a course period to Scholar and set it up.
 *
 * @param int      $course_period_id  Course period.
 * @param int|null $scholar_course_id Existing Scholar course to link, or null to create one.
 *
 * @throws ScholarApiException
 *
 * @return array Roster response from Scholar (contains created accounts / temporary passwords).
 */
function ScholarLink( $course_period_id, $scholar_course_id = null )
{
	$cp = ScholarCoursePeriod( $course_period_id );

	if ( ! $cp )
	{
		throw new ScholarApiException( 'Course period ' . (int) $course_period_id . ' not found.' );
	}

	$api = ScholarApi();

	$payload = ScholarCoursePayload( $cp );

	if ( $scholar_course_id )
	{
		$course = $api->patch( 'courses/' . (int) $scholar_course_id, [
			'external_id' => $payload['external_id'],
			'archived' => false,
		] );
	}
	else
	{
		// A course that already exists for this course period is linked, not duplicated.
		$existing_course = ScholarSetting( 'MATCH_EXISTING' ) !== 'N' ? ScholarFindExisting( $cp ) : false;

		if ( $existing_course )
		{
			$course = $api->patch( 'courses/' . (int) $existing_course['id'], [
				'external_id' => $payload['external_id'],
				'archived' => false,
			] );
		}
		else
		{
			// Idempotent: Scholar returns the existing course if this external id is already there.
			$course = $api->post( 'courses', $payload );
		}
	}

	$scholar_course_id = (int) $course['course']['id'];

	$existing = ScholarLinkOf( $course_period_id );

	if ( $existing )
	{
		DBUpdate( 'scholar_links', [
			'SCHOLAR_COURSE_ID' => $scholar_course_id,
			'STATUS' => 'active',
			'LAST_ERROR' => '',
			'UPDATED_AT' => DBEscapeString( date( 'Y-m-d H:i:s' ) ),
		], [ 'COURSE_PERIOD_ID' => (int) $course_period_id ] );

		if ( (int) $existing['SCHOLAR_COURSE_ID'] !== $scholar_course_id )
		{
			// Different Scholar course: earlier assessment ↔ assignment mappings no longer apply.
			DBQuery( "DELETE FROM scholar_assignments WHERE COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );
		}
	}
	else
	{
		DBInsert( 'scholar_links', [
			'COURSE_PERIOD_ID' => (int) $course_period_id,
			'SCHOLAR_COURSE_ID' => $scholar_course_id,
			'SYEAR' => (int) $cp['SYEAR'],
			'SCHOOL_ID' => (int) $cp['SCHOOL_ID'],
			'STATUS' => 'active',
		] );
	}

	ScholarLog( 'info', 'Linked ' . $cp['TITLE'] . ' to Scholar course #' . $scholar_course_id . ' (' . $course['course']['code'] . ').' );

	return ScholarSetUpLink( $course_period_id );
}

/**
 * Automatic set-up of a linked course period: roster, gradebook category, existing grades.
 *
 * @throws ScholarApiException
 */
function ScholarSetUpLink( $course_period_id )
{
	$cp = ScholarCoursePeriod( $course_period_id );

	$roster = ScholarPushRoster( $course_period_id );

	ScholarEnsureCategory( $cp['TEACHER_ID'], $cp['COURSE_ID'] );

	ScholarSyncGrades( $course_period_id );

	return $roster;
}

/**
 * Unlink a course period. Grades already in the gradebook stay.
 *
 * @param bool $archive Also archive the course in Scholar (its results are kept there).
 */
function ScholarUnlink( $course_period_id, $archive = false )
{
	$link = ScholarLinkOf( $course_period_id );

	if ( ! $link )
	{
		return;
	}

	try
	{
		$body = [ 'external_id' => '' ];

		if ( $archive )
		{
			$body['archived'] = true;
		}

		ScholarApi()->patch( 'courses/' . (int) $link['SCHOLAR_COURSE_ID'], $body );
	}
	catch ( ScholarApiException $e )
	{
		// The Scholar course may have been deleted; unlinking locally is still right.
		ScholarLog( 'warning', 'Unlink course period ' . (int) $course_period_id . ': ' . $e->getMessage() );
	}

	DBQuery( "DELETE FROM scholar_assignments WHERE COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );
	DBQuery( "DELETE FROM scholar_links WHERE COURSE_PERIOD_ID='" . (int) $course_period_id . "'" );

	ScholarLog( 'info', 'Unlinked course period ' . (int) $course_period_id . ( $archive ? ' and archived its Scholar course.' : '.' ) );
}

/**
 * Students & teachers of a course period in the format of Scholar's roster endpoint.
 */
function ScholarRosterPayload( $cp )
{
	$prefix = ScholarIdPrefix();

	$email_column = '';

	if ( Config( 'STUDENTS_EMAIL_FIELD' ) )
	{
		$email_column = Config( 'STUDENTS_EMAIL_FIELD' ) === 'USERNAME' ?
			's.USERNAME' : 's.CUSTOM_' . (int) Config( 'STUDENTS_EMAIL_FIELD' );
	}

	$students_RET = DBGet( "SELECT DISTINCT s.STUDENT_ID,s.FIRST_NAME,s.LAST_NAME,s.USERNAME" .
		( $email_column ? "," . $email_column . " AS EMAIL" : "" ) . "
		FROM schedule sch,students s
		WHERE s.STUDENT_ID=sch.STUDENT_ID
		AND sch.COURSE_PERIOD_ID='" . (int) $cp['COURSE_PERIOD_ID'] . "'
		AND sch.SYEAR='" . (int) $cp['SYEAR'] . "'
		AND (sch.END_DATE IS NULL OR sch.END_DATE>=CURRENT_DATE)
		ORDER BY s.LAST_NAME,s.FIRST_NAME" );

	$students = [];

	foreach ( (array) $students_RET as $student )
	{
		$email = isset( $student['EMAIL'] ) && filter_var( $student['EMAIL'], FILTER_VALIDATE_EMAIL ) ? $student['EMAIL'] : '';

		$students[] = [
			'external_id' => $prefix . ':student:' . $student['STUDENT_ID'],
			'username' => ScholarUsername( $student['USERNAME'], $prefix . '-s' . $student['STUDENT_ID'] ),
			'full_name' => trim( $student['FIRST_NAME'] . ' ' . $student['LAST_NAME'] ),
			'email' => $email,
			'student_number' => (string) $student['STUDENT_ID'],
		];
	}

	$teacher_ids = array_filter( [ (int) $cp['TEACHER_ID'], (int) $cp['SECONDARY_TEACHER_ID'] ] );

	$teachers_RET = $teacher_ids ? DBGet( "SELECT STAFF_ID,FIRST_NAME,LAST_NAME,USERNAME,EMAIL
		FROM staff
		WHERE STAFF_ID IN (" . implode( ',', $teacher_ids ) . ")" ) : [];

	$instructors = [];

	foreach ( (array) $teachers_RET as $teacher )
	{
		$instructors[] = [
			// Staff get a new STAFF_ID every school year (rollover), so they are identified by username.
			'external_id' => $teacher['USERNAME'] !== '' && $teacher['USERNAME'] !== null ?
				$prefix . ':staff:' . mb_strtolower( $teacher['USERNAME'] ) :
				$prefix . ':staff:id' . $teacher['STAFF_ID'],
			'username' => ScholarUsername( $teacher['USERNAME'], $prefix . '-t' . $teacher['STAFF_ID'] ),
			'full_name' => trim( $teacher['FIRST_NAME'] . ' ' . $teacher['LAST_NAME'] ),
			'email' => filter_var( $teacher['EMAIL'], FILTER_VALIDATE_EMAIL ) ? $teacher['EMAIL'] : '',
		];
	}

	return [ 'students' => $students, 'instructors' => $instructors ];
}

/**
 * Make the Scholar course roster match the course period (adds, drops, teacher changes).
 *
 * @throws ScholarApiException
 *
 * @return array Scholar's answer.
 */
function ScholarPushRoster( $course_period_id )
{
	$link = ScholarLinkOf( $course_period_id );
	$cp = ScholarCoursePeriod( $course_period_id );

	if ( ! $link || ! $cp )
	{
		return [];
	}

	$body = ScholarRosterPayload( $cp );
	$body['account_type'] = ScholarSetting( 'ACCOUNT_TYPE' ) === 'local' ? 'local' : 'ldap';
	$body['remove_missing'] = true;

	$response = ScholarApi()->put( 'courses/' . (int) $link['SCHOLAR_COURSE_ID'] . '/roster', $body );

	DBUpdate( 'scholar_links', [
		'ROSTER_SYNCED_AT' => DBEscapeString( date( 'Y-m-d H:i:s' ) ),
	], [ 'COURSE_PERIOD_ID' => (int) $course_period_id ] );

	if ( ! empty( $response['conflicts'] ) )
	{
		foreach ( $response['conflicts'] as $conflict )
		{
			ScholarLog( 'warning', $cp['TITLE'] . ': ' . $conflict['username'] . ' not added, ' . $conflict['reason'] . '.' );
		}
	}

	if ( ! empty( $response['enrolled'] ) || ! empty( $response['unenrolled'] ) )
	{
		ScholarLog( 'info', $cp['TITLE'] . ': roster updated in Scholar (' .
			count( $response['enrolled'] ) . ' added, ' . count( $response['unenrolled'] ) . ' removed).' );
	}

	return $response;
}

/**
 * Get (or create) the teacher's assignment category for Scholar tests in this course.
 *
 * @return int Assignment type ID.
 */
function ScholarEnsureCategory( $staff_id, $course_id )
{
	$title = ScholarSetting( 'CATEGORY' );

	$type_id = DBGetOne( "SELECT ASSIGNMENT_TYPE_ID
		FROM gradebook_assignment_types
		WHERE STAFF_ID='" . (int) $staff_id . "'
		AND COURSE_ID='" . (int) $course_id . "'
		AND TITLE='" . DBEscapeString( $title ) . "'" );

	if ( $type_id )
	{
		return (int) $type_id;
	}

	$sort_order = (int) DBGetOne( "SELECT COALESCE(MAX(SORT_ORDER),0)+1
		FROM gradebook_assignment_types
		WHERE STAFF_ID='" . (int) $staff_id . "'
		AND COURSE_ID='" . (int) $course_id . "'" );

	$type_id = DBInsert( 'gradebook_assignment_types', [
		'STAFF_ID' => (int) $staff_id,
		'COURSE_ID' => (int) $course_id,
		'TITLE' => DBEscapeString( $title ),
		'SORT_ORDER' => $sort_order,
		'COLOR' => '#3B6620',
	], 'id' );

	return (int) $type_id;
}
