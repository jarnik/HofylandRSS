<?php

    /*
    * HLL RSS
    *    
    * Jednoduchy skript pro parsnuti klubu z Hofylandu a jejich prevod na RSS feed.
    * Podporuje dva mody:
    *  ?club=<ID_KLUBU> - RSS feed poslednich prispevku verejneho klubu
    *  ?user=<NICKNAME>&pass=<PASSWORD> - RSS feed poslednich prispevku z klubu v BOOKu (vcetne neverejnych)
    *
    * Soucasti instalace PHP musi byt cURL.
    *
    */
    
    // cURL a COOKIES http://stackoverflow.com/questions/247006/php-how-to-save-cookies-for-remote-web-pages

    header('Content-type: application/rss+xml');
    include "ganon.php"; // knihovna pro parsovani HTML DOMu
    $USER_NAME = NULL;
    $USER_PASS = NULL;
    $COOKIE_FILE = "./cookie.txt";

    // stahnout stranku klubu
    function getClubPage($club_id) {
        global $COOKIE_FILE;
        $cr = curl_init( "http://www.hofyland.cz/?club&klub=".$club_id );
        curl_setopt($cr, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cr, CURLOPT_COOKIEFILE, $COOKIE_FILE);
        $page = curl_exec($cr);
        curl_close($cr); 
        return $page;
    }

    // stahnout stranku BOOKu uzivatele
    function getBookPage($user_name, $user_pass) {
        global $COOKIE_FILE;

        $post_data = array(
            'hlnick'=>$user_name,
            'hlpsw'=>$user_pass,
            'send_hlprihlaseni'=>'Přihlásit',
            'hlform'=>'true');
        
        $cr = curl_init("http://www.hofyland.cz/index.php");
        // get login page, receive cookies
        curl_setopt($cr, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cr, CURLOPT_COOKIEJAR, $COOKIE_FILE);
        
        // login with cookies and 303 redir
        curl_setopt($cr, CURLOPT_POST, TRUE);
        curl_setopt($cr, CURLOPT_COOKIEFILE, $COOKIE_FILE);
        curl_setopt($cr, CURLOPT_POSTFIELDS, $post_data);
        curl_exec($cr); 
        curl_close($cr); 
        
        // get book using cookies
        $cr = curl_init("http://www.hofyland.cz/?book");
        curl_setopt($cr, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cr, CURLOPT_COOKIEFILE, $COOKIE_FILE);
        $page = curl_exec($cr);
        curl_close($cr); 
      
        return  $page;
    }

    // stahnout posledni prispevky z daneho klubu a vratit jejich pole
    function parseClub($club_id, $club_name=NULL) {
        $page = getClubPage($club_id);
        $html = str_get_dom($page);
        $title=($club_name?$club_name:$html->select('div[class="nadpis"]',0)->getPlainText());
        $link="http://www.hofyland.cz/?club&klub=".$club_id;
        $posts = Array();
        foreach($html->select('div[class="rowp"]') as $index => $element) {
            $post = Array();
            $post['author']=trim($element->select('span[class="nickpost"]',0)->getPlainText());
             // preskocit prispevky uzivatele "REKLAMA"
            if ( $post['author'] == "REKLAMA" ) continue;

            $post_date = $element->select('span[class="datepost"]',0)->getPlainText();
            // bezva hofy spek - datum u prispevku se lisi pro prihlasene a anonymni navstevniky
            if ( strpos($post_date,":")<strpos($post_date,".") ) 
                list($hour,$min,$sec,$day,$month,$year) = sscanf($post_date, " %d:%d:%d %d.%d.%d ");
            else
                list($day,$month,$year,$hour,$min,$sec) = sscanf($post_date, " %d.%d.%d %d:%d:%d ");
            $post['timestamp']=strtotime(sprintf("%d/%d/%d %d:%d:%d",$year,$month,$day,$hour,$min,$sec));
            $post['date']=date("D, d M Y H:i:s ",$post['timestamp'])."EST";
            $post['category']=$title;
            $post['text']=$element->select('td[class="txtpost"]',0)->getInnerText();
            $post['link']=$link;

            $posts[] = $post;
        }

        return $posts;
    }

    // stahnout seznam klubu, ktere ma uzivatel v BOOKu, vrati pole [ club_id => club_name , ... ]
    function parseUser($user_name, $user_pass) {
        $page = getBookPage($user_name,$user_pass);
        
        $html = str_get_dom($page);
        $clubs = Array();
        foreach($html->select('div[class="nadpis"]:has(:text:contains(Základní skupina)) ~ div[class="bookrow"]') as $element) {
            $club_name = $element->select('a[class*="klub"]',0)->getPlainText();
            sscanf( $element->select('a[class*="klub"]',0)->href, "?club&amp;klub=%d&amp;", $club_id );
            $clubs[$club_id] = $club_name;
        }
        
        return $clubs;
    }

    // URL aktualni stranky
    function curPageURL() {
        $pageURL = 'http';
        if ($_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
            $pageURL .= "://";
        if ($_SERVER["SERVER_PORT"] != "80") {
            $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
        } else {
            $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
        }
        $pageURL_split = explode("?",$pageURL);
        return $pageURL_split[0];
    }

    // V sablonovem HTML nahradit {$var} hodnotami promennych
    function useTemplate($template, $data, $isLoop = False ) {
        if ( $isLoop ) {
            $fragment = $data;
        } else {
            $fragment = array( $data );
            $template = file_get_contents($template);
        }

        $compiled = "";
        foreach ($fragment as $data ) {
            $compiled_item = $template;
        
            // process loops
            $compiled_item = preg_replace("/{loop\\$([^}]*)}"."(.*)"."{endloop}/es","useTemplate('\\2',\$data['\\1'], True)",$compiled_item);
    
            // replace variables
            $compiled_item = preg_replace("/{\\$([^}]*)}/e","\$data['\\1']",$compiled_item);

            $compiled .= $compiled_item;
        }

        return $compiled;
    }

    function comparePosts($a, $b) {
        return $a['timestamp']<$b['timestamp'];
    }    
    function sortPostsByTimestamp($items) {
        usort($items, "comparePosts");
        return $items;
    }

    // vratit RSS feed poslednich prispevku klubu
    function processClub($id) {
        $items = parseClub($id);
        $items = sortPostsByTimestamp($items);
        $data = array(
            "title"=>"HL things",
            "description"=>"some stuf...",
            "link"=>curPageURL(),
            "item"=>$items
        );
        return useTemplate("rss.xml",$data);
    }

    // vratit RSS feed poslednich prispevku z klubu v BOOKu
    function processUser($user_name, $user_pass) {
        $clubs = parseUser($user_name, $user_pass);
        
        $items = array();
        foreach ($clubs as $club_id=>$club_name) {
            $club_items = parseClub($club_id, $club_name);
            $items = array_merge($items, $club_items);
        }
        $items = sortPostsByTimestamp($items);

        //print_r( $clubs );
        //return "";
        
        $data = array(
            "title"=>"HofyLand",
            "description"=>"Posledni prispevky v klubech z booku uzivatele $id",
            "link"=>curPageURL(),
            "item"=>$items
        );

        return useTemplate("rss.xml",$data);
    }

    if ($_GET['club']!=NULL)
        echo processClub( $_GET['club'] );
    else
        echo processUser( $_GET['user'], $_GET['pass'] );

?>


