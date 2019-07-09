<?php

namespace Guc;

use Exception;

class Settings {

    /**
     * Gibt eine Einstellung zurück.
     * @param string $setting
     * @return string wert
     */
    public static function getSetting($setting) {
        static $settings = null;
        if ($settings === null) {
               $cnf = self::getDatabaseLogin();
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


    private static function getDatabaseLogin() {
        static $cnf = null;
        if ($cnf === null) {
            $homeDir = getenv('HOME');
            if (!$homeDir) {
                throw new Exception('Unable to find HOME directory');
            } else {
                $cnfPath = $homeDir . '/replica.my.cnf';
                $cnf = parse_ini_file($cnfPath);
                if (!isset($cnf['user']) || !isset($cnf['password'])) {
                    throw new Exception("Failed to read credentials from " . $cnfPath);
                }
            }
        }
        return $cnf;
    }
}
