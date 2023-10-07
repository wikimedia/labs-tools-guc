<?php

namespace Guc;

use RuntimeException;

class Settings {

    /**
     * Gibt eine Einstellung zurück.
     * @param string $setting
     * @return string wert
     */
    public static function getSetting($setting) {
        static $settings = null;
        if ($settings === null) {
               $cnf = self::getMySQLloginFromFile();
                $settings = array(
                    // Database
                    'user' => $cnf['user'],
                    'password' => $cnf['password'],

                    // Paths
                    'cacheFile' => 'cache/namespaces.json',
            );
        }
        return $settings[$setting];
    }


    private static function getMySQLloginFromFile() {
        static $cnf = null;
        if ($cnf === null) {
            $uinfo = posix_getpwuid(posix_geteuid());
            $cnf = parse_ini_file($uinfo['dir'] . '/replica.my.cnf');
            if (!$cnf || !$cnf['user'] || !$cnf['password']) {
                throw new RuntimeException("MySQL login data not found at " . $uinfo['dir']);
            }
        }
        return $cnf;
    }
}
