<?php

class lb_Exception extends Exception
{

    public function __construct($message = '', $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    public function printDieMessageHTML() {
        $html = '<!DOCTYPE html><html>
                    <head>
                    <meta charset="UTF-8">
                    <title>Sorry - Error</title>
                    </head>
                    <body style="font-family: sans-serif, arial;text-align:center;">
                        <img src="resources/img/tool_Labs_logo_notworking.png" />
                        <h1>Sorry</h1>
                        <p>Something is not working. Try again later.</p>
                        <p style="font-style:italic">'.htmlspecialchars($this->getCode().': '.$this->getMessage()).'</p>
                    </body>
                    </html>';
        print $html;
        die();
    }
}
