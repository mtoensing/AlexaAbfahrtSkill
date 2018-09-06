<?php

spl_autoload_register( function ( $class_name ) {
	include 'classes/' . $class_name . '.class.php';
} );

$AlexaAbfahrtenSkill = new AlexaAbfahrtenSkill(
    "Köln Geldernstr./Parkgürtel",
    "Klettenberg Sülzgürtel, Köln",
    'amzn1.ask.skill.b5204a85-c6a8-474e-a591-36fa6e9147ad'
);

$AlexaAbfahrtenSkill->replace_in_output = array(
    array( '  ',' Sülzgürtel','./Parkgürtel','  ', 'STR', 'Köln', ', ' ),
    array( ' ','','',' ', 'Linie ', '' )
);

echo $AlexaAbfahrtenSkill->getAlexaJSONResponse();


