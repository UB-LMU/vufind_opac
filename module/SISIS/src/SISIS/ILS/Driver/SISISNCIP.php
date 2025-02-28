<?php

/**
 * ILS Driver for the SISIS NCIP2SLNP module
 *
 * PHP version 7
 *
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Johannes Schlüßlhuber
 * @license
 * @link
 */


namespace SISIS\ILS\Driver;
use \DateTime;
use \DatetimeZone;

class SISISNCIP extends \VuFind\ILS\Driver\AbstractBase implements
    \VuFind\I18n\Translator\TranslatorAwareInterface
{
    use \VuFind\I18n\Translator\TranslatorAwareTrait;

    /**
     * NCIP server URL
     *
     * @var string
     */
    protected $url;

    /**
     * katkey
     *
     * @var string
     */
    protected $katkey;

    /**
     * Record loader
     *
     * @var \VuFind\Record\Loader
     */
    protected $recordLoader;

    /**
     * Used for Password resets
     *
     * @var string
     */
    protected $passwordResetString;

    /**
     * Constructor
     *
     * @param \VuFind\Record\Loader $loader - Record loader
     */
    public function __construct(\VuFind\Record\Loader $loader)
    {
        $this->recordLoader = $loader;
    }

    /**
     * Get a Solr record.
     *
     * @param string $id - ID of record to retrieve
     *
     * @return \VuFind\RecordDriver\AbstractBase
     */
    public function getSolrRecord($id)
    {
        return $this->recordLoader->load(
            $id,
            DEFAULT_SEARCH_BACKEND,
            true    // tolerate missing records
        );
    }

    /***************************************************************************************
    * INIT/CONFIG FUNCTIONS
    ****************************************************************************************/

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @return void
     */
    public function init()
    {
        if (empty($this->config)) {
            throw new ILSException('Configuration needs to be set.');
        }

        // get NCIP Server URL from Config File
        $this->url = $this->config['Catalog']['url'];

        // get katkey field name from Config File
        $this->katkey = $this->config['Catalog']['katkey'];

        // get key for password resets from Config File
        $this->passwordResetString = $this->config['Catalog']['passwordReset'];
    }

    /**
     * This method returns driver configuration settings related to a particular
     * function. It is primarily used to get the configuration settings for placing
     * holds. (optional, but necessary if you want to implement hold or other
     * request functionality)
     *
     * @param String $function - the name of the function
     * @param array $params    - an optional array of function-specific parameters
     *
     * @return array;
     *  An associative array of configuration settings
     *  Array keys used for input of “Holds”:
     *      -HMACKeys - a colon-separated list of fields to verify with a hash key
     *      when submitting a hold form
     *      -extraHoldFields (optional) - a colon-separated list of form fields to
     *      include in the place hold form; may include “comments”, “requiredByDate”,
     *      “pickUpLocation” and (from VuFind® 8.0) “startDate”.
     *      ...
     */
    public function getConfig(string $function, array $params = [])
    {
        if($function == "Holds"){
            return [
                'HMACKeys' => "id:item_id:holdtype:location_code:holdings_id",          #required for placeHold, all keys needed from getHolding seperated by : (needed: id, cs status and location)
                'extraHoldFields' => "pickUpLocation",     #required for placeHold, all keys needed for the place hold form,  all parameters activated here must be processed by the placeHold method
                #'defaultRequiredDate' => 'driver:0:2:0',
            ];
        }
        if($function == "changePassword"){
            return [
                #use minimum and maximum password length defined in config.ini
                'minLength' => $this->config['Authentication']['minimum_password_length'],
                'maxLength' => $this->config['Authentication']['maximum_password_length'],
            ];
        }
        /**
        * No required keys at this time
        */
        if($function == "Renewals"){
            return [];
        }
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id - The record id to retrieve the holdings for
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
        // get katKey
        $katKeyId = $this->getSolrRecord($id)->getKatKey($this->katkey);
        if(is_null($katKeyId)){
            return [];
        }
        if($katKeyId == "none"){
            return [];
        }

        $options = [
            'NCIPFunction' => "LookupItem",
            'ItemId' => $katKeyId,
            'ItemIdentifierType' => "TitleId",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);


        $error = null;
        if(is_null($response)){$error = (string)$this->translate("ils_connection_failed");}

        $items = [];

        if(!isset($xml->LookupItemResponse->Item)){
            return $items;
        }

        // to be filled with $location_codes which need additional requests
        $extraRequestLocations = [];

        foreach($xml->LookupItemResponse->Item as $item)
        {
            $shelfmark = "";
            $location = "";
            $index = 0;
            foreach($item->ItemOptionalFields->Ext->UnstructuredAddressType as $uat)
            {
                if(((string)$uat) == "Location"){
                    $location = (string)$item->ItemOptionalFields->Ext->UnstructuredAddressData[$index];
                }
                if(((string)$uat) == "Shelfmark1"){
                    $shelfmark = (string)$item->ItemOptionalFields->Ext->UnstructuredAddressData[$index];
                }
                $index = $index + 1;
            }

            // values that can be accessed directly
            $locality = (string)$item->ItemOptionalFields->Ext->Locality;
            $location_code = $this->getLocationCode($location);
            $bibliographicId = (string)$item->ItemId->ItemIdentifierValue;

            //values that depend on the location and the circulationstatus
            $availability = 0;
            $status = "";


            // If the Circulationstatus for this $item wants us to select the right pickuplocation, flag the location_code and bibliographicId
            if(((string)$item->ItemOptionalFields->Ext->CirculationStatus == "038") or ((string)$item->ItemOptionalFields->Ext->CirculationStatus == "039")){
                if (! array_key_exists($location_code, $extraRequestLocations)) {
                    $extraRequestLocations[$location_code] = [];
                }
                $extraRequestLocations[$location_code][] = $bibliographicId;
            }

            //get the circulation status code and strip _NoAction if necessary
            $circulationStatus = (string)$item->ItemOptionalFields->CirculationStatus;
            if(str_ends_with($circulationStatus, "_NoAction")){
                $circulationStatus = substr($circulationStatus, 0, -9);
            }

            $availability = $this->isAvailable($circulationStatus);
            $status = $this->getStatusString($circulationStatus) ?? $circulationStatus;

            if(!($status === 'no_status')) {
                $items[] = [
                    'id' => $id,
                    'item_id' => $bibliographicId,
                    'availability' => $availability,
                    'status' => $status,
                    'location' => $locality,
                    'reserve' => 'N',
                    'callnumber' => $shelfmark,
                    'error' => $error,
                ];
            }
        }

        foreach ($extraRequestLocations as $extraRequestLocation => $extrabibliographicIds) {
            // set new options for the additional NCIP request
            $itemOptions = [
                'NCIPFunction' => "LookupItem",
                'ItemId' => $katKeyId,                             // search with the katkey
                'LocationNameLevel' => $extraRequestLocation,      // set the LocationNameLevel to the location
                'ItemIdentifierType' => "TitleId",
            ];

            // send new LookupItem request for the specified location_code
            $itemRequest = $this->createMessage($itemOptions);
            $itemResponse = $this->sendRequest($itemRequest);
            $itemXml = simplexml_load_string($itemResponse);

            foreach($itemXml->LookupItemResponse->Item as $item)
            {
                if (in_array((string)$item->ItemId->ItemIdentifierValue, $extrabibliographicIds, true)) {
                    //get the circulation status code and strip _NoAction if necessary
                    $circulationStatus = (string)$item->ItemOptionalFields->CirculationStatus;
                    if(str_ends_with($circulationStatus, "_NoAction")){
                        $circulationStatus = substr($circulationStatus, 0, -9);
                    }
                    $availability = $this->isAvailable($circulationStatus);
                    $status = $this->getStatusString($circulationStatus) ?? $circulationStatus;

                    foreach ($items as &$i) {
                        if ($i['item_id'] == (string)$item->ItemId->ItemIdentifierValue) {
                            $i['availability'] = $availability;
                            $i['status'] = $status;
                            break;
                        }
                    }
                    unset($i);
                }
            }
        }

        // Request for newly aquired items
        $options = [
            'NCIPFunction' => "LookupItem",
            'ItemId' => $katKeyId,
            'ItemIdentifierType' => "NewACQ",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return $items;
        }

        if(!isset($xml->LookupItemResponse->Item)){
            return $items;
        }

        foreach($xml->LookupItemResponse->Item as $item)
        {
            if(!((string)$item->ItemOptionalFields->CirculationStatus === "LSError")){
                $location = "";
                $index = 0;
                foreach($item->ItemOptionalFields->Ext->UnstructuredAddressType as $uat)
                {
                    if(((string)$uat) == "Location"){
                        $location = (string)$item->ItemOptionalFields->Ext->UnstructuredAddressData[$index];
                    }
                    $index = $index + 1;
                }

                // values that can be accessed directly
                $location_code = $this->getLocationCode($location);
                $circulationStatus = (string)$item->ItemOptionalFields->CirculationStatus;

                $items[] = [
                    'id' => $id,
                    'availability' => 0,
                    'status' => $circulationStatus,
                    'location' => $location,
                    'reserve' => 'N',
                    'callnumber' => "Im Erwerbungsvorgang",
                ];
            }
        }

        return $items;
    }

    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $ids - The array of record ids to retrieve the status for
     *
     * @return mixed     An array of getStatus() return values on success.
     */
    public function getStatuses($ids)
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = $this->getStatus($id);
        }
        return $items;
    }

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id      - The record id to retrieve the holdings for
     * @param array  $patron  - Patron data
     * @param array  $options - Extra options (not currently used)
     *
     * @return array with 'total' and 'holdings' keys, where
     * 'total' is the total number of items available from the ILS, and 'holdings'
     * is the requested page of results, represented as associative arrays
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getHolding($id, array $patron = null, array $options = [])
    {

        // get katKey
        $katKeyId = $this->getSolrRecord($id)->getKatKey($this->katkey);
        if($katKeyId == "none"){
            return [];
        }

        $options;
        if(is_null($patron)){
            $options = [
                'NCIPFunction' => "LookupItem",
                'ItemId' => $katKeyId,
                'ItemIdentifierType' => "TitleId",
            ];
        } else {
            $options = [
                'NCIPFunction' => "LookupItem",
                'ItemId' => $katKeyId,
                'ItemIdentifierType' => "TitleId",
                'UserId' => $patron['cat_username'],
            ];
        }

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        $holdings = [];

        if(is_null($response)){
            return [
                'total' => sizeof($holdings),
                'holdings' => $holdings,
            ];
        }

        if(!isset($xml->LookupItemResponse->Item)){
            return [
                'total' => sizeof($holdings),
                'holdings' => $holdings,
            ];
        }

        foreach($xml->LookupItemResponse->Item as $item)
        {
            // values hidden in unstructeredaddresstypes
            $location = "";
            $shelfmark = "";
            $notes = null;
            $url = "";
            $index = 0;
            foreach($item->ItemOptionalFields->Ext->UnstructuredAddressType as $uat)
            {
                if(((string)$uat) == "Location"){
                    $location = (string)$item->ItemOptionalFields->Ext->UnstructuredAddressData[$index];
                }
                if(((string)$uat) == "Shelfmark1"){
                    $shelfmark = (string)$item->ItemOptionalFields->Ext->UnstructuredAddressData[$index];
                }
                if(((string)$uat) == "URL"){
                    $url = (string)$item->ItemOptionalFields->Ext->UnstructuredAddressData[$index];
                }
                if(((string)$uat) == "FootNotes"){
                    $notes = [(string)$item->ItemOptionalFields->Ext->UnstructuredAddressData[$index]];
                }
                $index = $index + 1;
            }

            // values that can be accessed directly
            $locality = (string)$item->ItemOptionalFields->Ext->Locality;
            $location_code = $this->getLocationCode($location);
            $requests_placed = (integer)$item->ItemOptionalFields->Ext->HoldQueueLength;
            $bibliographicId = (string)$item->ItemId->ItemIdentifierValue;
            $duedate = $this->formatDate((string)$item->ItemOptionalFields->Ext->DateDue) ?? null;

            //values that depend on the location and the circulationstatus
            $availability = 0;
            $status = "";
            $holdtype = "";

            // If the Circulationstatus for this $item wants us to select the right pickuplocation, send new LookupItem request with correct pickuplocation
            if(((string)$item->ItemOptionalFields->Ext->CirculationStatus == "038") or ((string)$item->ItemOptionalFields->Ext->CirculationStatus == "039") ){

                // set new options for the additional NCIP request
                $itemOptions = [
                    'NCIPFunction' => "LookupItem",
                    'ItemId' => $bibliographicId,                   // search with the BibliographicId
                    'LocationNameLevel' => $location_code,          // set the LocationNameLevel to the location the $item is
                    'ItemIdentifierType' => "BibliographicId",      // set flag for single item status lookup
                ];

                // send new LookupItem request for the $item
                $itemRequest = $this->createMessage($itemOptions);
                $itemResponse = $this->sendRequest($itemRequest);
                $itemXml = simplexml_load_string($itemResponse);

                //get the circulation status code and strip _NoAction if necessary
                $circulationStatus = (string)$itemXml->LookupItemResponse->ItemOptionalFields->CirculationStatus;
                if(str_ends_with($circulationStatus, "_NoAction")){
                    $circulationStatus = substr($circulationStatus, 0, -9);
                }

                // set the status variables for this $item
                $availability = $this->isAvailable($circulationStatus);
                $status = $this->getStatusString($circulationStatus) ?? $circulationStatus;
                $holdtype = $this->getRequestType($circulationStatus);

            }else{
                //else set status values normally

                //get the circulation status code and strip _NoAction if necessary
                $circulationStatus = (string)$item->ItemOptionalFields->CirculationStatus;
                if(str_ends_with($circulationStatus, "_NoAction")){
                    $circulationStatus = substr($circulationStatus, 0, -9);
                }

                $availability = $this->isAvailable($circulationStatus);
                $status = $this->getStatusString($circulationStatus) ?? $circulationStatus;
                $holdtype = $this->getRequestType($circulationStatus);
            }

            //check if status code != no_status
            if(!($status === 'no_status')){
                // add all the values for that specific holding into the list
                $holdings[] = [
                    'id' => $id,
                    'availability' => $availability,
                    'status' => $status,
                    'location' => $locality,    #Locality wird angezeigt und Location auf den Zweigstellen Code gemapt
                    'location_code' => $location_code,
                    'locationhref' => $url,
                    'reserve' => 'N',
                    'callnumber' => $shelfmark,
                    'duedate' => $duedate,
                    'number' => null,
                    'requests_placed' => $requests_placed,
                    'barcode' => "", #even if you do not have access to real barcode numbers, you may want to include dummy values
                    'item_notes' => $notes ?? null,
                    'holdtype' => $holdtype,
                    'addLink' => "check",
                    'item_id' => $bibliographicId,  //mediennummer
                    'holdings_id' => $katKeyId,     //katkey
                ];
            }
        }

        /**
         * Request for newly aquired items
         */
        $options = [
            'NCIPFunction' => "LookupItem",
            'ItemId' => $id,
            'ItemIdentifierType' => "NewACQ",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return [
                'total' => sizeof($holdings),
                'holdings' => $holdings,
            ];
        }

        if(!isset($xml->LookupItemResponse->Item)){
            return [
                'total' => sizeof($holdings),
                'holdings' => $holdings,
            ];
        }

        foreach($xml->LookupItemResponse->Item as $item)
        {
            if(!((string)$item->ItemOptionalFields->CirculationStatus === "LSError")){
                $location = "";
                $index = 0;
                foreach($item->ItemOptionalFields->Ext->UnstructuredAddressType as $uat)
                {
                    if(((string)$uat) == "Location"){
                        $location = (string)$item->ItemOptionalFields->Ext->UnstructuredAddressData[$index];
                    }
                    $index = $index + 1;
                }

                // values that can be accessed directly
                $location_code = $this->getLocationCode($location);
                $circulationStatus = (string)$item->ItemOptionalFields->CirculationStatus;

                $holdings[] = [
                    'id' => $id,
                    'availability' => 0,
                    'status' => $circulationStatus,
                    'location' => $location,
                    'reserve' => 'N',
                    'callnumber' => "Im Erwerbungsvorgang",
                    'item_notes' => ["Datum: ".$this->formatDate((string)$item->ItemOptionalFields->Ext->DatePlaced)],
                ];
            }
        }

        return [
            'total' => sizeof($holdings),
            'holdings' => $holdings,
        ];
    }

    /**
     * Has Holdings
     *
     * This is responsible for determining if holdings exist for a particular
     * bibliographic id
     *
     * @param string $id - The record id to retrieve the holdings for
     *
     * @return bool True if holdings exist, False if they do not
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
     public function hasHoldings($id)
    {
        $holdings = $this->getHolding($id);

        if($holdings["total"] < 1){
            return false;
        } else {
            return true;
        }

    }

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id - The record id to retrieve the info for
     *
     * @return mixed     An array with the acquisitions data on success.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getPurchaseHistory($id)
    {
        return [];
    }

    /**
     * Get New Items
     *
     * Retrieve the IDs of items recently added to the catalog.
     *
     * @param int $page    - Page number of results to retrieve (counting starts at 1)
     * @param int $limit   - The size of each page of results to retrieve
     * @param int $daysOld - The maximum age of records to retrieve in days (max. 30)
     * @param int $fundId  - optional fund ID to use for limiting results (use a value
     * returned by getFunds, or exclude for no limit); note that "fund" may be a
     * misnomer - if funds are not an appropriate way to limit your new item
     * results, you can return a different set of values from getFunds. The
     * important thing is that this parameter supports an ID returned by getFunds,
     * whatever that may mean.
     *
     * @return array       Associative array with 'count' and 'results' keys
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getNewItems($page, $limit, $daysOld, $fundId = null)
    {
        return ['count' => 0, 'results' => []];
    }

    /**
     * Find Reserves
     *
     * Obtain information on course reserves.
     *
     * @param string $course - ID from getCourses (empty string to match all)
     * @param string $inst   - ID from getInstructors (empty string to match all)
     * @param string $dept   - ID from getDepartments (empty string to match all)
     *
     * @return mixed An array of associative arrays representing reserve items.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function findReserves($course, $inst, $dept)
    {
        return [];
    }


    /***************************************************************************************
    * LOOKUPUSER
    ****************************************************************************************/

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $username - The patron username
     * @param string $password - The patron password
     *
     * @return mixed           Associative array of patron info on successful login,
     * null on unsuccessful login.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function patronLogin(string $username, string $password) : mixed
    {

        $options = [
            'NCIPFunction' => "LookupUser",
            'UserId' => $username,
            'Password' => $password,
            'Infotype' => "user",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return null;
        }

        if(isset($xml->LookupUserResponse->Problem)){
            if((string)$xml->LookupUserResponse->Problem->ProblemValue == "OpsBenutzerAutoPinChange"){
                $errormessage = $this->translate("first_login_message");
                $errorlink = '/vufind/Content/initiallogin';
                $errorlinktext = $this->translate("first_login_link_text");
                echo "<div role='alert' class='flash-message alert alert-danger'>{$errormessage} <a href='{$errorlink}' data-lightbox>{$errorlinktext}</a></div>";
            }
            return null;
        }

        $prefix = (string)$xml->LookupUserResponse->UserOptionalFields->NameInformation->PersonalNameInformation->StructuredPersonalUserName->Prefix ?? "";
        $prefixes = explode(" ", $prefix);
        $degree = "";
        for ($i = 1; $i < count($prefixes) ; $i++) {
          $degree = $degree.$prefixes[$i]." ";
        }


        $patron = [
            'id' => (string)$xml->LookupUserResponse->UserId->UserIdentifierValue,
            'firstname' => substr((string)$xml->LookupUserResponse->UserOptionalFields->NameInformation->PersonalNameInformation->StructuredPersonalUserName->GivenName, 0, 50),
            'lastname' => substr((string)$xml->LookupUserResponse->UserOptionalFields->NameInformation->PersonalNameInformation->StructuredPersonalUserName->Surname, 0, 50),
            'cat_username' => (string)$xml->LookupUserResponse->UserId->UserIdentifierValue,
            'cat_password' => $password,
            'email' => (string)$xml->LookupUserResponse->UserOptionalFields->UserAddressInformation->ElectronicAddress->Ext->ElectronicAddressData[0],
            'major' => null,
            'college' => $degree, # custom field for profile
            'group' => (string)$xml->LookupUserResponse->Ext->UnstructuredAddressData[1],
        ];

        return $patron;
    }

    /**
    *
    * Get Patron Profile
    *
    * This is responsible for retrieving the profile for a specific patron.
    *
    * @param array $patron - The patron array
    *
    * @throws ILSException
    * @return array        Array of the patron's profile data on success.
    */
    public function getMyProfile(array $patron) : array
    {

        $options = [
            'NCIPFunction' => "LookupUser",
            'UserId' => $patron['id'],
            'Password' => $patron['cat_password'],
            'Infotype' => "user",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return null;
        }

        $phone = "";
        $expiration_date = "";
        $country = "";
        $index = 0;
        foreach($xml->LookupUserResponse->UserOptionalFields->UserAddressInformation[3]->Ext->UnstructuredAddressType as $uat)
        {
            if(((string)$uat) == "ContactPhoneNumber"){
                $phone = (string)$xml->LookupUserResponse->UserOptionalFields->UserAddressInformation[3]->Ext->UnstructuredAddressData[$index];
            }
            if(((string)$uat) == "IdentityCardValid"){
                $expiration_date = (string)$xml->LookupUserResponse->UserOptionalFields->UserAddressInformation[3]->Ext->UnstructuredAddressData[$index];
            }
            if(((string)$uat) == "Nationality"){
                $country = (string)$xml->LookupUserResponse->UserOptionalFields->UserAddressInformation[3]->Ext->UnstructuredAddressData[$index];
            }
            $index = $index + 1;
        }

        $user_information = [
            'firstname' => $patron['firstname'],
            'lastname' => $patron['lastname'],
            'address1' => (string)$xml->LookupUserResponse->UserOptionalFields->UserAddressInformation[1]->PhysicalAddress->StructuredAddress->Street,
            'address2' => (string)$xml->LookupUserResponse->UserOptionalFields->UserAddressInformation[2]->PhysicalAddress->StructuredAddress->Street,
            'city' => (string)$xml->LookupUserResponse->UserOptionalFields->UserAddressInformation[1]->PhysicalAddress->StructuredAddress->Locality,
            'country' => $country,
            'zip' => (string)$xml->LookupUserResponse->UserOptionalFields->UserAddressInformation[1]->PhysicalAddress->StructuredAddress->PostalCode,
            'phone' => $phone,
            'mobile_phone' => null,
            'group' => (string)$xml->LookupUserResponse->Ext->UnstructuredAddressData[1],
            'expiration_date' => $this->formatDate($expiration_date),
            'birthdate' => $this->formatDate((string)$xml->LookupUserResponse->UserOptionalFields->DateOfBirth) ?? "",
        ];

        return $user_information;
    }

    /**
    * Get Patron Fines
    *
    * This is responsible for retrieving all fines by a specific patron.
    *
    * @param array $patron - The patron array from patronLogin
    *
    * @throws DateException
    * @throws ILSException
    * @return mixed        Array of the patron's fines on success.
    */
    public function getMyFines(array $patron) : array
    {

        $options = [
            'NCIPFunction' => "LookupUser",
            'UserId' => $patron['id'],
            'Password' => $patron['cat_password'],
            'Infotype' => "fiscal",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return null;
        }

        $fines = [];

        if(!isset($xml->LookupUserResponse->UserFiscalAccount->AccountDetails->FiscalTransactionInformation)){
            return $fines;
        }

        foreach($xml->LookupUserResponse->UserFiscalAccount->AccountDetails->FiscalTransactionInformation as $loan)
        {
            $fines[] = [
                'amount' => (string)$loan->Amount->MonetaryValue,
                'checkout' => null,
                'fine' => (string)$loan->FiscalTransactionDescription,
                'balance' => $xml->LookupUserResponse->UserFiscalAccount->AccountBalance->MonetaryValue,
                'createdate' => $this->formatDate((string)$loan->ValidFromDate),
                'duedate' => null,
                'id' => (string)$loan->ItemDetails->ItemId->ItemIdentifierValue,
                'source' => null,
            ];
        }

        return $fines;
    }

    /**
    * Check whether the patron has any blocks on their account.
    *
    * @param array $patron - Patron data from patronLogin().
    *
    * @return mixed A boolean false if no blocks are in place and an array
    * of block reasons if blocks are in place
    * @throws ILSException
    */
    public function getAccountBlocks(array $patron)
    {

        $options = [
            'NCIPFunction' => "LookupUser",
            'UserId' => $patron['id'],
            'Password' => $patron['cat_password'],
            'Infotype' => "none",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return null;
        }

        $blocks = [];
        if(!$xml->LookupUserResponse->UserOptionalFields->BlockOrTrap[0]){
            return false;
        }else{
            foreach($xml->LookupUserResponse->UserOptionalFields->BlockOrTrap as $block){
                $blocks[] = (string)$block->Ext->Line1;
            }
            return $blocks;
        }
    }

    /**
    * This method queries the ILS for a patron's current holds and recalls (auf Deutsch Bestellungen und Vormerkungen)
    *
    * @param array $patron - returned by patronLogin method
    *
    * @return array of associative arrays, one for each hold associated with the specified account.
    */
    public function getMyHolds(array $patron) : array
    {

        $options = [
            'NCIPFunction' => "LookupUser",
            'UserId' => $patron['id'],
            'Password' => $patron['cat_password'],
            'Infotype' => "order",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return null;
        }

        $holds = [];

        if(!isset($xml->LookupUserResponse->RequestedItem)){
            return $holds;
        }

        foreach($xml->LookupUserResponse->RequestedItem as $item)
        {
            if(((string)$item->RequestType) == "PreBook"){
                $holds[] = [
                    'type' => "recall",
                    'holdings_id' => (string)$item->Ext->BibliographicRecordIdentifier,
                    'item_id' => (string)$item->ItemId->ItemIdentifierValue,
                    'location' => (string)$item->Ext->LocationNameValue . ' / ' . (string)$item->Ext->PickupLocation,
                    'expire' => $this->formatDate((string)$item->Ext->DateDue),
                    'create' => $this->formatDate((string)$item->DatePlaced),
                    'position' => (string)$item->HoldQueuePosition,
                    'title' => (string)$item->Title,
                    'callnumber' => (string)$item->Ext->LocationWithinBuilding, #custom field
                ];
            }
            if(((string)$item->RequestType) == "Order"){
                //check if item is available for pickup
                if(((string)$item->Ext->CirculationStatus) == "UAS_PickUp") {
                  $available = true;
                } else {
                  $available = false;
                }
                $holds[] = [
                    'type' => "hold",
                    //'id' => $this->getIdFromShelfmark((string)$item->Ext->LocationWithinBuilding, (string)$item->ItemId->ItemIdentifierValue),
                    'item_id' => (string)$item->ItemId->ItemIdentifierValue,
                    'location' => (string)$item->Ext->LocationNameValue . ' / ' . (string)$item->Ext->PickupLocation,
                    'last_pickup_date' => $this->formatDate((string)$item->PickupExpiryDate),
                    'create' => $this->formatDate((string)$item->DatePlaced),
                    'available' => $available,                              #whether or not the hold is available for pickup
                    'cancel_details' => "",                                 #a blank string to indicate that the hold cannot be canceled
                    'title' => (string)$item->Title,
                    'callnumber' => (string)$item->Ext->LocationWithinBuilding, #custom field
                ];
            }
        }
        return $holds;
    }

    /**
    * This method queries the ILS for a patron's current checked out items  (auf Deutsch Ausgeliehene Medien)
    *
    * @param array $patron - returned by patronLogin method
    * @param array $parameters - (starting with VuFind® 5.1) an optional array of parameters (keys = 'limit', 'page', 'sort')
    *
    * @return array Starting with VuFind® 5.1, may return an array with 'count' and 'records' keys, where 'count' is
    * the total number of transactions available, and 'records' is the currently-requested page of results
    * (in the format described below). Prior to 5.1, always returned an array of associative arrays, one for
    * each item checked out by the specified account; this response format is still supported for drivers that
    * do not allow pagination.
    */
    public function getMyTransactions(array $patron, array $parameters) : array
    {

        $options = [
            'NCIPFunction' => "LookupUser",
            'UserId' => $patron['id'],
            'Password' => $patron['cat_password'],
            'Infotype' => "loan",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return null;
        }

        $loans = [];

        if(!isset($xml->LookupUserResponse->LoanedItem)){
            return $loans;
        }

        foreach($xml->LookupUserResponse->LoanedItem as $item)
        {
            $loans[] = [
                //'id' => $this->getIdFromShelfmark((string)$item->Ext->LocationWithinBuilding, (string)$item->ItemId->ItemIdentifierValue),
                'duedate' => $this->formatDate((string)$item->DateDue),
                'dueStatus' => $this->getDueStatus((string)$item->Ext->CirculationStatus,(string)$item->DateDue),//$this->isDue((string)$item->DateDue),
                'renewable' => $this->isRenewable((string)$item->Ext->CirculationStatus),
                'message' => (string)$item->Ext->CirculationStatus, //TEST
                'title' => (string)$item->Title,
                'item_id' => (string)$item->ItemId->ItemIdentifierValue,
                'renew' => (string)$item->Ext->RenewalCount,
                'callnumber' => (string)$item->Ext->LocationWithinBuilding, #custom field
                'borrowingLocation' => $this->getborrowingLocation((string)$item->Ext->LocationNameValue, (string)$item->Ext->PickupLocation),
            ];
        }

        return $loans;
    }


    /***************************************************************************************
    * UPDATEUPUSER
    ****************************************************************************************/

    /**
     * This method changes patron's password.
     *
     * @param array $details - An associative array with three keys:
     *          - patron - The patron array from patronLogin
     *          -  oldPassword - Old password
     *          -  newPassword - New password
     *
     * @return array containing:
     *          - success – Boolean true or false
     *          - status – A status message from the language file
     */
    public function changePassword(array $details) : array
    {

        $options = [
            'NCIPFunction' => "UpdateUser",
            'UserId' => $details['patron']['id'],
            'Password' => $details['oldPassword'],
            'NewPassword' => $details['newPassword'],
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return [
                'success' => false,
                'status' => 'An error has occurred',
                'sysMessage' => 'No Response from the ILS',
            ];
        }

        if(isset($xml->UpdateUserResponse->Problem)){
            return [
                'success' => false,
                'status' => 'An error has occurred',
                'sysMessage' => (string)$xml->UpdateUserResponse->Problem->ProblemDetail,
            ];
        }

        return [
            'success' => true,
            'status' => 'Passwort erfolgreich geändert!',
        ];
    }

    /**
     * This function is called when a user wants to reset his password.
     *
     * @param String $id - user id
     *
     * @return array containing:
     *          - success – Boolean true or false
     *          - status – A status message from the language file
     */
    public function resetPassword(String $id) : array
    {

        // get mail of user
        $patron = $this->patronLogin($id, $this->passwordResetString);
        if(is_null($patron)){
            return [
                'success' => false,
                'status' => $this->translate("username_error_invalid"),
            ];
        }
        if(!array_key_exists('email', $patron) || (empty($patron['email']))){
            return [
                'success' => false,
                'status' => $this->translate("Email address is invalid"),
            ];
        }
        $mail = (string)$patron['email'];

        // create new random Password from numbers and letters with lenth 12
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < 12; $i++) {
            $index = rand(0, strlen($characters) - 1);
            $randomString .= $characters[$index];
        }

        // set options for ncip request
        $options = [
            'NCIPFunction' => "UpdateUser",
            'UserId' => $id,
            'Password' => $this->passwordResetString,
            'NewPassword' => $randomString,
        ];

        // handle request
        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return [
                'success' => false,
                'status' => $this->translate("ils_connection_failed"),
            ];
        }

        if(isset($xml->UpdateUserResponse->Problem)){
            return [
                'success' => false,
                'status' => (string)$xml->UpdateUserResponse->Problem->ProblemDetail,
            ];
        }

        // return mail of user back to controller
        return [
            'success' => true,
            'status' => $this->translate("reset_successful"),
            'mail' => $mail,
            'newPW' => $randomString,
        ];
    }

    /**
     * This function is called when a user logs in for the first time.
     *
     * @param String $id                - user id
     * @param String $initialPassword   - the initial password that needs to be changed
     * @param String $newPassword       - the new password
     *
     * @return array containing:
     *          - success – Boolean true or false
     *          - status – A status message from the language file
     */
    public function initialLogin(String $id, String $initialPassword, String $newPassword) : array
    {

        // make login attempt with the initial password
        $options = [
            'NCIPFunction' => "LookupUser",
            'UserId' => $id,
            'Password' => $initialPassword,
            'Infotype' => "user",
        ];

        if($initialPassword == $this->passwordResetString){
            return [
                'success' => false,
                'status' => $this->translate("ils_connection_failed"),
            ];
        }

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return [
                'success' => false,
                'status' => $this->translate("ils_connection_failed"),
            ];
        }

        if(isset($xml->LookupUserResponse->Problem)){
            if((string)$xml->LookupUserResponse->Problem->ProblemValue == "OpsBenutzerAutoPinChange"){
                $result = $this->changePassword(['patron' => ['id' => $id], 'oldPassword' => $this->passwordResetString, 'newPassword' => $newPassword]);
                return [
                    'success' => $result['success'],
                    'status' => $this->translate("new_password_success"),
                ];
            }else{
                return [
                    'success' => false,
                    'status' => $this->translate("password_error_not_unique"),
                ];
            }
        }else{
            return [
                'success' => false,
                'status' => $this->translate("initial_login_failed"),
            ];
        }
    }


    /***************************************************************************************
    * REQUESTITEM
    ****************************************************************************************/

    /**
    * This method places a hold on a specific record for a specific patron.
    *
    * @param array $holdDetails
    *  An associative array with several keys. 'patron' will always be
    *  defined to contain the array returned by patronLogin method; other fields may
    *  vary depending on the fields defined in the HMACKeys and extraHoldFields settings
    *  returned by the getConfig method.
    *
    * @return array containing:
    *   success – Boolean true or false
    *   sysMessage – A system supplied failure message (optional)
    */

    public function placeHold(array $holdDetails) : array
    {

        $options = [
            'NCIPFunction' => "RequestItem",
            'UserId' => $holdDetails['patron']['id'],
            'Password' => $holdDetails['patron']['cat_password'],
            //'ItemId' => $holdDetails['holdings_id'],  //katkey
            'ItemId' => $holdDetails['item_id'],        //mediennummer
            'RequestType' => "", #ORDER or PreBook, depending on holdtype
            'PickupLocation' => $holdDetails['pickUpLocation'],
            'LocationNameLevel' => $holdDetails['location_code'],
            //'RequestScopeType' => "BibliographicId",  //katkey
            'RequestScopeType' => "ItemId",             //mediennummner

        ];
        if($holdDetails['holdtype'] === "hold"){
            $options['RequestType'] = "ORDER";
        }elseif($holdDetails['holdtype'] === "recall"){
            $options['RequestType'] = "PreBook";
            $options['ItemId'] = $holdDetails['id'];
            $options['RequestScopeType'] = $holdDetails['BibliographicId'];
        }

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return ['success' => false, 'sysMessage' => "Problems connecting to the ILS!"];
        }

        if(isset($xml->RequestItemResponse->Problem)){
            return ['success' => false, 'sysMessage' => (string)$xml->RequestItemResponse->Problem->ProblemDetail];
        }


        return ['success' => true];
    }

    /**
     * Check if a particular user is allowed to place a hold/recall
     * request on a particular item.
     *
     * @param string $id - Bibliographic ID
     * @param array $data - Item Data           (similar to the holdDetails)
     * @param array $patron - Patron Data       (from patronLogin)
     *
     * @return array containing:
     *          - valid: Boolean true or false
     *          - status: Message string to display to user
     */
    public function checkRequestIsValid(string $id, array $data, array $patron) : array
    {

        # Options for the NCIP Request
        $options = [
            'NCIPFunction' => "RequestItem",
            'UserId' => $patron['id'],
            'Password' => $patron['cat_password'],
            //'ItemId' => $data['holdings_id'],         //katkey
            'ItemId' => $data['item_id'],               //mediennummer
            'RequestType' => "", #ORDER or PreBook, depending on holdtype
            //'RequestScopeType' => "BibliographicId",  //katkey
            'RequestScopeType' => "ItemId",             //mediennummer
            'LocationNameLevel' => $data['location_code'],
        ];
        if($data['holdtype'] === "hold"){
            $options['RequestType'] = "ORDER";
            $status = 'hold_place';
        }elseif($data['holdtype'] === "recall"){
            $options['RequestType'] = "PreBook";
            $status = 'Recall This';
        }

        # send ncip request
        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        # return nothing in case no response or of a problem
        if(is_null($response)){
            return [
            'valid' => false,
            'status' => 'Problem connecting to the ILS!',
            ];
        }
        if(isset($xml->RequestItemResponse->Problem)){
            return [
            'valid' => false,
            'status' => (string)$xml->RequestItemResponse->Problem->ProblemDetail,
            ];
        }

        return [
            'valid' => true,
            'status' => $status,
        ];
    }

    /**
    *
    * This method returns a list of locations where a user may collect a hold
    *
    * @param array $patron - Patron array returned by patronLogin method, hold information array similar to placeHold's input
    *
    * @return array an array of associative arrrays with locationID and locationDisplay as keys
    */
    public function getPickUpLocations(array $patron, array $holdDetails = Null) : array
    {

        # options for request check
        $options = [
            'NCIPFunction' => "RequestItem",
            'UserId' => $patron['id'],
            'Password' => $patron['cat_password'],
            //'ItemId' => $holdDetails['holdings_id'],  //katkey
            'ItemId' => $holdDetails['item_id'],        //mediennummer
            'RequestType' => "", #ORDER or PreBook, depending on holdtype
            'LocationNameLevel' => $holdDetails['location_code'],
            //'RequestScopeType' => "BibliographicId",  //katkey
            'RequestScopeType' => "ItemId",             //mediennummer
        ];
        if($holdDetails['holdtype'] === "hold"){
            $options['RequestType'] = "ORDER";
        }elseif($holdDetails['holdtype'] === "recall"){
            $options['RequestType'] = "PreBook";
        }

        # send ncip request
        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        # return nothing in case no response or of a problem
        if(is_null($response)){
            return [];
        }
        if(isset($xml->RequestItemResponse->Problem)){
            return [];
        }

        $returnvalue = [];

        # get the pickuplocations
        foreach($xml->RequestItemResponse->Ext->LocationNameInstance as $locationNameInstance)
        {
            if(!((string)$locationNameInstance->LocationNameLevel == "15"))
            {
                $returnvalue[] = [
                    'locationID' => (string)$locationNameInstance->LocationNameLevel,
                    'locationDisplay' => (string)$locationNameInstance->LocationNameValue,
                ];
            }
        }

        return $returnvalue;
    }

    /**
    *
    * This method returns the default pick up location code or id for use when placing holds and other requests.
    *
    * @param array $patron - Patron array returned by patronLogin method
    * @param array $holdDetails - hold information array similar to placeHold's input
    *
    * @return string A pick up location id or code, this may also return false to force the user to choose a location
    */
    public function getDefaultPickUpLocation(array $patron = Null, array $holdDetails = Null) : string
    {
        return false;
    }


    /***************************************************************************************
    * CANCELREQUESTITEM
    ****************************************************************************************/

    /**
    * This method returns a string to use as the input form value for cancelling each hold item.
    *
    * @param array $holdDetails - One of the individual item arrays returned by the getMyHolds
    * method
    *
    * @return string A string to use as the input form value for cancelling each hold item; you
    * can pass any data that is needed by your ILS to identify the hold – the output of
    * this method will be used as part of the input to the cancelHolds method. Starting
    * with VuFind® 3.0, you may indicate holds which are not allowed to be cancelled by
    * returning an empty string.
    */
    public function getCancelHoldDetails(array $holdDetails) : string
    {

        if($holdDetails['type'] == "hold"){
            return "";
        }
        return $holdDetails['holdings_id'];
    }

    /**
    * Cancel Holds
    *
    * Attempts to Cancel a hold or recall on a particular item.
    *
    * @param array $cancelDetails - An array with two keys: patron (array returned
    * by the driver's patronLogin method) and details (an array of strings –
    * either cancel_details values provided by the getMyHolds method, or values
    * returned by the driver's getCancelHoldDetails method if cancel_details was
    * unset)
    *
    * @return array An array of data on each request including
    * whether or not it was successful.
    */
    public function cancelHolds(array $cancelDetails) : array
    {

        // initialize return array
        $returnvalue = [
            'count' => 0,
            'items' => []
        ];

        foreach($cancelDetails['details'] as $item)
        {
            // set options for the ncip request
            $options = [
                'NCIPFunction' => "CancelRequestItem",
                'ItemId' => $item,
                'UserId' => $cancelDetails['patron']['id'],
                'Password' => $cancelDetails['patron']['cat_password'],
                'Requesttype' => "PreBook", #Hardcoded, because only PreBook (Vormerkung/recall) can be cancelled
                'LocationNameLevel' => "00", #Temporarely Hardcode; probably doesnt matter here
            ];

            // send NCIP request
            $request = $this->createMessage($options);
            $response = $this->sendRequest($request);
            $xml = simplexml_load_string($response);

            if(is_null($response)){
                return null;
            }

            // check if the cancelation was successful
            if($xml->CancelRequestItemResponse->Problem){
                $returnvalue['items'][$item] = ['success' => false, 'status' => ((string)$xml->CancelRequestItemResponse->Problem->ProblemDetail)];
            }else{
                $returnvalue['count']++; # only count successful cancellations
                $returnvalue['items'][$item] = ['success' => true, 'status' => "Die Vormerkung wurde erfolgreich storniert!"];
            }

        }

        // return array
        return $returnvalue;

    }


    /***************************************************************************************
    * RENEWITEM
    ****************************************************************************************/

    /**
    * This method returns a string to use as the input form value for renewing each hold item.
    *
    * @param array $checkOutDetails - One of the individual item arrays returned by the getMyTransactions method
    *
    * @return string A string to use as the input form value for renewing each item; you can pass any data that
    * is needed by your ILS to identify the transaction to renew
    */
    public function getRenewDetails(array $checkOutDetails) : string
    {
        return $checkOutDetails['item_id'];
    }

    /**
    * This method renews a list of items for a specific patron.
    *
    * @param array $renweDetails - An associative array with two keys:
    *   - patron - array returned by patronLogin method
    *   - details - array of values returned by the getRenewDetails method identifying which items to renew
    *
    * @return array An associative array with two keys:
    *   - blocks - An array of strings specifying why a user is blocked from renewing (false if no blocks)
    *   - details - Not set when blocks exist; otherwise, an array of associative arrays (keyed by item ID) with each subarray containing these keys:
    *       - success – Boolean true or false
    *       - new_date – string – A new due date
    *       - new_time – string – A new due time
    *       - item_id – The item id of the renewed item
    *       - sysMessage – A system supplied renewal message (optional)
    */
    public function renewMyItems(array $renewDetails) : array
    {

        // check if blocks are present
        $blocks = $this->getAccountBlocks($renewDetails['patron']);

        if(!$blocks){

            // initialize return array
            $returnvalue = [
                'blocks' => false,
                'details' => [],
            ];

            // if no blocks are present renew each item from $renewDetails['details']
            foreach($renewDetails['details'] as $item)
            {

                $options = [
                    'NCIPFunction' => "RenewItem",
                    'UserId' => $renewDetails['patron']['id'],
                    'Password' => $renewDetails['patron']['cat_password'],
                    'ItemId' => $item,
                ];

                // send NCIP request
                $request = $this->createMessage($options);
                $response = $this->sendRequest($request);
                $xml = simplexml_load_string($response);

                if(is_null($response)){
                    return $returnvalue;
                }

                // check if the cancelation was successful
                if($xml->RenewItemResponse->Problem){
                    $returnvalue['details'][$item] = [
                        'success' => false,
                        'new_date' => NULL,
                        'new_time' => NULL,
                        'item_id' => $item,
                        'sysMessage' => ((string)$xml->RenewItemResponse->Problem->ProblemDetail),
                    ];
                }else{
                    $returnvalue['details'][$item] = [
                        'success' => true,
                        'new_date' => $this->formatDate((string)$xml->RenewItemResponse->Ext->ItemDetails->Ext->DateDue),
                        'new_time' => "",
                        'item_id' => $item,
                        'sysMessage' => (string)$xml->RenewItemResponse->Ext->ItemDetails->Ext->ShippingNote ?? "",
                    ];
                }
            }

            return $returnvalue;

        }else{
            # if blocks are present return the blocks
            return [ 'blocks' => $blocks,];
        }

    }


    /***************************************************************************************
    * Different Mappings and Helper Functions
    ****************************************************************************************/

    /**
     * checks for a Circulation Status Code if it is available for request (for order/Bestellung or prebook/Vormerkung)
     *
     * @param string $statuscode - CS-Status Code from NCIP
     *
     * @return bool true or false
     */
    function checkCsStatus(string $statuscode) : bool
    {
        $returnvalue = $this->config['RequestCode'][$statuscode] ?? null;

        if(is_null($returnvalue)){
            return false;
        }else{
            return $returnvalue;
        }
    }

    /**
     * Checks for a Circulation Status Code if it is available in any form.
     *
     * @param string $statuscode - CS-Status Code from NCIP
     *
     * @return bool true or false
     * @return int [0,1,2] (since VuFind v. 9.1)
     */
    function isAvailable(string $statuscode) : int
    {
        $returnvalue = $this->config['IsAvailable'][$statuscode] ?? 2;

        return $returnvalue;

    }

    /**
    * Checks for a Circulation Status Code if the item is renewable.
    * Because combinations of different status codes are possible, only the end of $status is checked.
    *
    * @param string $status - NCIP circulation status code for loaned items
    *
    * @return bool true if the item is renewable or Bool false if it isn't
    */
    function isRenewable(string $status) : bool
    {
        if( substr($status, -16) == "_RenewalPossible"){
            return true;
        }
        if( substr($status, -18) == "_RenewSubsequently"){
            return true;
        }
        return false;
    }

    /**
    * @param string $dueDate - date string for the due date
    *
    * @return string [due, overdue, emptystring]
    */
    function isDue(string $dueDate) : string
    {
        $dueDateTime = new DateTime($dueDate, new DateTimeZone('Europe/Berlin'));
        $todayDateTime = new DateTime('', new DateTimeZone('Europe/Berlin'));
        $dateDiff = (int)$todayDateTime->diff($dueDateTime)->days;

        if($todayDateTime > $dueDateTime){
          if($dateDiff == 0){
            return "due";
          } else{
            return "overdue";
          }
        }else{
            return "";
        }
    }

    /**
    * @param strin $dueStatus - string for ciculation status
    *
    * @return string [due, overdue, missing, renewal, no_renewal or an emptystring]
    */
    function getDueStatus(string $dueStatus, string $dueDate) : string
    {
      $status = (string)$dueStatus;
      $missed = 'Missed';
      $overdue = 'DueDateExceeded';
      $renewal = 'RenewalPossible';
      $no_renewal = 'NoRenewalPossible';
      $no_renewal_yet = 'RenewalNotYet';
      $staff_only = "StaffOnly"; //renewal not possible

	    $isdue = $this->isDue($dueDate);

      if (str_contains($status,$missed)) {
        return "missed";
      }elseif (strlen($isdue) > 0) {
          if($isdue === "due"){
            return "due";
          }else {
  			    return "overdue";
  		    }
      }elseif (str_contains($status,$no_renewal) || str_contains($status,$staff_only)) {
        return "no_renewal";
      }elseif (str_contains($status,$renewal)) {
        return "renewable";
      }elseif (str_contains($status,$no_renewal_yet)) {
        return "renewal_not_yet";
      }else {
        return "";
      }
    }

    /**
     * Checks for a Circulation Status Code that can be requested if it is an ORDER (hold/Bestellung) or a PreBook (recall/Vormerkung).
      * needed for placeHold / RequestItem
     *
     * @param string $statuscode - CS-Status Code from NCIP
     *
     * @return string [hold, recall]
     */
    function getRequestType(string $statuscode) : string
    {
        $returnvalue = $this->config['RequestType'][$statuscode] ?? "hold";
        return $returnvalue;
    }

    /**
     * Gets the Status String for a given Circulation Status Code.
     * needed for getStatus/getHolding
     *
     * @param string $statuscode - CS-Status Code from NCIP
     *
     * @return string for output on the website
     */
    function getStatusString(string $statuscode)
    {
        $returnvalue = $this->config['StatusString'][$statuscode] ?? Null;
        return $returnvalue;
    }

    /**
    * Zweigstellencode anhand des Location Strings bestimmen
    *
    * @param string $location
    *
    * @return string location code
    */
    function getLocationCode(string $location) : string
    {
        $returnvalue = $this->config['LocationCode'][$location] ?? "98";

        return $returnvalue;
    }

    /**
    *
    * URL-Umleitungen für Bibliotheksinfos
    *
    * @param string $locationurl
    *
    * @return string url
    */
    function getLocationURL(string $locationurl) : string
    {
        $returnvalue = $this->config['LocationURL'][$locationurl] ?? $locationurl;

        return $returnvalue;
    }

    /**
     * Formats the date strings from NCIP.
     *
     * @param string $date - String from NCIP
     *
     * @return string "d.m.Y"
     */
    function formatDate(string $date) : string
    {
        $dateTime = new DateTime($date, new DateTimeZone('Europe/Berlin'));

        return (string)$dateTime->format('d.m.Y');
    }

    /**
     * Given a shelfmark gets the BibliographicItemIdentifier from NCIP per LookupItem.
     *
     * @param string $shelfmark - shelfmark of the item
     * @param string $item_id   - mediennummer of the item
     *
     * @return string $id on success or emptystring
     */
    function getIdFromShelfmark(string $shelfmark, string $item_id) : string
    {
        $options = [
            'NCIPFunction' => "LookupItem",
            'ItemId' => $shelfmark,
            'ItemIdentifierType' => "ShelfMark",
        ];

        $request = $this->createMessage($options);
        $response = $this->sendRequest($request);
        $xml = simplexml_load_string($response);

        if(is_null($response)){
            return "";
        }

        if(!isset($xml->LookupItemResponse->Item)){
            return "";
        }

        //Fernleihbestellungen
        if(str_starts_with($item_id, '@')) {
          return $item_id;
        }

        // Bindeeinheiten abfangen
        $index = 0;
        foreach($xml->LookupItemResponse->Item->ItemOptionalFields->Ext->UnstructuredAddressType as $uat)
        {
            if(((string)$uat) == "FootNotes"){
                if(((string)$xml->LookupItemResponse->Item->ItemOptionalFields->Ext->UnstructuredAddressData[$index]) == "Sammelband")
                {
                    return "";
                }
            }
            $index = $index + 1;
        }

        // in case of multiple items found return the id of the item with the correct item_id
        foreach($xml->LookupItemResponse->Item as $item)
        {
           if((string)$item->ItemId->ItemIdentifierValue == $item_id){
               return (string)$item->ItemOptionalFields->Ext->BibliographicItemIdentifier;
           }
        }

        return "";
    }

    /**
     * Return location and pickupLocation for borrowingLocation.
     *
     * @param string $locationName
     * @param string $pickupLocation
     *
     * @return string $borrowingLocation
     */
    function getborrowingLocation(string $locationName, string $pickupLocation) : string
    {
      if($pickupLocation === "") {
        return $locationName;
      } else {
        return $locationName . ' / ' . $pickupLocation;
      }
    }

    /***************************************************************************************
    * NCIP COMMUNICATION FUNCTIONS
    ****************************************************************************************/

    /**
    * Send requests to NCIP server and return the response
    *
    * @param string $message - ncip request in xml format
    *
    * @return string response from ncip
    */
    function sendRequest(string $message) : string
    {

        #curl init
        $ch = curl_init($this->url);

        $headers = [
            "Content-Type: document/xml"
        ];

        #curl setopt
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        # SSL ERROR JANUAR 2024
        #curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        # 02.08.24 Connection Problem NCIP2SLNP Modul
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        #curl exec
        $response = curl_exec($ch);

        #curl close
        curl_close($ch);

        #return response
        return $response;
    }

    /**
    * Create message-xml-string for all kinds NCIP requests
    *
    * via:  XMLWriter               https://www.php.net/manual/en/book.xmlwriter.php
    *
    * @param array options: [NCIPFunction, UserId, Password, ItemId, Requesttype, Infotype, NewPassword, ...]
    *
    *   NCIPFunction        What NCIP Function to use
    *   UserId              UserId of the User
    *   Password            Password of the User
    *   ItemId              ItemId of the item to search, requst, etc.
    *   Requesttype         The type of request to execute (ORDER, PreBook)
    *   Infotype            What User Information to get (user, fiscal, loan, order); if no extra infos are needed give any other string, ie. "none"
    *   NewPassword         New Password for Password change in UpdateUser
    *   PickupLocation      selected branch library for LookupItem and RequestItem
    *   ItemIdentifierType  [TitleId, BibliographicId] for LookupItem
    *   ...                 possible additional options for RequestItem
    *
    *   @return string XML string for NCIP request
    */
    private function createMessage(array $options) : string
    {

        #create xml variable in memory
        $xml = xmlwriter_open_memory();

        #first xml line; defines xml version and charset
        xmlwriter_start_document($xml, '1.0', 'UTF-8');

            #NCIP message tag start
            xmlwriter_start_element($xml, 'NCIPMessage');

            #NCIP function tag start
            xmlwriter_start_element($xml, $options['NCIPFunction']);

                #LookupAgency
                if ($options['NCIPFunction'] == 'LookupAgency'){
                    xmlwriter_start_element($xml, 'AgencyId');
                        xmlwriter_text($xml, 'sisis');
                    xmlwriter_end_element($xml);
                    xmlwriter_start_element($xml, 'AgencyElementType');
                        xmlwriter_text($xml, 'BranchView');
                    xmlwriter_end_element($xml);
                }

                #Authentication
                if (($options['NCIPFunction'] == 'LookupUser') or ($options['NCIPFunction'] == 'UpdateUser') or ($options['NCIPFunction'] == 'RequestItem') or ($options['NCIPFunction'] == 'CancelRequestItem') or ($options['NCIPFunction'] == 'RenewItem')){
                    xmlwriter_start_element($xml, 'AuthenticationInput');
                        xmlwriter_start_element($xml, 'AuthenticationInputData');
                            xmlwriter_text($xml, $options['UserId']);
                        xmlwriter_end_element($xml);
                        xmlwriter_start_element($xml, 'AuthenticationDataFormatType');
                            xmlwriter_text($xml, 'text');
                        xmlwriter_end_element($xml);
                        xmlwriter_start_element($xml, 'AuthenticationInputType');
                            xmlwriter_text($xml, 'UserId');
                        xmlwriter_end_element($xml);
                    xmlwriter_end_element($xml);
                    xmlwriter_start_element($xml, 'AuthenticationInput');
                        xmlwriter_start_element($xml, 'AuthenticationInputData');
                            xmlwriter_text($xml, $options['Password']);
                        xmlwriter_end_element($xml);
                        xmlwriter_start_element($xml, 'AuthenticationDataFormatType');
                            xmlwriter_text($xml, 'text');
                        xmlwriter_end_element($xml);
                        xmlwriter_start_element($xml, 'AuthenticationInputType');
                            xmlwriter_text($xml, 'Password');
                        xmlwriter_end_element($xml);
                    xmlwriter_end_element($xml);
                }

                #LookupUser: get fiscal data
                if (($options['NCIPFunction'] == 'LookupUser') and ($options['Infotype'] == 'fiscal')){
                    xmlwriter_write_element($xml, "UserFiscalAccountDesired");
                }

                #LookupUser: get account information
                if (($options['NCIPFunction'] == 'LookupUser') and ($options['Infotype'] == 'user')){
                    xmlwriter_start_element($xml, 'UserElementType');
                        xmlwriter_text($xml, 'NameInformation');
                    xmlwriter_end_element($xml);
                    xmlwriter_start_element($xml, 'UserElementType');
                        xmlwriter_text($xml, 'UserAddressInformation');
                    xmlwriter_end_element($xml);
                    xmlwriter_start_element($xml, 'UserElementType');
                        xmlwriter_text($xml, 'DateOfBirth');
                    xmlwriter_end_element($xml);
                    xmlwriter_start_element($xml, 'UserElementType');
                        xmlwriter_text($xml, 'UserLanguage');
                    xmlwriter_end_element($xml);
                }

                #LookupUser: get list of loaned items
                if (($options['NCIPFunction'] == 'LookupUser') and ($options['Infotype'] == 'loan')){
                    xmlwriter_write_element($xml, "LoanedItemsDesired");
                }

                #LookupUser: get list of requested items
                if (($options['NCIPFunction'] == 'LookupUser') and ($options['Infotype'] == 'order')){
                    xmlwriter_write_element($xml, "RequestedItemsDesired");
                }

                #UpdateUser: password change
                #WIP
                if ($options['NCIPFunction'] == 'UpdateUser'){
                    #Delete old Password
                    xmlwriter_start_element($xml, 'DeleteUserFields');
                        xmlwriter_start_element($xml, 'UserAddressInformation');
                            xmlwriter_start_element($xml, 'UserAddressRoleType');
                                xmlwriter_text($xml, 'UserManagementData');
                            xmlwriter_end_element($xml);
                            xmlwriter_start_element($xml, 'Ext');
                                xmlwriter_start_element($xml, 'UnstructuredAddressType');
                                    xmlwriter_text($xml, "PIN");
                                xmlwriter_end_element($xml);
                                xmlwriter_start_element($xml, 'UnstructuredAddressData');
                                    xmlwriter_text($xml, $options['Password']);
                                xmlwriter_end_element($xml);
                            xmlwriter_end_element($xml);
                        xmlwriter_end_element($xml);
                    xmlwriter_end_element($xml);
                    #Set new Password
                    xmlwriter_start_element($xml, 'AddUserFields');
                        xmlwriter_start_element($xml, 'UserAddressInformation');
                            xmlwriter_start_element($xml, 'UserAddressRoleType');
                                xmlwriter_text($xml, 'UserManagementData');
                            xmlwriter_end_element($xml);
                            xmlwriter_start_element($xml, 'Ext');
                                xmlwriter_start_element($xml, 'UnstructuredAddressType');
                                    xmlwriter_text($xml, "PIN");
                                xmlwriter_end_element($xml);
                                xmlwriter_start_element($xml, 'UnstructuredAddressData');
                                    xmlwriter_text($xml, $options['NewPassword']);
                                xmlwriter_end_element($xml);
                            xmlwriter_end_element($xml);
                        xmlwriter_end_element($xml);
                    xmlwriter_end_element($xml);
                }

                # RenewItem
                if ($options['NCIPFunction'] == 'RenewItem'){
                    xmlwriter_start_element($xml, 'ItemId');
                        xmlwriter_start_element($xml, 'ItemIdentifierValue');
                            xmlwriter_text($xml, $options['ItemId']);
                        xmlwriter_end_element($xml);
                    xmlwriter_end_element($xml);
                }

                # LookupItem
                if ($options['NCIPFunction'] == 'LookupItem'){
                    xmlwriter_start_element($xml, 'ItemId');
                        xmlwriter_start_element($xml, 'ItemIdentifierValue');
                            xmlwriter_text($xml, $options['ItemId']);
                        xmlwriter_end_element($xml);
                        xmlwriter_start_element($xml, 'ItemIdentifierType');
                            xmlwriter_text($xml, $options['ItemIdentifierType']);
                        xmlwriter_end_element($xml);
                    xmlwriter_end_element($xml);
                    # Circulationstatus
                    if (array_key_exists("CirculationStatus", $options)){
                        xmlwriter_start_element($xml, 'ItemElementType');
                            xmlwriter_text($xml, 'CirculationStatus');
                        xmlwriter_end_element($xml);
                    }
                }

                # RequestItem
                if ($options['NCIPFunction'] == 'RequestItem'){
                    # BibliographicId
                    xmlwriter_start_element($xml, 'BibliographicId');
                        xmlwriter_start_element($xml, 'BibliographicRecordId');
                            xmlwriter_start_element($xml, 'BibliographicRecordIdentifier');
                                xmlwriter_text($xml, $options['ItemId']);
                            xmlwriter_end_element($xml);
                        xmlwriter_end_element($xml);
                    xmlwriter_end_element($xml);
                    # RequestType
                    if(array_key_exists("RequestType", $options)){
                        xmlwriter_start_element($xml, 'RequestType');
                            xmlwriter_text($xml, $options['RequestType']);
                        xmlwriter_end_element($xml);
                    }
                    #RequestScopeType
                    if(array_key_exists("RequestScopeType", $options)){
                        xmlwriter_start_element($xml, 'RequestScopeType');
                            xmlwriter_text($xml, $options['RequestScopeType']);
                        xmlwriter_end_element($xml);
                    }
                    # PickupLocation
                    if(array_key_exists("PickupLocation", $options)){
                        xmlwriter_start_element($xml, 'PickupLocation');
                            xmlwriter_text($xml, $options['PickupLocation']);
                        xmlwriter_end_element($xml);
                    }
                }

                # CancelRequestItem
                if ($options['NCIPFunction'] == 'CancelRequestItem'){
                    # ItemId
                    xmlwriter_start_element($xml, 'ItemId');
                        xmlwriter_start_element($xml, 'ItemIdentifierValue');
                            xmlwriter_text($xml, $options['ItemId']);
                        xmlwriter_end_element($xml);
                    xmlwriter_end_element($xml);
                    # RequestType
                    xmlwriter_start_element($xml, 'RequestType');
                        xmlwriter_text($xml, $options['RequestType']);
                    xmlwriter_end_element($xml);
                }

                //Ext tag
                if ($options['NCIPFunction'] != 'LookupUser'){
                    xmlwriter_start_element($xml, 'Ext');
                        xmlwriter_start_element($xml, 'AgencyId');
                            xmlwriter_text($xml, 'sisis');
                        xmlwriter_end_element($xml);
                        xmlwriter_start_element($xml, 'LocationNameLevel');
                            if(array_key_exists("LocationNameLevel", $options)){
                                xmlwriter_text($xml, "1".$options['LocationNameLevel']);
                            }else{
                                xmlwriter_text($xml, '100');
                            }
                        xmlwriter_end_element($xml);
                        xmlwriter_start_element($xml, 'Language');
                            xmlwriter_text($xml, 'de');
                        xmlwriter_end_element($xml);
                        if(($options['NCIPFunction'] == 'LookupItem') && array_key_exists("UserId", $options)){
                            xmlwriter_start_element($xml, 'UserIdentifierValue');
                                xmlwriter_text($xml, $options['UserId']);
                            xmlwriter_end_element($xml);
                        }
                    xmlwriter_end_element($xml);
                }


            #NCIP function tag end
            xmlwriter_end_element($xml);

            #NCIP message tag end
            xmlwriter_end_element($xml);

        #End xml creation
        xmlwriter_end_document($xml);

        #return xml from memory
        return xmlwriter_output_memory($xml);
    }

}
