<?php
/**
 * Plugin settings, client and log.
 *
 * Settings are stored in the `config` table with SCHOOL_ID=0 (shared by every school and
 * every school year), under titles prefixed with SCHOLAR_.
 *
 * @package Scholar plugin
 */

/**
 * Get or set a plugin setting.
 *
 * @param string      $key   Setting name, without the SCHOLAR_ prefix.
 * @param string|null $value Raw (unescaped) value to store, or null to read.
 *
 * @return string Current value (default when not set).
 */
function ScholarSetting( $key, $value = null )
{
	static $cache = [];

	$defaults = [
		'URL' => '',
		'TOKEN' => '',
		'ACCOUNT_TYPE' => 'ldap', // ldap = YunoHost accounts; local = Scholar passwords
		'AUTO_LINK' => '', // Y = create & link every new course period
		'MATCH_EXISTING' => 'Y', // Y = link course periods to Scholar courses that already exist for them
		'CARRY_OVER' => 'Y', // Y = at a new default school year, link rolled-over course periods again
		'DEACTIVATE_LEAVERS' => 'Y', // Y = at a new default school year, deactivate students who left
		'SYNC_MINUTES' => '15',
		'CATEGORY' => 'Scholar tests',
		'ID_PREFIX' => 'rosario',
		'LAST_SYNC' => '0',
		'LAST_SYEAR' => '',
		'CRON_KEY' => '',
		'CALLBACK_URL' => '', // address Scholar calls instead of this site's own webhook.php
		'WEBHOOK_SECRET' => '', // shared with Scholar, signs its requests
		'WEBHOOK_URL' => '',
		'LAST_EVENT' => '', // newest Scholar change handled by polling
	];

	$title = 'SCHOLAR_' . $key;

	if ( $value !== null )
	{
		$exists = DBGetOne( "SELECT 1 FROM config WHERE TITLE='" . DBEscapeString( $title ) . "' AND SCHOOL_ID='0'" );

		DBUpsert(
			'config',
			[ 'CONFIG_VALUE' => DBEscapeString( (string) $value ) ],
			[ 'TITLE' => DBEscapeString( $title ), 'SCHOOL_ID' => 0 ],
			$exists ? 'update' : 'insert'
		);

		$cache[ $key ] = (string) $value;

		return $cache[ $key ];
	}

	if ( ! array_key_exists( $key, $cache ) )
	{
		$stored = DBGetOne( "SELECT CONFIG_VALUE FROM config WHERE TITLE='" . DBEscapeString( $title ) . "' AND SCHOOL_ID='0'" );

		$cache[ $key ] = ( $stored === false || $stored === null || $stored === '' ) && isset( $defaults[ $key ] ) ?
			$defaults[ $key ] : (string) $stored;
	}

	return $cache[ $key ];
}

/**
 * @return bool True when the Scholar address and token are set.
 */
function ScholarConfigured()
{
	return ScholarSetting( 'URL' ) !== '' && ScholarSetting( 'TOKEN' ) !== '';
}

/**
 * @return ScholarClient
 */
function ScholarApi()
{
	return new ScholarClient( ScholarSetting( 'URL' ), ScholarSetting( 'TOKEN' ) );
}

/**
 * Prefix of the external ids written to Scholar, e.g. "rosario" → "rosario:student:42".
 */
function ScholarIdPrefix()
{
	$prefix = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', ScholarSetting( 'ID_PREFIX' ) ) );

	return $prefix !== '' ? $prefix : 'rosario';
}

/**
 * Add a line to the plugin log (shown on the plugin configuration page).
 *
 * @param string $level   info|warning|error
 * @param string $message Plain text.
 */
function ScholarLog( $level, $message )
{
	DBInsert( 'scholar_log', [
		'LEVEL' => DBEscapeString( $level ),
		'MESSAGE' => DBEscapeString( mb_substr( $message, 0, 2000 ) ),
	] );

	// Keep the log small.
	if ( mt_rand( 1, 50 ) === 1 )
	{
		$keep_from = DBGetOne( "SELECT ID FROM scholar_log ORDER BY ID DESC LIMIT 1 OFFSET 999" );

		if ( $keep_from )
		{
			DBQuery( "DELETE FROM scholar_log WHERE ID<'" . (int) $keep_from . "'" );
		}
	}
}

/**
 * Turn any text into a username Scholar accepts (letters, digits, . _ - @).
 */
function ScholarUsername( $username, $fallback )
{
	$username = trim( (string) $username );
	$username = preg_replace( '/[^A-Za-z0-9._@-]+/', '.', $username );
	$username = trim( $username, '.' );

	return $username !== '' ? mb_substr( $username, 0, 120 ) : $fallback;
}

/**
 * Default school year, read fresh on every request.
 *
 * The $DefaultSyear global comes from config.inc.php (RosarioSIS rewrites it when an admin
 * clicks "OK, I am ready" after a rollover). $_SESSION['DefaultSyear'] is only a copy made at
 * login, so it stays on the old year for anyone already logged in: use it as a last resort.
 */
function ScholarDefaultSyear()
{
	global $DefaultSyear;

	if ( ! empty( $DefaultSyear ) )
	{
		return (int) $DefaultSyear;
	}

	if ( getenv( 'ROSARIOSIS_YEAR' ) )
	{
		return (int) getenv( 'ROSARIOSIS_YEAR' );
	}

	return empty( $_SESSION['DefaultSyear'] ) ? 0 : (int) $_SESSION['DefaultSyear'];
}
