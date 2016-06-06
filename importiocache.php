<?php
//This is just an import of updateListings.php from my HawaiiInformationServiceSearch project since the code is all here - it just needs ot be isolated
require_once('config/config.php');
require_once('libs/phputils/php/phputils.class.php');

set_time_limit(0); //This is a long script, usually takes ~10min depending on database size of HIIS
/**
 * Generates a 'search' URL for AlohaLiving.
 *
 * @param $realOrBase //Type of url to generate. Real is the actual URL. Base is the import.io URL
 * @param $pageNumber //This is the page number you want to get. Set to 1 if you want the first results
 * @param $islandNumber
 * @param $district
 * @return bool|string //Will return URL or false if $realOrBase is invalid
 */
function createSearchUrl($realOrBase, $pageNumber, $islandNumber, $district) {
    global $config_importio_hiis_GUID_search;
    global $config_importio_apikey;
    if($realOrBase == 'real') {
        $Url = 'http://www.alohaliving.com/search/?page='. $pageNumber .'&ipp=100&island='. $islandNumber .'&District='. $district .'&minprice=0&maxprice=9999999999999&minbeds=0&minbaths=0';
    }else if($realOrBase == 'base') {
        $Url = 'https://extraction.import.io/query/extractor/'. $config_importio_hiis_GUID_search .'?_apikey='. $config_importio_apikey .'&url=http%3A%2F%2Fwww.alohaliving.com%2Fsearch%2F%3Fpage%3D'. $pageNumber .'%26ipp%3D100%26island%3D'. $islandNumber .'%26District%3D'. $district .'%26minprice%3D0%26maxprice%3D9999999999999%26minbeds%3D0%26minbaths%3D0';
    }else {
        return false;
    }
    return $Url;
}
/**
 * Generates a 'listing' URL for AlohaLiving.
 *
 * @param $realOrBase //Type of url to generate. Real is the actual URL. Base is the import.io URL
 * @param $listingId //This would be the MLS
 * @return bool|string //Will return URL or false if $realOrBase is invalid
 */
function createListingUrl($realOrBase, $listingId) {
    global $config_importio_hiis_GUID_listing;
    global $config_importio_apikey;
    if($realOrBase == 'real') {
        $Url = 'http://www.alohaliving.com/search/details/?linkmlsnum=' . $listingId;
    }else if($realOrBase == 'base') {
        $Url = 'https://extraction.import.io/query/extractor/'. $config_importio_hiis_GUID_listing .'?_apikey='. $config_importio_apikey .'&url=http%3A%2F%2Fwww.alohaliving.com%2Fsearch%2Fdetails%2F%3Flinkmlsnum%3D' . $listingId;
    }else {
        return false;
    }
    return $Url;
}
/**
 * Use this function for running queries to ImportIO. Will be useful later in tracking remaining amount of API requests on the "client".
 *
 * @param $url //URL shall be in base (import.io) form
 * @param bool $returnChecksum //True to return checksum of page in array, false to just return the array from import.io
 * @return array //Returns the contents of ImportIO, with checksum if requested
 */
function importIOQuery($url, $returnChecksum = false) {
    $pageData = file_get_contents($url);
    if($returnChecksum) {
        return array(json_decode($pageData),'checksum' => md5($pageData));
    }
    return json_decode($pageData);
}
/**
 * Check if a URL is cached yet. If it is not, it will add it to the database cache by default.
 *
 * @param $url //URL you want to check to see if it's cached
 * @param $searchOrListing //Either 'search' or 'listing'. Will tell it what type of URL you're checking
 * @param bool $addToDatabase //If you want this check to be added to the database if it's not already cached
 * @return bool|string //Will return false if it was not previous cached, or the cache was outdated
 */
function checkUrl($url, $searchOrListing, $addToDatabase = true) {
    global $client;
    global $con;
    global $config_importio_apikey;
    global $config_importio_hiis_GUID_search;
    global $config_importio_hiis_GUID_listing;
    $pageData = file_get_contents($url);
    //Stripping ad data that keeps changing on page reload. Everything from featuredlistor, to modal fade
    $pageDataTempArray = explode('featuredlistor',$pageData);
    $pageData = $pageDataTempArray[0] . substr($pageDataTempArray[count($pageDataTempArray)-1],strpos($pageDataTempArray[count($pageDataTempArray)-1],'modal fade'));
    $checksum = md5($pageData);
    if($searchOrListing == 'search') {
        $result = query("SELECT id FROM real_estate_app.listings_extractor_log WHERE realChecksum = '" . mysqli_real_escape_string($con,$checksum) . "' AND realUrl = '" . mysqli_real_escape_string($con,$url) . "' LIMIT 1;");
    }else if($searchOrListing == 'listing') {
        $result = query("SELECT id FROM real_estate_app.listings WHERE checksum = '" . mysqli_real_escape_string($con,$checksum) . "' AND url = '" . mysqli_real_escape_string($con,$url) . "' LIMIT 1;");
    }
    if($result) {
        return true;
    }else {
        if($searchOrListing == 'search') {
            query("DELETE FROM `real_estate_app`.`listings_extractor_log` WHERE `realUrl`='". mysqli_real_escape_string($con,$url)."';");
            if($addToDatabase) {
                $importUrl = 'https://extraction.import.io/query/extractor/'. $config_importio_hiis_GUID_search .'?_apikey='. $config_importio_apikey .'&url='. urlencode($url);
                $result = importIOQuery($importUrl,true);
                $listing_log = array(
                    'resourceId' => $result[0]->extractorData->resourceId,
                    'url' => $importUrl,
                    'searchTerms' => 'TBD',
                    'checksum' => $result['checksum'],
                    'data' => json_encode($result[0]),
                    'realUrl' => $url,
                    'realChecksum' => $checksum,
                    'realData' => $pageData,
                );
                query("INSERT INTO `real_estate_app`.`listings_extractor_log` (`resourceId`, `url`, `searchTerms`, `checksum`, `data`, `realUrl`, `realChecksum`, `realData`) VALUES ('" . mysqli_real_escape_string($con,$result[0]->extractorData->resourceId) . "', '" . mysqli_real_escape_string($con,$importUrl) . "', '', '" . mysqli_real_escape_string($con,$result['checksum']) . "', '" . mysqli_real_escape_string($con,json_encode($result[0])) . "', '" . mysqli_real_escape_string($con,$url) . "', '" . mysqli_real_escape_string($con,$checksum) . "', '" . mysqli_real_escape_string($con,$pageData) . "');");
            }
            return false;
        }else if($searchOrListing == 'listing') {
            query("DELETE FROM `real_estate_app`.`listings` WHERE `url`='". mysqli_real_escape_string($con,$url)."';");
            if($addToDatabase) {
                $importUrl = 'https://extraction.import.io/query/extractor/' . $config_importio_hiis_GUID_listing . '?_apikey=' . $config_importio_apikey . '&url=' . urlencode($url);
                $result = importIOQuery($importUrl,true);
                var_dump($result[0]->extractorData->data[0]->group[0]);
                //Oceanfront code string to bit
                $oceanfront = 0;
                if(strtolower($result[0]->extractorData->data[0]->group[0]->Oceanfront[0]->text) == 'yes') {
                    $oceanfront = 1;
                }else if(strtolower($result[0]->extractorData->data[0]->group[0]->Oceanfront[0]->text) != 'no') {
                    ChromePhp::warn("WARNING: Oceanfront's value has changed from Yes to No. Here's what it is now: " . $result[0]->extractorData->data[0]->group[0]->Oceanfront[0]->text);
                }
                $listing = array(
                    'listing_id' => $result[0]->extractorData->data[0]->group[0]->MLS[0]->text,
                    'url' => $result[0]->extractorData->url,
                    'resource_id' => $result[0]->extractorData->resourceId,
                    'address' => $result[0]->extractorData->data[0]->group[0]->Address[0]->text,
                    'price' => toFloat($result[0]->extractorData->data[0]->group[0]->Price[0]->text),
                    'bedrooms' => intval($result[0]->extractorData->data[0]->group[0]->Bedrooms[0]->text),
                    'mls' => $result[0]->extractorData->data[0]->group[0]->MLS[0]->text,
                    'price/sqft' => toFloat($result[0]->extractorData->data[0]->group[0]->Price->sqft[0]->text),
                    'interior_area_size' => $result[0]->extractorData->data[0]->group[0]->{'Interior Area'}[0]->text,
                    'year_built' => intval($result[0]->extractorData->data[0]->group[0]->{'Year Built'}[0]->text),
                    'lot_size' => $result[0]->extractorData->data[0]->group[0]->{'Lot Size'}[0]->text,
                    'land_tenure' => $result[0]->extractorData->data[0]->group[0]->{'Land Tenure'}[0]->text,
                    'on_market_since' => $result[0]->extractorData->data[0]->group[0]->{'On Market Since'}[0]->text,
                    'last_updated' => $result[0]->extractorData->data[0]->group[0]->{'Last Updated'}[0]->text,
                    'property_type' => $result[0]->extractorData->data[0]->group[0]->{'Property Type'}[0]->text,
                    'oceanfront' => $oceanfront,
                    'description' => $result[0]->extractorData->data[0]->group[0]->{'Listing Remarks'}[0]->text,
                    'misc_data' => 'N/A',
                    'statusCode' => $result[0]->pageData->statusCode,
                    'checksum' => $result['checksum']
                );
                query("INSERT INTO `real_estate_app`.`listings` (`his_listing_id`, `url`, `resource_id`, `address`, `price`, `bedrooms`, `mls`, `price/sqft`, `interior_area_size`, `year_built`, `lot_size`, `land_tenure`, `on_market_since`, `last_updated`, `property_type`, `oceanfront`, `description`, `misc_data`, `statusCode`, `checksum`) VALUES ('". mysqli_real_escape_string($con, $result[0]->extractorData->data[0]->group[0]->MLS[0]->text). "', '". mysqli_real_escape_string($con, $result[0]->extractorData->url). "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->resourceId) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->Address[0]->text) . "', '" . mysqli_real_escape_string($con,toFloat($result[0]->extractorData->data[0]->group[0]->Price[0]->text)) . "', '" . mysqli_real_escape_string($con,toFloat($result[0]->extractorData->data[0]->group[0]->Bedrooms[0]->text)) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->MLS[0]->text) . "', '" . mysqli_real_escape_string($con,toFloat($result[0]->extractorData->data[0]->group[0]->Price->sqft[0]->text)) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->{'Interior Area'}[0]->text) . "', '" . mysqli_real_escape_string($con,toFloat($result[0]->extractorData->data[0]->group[0]->{'Year Built'}[0]->text)) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->{'Lot Size'}[0]->text) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->{'Land Tenure'}[0]->text) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->{'On Market Since'}[0]->text) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->{'Last Updated'}[0]->text) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->{'Property Type'}[0]->text) . "', '" . mysqli_real_escape_string($con,$oceanfront) . "', '" . mysqli_real_escape_string($con,$result[0]->extractorData->data[0]->group[0]->{'Listing Remarks'}[0]->text) . "', 'none', '" . mysqli_real_escape_string($con,$result[0]->pageData->statusCode) . "', '" . mysqli_real_escape_string($con,$result['checksum']) . "');");
                $kvPutOp = new KvPutOperation('listings', $listing['listing_id'], json_encode($listing));
                $kvObject = $client->execute($kvPutOp);
                $ref = $kvObject->getRef();
                echo $ref;
            }
        }else {
            return false;
        }
    }
    return false;
}
/**
 * Fetch the contents of a cached URL.
 *
 * @param $url //url to fetch from the cache
 * @param $searchOrListing //either a 'search' URL or 'listing URL
 * @return array|bool|mixed|null //Will return the URL cache in an array, or false if the URL isn't already cached
 */
function fetchCacheUrl($url, $searchOrListing) {
    global $con;
    checkUrl($url,$searchOrListing);
    if($searchOrListing == 'search') {
        $result = query("SELECT data FROM real_estate_app.listings_extractor_log WHERE realUrl = '" . mysqli_real_escape_string($con,$url) . "' LIMIT 1;");
        return json_decode($result[0]['data']);
    }else if($searchOrListing == 'listing') {
        $result = query("SELECT * FROM real_estate_app.listings WHERE url = '". mysqli_real_escape_string($con, $url) ."' LIMIT 1;");
        return $result;
    }
    return false;
}
$con = MySQLConnect();
$realUrl = createSearchUrl('real',1,3,'',0,999999999999,0,0);
$first_page_data = fetchCacheUrl($realUrl,'search');
$total_pages = intval($first_page_data->extractorData->data[0]->group[0]->{'Amount of Pages'}[0]->text);
var_dump($total_pages);
for($i = 1; $i <= $total_pages-40; $i++) { //take out the -40 later
    $page_number = $i;
    echo $i;
    $page_data = fetchCacheUrl(createSearchUrl('real',$i,3,'',0,999999999999,0,0),'search');
    for($p = 0; $p <= 1; $p++) { //replace 1 with count($page_data->extractorData->data[1]->group)-1 when ready for full testing
        fetchCacheUrl($page_data->extractorData->data[1]->group[$p]->Image[0]->href,'listing');
    }
}
die('Thanks for playing!');