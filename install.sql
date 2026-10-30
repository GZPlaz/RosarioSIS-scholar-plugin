/**
 * Scholar plugin tables (PostgreSQL). Run by RosarioSIS when the plugin is activated.
 */

CREATE TABLE IF NOT EXISTS scholar_links (
    course_period_id integer PRIMARY KEY,
    scholar_course_id integer NOT NULL,
    syear numeric(4,0) NOT NULL,
    school_id integer NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'active',
    roster_synced_at timestamp,
    grades_synced_at timestamp,
    last_error text,
    created_at timestamp DEFAULT current_timestamp,
    updated_at timestamp
);

CREATE TABLE IF NOT EXISTS scholar_assignments (
    scholar_assessment_id integer NOT NULL,
    course_period_id integer NOT NULL,
    assignment_id integer NOT NULL,
    scale numeric(14,6) NOT NULL DEFAULT 1,
    created_at timestamp DEFAULT current_timestamp,
    PRIMARY KEY (scholar_assessment_id, course_period_id)
);

CREATE TABLE IF NOT EXISTS scholar_grades (
    assignment_id integer NOT NULL,
    student_id integer NOT NULL,
    points numeric(8,2),
    PRIMARY KEY (assignment_id, student_id)
);

CREATE TABLE IF NOT EXISTS scholar_log (
    id serial PRIMARY KEY,
    created_at timestamp DEFAULT current_timestamp,
    level varchar(10) NOT NULL,
    message text NOT NULL
);
