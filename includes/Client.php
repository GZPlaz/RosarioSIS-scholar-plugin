<?php
/**
 * Minimal JSON client for Scholar's integration API (/api/v1).
 *
 * @package Scholar plugin
 */

class ScholarApiException extends Exception
{
	/** @var int HTTP status (0 when the server could not be reached). */
	public $status = 0;

	public function __construct( $message, $status = 0 )
	{
		parent::__construct( $message );

		$this->status = (int) $status;
	}
}

class ScholarClient
{
	/** @var string Base URL ending with /api/v1 */
	private $base;

	/** @var string Bearer token */
	private $token;

	public function __construct( $url, $token )
	{
		$url = rtrim( trim( (string) $url ), '/' );

		if ( substr( $url, -7 ) === '/api/v1' )
		{
			$url = substr( $url, 0, -7 );
		}

		$this->base = $url . '/api/v1';
		$this->token = trim( (string) $token );
	}

	public function get( $path )
	{
		return $this->request( 'GET', $path );
	}

	public function post( $path, $body )
	{
		return $this->request( 'POST', $path, $body );
	}

	public function patch( $path, $body )
	{
		return $this->request( 'PATCH', $path, $body );
	}

	public function put( $path, $body )
	{
		return $this->request( 'PUT', $path, $body );
	}

	/**
	 * @throws ScholarApiException On network errors and non-2xx responses.
	 *
	 * @return array Decoded JSON response.
	 */
	public function request( $method, $path, $body = null )
	{
		if ( ! function_exists( 'curl_init' ) )
		{
			throw new ScholarApiException( 'The PHP cURL extension is required.' );
		}

		$ch = curl_init( $this->base . '/' . ltrim( $path, '/' ) );

		$headers = [
			'Accept: application/json',
			'Authorization: Bearer ' . $this->token,
		];

		if ( $body !== null )
		{
			$headers[] = 'Content-Type: application/json';

			curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		}

		curl_setopt_array( $ch, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_USERAGENT => 'RosarioSIS-Scholar-plugin/' . SCHOLAR_PLUGIN_VERSION,
		] );

		$raw = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_error = curl_error( $ch );

		curl_close( $ch );

		if ( $raw === false )
		{
			throw new ScholarApiException( 'Scholar could not be reached: ' . $curl_error );
		}

		$data = json_decode( $raw, true );

		if ( $status >= 300 && $status < 400 )
		{
			throw new ScholarApiException(
				'Scholar redirected the request (HTTP ' . $status . '). Check the address, and that the YunoHost portal lets /api through.',
				$status
			);
		}

		if ( ! is_array( $data ) )
		{
			throw new ScholarApiException( 'Unexpected answer from Scholar (HTTP ' . $status . '). Is the address right?', $status );
		}

		if ( $status >= 400 )
		{
			$message = isset( $data['error'] ) ? $data['error'] : 'HTTP ' . $status;

			throw new ScholarApiException( 'Scholar: ' . $message, $status );
		}

		return $data;
	}
}
