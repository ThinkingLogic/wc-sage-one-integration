<?php

$sageone_client = false;
$response = false;
$client = false;

if (ThinkingLogicWCSage::hasClientDetails()) {
	$client = ThinkingLogicWCSage::instance();
	$sageone_client = ThinkingLogicWCSage::sageClient();
	$test_client = $_GET['test_client'];
	$list_tax_rates = $_GET['list_tax_rates'];
	$list_contact_types = $_GET['list_contact_types'];
	$test_endpoint = $_GET['test_endpoint'];
	if ($test_client) {
        $response = $client->listCustomers();
    } elseif ($list_tax_rates) {
        $response = $client->listTaxRates();
    } elseif ($list_contact_types) {
		$response = $client->makeGetRequest('/contact_types');
    } elseif ($test_endpoint) {
        $response = $client->makeGetRequest($test_endpoint);
    }
}

$refreshTokenExpires = get_option( ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES );

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
    ?>
    <p>You should have registered this as an application with <a href="https://developers.sageone.com/applications/">developers.sageone.com</a> (requires a github login) - once registered it will provide you with the Client ID and Client Secret to enter below.</p>
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
            <th scope="row">Carriage Tax Rate id</th>
            <td>
                <input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_CARRIAGE_TAX_ID ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_CARRIAGE_TAX_ID, '5' ) ); ?>" /> <a class="button" href="<?php echo (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]" . "&list_tax_rates=true" ?>">List Tax Rates</a></td>
            </tr>

            <tr valign="top">
            <th scope="row">Line item Tax Rate id</th>
            <td>
                <input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_LINE_ITEM_TAX_ID ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_LINE_ITEM_TAX_ID, '5' ) ); ?>" /></td>
            </tr>

            <tr valign="top">
            <th scope="row">Access Token</th>
                <td>To obtain a refresh token and an access token, you must authenticate with Sage One and authorise this app by clicking <a href="<?php echo $sageone_client->authorizationEndpoint() ?>">here</a>.
                    <br/>
                    <input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_ACCESS_TOKEN ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN ) ); ?>" />
                </td>
            </tr>

            <tr valign="top">
            <th scope="row">Expires at</th>
            <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES ) ); ?>" /> (<?php echo gmdate(ThinkingLogicWCSage::DATE_TIME_FORMAT, get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES )); ?>, Time now: <?php $now=new DateTime(); echo $now->format(ThinkingLogicWCSage::DATE_TIME_FORMAT); ?>)</td>
            </tr>

            <tr valign="top">
                <th scope="row">Refresh Token</th>
                <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_REFRESH_TOKEN ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN ) ); ?>" /></td>
            </tr>

            <tr valign="top">
                <th scope="row">Refresh Token Expires in (seconds)</th>
                <td>
                    <input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES ?>" value="<?php echo esc_attr( $refreshTokenExpires ); ?>" />
                    (<?php echo number_format ( $refreshTokenExpires / ( 60 * 60) ); ?> hours
                    = <?php echo number_format ( $refreshTokenExpires / ( 60 * 60 * 24) ); ?> days)
                </td>
            </tr>

        <?php } ?>
        <?php 
        $access_token = get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN );
        if($access_token) { 
            $test_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
            ?>
            <tr valign="top">
            <th scope="row"></th>
            <td><a class="button" href="<?php echo $test_url ?>&test_client=true">Test Sage connection by listing customers</a>
                <a class="button" href="<?php echo $test_url ?>&list_contact_types=true">List contact types</a>
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
        var loc = location.href;        
        loc += loc.indexOf("?") === -1 ? "?" : "&";

        location.href = loc + "test_endpoint=" + document.getElementById('_tl_test_endpoint').value;
    }
</script>