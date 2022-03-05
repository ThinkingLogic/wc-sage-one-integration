<?php

namespace ThinkingLogic;

use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessTokenInterface;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/AccessTokenStore.php';
require_once __DIR__ . '/Logger.php';
include 'sage/api_response.php';

/**
 * Based on: https://raw.githubusercontent.com/Sage/sageone_api_php_sample/master/lib/api_client.php
 * at commit: https://github.com/Sage/sageone_api_php_sample/tree/f04bd7dd910aacc7b60bfc0f81da90ccfcddadc4
 * Class SageApiClient
 * @package SageAccounting
 *
 */
class SageApiClient {
	private $clientId;
	private $clientSecret;
	private $callbackUrl;
	private $oauthClient;
	private $accessTokenStore;
	private $generatedState;

	const BASE_ENDPOINT = "https://api.accounting.sage.com/v3.1";
	const AUTH_ENDPOINT = "https://www.sageone.com/oauth2/auth/central?filter=apiv3.1";
	const TOKEN_ENDPOINT = "https://oauth.accounting.sage.com/token";
	const SCOPE = "full_access";

	/**
	 * Constructor
	 *
	 * @param string $client_id Your application's client_id
	 * @param string $client_secret Your application's client_secret
	 * @param string $callback_url Your application's callback_url
	 */
	public function __construct( $client_id, $client_secret, $callback_url ) {
		$this->clientId     = $client_id;
		$this->clientSecret = $client_secret;
		$this->callbackUrl  = $callback_url;
		$this->generateRandomState();
		$this->oauthClient = new \League\OAuth2\Client\Provider\GenericProvider( [
			'clientId'                => $this->clientId,
			'clientSecret'            => $this->clientSecret,
			'redirectUri'             => $this->callbackUrl,
			'urlAuthorize'            => self::AUTH_ENDPOINT,
			'urlAccessToken'          => self::TOKEN_ENDPOINT,
			'urlResourceOwnerDetails' => '',
			'timeout'                 => 10
		] );
	}

	/**
	 * Returns the authorization endpoint with all required query params for
	 * making the auth request
	 */
	public function authorizationEndpoint() {
		return self::AUTH_ENDPOINT . "&response_type=code&client_id=" .
		       $this->clientId . "&redirect_uri=" . urlencode( $this->callbackUrl ) .
		       "&scope=" . self::SCOPE . "&state=" . $this->generatedState;
	}

	/* POST request to exchange the authorization code for an access_token */
	public function getInitialAccessToken( $code, $receivedState ) {
		$initialAccessToken = null;
		try {
			Logger::log( "About to getInitialAccessToken using clientId=" . $this->clientId . ", clientSecret=" . $this->clientSecret . ", callbackUrl=" . $this->callbackUrl );
			$initialAccessToken = $this->oauthClient->getAccessToken( 'authorization_code', [ 'code' => $code ] );

			return $this->storeAccessToken( $initialAccessToken );
		} catch ( \League\OAuth2\Client\Grant\Exception\InvalidGrantException $e ) {
			// authorization code was not found or is invalid
			Logger::log( "Unable to getInitialAccessToken - InvalidGrantException: " . $e->getMessage() );
			Logger::addAdminWarning( $e->getMessage() );
		} catch ( \League\OAuth2\Client\Provider\Exception\IdentityProviderException $e ) {
			// authorization code was not found or is invalid
			Logger::log( "Unable to getInitialAccessToken - IdentityProviderException: " . $e );
			Logger::addAdminWarning( $e->getMessage() );
		} catch ( \GuzzleHttp\Exception\ConnectException $e ) {
			// if no internet connection is available
			Logger::log( "Unable to getInitialAccessToken - ConnectException: " . $e->getMessage() );
			Logger::addAdminWarning( $e->getMessage() );
		} catch ( \UnexpectedValueException $e ) {
			// An OAuth server error was encountered that did not contain a JSON body
			Logger::log( "Unable to getInitialAccessToken - UnexpectedValueException: " . $e->getMessage() );
			Logger::addAdminWarning( $e->getMessage() );
		} catch ( \Exception $e ) {
			// general exception
			Logger::log( "Unable to getInitialAccessToken - Exception: " . $e . ", message: " . $e->getMessage() );
			Logger::addAdminWarning( $e->getMessage() );
		}

		return $initialAccessToken;
	}

	/* POST request to renew the access_token */
	public function renewAccessToken() {
		$newAccessToken = null;
		try {
			$newAccessToken = $this->oauthClient->getAccessToken( 'refresh_token', [ 'refresh_token' => $this->getRefreshToken() ] );
			Logger::log( "renewAccessToken: token renewed" );
			$this->storeAccessToken( $newAccessToken );
		} catch ( \League\OAuth2\Client\Grant\Exception\InvalidGrantException $e ) {
			// refresh token was not found or is invalid
			Logger::log( "Unable to renewAccessToken - InvalidGrantException: " . $e->getMessage() );
			$this->warnAccessTokenError( $e->getMessage() );
		} catch ( \League\OAuth2\Client\Provider\Exception\IdentityProviderException $e ) {
			// refresh token was not found or is invalid
			Logger::log( "Unable to renewAccessToken - IdentityProviderException: " . $e->getMessage() );
			$this->warnAccessTokenError( $e->getMessage() );
		} catch ( \GuzzleHttp\Exception\ConnectException $e ) {
			// if no internet connection is available
			Logger::log( "Unable to renewAccessToken - ConnectException: " . $e->getMessage() );
			$this->warnAccessTokenError( $e->getMessage() );
		} catch ( \Exception $e ) {
			// general exception
			Logger::log( "Unable to getInitialAccessToken - message: " . $e->getMessage() );
			$this->warnAccessTokenError( $e->getMessage() );
		} finally {
			return $newAccessToken;
		}
	}

	public function refreshTokenIfNecessary() {
		$expires = $this->getExpiresAt();
		if ( $expires ) {
			if ( time() >= $expires ) {
				$this->renewAccessToken();
			} else {
				Logger::log( "token does not require refreshing" );
			}
		} else {
			Logger::log( "Cannot renew token - no expires value found" );
		}
	}


	/**
	 * @param $resource
	 * @param $httpMethod
	 * @param null $postData
	 *
	 * @return \SageAccounting\ApiResponse
	 */
	public function execApiRequest( $resource, $httpMethod, $postData = null ) {
		$this->refreshTokenIfNecessary();
		$method                             = strtoupper( $httpMethod );
		$options['headers']['Content-Type'] = 'application/json';
		$requestResponse                    = new Response( 500 );

		if ( $postData && ( $method == 'POST' || $method == 'PUT' ) ) {
			$options['body'] = $postData;
		}

		try {
			$request = $this->oauthClient->getAuthenticatedRequest( $method, self::BASE_ENDPOINT . $resource, $this->getAccessToken(), $options );

			$startTime       = microtime( 1 );
			$requestResponse = $this->oauthClient->getResponse( $request );

		} catch ( \GuzzleHttp\Exception\ClientException|\GuzzleHttp\Exception\ServerException $e ) {
			// catch all 4xx errors
			Logger::log( "Caught " . get_class( $e ) . " making " . $httpMethod . " request to " . $resource . ": " . $e->getMessage() );
			$requestResponse = $e->getResponse();
			Logger::addAdminWarning( $e->getMessage() );
		} catch ( \GuzzleHttp\Exception\ConnectException|Exception $e ) {
			// general exception
			Logger::log( "Caught " . get_class( $e ) . " making " . $httpMethod . " request to " . $resource . ": " . $e->getMessage() );
			Logger::addAdminWarning( $e->getMessage() );
		} finally {
			$endTime      = microtime( 1 );
			$api_response = new \SageAccounting\ApiResponse( $requestResponse, $endTime - $startTime );
			Logger::debug( 'Made ' . $httpMethod . ' request to ' . $resource . ' with request body=' . $postData . ', response: ' . $api_response->getBody() );

			return $api_response;
		}
	}

	/**
	 * Returns the access token
	 */
	public function getAccessToken() {
		return $this->getAccessTokenStore()->getAccessToken();
	}

	/**
	 * Returns the UNIX timestamp when the access token expires
	 */
	public function getExpiresAt() {
		return $this->getAccessTokenStore()->getExpiresAt();
	}

	/**
	 * Returns the refresh token
	 */
	public function getRefreshToken() {
		return $this->getAccessTokenStore()->getRefreshToken();
	}

	/**
	 * Returns the UNIX timestamp when the refresh token expires
	 */
	public function getRefreshTokenExpiresAt() {
		return $this->getAccessTokenStore()->getRefreshTokenExpiresAt();
	}

	/**
	 * @return true if the refresh token will expire within the next hour
	 */
	public function isRefreshTokenExpiringSoon() {
		$expires_at = $this->getRefreshTokenExpiresAt();

		return empty( $expires_at ) || $expires_at < ( time() + ( 60 * 60 ) );
	}

	public function getAccessTokenStore() {
		if ( $this->accessTokenStore ) {
			return $this->accessTokenStore;
		}

		$this->accessTokenStore = new AccessTokenStore();
		if ( ! $this->accessTokenStore->load() ) {
			$this->accessTokenStore = null;
		}

		return $this->accessTokenStore;
	}

	// Private area

	private function storeAccessToken( AccessTokenInterface $response ) {
		if ( ! $this->accessTokenStore ) {
			$this->accessTokenStore = new AccessTokenStore();
		}

		$this->accessTokenStore->save(
			$response->getToken(),
			$response->getExpires(),
			$response->getRefreshToken(),
			$response->getValues()["refresh_token_expires_in"]
		);

		Logger::log( "Stored access token as " . substr( $response->getToken(), 0, 5 ) . "..." );

		return $response;
	}

	private function generateRandomState() {
		$include_chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";

		$charLength   = strlen( $include_chars );
		$randomString = '';
		// length of 30
		for ( $i = 0; $i < 30; $i ++ ) {
			$randomString .= $include_chars [ rand( 0, $charLength - 1 ) ];
		}
		$this->generatedState = $randomString;
	}

	private function warnAccessTokenError( $message ) {
		Logger::addAdminWarning( $message );
		Logger::addAdminNotice( '<a class="button" href="' . $this->authorizationEndpoint() . '">Refresh Authorisation</a>' );
	}

}
