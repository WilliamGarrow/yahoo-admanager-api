<?php

$appRoot = dirname(__FILE__).'<YOUR PATH>';
require "YahooOAuth2.class.php";
require_once $appRoot.'config.php';
require_once $appRoot.'load.php';
global $userdb;

define('CONSUMER_KEY', '<YOUR CONSUMER KEY>');
define('CONSUMER_SECRET', '<YOUR CONSUMER SECRET>');

$redirect_uri = "http://" . $_SERVER['SERVER_NAME'] . $_SERVER['PHP_SELF'];  // Or your other redirect URL - must match the callback domain
$gemini_api_endpoint = "https://api.admanager.yahoo.com/v2/rest";
$oauth2client = new YahooOAuth2();

$code = $_GET['code'];

if ($code || is_file(dirname(__FILE__).'/yahoo_refresh.txt')) {
    #oAuth 3-legged authorization is successful, fetch access token
    $token = $oauth2client->get_access_token(CONSUMER_KEY, CONSUMER_SECRET, $redirect_uri, $code);

    // access token is available. Do API calls.
    $headers = array(
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    );

    //Fetch Advertiser Name and Advertiser ID
    $url = $gemini_api_endpoint . "/reports/custom/";

    $postdata = new stdClass();
    $postdata->cube = "performance_stats";

    $fieldDay = new stdClass();
    $fieldDay->field = "Day";

    $fieldCampaignId = new stdClass();
    $fieldCampaignId->field = "Campaign ID";

    $fieldSpend = new stdClass();
    $fieldSpend->field = "Spend";

    $postdata->fields = [
        $fieldDay,
        $fieldCampaignId,
        $fieldSpend,
    ];

    $filterAdvertiserId = new stdClass();
    $filterAdvertiserId->field = "Advertiser ID";
    $filterAdvertiserId->operator = '=';
    $filterAdvertiserId->value = <YOUR ADVERTISER ID>;

    $filterDay = new stdClass();
    $filterDay->field = "Day";
    $filterDay->operator = "between";

    $filterDay->from = date('Y-m-d', strtotime('2016-07-05'));
    $filterDay->to = date('Y-m-d', strtotime('2017-12-01'));

    $postdata->filters = [
        $filterAdvertiserId,
        $filterDay
    ];

    $resp = $oauth2client->fetchV2($url, $postdata, "", $headers);
    $jsonResponse = json_decode($resp);

    // var_dump($jsonResponse);
    // exit();

    if($jsonResponse->response && $jsonResponse->response->jobId){

        $url = $gemini_api_endpoint."/reports/custom/".$jsonResponse->response->jobId."?advertiserId=<YOUR ADVERTISER ID>";
        $resp = json_decode($oauth2client->fetchV2($url, "", "", $headers));
        $attempts = 1;
        while($resp->response->status != "completed"){
            if($attempts > 30){
                exit('Failed to download...');
            }
            sleep(5);
            $resp = json_decode($oauth2client->fetchV2($url, "", "", $headers));
            $attempts++;
            flush();
            ob_flush();
        }

        if (($file = fopen($resp->response->jobResponse, 'r')) !== false) {
            $data = fgetcsv($file, 1000); // skips the header row
            while(($data = fgetcsv($file, 1000)) !== false) {

                $insertData = array(
                    'spend_day' => $data[0],
                    'spend' => $data[2],
                    'campaign_id' => $data[1],
                    'campaign_name' => 'Campaign Name',
                    'channel' => 'Yahoo',
                    'lead_source' => 'Yahoo Paid Lead',
                    'affiliate' => '<YOUR AFFILIATE INFO>',
                );

                if( $insertData['clicks'] > 0 || $insertData['spend'] > 0) {
                    $userdb->insert('daily_ad_spend', $insertData);
                }
            }
            fclose($file);
        }
        $sql = "SELECT `campaign_id` FROM `daily_ad_spend` WHERE `campaign_name` = 'campaign name'";
        $results = $userdb->get_results( $sql );
        if(is_array($results)){
            foreach ($results as $row){
                $campaign_name = get_campaign_name($oauth2client, $headers, $row->campaign_id);
                $campaign_name = sanitize_text_field($campaign_name);
                $sql = "UPDATE `daily_ad_spend` SET `campaign_name` = '{$campaign_name}' WHERE `campaign_id` = '{$row->campaign_id}'";
                $userdb->query($sql);
            }
        }
    }

} else {
    header("HTTP/1.1 302 Found");
    header("Location: " . $oauth2client->getAuthorizationURL(CONSUMER_KEY, $redirect_uri));
    exit;
}

function get_campaign_name($oauth2client, $headers, $campaignId){
    $gemini_api_endpoint = "https://api.admanager.yahoo.com/v2/rest/campaign/$campaignId";
    $json = $oauth2client->fetchV2($gemini_api_endpoint, "", "", $headers);
    $json = json_decode($json);
    return ($json->response->campaignName);
}
