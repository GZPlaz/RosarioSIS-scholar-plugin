<?php
/**
 * Scholar plugin: scheduled sync (the safety net behind the instant updates).
 *
 * Every run asks Scholar for changes it could not deliver; the full sync runs when it is due
 * (every "Sync every" minutes), so running this every minute is fine. Add the word "full"
 * to force a full sync.
 *
 * Command line (recommended), from any folder:
 *     php /path/to/rosariosis/plugins/<plugin folder>/cron.php
 * or from a web scheduler, once a web cron key is enabled in the plugin settings:
 *     https://your-rosariosis/plugins/<plugin folder>/cron.php?key=…
 *
 * @package Scholar plugin
 */

$scholar_cli = PHP_SAPI === 'cli';

// Run from the RosarioSIS root folder.
chdir( dirname( dirname( __DIR__ ) ) );

if ( $scholar_cli )
{
	// Minimal server variables RosarioSIS expects from a web request.
	$_SERVER += [
		'HTTP_HOST' => 'localhost',
		'SCRIPT_NAME' => '/plugins/' . basename( __DIR__ ) . '/cron.php',
		'PHP_SELF' => '/plugins/' . basename( __DIR__ ) . '/cron.php',
		'REQUEST_URI' => '/plugins/' . basename( __DIR__ ) . '/cron.php',
		'REMOTE_ADDR' => '127.0.0.1',
		'HTTP_USER_AGENT' => 'cron',
	];
}
elseif ( isset( $_GET['modfunc'] ) || isset( $_POST['modfunc'] ) )
{
	exit;
}

require_once 'Warehouse.php';

header( 'Content-Type: text/plain; charset=UTF-8' );

if ( empty( $RosarioPlugins[ basename( __DIR__ ) ] ) || ! function_exists( 'ScholarSyncAll' ) )
{
	http_response_code( 503 );

	exit( "The Scholar plugin is not activated.\n" );
}

if ( ! $scholar_cli )
{
	$key = ScholarSetting( 'CRON_KEY' );

	if ( $key === '' || ! isset( $_GET['key'] ) || ! hash_equals( $key, (string) $_GET['key'] ) )
	{
		http_response_code( 403 );

		exit( "Forbidden.\n" );
	}
}

if ( ! ScholarConfigured() )
{
	exit( "Scholar is not configured yet (address and token).\n" );
}

// Missed changes first (cheap), then the full sync when it is due.
$poll = ScholarPollChanges();

if ( $poll !== false && $poll['events'] )
{
	echo date( 'Y-m-d H:i:s' ) . ' Scholar changes: ' . $poll['events'] . ' change(s) in ' . $poll['courses'] .
		' course(s), ' . $poll['grades'] . ' grade(s) written, ' . $poll['errors'] . " error(s).\n";
}

$interval = max( 1, (int) ScholarSetting( 'SYNC_MINUTES' ) ) * 60;

if ( ! in_array( 'full', isset( $argv ) ? $argv : [], true )
	&& time() - (int) ScholarSetting( 'LAST_SYNC' ) < $interval
	&& (int) ScholarSetting( 'LAST_SYEAR' ) === ScholarDefaultSyear() )
{
	exit( $poll !== false && $poll['errors'] ? 1 : 0 );
}

$summary = ScholarSyncAll();

if ( $summary === false )
{
	exit( "Another sync is running.\n" );
}

echo date( 'Y-m-d H:i:s' ) . ' Scholar sync: ' . $summary['links'] . ' linked course period(s), ' .
	$summary['assignments'] . ' assignment(s) created, ' . $summary['grades'] . ' grade(s) written, ' .
	$summary['errors'] . " error(s).\n";

exit( $summary['errors'] ? 1 : 0 );
