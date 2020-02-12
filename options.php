<?php

use ThinkingLogic\Logger;

$sageone_client = false;
$response       = false;
$client         = false;

if (ThinkingLogicWCSage::hasClientDetails()) {
	$client = ThinkingLogicWCSage::instance();
	$sageone_client = ThinkingLogicWCSage::sageClient();
	$test_client = $_GET['test_client'];
	$test_endpoint = $_GET['test_endpoint'];
	if ($test_client) {
        $response = $client->listCustomers();
    } elseif ($test_endpoint) {
        $response = $client->makeGetRequest( urldecode( $test_endpoint ) );
    }
}

$refreshTokenExpires = get_option( ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES );

$base_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$test_url = $base_url . "$_SERVER[REQUEST_URI]";
$test_url = substr($test_url, 0, strpos($test_url, '?')) . '?page=tl-wc-sage-plugin-options'

?>
<div class="wrap">
<h1>ThinkingLogic WooCommerce / Sage One Integration</h1>
<form method="post" action="options.php"> 
    <?php settings_fields(      ThinkingLogicWCSage::OPTIONS_GROUP ); ?>
    <?php do_settings_sections( ThinkingLogicWCSage::OPTIONS_GROUP ); ?>
    <?php
        if (isset($client) && $sageone_client->isRefreshTokenExpiringSoon() ) {
            echo '<p><strong>Your Refresh Token expires soon - you should:<br/>';
	        echo '<a class="button" href="' . $sageone_client->authorizationEndpoint() .'">Refresh Authorisation</a>';
	        echo '</strong></p><hr/>';
        }
        if ( $response ) {
            echo '<h2>View the results of the call to Sage One at the bottom of the page</h2>';
        }
    ?>
    <p>You should have registered this as an application with <a href="https://developerselfservice.sageone.com/user">developerselfservice.sageone.com</a> (requires a github login) - once registered it will provide you with the Client ID and Client Secret to enter below.
        <br/>
        When registering the application with Sage, ensure to include a callback url of: <?php echo $base_url . ThinkingLogicWCSage::CALLBACK_REQUEST_PATH ?>
    </p>

    <table class="form-table">
        <tr valign="top">
        <th scope="row">Client ID</th>
        <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_CLIENT_ID ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_CLIENT_ID ) ); ?>" /></td>
        </tr>
         
        <tr valign="top">
        <th scope="row">Client Secret</th>
        <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_CLIENT_SECRET ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_CLIENT_SECRET ) ); ?>" /></td>
        </tr>

        <?php if($sageone_client) { ?>
            <tr valign="top">
            <th scope="row">Shipping Tax Rate id</th>
            <td>
                <input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_SHIPPING_TAX_ID ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_SHIPPING_TAX_ID, ThinkingLogicWCSage::DEFAULT_TAX_ID ) ); ?>" />
                <a class="button" href="<?php echo $test_url ?>&test_endpoint=/tax_rates" ?>List Tax Rates</a></td>
            </tr>

            <tr valign="top">
            <th scope="row">Line item Tax Rate id</th>
            <td>
                <input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_LINE_ITEM_TAX_ID ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_LINE_ITEM_TAX_ID, ThinkingLogicWCSage::DEFAULT_TAX_ID ) ); ?>" /></td>
            </tr>

            <tr valign="top">
            <th scope="row">Log API request/responses</th>
            <td>
                <input type="text" name="<?php echo Logger::OPTION_LOG_DEBUG ?>" value="<?php echo esc_attr( get_option(Logger::OPTION_LOG_DEBUG, 'false' ) ); ?>" />
                (true|false) for debugging - only takes effect if the WP_DEBUG and WP_DEBUG_LOG constants are set to true
            </td>
            </tr>

            <tr valign="top">
            <th scope="row">Access Token</th>
                <td>To obtain a refresh token and an access token, you must authenticate with Sage One and authorise this app by clicking <a href="<?php echo $sageone_client->authorizationEndpoint() ?>">here</a>.
                    <br/>
                    <input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_ACCESS_TOKEN ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN ) ); ?>" />
                </td>
            </tr>

            <tr valign="top">
            <th scope="row">Access Token Expires at</th>
            <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES ) ); ?>" />
                (<?php echo gmdate(ThinkingLogicWCSage::DATE_TIME_FORMAT, get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES )); ?>, Time now: <?php $now=new DateTime(); echo $now->format(ThinkingLogicWCSage::DATE_TIME_FORMAT); ?>)
            </td>
            </tr>

            <tr valign="top">
                <th scope="row">Refresh Token</th>
                <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_REFRESH_TOKEN ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN ) ); ?>" /></td>
            </tr>

            <tr valign="top">
                <th scope="row">Refresh Token Expires at</th>
                <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES_AT ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES_AT ) ); ?>" />
                    (<?php echo gmdate(ThinkingLogicWCSage::DATE_TIME_FORMAT, get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES_AT )); ?>, Time now: <?php $now=new DateTime(); echo $now->format(ThinkingLogicWCSage::DATE_TIME_FORMAT); ?>)
                </td>
            </tr>

        <?php } ?>
        <?php 
        $access_token = get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN );
        if($access_token) { 
            ?>
            <tr valign="top">
            <th scope="row">Test Sage connection</th>
            <td><a class="button" href="<?php echo $test_url ?>&test_client=true">List customers</a>
                <a class="button" href="<?php echo $test_url ?>&test_endpoint=/contact_types">List contact types</a>
                <a class="button" href="<?php echo $test_url ?>&test_endpoint=/contact_person_types">List contact person types</a>
                <a class="button" href="<?php echo $test_url ?>&test_endpoint=/ledger_accounts<?php echo urlencode('?items_per_page=100&attributes=nominal_code') ?>">List Ledger accounts</a>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row">(Advanced)</th>
            <td><input type="text" name="_tl_test_endpoint" id="_tl_test_endpoint"/><a class="button" id="tl_test_endpoint_button" onclick="testEndpoint()">Test this endpoint</a></td>
            </tr>
        <?php } ?>
 
        <tr valign="top">
        <th scope="row"></th>
        <td><?php submit_button(); ?></td>
        </tr>

        <?php if($response) { ?>
            <tr valign="top">
            <th scope="row">Response from Sage</th>
            <td><pre><?php $pretty_json = json_encode($response->getJSON(), JSON_PRETTY_PRINT); echo $pretty_json; ?></pre>
            </td>
            </tr>

        <?php } ?>
    </table>
    
</form>

</div>
<script language="javascript">
    function testEndpoint() {
        var loc = "<?php echo $test_url ?>";

        location.href = loc + "&test_endpoint=" + encodeURIComponent(document.getElementById('_tl_test_endpoint').value);
    }
</script>