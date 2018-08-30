<?php

class AlexaAbfahrtSkill {

	public function __construct() {
		$DBJourneyXMLParser = new DBJourneyXMLParser();

		$DBJourneyXMLParser->getRequest();
		$DBJourneyXMLParser->remove_from_output = array( ', Hannover', 'Hannover,' );
		$DBJourneyXMLParser->replace_in_output  = array( 'STB', 'Stadtbahn ' );
		$DBJourneyXMLParser->setOrigin( "Hannover, Kafkastrasse" );
		$DBJourneyXMLParser->setDestination( "Wettbergen, Hannover" );
		$DBJourneyXMLParser->setShowDestinationOnly( true );
		$DBJourneyXMLParser->getXML();
		$DBJourneyXMLParser->fillJourneys();

		echo $DBJourneyXMLParser->getAlexaJSON();
	}

}

$alexa = new AlexaAbfahrtSkill();

class Journey {

	public $product = '';
	public $destination = '';
	public $arrival_timestamp = '';
	public $delay = '';


	public function getArrivalFullDate() {
		$arrival_date = date( 'l dS \o\f F Y H:i:s', $this->arrival_timestamp );

		return $arrival_date;
	}

	public function getRelativeMinutes() {
		$timestampt_diff = $this->arrival_timestamp - time();
		$minutes         = floor( $timestampt_diff / 60 );

		return $minutes;
	}

	/**
	 * @param string $delay
	 */
	public function setDelay( $delay ) {
		if ( is_numeric( $delay ) ) {
			$this->delay = intval( $delay );
		} else {
			$this->delay = 0;
		}
	}

	/**
	 * @param string $product
	 */
	public function setProduct( $product ) {
		$this->product = $product;
	}

	/**
	 * @param string $destination
	 */
	public function setDestination( $destination ) {
		$this->destination = $destination;
	}

	/**
	 * @param string $arrival_timestamp
	 */
	public function setArrivalTimestamp( $arrival_timestamp ) {
		$this->arrival_timestamp = $arrival_timestamp;
	}

	public function fixProduct() {
		$product       = trim( $this->product );
		$product       = substr( $product, 0, strpos( $product, "#" ) );
		$this->product = preg_replace( '/\s+/', '', $product );

	}

}

class DBJourneyXMLParser {

	const BAHN_ENDPOINT_URL = 'https://reiseauskunft.bahn.de//bin/stboard.exe/dn?rt=1&time=actual&start=yes&boardType=dep&L=vs_java3&input=';
	const USE_LOCALCOPY = true;
	const CACHE = true;

	public $data = '';
	public $journeys = array();
	public $journeys_xml = '';
	public $origin = '';
	public $destination = '';
	public $destination_only;
	public $EchoReqObj = '';
	public $display_supported = false;
	public $remove_from_output = '';
	public $replace_in_output = '';
	public $rawJSON;

	/**
	 * @param bool $display_supported
	 */
	public function setDisplaySupported( $display_supported ) {
		$this->display_supported = $display_supported;
	}

	/**
	 * @param mixed $filter_destination_only
	 */
	public function setShowDestinationOnly( $show_destination_only ) {
		$this->show_destination_only = $show_destination_only;
	}

	/**
	 * DBJourneyXMLParser
	 */
	public function __construct() {

	}

	public function getRequest() {
		$rawJSON          = file_get_contents( 'php://input' );
		$this->rawJSON    = $rawJSON;
		$EchoReqObj       = json_decode( $rawJSON );
		$this->EchoReqObj = $EchoReqObj;

		if ( isset( $EchoReqObj->context->System->device->supportedInterfaces->Display ) ) {
			$this->setDisplaySupported( true );
		}

		$this->validateRequest();
	}

	public function ThrowRequestError( $code = 400, $msg = 'Bad Request' ) {
		GLOBAL $SETUP;
		$code = 400;
		http_response_code( $code );
		echo "Error " . $code . "<br />\n" . $msg;
		error_log( "alexa/" . $SETUP['SkillName'] . ":\t" . $msg, 0 );
		exit();
	}


	public function validateRequest() {

		$SETUP = array(
			'SkillName'           => "kafkastrasse",
			'SkillVersion'        => '1.0',
			'ApplicationID'       => 'amzn1.ask.skill.6f5d7f58-b0c7-4ef4-96c8-dd28418c96ba',
			// From your ALEXA developer console like: 'amzn1.ask.skill.45c11234-123a-1234-ffaa-1234567890a'
			'CheckSignatureChain' => true,
			// make sure the request is a true amazonaws api call
			'ReqValidTime'        => 60,
			// Time in Seconds a request is valid
			'AWSaccount'          => '',
			//If this is != empty the specified session->user->userId is required. This is usefull for account bound private only skills
			'validIP'             => false,
			// Limit allowed requests to specified IPv4, set to FALSE to disable the check.
			'LC_TIME'             => "de_DE"
			// We use german Echo so we want our date output to be german
		);

		$EchoReqObj = $this->EchoReqObj;
		$rawJSON    = $this->rawJSON;

		if ( $EchoReqObj == '' ) {
			$this->ThrowRequestError( 400, "Result is empty." );
		}

		// Check if Amazon is the Origin
		if ( is_array( $SETUP['validIP'] ) ) {
			$isAllowedHost = false;
			foreach ( $SETUP['validIP'] as $ip ) {
				if ( stristr( $_SERVER['REMOTE_ADDR'], $ip ) ) {
					$isAllowedHost = true;
					break;
				}
			}
			if ( $isAllowedHost == false ) {
				$this->$this->ThrowRequestError( 400, "Forbidden, your Host is not allowed to make this request!" );
			}
			unset( $isAllowedHost );
		}

// Check if correct requestId
		if ( strtolower( $EchoReqObj->session->application->applicationId ) != strtolower( $SETUP['ApplicationID'] ) || empty( $EchoReqObj->session->application->applicationId ) ) {
			$this->ThrowRequestError( 400, "Forbidden, unkown Application ID!" );
		}
// Check SSL Signature Chain
		if ( $SETUP['CheckSignatureChain'] == true ) {
			if ( preg_match( "/https:\/\/s3.amazonaws.com(\:443)?\/echo.api\/*/i", $_SERVER['HTTP_SIGNATURECERTCHAINURL'] ) == false ) {
				$this->ThrowRequestError( 400, "Forbidden, unkown SSL Chain Origin!" );
			}
			// PEM Certificate signing Check
			// First we try to cache the pem file locally
			$local_pem_hash_file = sys_get_temp_dir() . '/' . hash( "sha256", $_SERVER['HTTP_SIGNATURECERTCHAINURL'] ) . ".pem";
			if ( ! file_exists( $local_pem_hash_file ) ) {
				file_put_contents( $local_pem_hash_file, file_get_contents( $_SERVER['HTTP_SIGNATURECERTCHAINURL'] ) );
			}
			$local_pem = file_get_contents( $local_pem_hash_file );
			if ( openssl_verify( $rawJSON, base64_decode( $_SERVER['HTTP_SIGNATURE'] ), $local_pem ) !== 1 ) {
				$this->ThrowRequestError( 400, "Forbidden, failed to verify SSL Signature!" );
			}
			// Parse the Certificate for additional Checks
			$cert = openssl_x509_parse( $local_pem );
			if ( empty( $cert ) ) {
				$this->ThrowRequestError( 400, "Certificate parsing failed!" );
			}
			// SANs Check
			if ( stristr( $cert['extensions']['subjectAltName'], 'echo-api.amazon.com' ) != true ) {
				$this->ThrowRequestError( 400, "Forbidden! Certificate SANs Check failed!" );
			}
			// Check Certificate Valid Time
			if ( $cert['validTo_time_t'] < time() ) {
				$this->ThrowRequestError( 400, "Forbidden! Certificate no longer Valid!" );
				// Deleting locally cached file to fetch a new at next req
				if ( file_exists( $local_pem_hash_file ) ) {
					unlink( $local_pem_hash_file );
				}
			}
			// Cleanup
			unset( $local_pem_hash_file, $cert, $local_pem );
		}
// Check Valid Time
		if ( time() - strtotime( $EchoReqObj->request->timestamp ) > $SETUP['ReqValidTime'] ) {
			$this->ThrowRequestError( 400, "Request Timeout! Request timestamp is to old." );
		}
// Check AWS Account bound, if this is set only a specific aws account can run the skill
		if ( ! empty( $SETUP['AWSaccount'] ) ) {
			if ( empty( $EchoReqObj->session->user->userId ) || $EchoReqObj->session->user->userId != $SETUP['AWSaccount'] ) {
				$this->ThrowRequestError( 400, "Forbidden! Access is limited to one configured AWS Account." );
			}
		}
	}

	public function getAlexaJSON() {

		$speech = 'Ich habe keine Informationen zu ' . $this->journeys[0]->product;
		$text1  = '';
		$text2  = '';
		$title  = $this->origin . ' in Richtung ' . $this->destination;

		$journeys_num = count( $this->journeys );


		switch ( true ) {
			case $journeys_num == 1:
				$speech = 'In ' . $this->journeys[0]->getRelativeMinutes() . ' Minuten fährt die nächste ' . $this->journeys[0]->product . ' ab ' . $this->origin . ' in Richtung ' . $this->destination . '.';
				$text1  = $this->journeys[0]->product . ' fährt in ' . $this->journeys[0]->getRelativeMinutes() . ' Minuten';
				break;
			case $journeys_num >= 1:
				$speech = 'In ' . $this->journeys[0]->getRelativeMinutes() . ' Minuten fährt die ' . $this->journeys[0]->product . ' ab ' . $this->origin . ' in Richtung ' . $this->destination . '. In ' . $this->journeys[1]->getRelativeMinutes() . ' Minuten kommt die nächste ' . $this->journeys[1]->product;
				$text1  = $this->journeys[0]->product . ' fährt in ' . $this->journeys[0]->getRelativeMinutes() . ' Minuten';
				$text2  = $this->journeys[1]->product . ' fährt in ' . $this->journeys[1]->getRelativeMinutes() . ' Minuten';
				break;
		}

		$speech = str_replace( $this->remove_from_output, "", $speech );
		$text1  = str_replace( $this->remove_from_output, "", $text1 );
		$text2  = str_replace( $this->remove_from_output, "", $text2 );
		$title  = str_replace( $this->remove_from_output, "", $title );


		if ( $this->replace_in_output != '' ) {
			$speech = str_replace( $this->replace_in_output[0], $this->replace_in_output[1], $speech );
			$text1  = str_replace( $this->replace_in_output[0], $this->replace_in_output[1], $text1 );
			$text2  = str_replace( $this->replace_in_output[0], $this->replace_in_output[1], $text2 );
			$title  = str_replace( $this->replace_in_output[0], $this->replace_in_output[1], $title );
		}

		header( "Content-type: application/json; charset=utf-8" );
		$responseArray = [
			'version'  => '1.0',
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
				'type'     => 'Display.RenderTemplate',
				'template' => [
					'type'        => 'BodyTemplate1',
					'token'       => 'stringstring',
					'title'       => $title,
					'textContent' => [
						'primaryText'   => [
							'text' => $text1,
							'type' => 'PlainText'
						],
						'secondaryText' => [
							'text' => $text2,
							'type' => 'PlainText'
						],

					],
				],
			]
		);

		if ( $this->display_supported ) {
			$responseArray['response']['directives'] = $directives;
		}

		header( 'Content-Type: application/json' );

		return json_encode( $responseArray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}

	public function renderJourneys() {#
		foreach ( $this->journeys as $journey ) {
			echo $journey->product . ' - In ' . $journey->getRelativeMinutes() . ' Min. - ' . $journey->destination . '<br>';
		}
	}

	/**
	 * @param string $journeys_xml
	 */
	public function setJourneysXml( $journeys_xml ) {
		$this->journeys_xml = $journeys_xml;
	}

	/**
	 * @param bool|string $data
	 */
	public function setData( $data ) {
		$this->data = $data;
	}

	/**
	 * @param string $origin
	 */
	public function setOrigin( $origin ) {
		$this->origin = $origin;
	}

	/**
	 * @param string $destination
	 */
	public function setDestination( $destination ) {
		$this->destination = $destination;
	}


	public function getXML() {

		if ( DBJourneyXMLParser::CACHE ) {
			$filename                = substr( md5( strtolower( $this->origin ) ), 0, 12 ) . '.xml';
			$local_cache_file        = sys_get_temp_dir() . '/' . $filename;
			$local_timestamp         = sys_get_temp_dir() . '/ts_' . $filename;
			$now_timestamp           = time();
			$last_saved_timestamp    = file_get_contents( $local_timestamp );
			$diff_minutes_last_saved = round( ( $now_timestamp - $last_saved_timestamp ) / 60 );

			if ( ! file_exists( $local_cache_file ) OR $diff_minutes_last_saved > 5 ) {
				$url  = DBJourneyXMLParser::BAHN_ENDPOINT_URL . urlencode( $this->origin );
				$data = file_get_contents( $url );
				file_put_contents( $local_cache_file, $data );
				file_put_contents( $local_timestamp, $now_timestamp );
			} else {
				$data = file_get_contents( $local_cache_file );
			}
		} else {
			$url  = DBJourneyXMLParser::BAHN_ENDPOINT_URL . urlencode( $this->origin );
			$data = file_get_contents( $url );
		}

		if ( $data === false ) {
			die( "xml data is empty" );

		}

		$this->setData( $data );

		$this->convertBAHNXML();
	}


	/**
	 * fix BAHN XML
	 */
	public function convertBAHNXML() {
		$xml                = '<?xml version="1.0" encoding="UTF-8" standalone="no" ?><Journeys>' . $this->data . '</Journeys>';
		$this->journeys_xml = simplexml_load_string( $xml );
	}


	public function getDirections() {
		$directions = array();

		foreach ( $this->journeys as $journey ) {
			$directions[] = $journey['targetLoc']->__toString();
		}

		print_r( array_unique( $directions ) );
	}


	public function getRelativeTimeInMinutes( $arrival_time ) {
		$timestamp_arrival = strtotime( $arrival_time );
		$now               = strtotime( 'now' );

		if ( $timestamp_arrival > $now ) {
			$arrival_in_minutes = ( $timestamp_arrival - $now ) / 60;

			return round( $arrival_in_minutes ) . ' NOW: ' . date( 'l dS \o\f F Y H:i:s', $now ) . 'TSNOW: ' . $now;
		} else {
			return false;
		}
	}

	public function isNotGone( $arrival_time ) {
		$timestamp_arrival = strtotime( $arrival_time );
		$now               = strtotime( 'now' );

		if ( $timestamp_arrival > $now ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * @param string $journeys
	 */
	public function setJourneys( $journeys ) {
		$this->journeys[] = $journeys;
	}


	public function fillJourneys() {

		foreach ( $this->journeys_xml as $journey_xml ) {

			$journey = new Journey();

			$arrival_timestamp = strtotime( $journey_xml['fpTime'] );
			$journey->setArrivalTimestamp( $arrival_timestamp );

			$destination = $journey_xml['targetLoc']->__toString();
			$journey->setDestination( $destination );

			if ( $this->show_destination_only == true && $destination != $this->destination ) {
				continue;
			}

			$product = $journey_xml['prod']->__toString();
			$journey->setProduct( $product );
			$journey->fixProduct();

			$delay = $journey_xml['delay']->__toString();
			$journey->setDelay( $delay );

			if ( $journey->getRelativeMinutes() > 0 ) {
				$this->setJourneys( $journey );
			}

		}
	}
}

