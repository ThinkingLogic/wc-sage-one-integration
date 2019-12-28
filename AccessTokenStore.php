<?php
namespace ThinkingLogic;


use ThinkingLogicWCSage;

class AccessTokenStore {
	private $accessToken;
	private $expiresAt;
	private $refreshToken;
	private $refreshTokenExpiresIn;

	/**
	 * Returns the previously loaded access token
	 */
	public function getAccessToken()
	{
		return $this->accessToken;
	}

	/**
	 * Returns the previously loaded UNIX timestamp when the access token expires
	 */
	public function getExpiresAt()
	{
		return $this->expiresAt;
	}

	/**
	 * Returns the previously loaded refresh token
	 */
	public function getRefreshToken()
	{
		return $this->refreshToken;
	}

	/**
	 * Returns the UNIX timestamp when the refresh token expires
	 */
	public function getRefreshTokenExpiresAt()
	{
		return get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES_AT);
	}

	/**
	 * Updates the access token details, storing them using update_option.
	 *
	 * @param $accessToken
	 * @param $expiresAt
	 * @param $refreshToken
	 * @param $refreshTokenExpiresIn
	 */
	public function save($accessToken, $expiresAt, $refreshToken, $refreshTokenExpiresIn)
	{
		update_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN, $accessToken);
		update_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES, $expiresAt);
		update_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN, $refreshToken);
		update_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES, $refreshTokenExpiresIn);
		update_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES_AT, time() + $refreshTokenExpiresIn);
		$this->accessToken           = $accessToken;
		$this->expiresAt             = $expiresAt;
		$this->refreshToken          = $refreshToken;
		$this->refreshTokenExpiresIn = $refreshTokenExpiresIn;
	}

	/**
	 * Loads the data from WP using get_option. Returns true.
	 */
	public function load()
	{
		$this->accessToken           = get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN);
		$this->expiresAt             = get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES);
		$this->refreshToken          = get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN);
		$this->refreshTokenExpiresIn = get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES);

		return true;
	}
}
