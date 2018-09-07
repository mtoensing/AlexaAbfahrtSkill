<?php

spl_autoload_register( function ( $class_name ) {
    include 'classes/' . $class_name . '.class.php';
} );

$AlexaAbfahrtenSkill = new AlexaAbfahrtenSkill(
    "Hannover, Kafkastrasse",
    "Wettbergen, Hannover",
    'amzn1.ask.skill.6f5d7f58-b0c7-4ef4-96c8-dd28418c96ba'
);

$AlexaAbfahrtenSkill->replace_in_output  = array(
    array( 'Hannover, ', ', Hannover', 'STB' ),
    array( '', '', 'Stadtbahn ' )
);

echo $AlexaAbfahrtenSkill->getAlexaJSONResponse();





