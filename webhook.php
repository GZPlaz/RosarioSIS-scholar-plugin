<?php
/**
 * Scholar plugin: where Scholar reports changes.
 *
 * Scholar sends a signed POST here the moment grades change (a test is submitted or graded, an
 * answer key is corrected, a score is overridden…). The request is checked against the shared
 * secret, then the plugin copies that course's grades into the gradebook before answering.
 * Anything else (a browser visit, a wrong signature) is refused.
 *
 * @package Scholar plugin
 */

// Run from the RosarioSIS root folder.
chdir( dirname( dirname( __DIR__ ) ) );

if ( isset( $_GET['modfunc'] ) || isset( $_POST['modfunc'] ) )
{
	exit;
}

require_once 'Warehouse.php';

header( 'Content-Type: application/json; charset=UTF-8' );
header( 'Cache-Control: no-store' );

/**
 * Answer and stop.
 */
function scholar_webhook_reply( $status, $data )
{
	http_response_code( $status );

	echo json_encode( $data );

	exit;
}

if ( empty( $RosarioPlugins[ basename( __DIR__ ) ] ) || ! function_exists( 'ScholarHandleChange' ) )
{
	scholar_webhook_reply( 503, [ 'ok' => false, 'error' => 'The Scholar plugin is not activated.' ] );
}

if ( ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) !== 'POST' )
{
	scholar_webhook_reply( 405, [ 'ok' => false, 'error' => 'POST only.' ] );
}

$body = file_get_contents( 'php://input', false, null, 0, 65537 );

if ( $body === false || strlen( $body ) > 65536 )
{
	scholar_webhook_reply( 413, [ 'ok' => false, 'error' => 'Request too large.' ] );
}

$refused = ScholarVerifyRequest( $body );

if ( $refused !== '' )
{
	// Log at most one refusal per minute: a wrong secret must not fill the log.
	if ( time() - (int) ScholarSetting( 'LAST_REFUSED' ) > 60 )
	{
		ScholarSetting( 'LAST_REFUSED', (string) time() );

		ScholarLog( 'warning', 'A request to the Scholar webhook was refused: ' . $refused . '. Click "Connect instant updates" in the plugin settings to share a new secret.' );
	}

	scholar_webhook_reply( 401, [ 'ok' => false, 'error' => 'Refused: ' . $refused ] );
}

$event = json_decode( $body, true );

if ( ! is_array( $event ) || empty( $event['event'] ) )
{
	scholar_webhook_reply( 400, [ 'ok' => false, 'error' => 'Unreadable request.' ] );
}

ScholarSetting( 'LAST_PUSH', (string) time() );

if ( $event['event'] === 'ping' )
{
	scholar_webhook_reply( 200, [ 'ok' => true, 'pong' => true ] );
}

if ( $event['event'] !== 'grades.changed' || empty( $event['course']['external_id'] ) )
{
	scholar_webhook_reply( 200, [ 'ok' => true, 'ignored' => true ] );
}

ignore_user_abort( true );
@set_time_limit( 120 );

try
{
	$stats = ScholarHandleChange(
		(string) $event['course']['external_id'],
		isset( $event['event_id'] ) ? (int) $event['event_id'] : 0
	);
}
catch ( ScholarApiException $e )
{
	ScholarLog( 'error', 'Update from Scholar for ' . $event['course']['external_id'] . ': ' . $e->getMessage() );

	// Not a 2xx answer: Scholar will send it again.
	scholar_webhook_reply( 502, [ 'ok' => false, 'error' => $e->getMessage() ] );
}
catch ( Throwable $e )
{
	ScholarLog( 'error', 'Update from Scholar failed: ' . $e->getMessage() );

	scholar_webhook_reply( 500, [ 'ok' => false, 'error' => 'The plugin failed to copy the grades; see its log.' ] );
}

if ( $stats === false )
{
	// Not a course linked here (or busy for over 30 s): nothing to copy now. The periodic sync covers a busy one.
	scholar_webhook_reply( 200, [ 'ok' => true, 'ignored' => true ] );
}

scholar_webhook_reply( 200, [
	'ok' => true,
	'assignments' => (int) $stats['assignments'],
	'grades' => (int) $stats['grades'],
	'kept' => (int) $stats['kept'],
] );
