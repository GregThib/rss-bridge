<?php
/**
* MediapartRecoveryBridge
* Get the full content of articles (w/ username & password).
* TODO : 1- force use of HTTPS even if connexion to rss-bridge is unsecure
* TODO : 1- this is done for masking credentials in transmission
* TODO : 1- $item->uri       = str_replace('http:','https:',$item->uri);
* TODO : 2- surcharge constructor for replacing registerSession
* TODO : 3- chiffrement / confusion mot de passe
*
* @name Mediapart (recovery mode)
* @homepage https://www.mediapart.fr/
* @description Recover full articles list (hard-coded) from Mediapart
* @maintainer GregThib
* @use2(user="user", pass="password",recovery)
*/
class MediapartRecoveryBridge extends BridgeAbstract{
	
	//private $proxy_chain = '';
	//private $proxy_chain = 'ldprox.bull.fr:80';
	
	private $session;
	
	
	// NOW: functions
	
	
	private function StripCDATA($string) {
		$string = str_replace('<![CDATA[', '', $string);
		$string = str_replace(']]>',       '', $string);
		return $string;
	}
	
	private function ExtractContent($url) {
		// fetch full content
		$html = file_get_html($url.'?onglet=full', false, $this->session) or $this->returnError('Error during fetch_content', 500);
		
		// 01 - deletion of "à lire aussi"
		$lireaussi = $html->find('div.content-article div[id=lire-aussi]');
		foreach($lireaussi as $bloc) $bloc->outertext = '';
		
		// end of manipulations
		$html->load($html->save());
		
		// conpound recup and recomposition
		$head = $html->find('div.chapo div.clear-block', 0)->innertext;
		$text = $html->find('div.content-article', 0)->innertext or $this->returnError('Content not found on article', 404);
		return '<b>'.$head.'</b>'.$text;
	}

	/// devrait être dans la surcharge du constructeur
	private function registerSession($user, $pass) {
		// prepare request context
		if(isset($this->proxy_chain) && strlen($this->proxy_chain) != 0)
			$proxy_ctx = array('http' => array('proxy' => $this->proxy_chain,'request_fulluri' => true));
		else
			$proxy_ctx = array('http' => array());
		$context   = stream_context_create($proxy_ctx);
		
		// get authentification form
		$auth = file_get_html('https://www.mediapart.fr/', false, $context) or $this->returnError('Could not request Mediapart.', 404);
		$auth = $auth->find('form[id=logFormEl]', 0)       or $this->returnError('Form has changed...', 422);
		$action = $auth->action;
		$post_data['name']          = $user;
		$post_data['pass']          = $pass;
		$post_data['op']            = $auth->find('input[name=op]', 0)->value;
		$post_data['form_build_id'] = $auth->find('input[name=form_build_id]', 0)->value;
		$post_data['form_id']       = $auth->find('input[name=form_id]', 0)->value;
		
		// prepare submission with curl
		$curl_connection = curl_init($action);
		curl_setopt($curl_connection, CURLOPT_HEADER, true);
		curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, false);

		if(isset($this->proxy_chain) && strlen($this->proxy_chain) != 0) {
			curl_setopt($curl_connection, CURLOPT_PROXY, $this->proxy_chain);
			curl_setopt($curl_connection, CURLOPT_HTTPPROXYTUNNEL, 1);
		}
		
		// submit form with curl and close connection
		$result = curl_exec($curl_connection) or $this->returnError('Submission failed',500);
		curl_close($curl_connection);
		
		// get session cookie in string request form
		preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches) or $this->returnError('Bad credentials',401);
		$cookie = 'Cookie: '.implode('; ', $matches[1]);
		
		// cookie context
		$cookie_ctx = array('http' => array('header' => $cookie));
		
		// add proxy values if any
		$context = array('http' => array_merge($cookie_ctx['http'],$proxy_ctx['http']));
		
		// cookie format for simple_html_dom
		$this->session = stream_context_create($context);
	}
	
	
	public function collectData(array $param) {
		// init (constructor ?)
		if (!isset($param['recovery']))
			$this->returnError('Not in recovery process, assertion failure', 500);
		
		if (isset($param['user']) && isset($param['pass']))
			$this->registerSession($param['user'],$param['pass']);
		else
			$this->returnError('You must specify your credentials', 400);
		
		
		// fetch recovery list
		$db = mysql_connect('localhost','ttrss','x?G&H-ya[S7ho=RaXdiV');
		mysql_select_db('ttrss', $db);
		echo mysql_error($db);
		
		// fetch recovery list
		$query = mysql_query("select link, entry_title, author, updated, user_entries, note
			from feeds
			where login='greg' and feeds=368 and unread=1 and (note is NULL OR NOT(note like 'XX%'))
			group by user_entries
			order by updated
			limit 5",$db);
		echo mysql_error($db);
		
		// create feed from recovery list
		while($res = mysql_fetch_object($query)) {
			$item = new \Item();
			$item->title     = $res->entry_title;
			$item->name      = $res->author;
			$item->uri       = $res->link;
			$item->timestamp = $res->updated;
			$item->timestamp = strtotime($item->timestamp.'+00:00');
			$item->content   = $this->ExtractContent($item->uri);
			$this->items[]   = $item;
			
			// si succès
			mysql_query("update ttrss_user_entries set note='". $res->note.'X' ."' where int_id=".$res->user_entries, $db);
			echo mysql_error($db);
		}
		
		// + request to force quick updating
		mysql_query( "UPDATE ttrss_feeds SET last_update_started = '1970-01-01',last_updated = '1970-01-01' where id=6479", $db);
		mysql_close($db);
	}

	public function getName(){
		return 'Mediapart';
	}

	public function getURI(){
		return 'https://www.mediapart.fr';
	}

	public function getCacheDuration(){
		return 0; // DEV
		return 3600; // 1 hour
	}
}
