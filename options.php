<?php

$sageone_client = false;
$response = false;

if (ThinkingLogicWCSage::hasClientDetails()) {
	$sageone_client = ThinkingLogicWCSage::sageClient();
    $test_client = $_GET['test_client'];
    $list_tax_rates = $_GET['list_tax_rates'];
    $test_endpoint = $_GET['test_endpoint'];
    if ($test_client) {
        $client = ThinkingLogicWCSage::instance();
        $response = $client->listCustomers();
    } elseif ($list_tax_rates) {
        $client = ThinkingLogicWCSage::instance();
        $response = $client->listTaxRates();
    } elseif ($test_endpoint) {
        $client = ThinkingLogicWCSage::instance();
        $response = $client->makeGetRequest($test_endpoint);
    }
}

?>
<div class="wrap">
<h1>ThinkingLogic WooCommerce / Sage One Integration</h1>
<form method="post" action="options.php"> 
    <?php settings_fields(      ThinkingLogicWCSage::OPTIONS_GROUP ); ?>
    <?php do_settings_sections( ThinkingLogicWCSage::OPTIONS_GROUP ); ?>
    <p>You will need to register this as an application with <a href="https://developers.sageone.com/applications/">developers.sageone.com</a> (requires a github login) - once registered it will provide you with the Client ID, Client Secret and Signing Secret to enter below. You can use the url of this page as the callback url.</p>
    <p>The Subscription Key can be found in the <a href="https://developer.columbus.sage.com/developer">Sage developer profile</a>.</p>
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
            <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES ) ); ?>" /> (Time now: <?php $now=new DateTime(); echo $now->format(ThinkingLogicWCSage::DATE_TIME_FORMAT); ?>)</td>
            </tr>

            <tr valign="top">
                <th scope="row">Refresh Token</th>
                <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_REFRESH_TOKEN ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN ) ); ?>" /></td>
            </tr>

            <tr valign="top">
                <th scope="row">Refresh Token Expires at</th>
                <td><input type="text" name="<?php echo ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES ?>" value="<?php echo esc_attr( get_option(ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES ) ); ?>" /></td>
            </tr>

        <?php } ?>
        <?php 
        $access_token = get_option(ThinkingLogicWCSage::OPTION_ACCESS_TOKEN );
        if($access_token) { 
            $test_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]" . "&test_client=";
            ?>
            <tr valign="top">
            <th scope="row"></th>
            <td><a class="button" href="<?php echo $test_url ?>true">Test Sage connection by listing customers</a>
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
            <td><pre><?php $json = json_decode($response); $pretty_json = json_encode($json, JSON_PRETTY_PRINT); echo $pretty_json; ?></pre>
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