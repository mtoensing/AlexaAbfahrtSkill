<?php

/**
 *
 * Marc Tönsing 2018
 *
 * Class AlexaAbfahrtenSkill
 */

class AlexaAbfahrtenSkill
{

    public $list_image_url = "https://traintime.marc.tv/assets/tram-small.png";
    public $setup = array(
        'ApplicationID' => 'amzn1.ask.skill.8228c964-a30c-41af-b817-948bd6c7903c',
        // From your ALEXA developer console like: 'amzn1.ask.skill.45c11234-123a-1234-ffaa-1234567890a'
        'CheckSignatureChain' => true,
        // make sure the request is a true amazonaws api call
        'ReqValidTime' => 60,
        // Time in Seconds a request is valid
        'AWSaccount' => '',
        //If this is != empty the specified session->user->userId is required. This is usefull for account bound private only skills
        'validIP' => false,
        // Limit allowed requests to specified IPv4, set to FALSE to disable the check.
        'LC_TIME' => "de_DE"
        // We use german Echo so we want our date output to be german
    );
    public $debug = false;
    public $journeys = array();
    public $destination = '';
    public $rawJSON;
    public $destination_only;
    public $EchoReqObj = '';
    public $display_supported = false;
    public $replace_in_output = '';

    public function __construct($origin, $destination, $application_id)
    {
        $this->destination = $destination;
        $this->setup['ApplicationID'] = $application_id;
        $DBreiseplanner = new DBreiseplanner($origin, $destination);
        $DBreiseplanner->cache_in_minutes = 0;
        $DBreiseplanner->getXML();
        $DBreiseplanner->fillJourneys();

        $this->journeys = $DBreiseplanner->getJourneys();

        if (isset($_GET["debug"]) AND htmlspecialchars($_GET["debug"]) == true) {
            $this->setDebug(true);
        }
    }

    /**
     * @param bool $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * @param array $journeys
     */
    public function setJourneys($journeys)
    {
        $this->journeys = $journeys;
    }

    /**
     * @param bool $display_supported
     */
    public function setDisplaySupported($display_supported)
    {
        $this->display_supported = $display_supported;
    }

    public function ThrowRequestError($code = 400, $msg = 'Bad Request')
    {
        $code = 400;
        http_response_code($code);
        echo "Error " . $code . "<br />\n" . $msg;
        error_log("alexa" . $msg, 0);
        exit();
    }


    public function validateRequest()
    {
        /**
         * Thanks to @solariz for the amazon certificate authentication
         * https://gist.github.com/solariz/a7b7b09e46303223523bba2b66b9b341
         */

        $rawJSON = file_get_contents('php://input');
        $this->rawJSON = $rawJSON;
        $EchoReqObj = json_decode($rawJSON);
        $this->EchoReqObj = $EchoReqObj;

        if (isset($EchoReqObj->context->System->device->supportedInterfaces->Display)) {
            $this->setDisplaySupported(true);
        }

        if ($this->debug == false) {

            $EchoReqObj = $this->EchoReqObj;
            $rawJSON = $this->rawJSON;
            $SETUP = $this->setup;

            if ($EchoReqObj == '') {
                $this->ThrowRequestError(400, "Result is empty.");
            }

            // Check if Amazon is the Origin
            if (is_array($SETUP['validIP'])) {
                $isAllowedHost = false;
                foreach ($SETUP['validIP'] as $ip) {
                    if (stristr($_SERVER['REMOTE_ADDR'], $ip)) {
                        $isAllowedHost = true;
                        break;
                    }
                }
                if ($isAllowedHost == false) {
                    $this->$this->ThrowRequestError(400, "Forbidden, your Host is not allowed to make this request!");
                }
                unset($isAllowedHost);
            }

            // Check if correct requestId
            if (strtolower($EchoReqObj->session->application->applicationId) != strtolower($SETUP['ApplicationID']) || empty($EchoReqObj->session->application->applicationId)) {
                $this->ThrowRequestError(400, "Forbidden, unkown Application ID!");
            }
            // Check SSL Signature Chain
            if ($SETUP['CheckSignatureChain'] == true) {
                if (preg_match("/https:\/\/s3.amazonaws.com(\:443)?\/echo.api\/*/i", $_SERVER['HTTP_SIGNATURECERTCHAINURL']) == false) {
                    $this->ThrowRequestError(400, "Forbidden, unkown SSL Chain Origin!");
                }
                // PEM Certificate signing Check
                // First we try to cache the pem file locally
                $local_pem_hash_file = sys_get_temp_dir() . '/' . hash("sha256", $_SERVER['HTTP_SIGNATURECERTCHAINURL']) . ".pem";
                if (!file_exists($local_pem_hash_file)) {
                    file_put_contents($local_pem_hash_file, file_get_contents($_SERVER['HTTP_SIGNATURECERTCHAINURL']));
                }
                $local_pem = file_get_contents($local_pem_hash_file);


                if (openssl_verify($rawJSON, base64_decode($_SERVER['HTTP_SIGNATURE']), $local_pem) !== 1) {
                    $this->ThrowRequestError(400, "Forbidden, failed to verify SSL Signature!");
                }

                // Parse the Certificate for additional Checks
                $cert = openssl_x509_parse($local_pem);
                if (empty($cert)) {
                    $this->ThrowRequestError(400, "Certificate parsing failed!");
                }
                // SANs Check
                if (stristr($cert['extensions']['subjectAltName'], 'echo-api.amazon.com') != true) {
                    $this->ThrowRequestError(400, "Forbidden! Certificate SANs Check failed!");
                }
                // Check Certificate Valid Time
                if ($cert['validTo_time_t'] < time()) {
                    $this->ThrowRequestError(400, "Forbidden! Certificate no longer Valid!");
                    // Deleting locally cached file to fetch a new at next req
                    if (file_exists($local_pem_hash_file)) {
                        unlink($local_pem_hash_file);
                    }
                }
                // Cleanup
                unset($local_pem_hash_file, $cert, $local_pem);
            }
            // Check Valid Time
            if (time() - strtotime($EchoReqObj->request->timestamp) > $SETUP['ReqValidTime']) {
                $this->ThrowRequestError(400, "Request Timeout! Request timestamp is to old.");
            }
            // Check AWS Account bound, if this is set only a specific aws account can run the skill
            if (!empty($SETUP['AWSaccount'])) {
                if (empty($EchoReqObj->session->user->userId) || $EchoReqObj->session->user->userId != $SETUP['AWSaccount']) {
                    $this->ThrowRequestError(400, "Forbidden! Access is limited to one configured AWS Account.");
                }
            }
        }
    }

    public function getAlexaJSONResponse()
    {
        $this->validateRequest();

        $title = $this->journeys[0]->origin . ' in Richtung ' . $this->destination;
        $title = str_replace($this->replace_in_output[0], $this->replace_in_output[1], $title);

        $delay = '';

        if (count($this->journeys) > 0) {
            if ($this->journeys[0]->delay > 0 OR $this->journeys[1]->delay > 0) {
                $delay = 'Verspätung! ';
            }

            $speech = $delay . 'In ' . $this->journeys[0]->getRealtime() . ' Minuten fährt die ' . $this->journeys[0]->product . ' ab ' . $this->journeys[0]->origin . ' in Richtung ' . $this->destination . '.';
        }

        if (count($this->journeys) == 0) {
            $speech = 'Ich habe aktuell keine Informationen zu Abfahrten ' . $this->origin;
        } elseif (count($this->journeys) > 1) {
            $speech = $speech . ' Die übernächste ' . $this->journeys[1]->product . ' kommt in ' . $this->journeys[1]->getRealtime() . ' Minuten.';
        }

        $speech = str_replace($this->replace_in_output[0], $this->replace_in_output[1], $speech);
        $speech = str_replace($this->remove_from_output, "", $speech);

        $items = array();
        $count = 0;

        foreach ($this->journeys as $journey) {

            $text = 'In <b>' . $journey->getRealtime() . '</b> Minuten';

            $items[] = [
                'token' => 'departure-item-' . $count,
                'image' => [
                    'contentDescription' => 'Tram',
                    'sources' => array(
                        [
                            'url' => $this->list_image_url,
                        ]
                    ),
                ],
                'textContent' => [
                    'primaryText' => [
                        'text' => $text,
                        'type' => 'RichText',
                    ],
                ]
            ];

            $count++;

        }

        $responseArray = [
            'version' => '1.0',
            'response' => [
                'outputSpeech' => [
                    'type' => 'PlainText',
                    'text' => $speech,
                    'ssml' => null
                ],

                'shouldEndSession' => true
            ]
        ];

        $directives = array(
            [
                'type' => 'Display.RenderTemplate',
                'template' => [
                    'type' => 'ListTemplate1',
                    'token' => 'departure-list',
                    'title' => $title,
                    'listItems' => $items,
                ],
            ]
        );

        if (($this->display_supported OR $this->debug == true) AND count($items) > 0) {
            $responseArray['response']['directives'] = $directives;
        }

        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header("Content-type: application/json; charset=utf-8");

        $json = json_encode($responseArray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $json;
    }

}

?>

