<?php

/**
 * Class DBJourneyXMLParser
 */

class Journey {
	public $product = '';
	public $destination = '';
	public $arrival_timestamp = '';
	public $delay = '';


	public function getArrivalFullDate() {
		return date( 'l dS \o\f F Y H:i:s', $this->arrival_timestamp );
	}

	public function getRelativeMinutes() {
		$diff = $this->arrival_timestamp - time();

		return floor( $diff / 60 );
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
	private $version = '1.0';

	const BAHN_ENDPOINT_URL = 'https://reiseauskunft.bahn.de//bin/stboard.exe/dn?rt=1&time=actual&start=yes&boardType=dep&L=vs_java3&input=';
	const MOCK = true;
	const DEBUG = true;
	const LOCALCOPY = true;

	public $data = '';
	public $journeys = array();
	public $journeys_xml = '';
	public $origin = '';
	public $destination = '';
	public $destination_only;
	public $echo_request = '';
	public $display_supported = false;
	public $remove_from_output = '';
	public $replace_in_output = '';

	/**
	 * @param bool $display_supported
	 */
	public function setDisplaySupported( $display_supported ) {
		$this->display_supported = $display_supported;
	}

	/**
	 * @param mixed $filter_destination_only
	 */
	public function setDestinationOnly( $destination_only ) {
		$this->destination_only = $destination_only;
	}

	/**
	 * DBJourneyXMLParser
	 */
	public function __construct( $origin, $destination ) {
		$this->getRequest();
		$this->remove_from_output = array( ', Hannover', 'Hannover,' );
		$this->replace_in_output  = array( 'STB', 'Stadtbahn ' );
		$this->setOrigin( $origin );
		$this->setDestination( $destination );
		$this->setDestinationOnly( true );
		$this->getXML();
		$this->fillJourneys();
		echo $this->getAlexaJSON();

	}

	public function getRequest() {
		$rawJSON            = file_get_contents( 'php://input' );
		$echo_request       = json_decode( $rawJSON );
		$this->echo_request = $echo_request;

		if ( isset( $echo_request->context->System->device->supportedInterfaces->Display ) ) {
			$this->setDisplaySupported( true );
		}
	}

	public function getAlexaJSON() {

		$speech = 'Ich habe keine Informationen zu ' . $this->journeys[0]->product;
		$text1  = '';
		$text2  = '';
		$title = $this->origin . ' in Richtung ' . $this->destination;

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
		$url = DBJourneyXMLParser::BAHN_ENDPOINT_URL . urlencode( $this->origin );

		if ( DBJourneyXMLParser::MOCK == true ) {
			$url = 'mock.txt';
		}

		if ( DBJourneyXMLParser::LOCALCOPY == true ) {
			$url = 'https://traintime.marc.tv/data.txt';
		}

		$data = file_get_contents( $url );

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

			if ( $this->destination_only == true && $destination != $this->destination ) {
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

$test = new DBJourneyXMLParser( "Hannover, Kafkastrasse", "Wettbergen, Hannover" );