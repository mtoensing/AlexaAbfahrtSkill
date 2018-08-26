<?php

$data = file_get_contents( 'https://reiseauskunft.bahn.de//bin/stboard.exe/dn?rt=1&time=actual&start=yes&boardType=dep&L=vs_java3&input=Hannover%2C+Kafkastrasse' );

file_put_contents("data.txt", $data);

?>