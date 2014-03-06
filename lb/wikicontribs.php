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

class lb_wikicontribs {
    private $contribLimit = 20;
    
    private $hasContribs = false;
    private $isIp;
    private $registeredUser = false;
    private $justLastHour = false;
    private $app;
    private $dbname;
    private $url;
    private $slice;
    private $family;
    
    private $username;
    private $usernameUnterlined;
    private $userId;
    private $userEditCount;
    private $userRegistration;
    private $centralAuth;
    
    /**
     * 
     * @param string $dbname
     * @param string $url
     * @param string $slice
     */
    public function __construct(lb_app $app,$username, $dbname, $url, $slice, $family, $centralAuth, $isIP, $justLastHour) {
        if(!$username) throw new Exception("No Username or IP");
        $this->app = $app;
        $this->dbname = $dbname;
        $this->url = $url;
        $this->slice = $slice;
        $this->family = $family;
        $this->username = str_replace("_", " ", $username);
        $this->usernameUnterlined = str_replace(" ", "_", $username);
        $this->recentchanges = null;
        $this->isIp = $isIP;
        $this->justLastHour = $justLastHour;
        $this->centralAuth = $centralAuth;
        
        if($this->isIp !== true) {
            //get user data
            $this->app->aTP('search user data at '.$url);
            $db = $this->app->getDB($dbname, $slice)->prepare("SELECT `user_id`, `user_editcount`, `user_registration` FROM `user` WHERE `user_name` = :username LIMIT 1;");
            $db->bindParam(':username', $this->username);
            $db->execute();
            $res = $db->fetch(PDO::FETCH_ASSOC);
            unset($db);

            if($res) {
                //set data
                $this->registeredUser = true;
                $this->userId = $res['user_id'];
                $this->userEditCount = $res['user_editcount'];
                $this->userRegistration = $res['user_registration'];
            }
        }
        //Beiträge Abfragen
        $this->_getRecentChanges();
    }
    
    
    /**
     * gibt die letzten 20 änderungen zurück
     * @return mixed;
     */
    private function _getRecentChanges() {
        $this->app->aTP('search recent changes at '.$this->url);
        date_default_timezone_set('UTC');
        $where = '';
        if($this->justLastHour) $where = " `rev_timestamp` >= '".date('YmdHis')."' AND ";
        
        $qry = "SELECT 
                `rev_comment`,
                `rev_timestamp`,
                `rev_minor_edit`,
                `rev_len`, 
                `rev_id`,
                `rev_parent_id`,
                `page_title`, 
                `page_namespace`,
                `page_latest` = `rev_id` AS `rev_cur`
            FROM 
                `revision_userindex` 
            INNER JOIN 
                `page` ON `rev_page` = `page_id` 
            WHERE
                ".$where."
                `rev_deleted` = 0 AND 
                ".(($this->isIP()) ? "`rev_user_text` = :username" : "`rev_user` = :userid")."
            ORDER BY `revision_userindex`.`rev_timestamp`  DESC LIMIT 0,".$this->contribLimit.";";
        $db = $this->app->getDB($this->dbname, $this->slice)->prepare($qry);
        if($this->isIP())   $db->bindParam(':username', $this->username);
        else                $db->bindParam(':userid', $this->userId);
        $db->execute();
         $this->recentchanges = $db->fetchAll(PDO::FETCH_ASSOC);
        $this->hasContribs = !!$this->recentchanges;
        //get namespace
        unset($db);               
        foreach($this->recentchanges as &$rc) {
            $rc['page_namespace_name'] = $this->getNamespaceName($rc['page_namespace']);
            $rc['full_page_title'] = ($rc['page_namespace_name']) ? $rc['page_namespace_name'].":".$rc['page_title'] : $rc['page_title'];
            $rc['full_page_title'] = str_replace(" ", "_", $rc['full_page_title']);
        }
    }
    
    /**
     * Gibt zurück ob der Benutzer eine IP ist oder nicht.
     * @return boolean
     */
    public function isIP() {
        return $this->isIp;
    }   
    
    /**
     * Gibt alle letzten 20 Beiträge zurück
     * @return type
     */
    public function getRecentChanges() {
        return $this->recentchanges;
    }
    
    public function getUserRegistration() {
        return ($this->registeredUser) ? null : $this->userRegistration;
    }
    
    public function getUserEditcount() {
        return ($this->registeredUser) ? null : $this->userEditCount;
    }


    /**
     * gibt die aktuellen Blocks zurück.
     * @return mixed
     */
    public function getBlocks() {
        return ($this->isIP()) ? $this->getIpBlocks() : $this->getUserBlocks();
    }
    
    private function getIpBlocks() {
        if(!$this->isIP()) return null;
        $qry = "SELECT 
                    ipblocks.*, 
                    `user`.user_name AS admin_username 
            FROM ipblocks 
            INNER JOIN `user` ON ipb_by = `user`.user_id 
            WHERE ipblocks.ipb_address = :ipadresse LIMIT 0,100;";
        $db = $this->app->getDB($this->dbname, $this->slice)->prepare($qry);
        $db->bindParam(':ipadresse', $this->username);
        $db->execute();
        $res = $db->fetchAll(PDO::FETCH_ASSOC);   
        unset($db);
        return $res;
    }
    
    private function getUserBlocks() {
        if($this->isIP()) return null;
        $qry = "SELECT 
                    ipblocks.*, 
                    `user`.user_name AS admin_username 
            FROM ipblocks 
            INNER JOIN `user` ON ipb_by = `user`.user_id 
            WHERE ipblocks.ipb_user = :userid LIMIT 0,100;";
        $db = $this->app->getDB($this->dbname, $this->slice)->prepare($qry);
        $db->bindParam(':userid', $this->userId);
        $db->execute();
        $res = $db->fetchAll(PDO::FETCH_ASSOC);   
        unset($db);
        return $res;  
    }
    
    public function hasContribs() {
        return $this->hasContribs;
    }
    
    //TODO: find other way for namespaces
    public function getNamespaceName($namespace_id) {
        $namespace_id = (int) $namespace_id;
        $Ns6Name = '';

        switch($this->family) {
            case 'wikibooks': $Ns6Name = 'Wikibooks'; break;
            case 'wiktionary': $Ns6Name = 'Wiktionary'; break;
            case 'wikiquote': $Ns6Name = 'Wikiquote'; break;
            case 'wikisource': $Ns6Name = 'Wikisource'; break;
            case 'wikinews': $Ns6Name = 'Wikinews'; break;
            case 'wikiversity': $Ns6Name = 'Wikiversity'; break;
            case 'wikivoyage': $Ns6Name = 'Wikivoyage'; break;
            case 'wikidata': $Ns6Name = 'Wikidata'; break;
            case 'wikimania': $Ns6Name = 'Wikimania'; break;
            default: $Ns6Name = 'Wikipedia'; break;
        }
        if($this->dbname == 'commonswiki') $Ns6Name = 'Commons';
        if($this->dbname == 'metawiki') $Ns6Name = 'Meta';
        if($this->dbname == 'mediawikiwiki') $Ns6Name = 'Mediawiki';
        
        $ns = array(
            0 => '',
            1 => 'Talk',
            2 => 'User',
            3 => 'User talk',
            4 => $Ns6Name,
            5 => $Ns6Name.' talk',
            6 => 'File',
            7 => 'File talk',
            8 => 'MediaWiki',
            9 => 'MediaWiki talk',
            10 => 'Template',
            11 => 'Template talk',
            12 => 'Help',
            13 => 'Help talk',
            14 => 'Category',
            15 => 'Category talk',
            100 => 'Portal',
            101 => 'Portal talk',
            108 => 'Book',
            109 => 'Book talk',
            446 => 'Education Program',
            447 => 'Education Program talk',
            710 => 'TimedText',
            711 => 'TimedText talk',
            828 => 'Module',
            829 => 'Module talk',
            -1 => 'Special',
            -2 => 'Media'            
        );
        
        //Special namespaces for mediawikiwiki
        if($this->dbname == 'mediawikiwiki') {
            $ns[90] = 'Thread';
            $ns[91] = 'Thread talk';
            $ns[92] = 'Summary';
            $ns[93] = 'Summary talk';            
            $ns[100] = 'Manual';
            $ns[101] = 'Manual talk';
            $ns[102] = 'Extension';
            $ns[103] = 'Extension talk';
            $ns[104] = 'API';
            $ns[105] = 'API talk';
            $ns[106] = 'Skin';            
            $ns[107] = 'Skin talk';            
            $ns[1198] = 'Translations';
            $ns[1199] = 'Translations talk';
            $ns[2500] = 'VisualEditor';
            $ns[2501] = 'VisualEditor talk';
        }
        
        if($this->dbname == 'enwikibooks') {
            $ns[102] = 'Cookbook';
            $ns[103] = 'Cookbook talk';
        }
        
        if(!key_exists($namespace_id, $ns)) return 'UNKNOWN NAMESPACE '.$namespace_id;//throw new Exception('Unknown namespace number: '.$namespace_id);
        return $ns[$namespace_id];
    } 
    
    public function getDataHtml() {
        $return = '';
        $return .= '<h1>'.$this->url.'</h1>';
        $return .= '<p class="wikiinfo">';
        $return .= 'For <a href="//'.$this->url.'/wiki/User:'.htmlspecialchars($this->usernameUnterlined).'">'.  htmlspecialchars($this->username).'</a> '
                . '(<a href="//'.$this->url.'/wiki/User_talk:'.htmlspecialchars($this->usernameUnterlined).'">talk</a>&nbsp;| '
                . '<a href="//'.$this->url.'/w/index.php?title=Special:Log/block&page='.  urlencode('User:'.$this->usernameUnterlined).'" title="Special:Log/block">block log</a>&nbsp;| '
                . '<a href="//'.$this->url.'/wiki/Special:ListFiles/'.htmlspecialchars($this->usernameUnterlined).'" title="Special:ListFiles">uploads</a>&nbsp;| '
                . '<a href="//'.$this->url.'/wiki/Special:Log/'.htmlspecialchars($this->usernameUnterlined).'" title="Special:Log">logs</a>&nbsp;| '
                . '<a href="//'.$this->url.'/w/index.php?title=Special:AbuseLog&wpSearchUser='.  urlencode($this->usernameUnterlined).'" title="Edit Filter log for this user">filter log</a>';
        if($this->userEditCount > 0)$return .= ' | '.  $this->userEditCount.' edits';
        if($this->centralAuth) $return .= ' | '.'SUL: Account attached at '.$this->app->TStoUserTime($this->centralAuth['lu_attached_timestamp'], 'H:i, d.m.Y', array());
        if($this->centralAuth === NULL) $return .= ' | '.'SUL: Account not attached.';
        $return .= ')</p>';
        $return .= '<ul>';
        foreach($this->recentchanges as $w_data) {
            $return .= '<li>';
            //Zeit
            $return .= $this->app->TStoUserTime($w_data['rev_timestamp'], 'H:i, d.m.Y', array()).'&nbsp;';
            //diff-Link full_page_title
            $return .= '(<a href="//'.$this->url.'/w/index.php?title='.urlencode($w_data['full_page_title']).'&diff=prev&oldid='.urlencode($w_data['rev_id']).'">diff</a>';
            $return .= '&nbsp;|&nbsp;';
            //history
            $return .= '<a href="//'.$this->url.'/w/index.php?title='.urlencode($w_data['full_page_title']).'&action=history">hist</a>)';
            //minor Edit
            if($w_data['rev_minor_edit']) $return .= '&nbsp;<span class="minor">M</span>';
            
            //Link to the page
            $return .= '&nbsp;<a href="//'.$this->url.'/w/index.php?title='.urlencode($w_data['full_page_title']).'">';
            if($w_data['page_namespace_name']) $return .= htmlspecialchars ($w_data['page_namespace_name'].":");
            $return .= htmlspecialchars($w_data['page_title'])."</a>";
            
            //Comment
            if($w_data['rev_comment']) {
                $return .= '&nbsp;('.$this->app->wikiparser($w_data['rev_comment'], $w_data['full_page_title'], $this->url).')';
            }
            
            //cur revision
            if($w_data['rev_cur']) {
                $return .= '&nbsp;<span class="rev_cur">(current)</span>';
            }            
            $return .= '</li>';
        }
        $return .= '</ul>';
        return $return;
    }
    
    /**
     * Gibt zurück ob der Beitrag als nicht CentralAuth markiert werden soll.
     * @return boolen
     */
    public function markAsNotUnified() {
        return $this->centralAuth === null;
    }
    
    private function _wikiTimestampToNormal($wp_time) {
        return $wp_time; //TODO:Umwandeln
    }
    
    private function _formatComment($unformated, $pageLink=null) {
        return $unformated; //TODO
    }
}
?>
