<?php

/**
 *
 * Marc Tönsing 2018
 *
 * Class AlexaAbfahrtenSkill
 */

class AlexaAbfahrtenSkill {

	const BAHN_ENDPOINT_URL = 'https://reiseauskunft.bahn.de//bin/stboard.exe/dn?rt=1&time=actual&start=yes&boardType=dep&L=vs_java3&input=';
	const CACHE_IN_MINUTES = 5;

	public $list_image_url = "https://traintime.marc.tv/assets/tram-small.png";
	public $debug = false;
	public $setup = array();
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
	 * @param bool $debug
	 */
	public function setDebug( $debug ) {
		$this->debug = $debug;
	}

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
	 * AlexaAbfahrtenSkill
	 */
	public function __construct() {
		if ( isset( $_GET["debug"] ) AND htmlspecialchars( $_GET["debug"] ) == true ) {
			$this->setDebug( true );
		}
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
		$code = 400;
		http_response_code( $code );
		echo "Error " . $code . "<br />\n" . $msg;
		error_log( "alexa" . $msg, 0 );
		exit();
	}


	public function validateRequest() {
		/**
		 * Thanks to @solariz for the amazon certificate authentication
		 * https://gist.github.com/solariz/a7b7b09e46303223523bba2b66b9b341
		 */

		if ( $this->debug == false ) {

			$EchoReqObj = $this->EchoReqObj;
			$rawJSON    = $this->rawJSON;
			$SETUP      = $this->setup;

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
	}

	public function getAlexaJSONResponse() {

		$title  = $this->origin . ' in Richtung ' . $this->destination;
		$title  = str_replace( $this->remove_from_output, "", $title );
		$title  = str_replace( $this->replace_in_output[0], $this->replace_in_output[1], $title );

		$speech = 'In ' . $this->journeys[0]->getRelativeMinutes() . ' Minuten fährt die ' . $this->journeys[0]->product . ' ab ' . $this->origin . ' in Richtung ' . $this->destination . '.';

		if(count( $this->journeys ) == 0) {
			$speech = 'Ich habe keine Informationen zu ' . $this->journeys[0]->product;
		} elseif (count( $this->journeys ) > 1) {
			$speech = $speech . ' In ' . $this->journeys[1]->getRelativeMinutes() . ' Minuten kommt die nächste ' . $this->journeys[1]->product;
		}

		$speech = str_replace( $this->replace_in_output[0], $this->replace_in_output[1], $speech );
		$speech = str_replace( $this->remove_from_output, "", $speech );

		$items = array();
		$count = 0;

		foreach ( $this->journeys as $journey ) {

			$text = 'In <b>' . $journey->getRelativeMinutes() . '</b> Minuten';

			$items[] = [
				'token'       => 'departure-item-'. $count,
				'image'       => [
					'contentDescription' => 'Tram',
					'sources'            => array([
						'url' => $this->list_image_url,
					]),
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
					'type'      => 'ListTemplate1',
					'token'     => 'departure-list',
					'title'     => $title,
					'listItems' => $items,
				],
			]
		);

		if ( $this->display_supported OR $this->debug == true ) {
			$responseArray['response']['directives'] = $directives;
		}

		header( "Content-type: application/json; charset=utf-8" );
		$json = json_encode( $responseArray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

		return $json;
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

		if ( AlexaAbfahrtenSkill::CACHE_IN_MINUTES > 0 ) {
			$filename         = substr( md5( strtolower( $this->origin ) ), 0, 12 ) . '.xml';
			$local_cache_file = sys_get_temp_dir() . '/' . $filename;
			$local_timestamp  = sys_get_temp_dir() . '/ts_' . $filename;
			$now_timestamp    = time();

			if ( file_exists( $local_timestamp ) ) {
				$last_saved_timestamp = file_get_contents( $local_timestamp );
			} else {
				file_put_contents( $local_timestamp, $now_timestamp );
				$last_saved_timestamp = $now_timestamp;
			}

			$diff_minutes_last_saved = round( ( $now_timestamp - $last_saved_timestamp ) / 60 );

			if ( ! file_exists( $local_cache_file ) OR $diff_minutes_last_saved > AlexaAbfahrtenSkill::CACHE_IN_MINUTES ) {
				$url  = AlexaAbfahrtenSkill::BAHN_ENDPOINT_URL . urlencode( $this->origin );
				$data = file_get_contents( $url );
				file_put_contents( $local_cache_file, $data );
				file_put_contents( $local_timestamp, $now_timestamp );
			} else {
				$data = file_get_contents( $local_cache_file );
			}
		} else {
			$url  = AlexaAbfahrtenSkill::BAHN_ENDPOINT_URL . urlencode( $this->origin );
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

			if ( $journey->getRelativeMinutes() > 1 ) {
				$this->setJourneys( $journey );
			}
		}
	}
}

?>