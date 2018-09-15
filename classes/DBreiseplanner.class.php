<?php

/**
 *
 * Marc Tönsing 2018
 *
 * Class DBreiseplanner
 */

class DBreiseplanner {

	const BAHN_ENDPOINT_URL = 'https://reiseauskunft.bahn.de//bin/stboard.exe/dn?rt=1&time=actual&start=yes&boardType=dep&L=vs_java3&input=';
	public $version = 1;
	public $cache_in_minutes = 5;
	public $debug = false;
	public $data = '';
	public $journeys = array();
	public $journeys_xml = '';
	public $origin = '';
	public $destination = '';
	public $show_destination_only = true;
	public $rawJSON;

	/**
	 * @param bool $debug
	 */
	public function setDebug( $debug ) {
		$this->debug = $debug;
	}

	/**
	 * @return array
	 */
	public function getJourneys() {
		return $this->journeys;
	}


	/**
	 * @param mixed $filter_destination_only
	 */
	public function setShowDestinationOnly( $show_destination_only ) {
		$this->show_destination_only = $show_destination_only;
	}


	public function __construct($origin, $destination) {

	    $this->setOrigin($origin);

	    $this->setDestination($destination);

		if ( isset( $_GET["debug"] ) AND htmlspecialchars( $_GET["debug"] ) == true ) {
			$this->setDebug( true );
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

		if ( $this->cache_in_minutes >= 0 ) {
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

			if ( ! file_exists( $local_cache_file ) OR $diff_minutes_last_saved > $this->cache_in_minutes ) {
				$url  = DBreiseplanner::BAHN_ENDPOINT_URL . urlencode( $this->origin );
				$data = file_get_contents( $url );

				if ( $data === false ) {
					die( "xml data is empty" );

				}

				file_put_contents( $local_cache_file, $data );
				file_put_contents( $local_timestamp, $now_timestamp );
			} else {
				$data = file_get_contents( $local_cache_file );
			}
		} else {
			$url  = DBreiseplanner::BAHN_ENDPOINT_URL . urlencode( $this->origin );
			$data = file_get_contents( $url );

		}

		$this->setData( $data );

		$this->convertBAHNXML();
	}


	/**
	 * fix BAHN XML
	 */
	public function convertBAHNXML() {
		$xml                = '<?xml version="1.0" encoding="ISO-8859-1" standalone="no" ?><Journeys>' . $this->data . '</Journeys>';
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

			$journey->origin = $this->origin;

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

			if($delay > 0){
			    $journey->setDelay( $delay );
            }

			if ( $journey->getRelativeMinutes() > 1 ) {
				$this->setJourneys( $journey );
			}
		}
	}
}

?>