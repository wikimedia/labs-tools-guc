<?php

class settings {
    
    /**
     * Gibt eine Einstellung zurück.
     * @param string $setting
     * @return string wert
     */
    static public function getSetting($setting) {
        $settings = array(
        
        //Database
        'user'      => 'x',
        'password'  => 'x',
        
        
        //Components
        'components' => array(
            'guc',
            'wikicontribs',
            'exception'
        )
    );
        return $settings[$setting];
    } 
    
    
    private static function _getMySQLloginFromFile() {
        //TODO: Fix
        $path = '../replica.my.cnf';
        
        $raw = file($path);
        $return = new stdClass();
        foreach($raw as $line) {
            preg_match('/^([a-zA-Z0-9]+)\=\'([a-zA-Z0-9]+)\'$/',$line, $result);	
            if($result[1]) $return->$result[1] = $result[2];
        }
        if(!$return->username || $return->password) throw new Exception("Got no MySQL login data. I'm at".$_SERVER["CONTEXT_DOCUMENT_ROOT"]);
        return $return;
    }
}

?>
