<?php
/**
 * Scholar plugin: scheduled sync.
 *
 * Command line (recommended), from any folder:
 *     php /path/to/rosariosis/plugins/Scholar/cron.php
 * or from a web scheduler, once a web cron key is enabled in the plugin settings:
 *     https://your-rosariosis/plugins/Scholar/cron.php?key=…
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
		'SCRIPT_NAME' => '/plugins/Scholar/cron.php',
		'PHP_SELF' => '/plugins/Scholar/cron.php',
		'REQUEST_URI' => '/plugins/Scholar/cron.php',
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

if ( empty( $RosarioPlugins['Scholar'] ) || ! function_exists( 'ScholarSyncAll' ) )
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

$summary = ScholarSyncAll();

if ( $summary === false )
{
	exit( "Another sync is running.\n" );
}

echo date( 'Y-m-d H:i:s' ) . ' Scholar sync: ' . $summary['links'] . ' linked course period(s), ' .
	$summary['assignments'] . ' assignment(s) created, ' . $summary['grades'] . ' grade(s) written, ' .
	$summary['errors'] . " error(s).\n";

exit( $summary['errors'] ? 1 : 0 );
