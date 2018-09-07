<?php

spl_autoload_register( function ( $class_name ) {
	include 'classes/' . $class_name . '.class.php';
} );

$AlexaAbfahrtenSkill = new AlexaAbfahrtenSkill(
    "Lister Platz (U), Hannover",
    "Misburg, Hannover",
    'amzn1.ask.skill.8228c964-a30c-41af-b817-948bd6c7903c'
);

$AlexaAbfahrtenSkill->replace_in_output  = array(
    array( '(U) ','Hannover, ', ', Hannover', 'STB' ),
    array( '','', '', 'Stadtbahn ' )
);

echo $AlexaAbfahrtenSkill->getAlexaJSONResponse();

