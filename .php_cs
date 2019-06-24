<?php

require('vendor/autoload.php');

return PhpCsFixer\Config::create()
    ->setRules([
        '@PSR2' => TRUE,
	'lowercase_constants' => FALSE,
	'indentation_type' => TRUE
    ])
    ->setIndent("\t")
    ->setLineEnding("\n")
;
