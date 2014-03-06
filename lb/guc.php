<?php
/*
 * Copyright 2014 by Luxo
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 */

class guc {
    private $app;
    private $isIP;
    private $hostname;
    private $username;
    private $wikis;
    private $alloverCount=0;
    public $wikisCount=0;
    public $wikisWithEditscount=0;
    
    function __construct(lb_app $app, $closedWikis=false, $justLastHour=false) {
        $this->app = $app;
        $this->username = str_replace("_", " ", ucfirst(trim($_POST['user'])));
        $wikis = $this->_getWikis($closedWikis);
        $WikisCountribsCounter = $this->_getWikisWithContribs($wikis);
        $this->wikisCount = count($wikis);
        try {            
            $this->hostname = @gethostbyaddr($this->username);
        } catch(Exception $e) {
            $this->hostname = '';
        }
        $this->isIP = !!$this->hostname;
        //check if input is a IP
        foreach($wikis as &$wiki) {
            if($WikisCountribsCounter[$wiki['dbname']] > 0) { 
                $wiki['url'] = mb_substr($wiki['url'], 7); //cut http://
                try {
                    $wiki['data'] = new lb_wikicontribs($this->app,$this->username,$wiki['dbname'],$wiki['url'],$wiki['slice'],$wiki['family'], $this->_getCentralauthData($wiki['dbname']), $this->isIP, $justLastHour);
                    $wiki['error'] = null;
                } catch (Exception $e) {
                    $wiki['data'] = null;
                    $wiki['error'] = "Error with ".$wiki['url'].': '.$e->getMessage().'<br>';
                }
            } else {
                //no contribs
                $wiki['data'] = null;
                $wiki['error'] = null;
            }
            
        }
        $this->wikis = $wikis;
    }
    
    /**
     * return all wikis
     * @return mixed
     */
    private function _getWikis($closedIncluded = false) { 
        $this->app->aTP('get wiki list..');
        $family = array(
            'wikipedia' => 1,
            'wikibooks' => 1,
            'wiktionary' => 1,
            'special' => 1,
            'wikiquote' => 1,
            'wikisource' => 1,
            'wikimedia' => 1,
            'wikinews' => 1,
            'wikiversity' => 1,
            'centralauth' => 0,
            'wikivoyage' => 1,
            'wikidata' => 1,
            'wikimania' => 1
        );
        $f_where = array();
        if(!$closedIncluded) $f_where[] = 'is_closed = 0';
        foreach($family as $name => $include) {
            if($include == 0) $f_where[] = '`family` != \''.$name.'\''; 
        }
        $f_where = implode(" AND ", $f_where);
        $qry = 'SELECT * FROM `meta_p`.`wiki` WHERE '.$f_where.' LIMIT 1500;';
        $con = $this->app->getDB()->prepare($qry);
        $con->execute();
        $r = $con->fetchAll();
        unset($con);
        return $r;
    }
    
    /**
     * return the wikis with contribs
     * @param type $wikis
     * @return type
     */
    private function _getWikisWithContribs($wikis) {
        $return = array();
        $slices = array();
        $wikisWithEditCount = 0;
        foreach($wikis as $wiki) {
            if(!is_array($slices[$wiki['slice']])) $slices[$wiki['slice']] = array();               
            $slices[$wiki['slice']][] = 'SELECT COUNT(rev_id) AS counter, \''.$wiki['dbname'].'\' AS dbname FROM '.$wiki['dbname'].'_p.revision_userindex WHERE rev_user_text = :username';
        }
        foreach($slices as $slicename => $sql) { 
            if($sql) {
                $sql = implode(' UNION ALL ', $sql);  
                $qry = $this->app->getDB('meta', $slicename);
                $con = $qry->prepare($sql);
                $con->bindParam(':username', $this->username);
                $con->execute();
                $r = $con->fetchAll(PDO::FETCH_ASSOC);
                foreach($r as $re) {
                    $return[$re['dbname']] = intval($re['counter']);
                    if($re['counter'] > 0) $wikisWithEditCount++;
                }
                unset($con);                
            }
        }
        $this->alloverCount = array_sum($return);
        $this->wikisWithEditscount = $wikisWithEditCount;
        return $return;
    }
    
    /**
     * returns centralauth information 
     * @staticvar null $centralauthData
     * @param string $dbname
     * @return array or null or false if no centralauth
     */
    private function _getCentralauthData($dbname) {
        static $centralauthData = NULL;
        if($centralauthData === NULL) {
            $centralauthData = array();
            $db = $this->app->getDB('centralauth', 'centralauth.labsdb');
            $qry = $db->prepare('SELECT * FROM localuser WHERE lu_name = :luName;');
            $qry->bindParam(':luName', $this->username, PDO::PARAM_STR);
            $qry->execute();
            $rows = $qry->fetchAll(PDO::FETCH_ASSOC);
            unset($qry);
            if(!$rows) return false;
            foreach($rows as $row) {
                $centralauthData[$row['lu_wiki']] = $row;
            }            
        }
        if(key_exists($dbname, $centralauthData)) return $centralauthData[$dbname];
        else return null;
    }
    
    /**
     * Gibt die Daten zurück
     * @return array
     */
    public function getData() {
        return $this->wikis;
    }
    
    public function getUsername() {
        return $this->username;
    }
    
    public function getHostname() {
        return $this->hostname;
    }
    
    public function getAlloverCount() {
        return $this->alloverCount;
    }
}
?>
