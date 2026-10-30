/**
 * Scholar plugin: run by RosarioSIS when the plugin is deleted
 * (School > Configuration > Plugins > Delete). Works on PostgreSQL and MySQL.
 * Gradebook assignments and grades written by the plugin are kept.
 */

DROP TABLE IF EXISTS scholar_grades;
DROP TABLE IF EXISTS scholar_assignments;
DROP TABLE IF EXISTS scholar_links;
DROP TABLE IF EXISTS scholar_log;

DELETE FROM config
WHERE school_id=0
AND title IN ('SCHOLAR_URL','SCHOLAR_TOKEN','SCHOLAR_ACCOUNT_TYPE','SCHOLAR_AUTO_LINK',
	'SCHOLAR_CARRY_OVER','SCHOLAR_DEACTIVATE_LEAVERS','SCHOLAR_SYNC_MINUTES','SCHOLAR_CATEGORY',
	'SCHOLAR_ID_PREFIX','SCHOLAR_LAST_SYNC','SCHOLAR_LAST_SYEAR','SCHOLAR_CRON_KEY');
