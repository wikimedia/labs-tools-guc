<?php

class settings
{

    /**
     * Gibt eine Einstellung zurück.
     * @param string $setting
     * @return string wert
     */
    public static function getSetting($setting) {
        static $settings = null;
        if ($settings === null) {
                $cnf = self::_getMySQLloginFromFile();
                $settings = array(
                    // Database
                    'user' => $cnf['user'],
                    'password' => $cnf['password'],


                    // Components
                    'components' => array(
                        'guc',
                        'wiki',
                        'wikicontribs',
                        'exception',
                    ),

                    // Paths
                    'cacheFile' => 'cache/namespaces.json',
            );
        }
        return $settings[$setting];
    }


    private static function _getMySQLloginFromFile() {
        static $cnf = null;
        if ($cnf === null) {
            $uinfo = posix_getpwuid(posix_geteuid());
            $cnf = parse_ini_file($uinfo['dir'] . '/replica.my.cnf');
            if (!$cnf || !$cnf['user'] || !$cnf['password']) {
                throw new Exception("MySQL login data not found at " . $uinfo['dir']);
            }
        }
        return $cnf;
    }
}
