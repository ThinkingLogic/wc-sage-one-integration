<?php
namespace ThinkingLogic;


use ThinkingLogicWCSage;

class AccessTokenStore {
	private $accessToken;
	private $expiresAt;
	private $refreshToken;
	private $refreshTokenExpiresAt;

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
	 * Returns the previously loaded UNIX timestamp when the refresh token expires
	 */
	public function getRefreshTokenExpiresAt()
	{
		return $this->refreshTokenExpiresAt;
	}

	/**
	 * Writes the data to the YAML file. Returns TRUE on success, otherwise FALSE
	 */
	public function save($accessToken, $expiresAt, $refreshToken, $refreshTokenExpiresIn)
	{
		update_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN, $accessToken);
		update_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES, $expiresAt);
		update_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN, $refreshToken);
		update_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES, $refreshTokenExpiresIn);
	}

	/**
	 * Loads the data from the YAML file. Returns TRUE on success, otherwise FALSE
	 */
	public function load()
	{

		$this->accessToken = get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN);
		$this->expiresAt = get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES);
		$this->refreshToken = get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN);
		$this->refreshTokenExpiresAt = get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES);

		return true;
	}
}
