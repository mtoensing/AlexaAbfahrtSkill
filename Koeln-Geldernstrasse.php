<?php

spl_autoload_register( function ( $class_name ) {
	include 'classes/' . $class_name . '.class.php';
} );


$AlexaAbfahrtenSkill = new AlexaAbfahrtenSkill();

$AlexaAbfahrtenSkill->setup = array(
	'ApplicationID'       => 'amzn1.ask.skill.b5204a85-c6a8-474e-a591-36fa6e9147ad',
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

$AlexaAbfahrtenSkill->replace_in_output = array(
	array( '  ', 'STR', 'Köln', ', ' ),
	array( ' ', 'Linie ', '' )
);
$AlexaAbfahrtenSkill->setOrigin( "Köln Geldernstr./Parkgürtel" );
$AlexaAbfahrtenSkill->setDestination( "Klettenberg Sülzgürtel, Köln" );
$AlexaAbfahrtenSkill->setShowDestinationOnly( true );

echo $AlexaAbfahrtenSkill->getAlexaJSONResponse();


