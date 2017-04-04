<?php
class WgmLinkedIn_API {
	const LINKEDIN_BASEURL = 'https://api.linkedin.com/v1/';
	const LINKEDIN_ACCESS_TOKEN_URL = "https://www.linkedin.com/oauth/v2/accessToken";
	const LINKEDIN_AUTHENTICATE_URL = "https://www.linkedin.com/oauth/v2/authorization";
	
	static $_instance = null;
	private $_oauth = null;
	
	private function __construct() {
		if(false == ($credentials = DevblocksPlatform::getPluginSetting('wgm.linkedin','credentials',false,true,true)))
			return;
		
		@$consumer_key = $credentials['consumer_key'];
		@$consumer_secret = $credentials['consumer_secret'];
		
		if(empty($consumer_key) || empty($consumer_secret))
			return;
		
		$this->_oauth = DevblocksPlatform::getOAuthService($consumer_key, $consumer_secret);
	}
	
	/**
	 * @return WgmLinkedIn_API
	 */
	static public function getInstance() {
		if(null == self::$_instance) {
			self::$_instance = new WgmLinkedIn_API();
		}

		return self::$_instance;
	}
	
	public function setToken($token) {
		$this->_oauth->setTokens($token);
	}
	
	public function getAccessToken() {
		return $this->_oauth->getAccessToken(self::LINKEDIN_ACCESS_TOKEN_URL);
	}
	
	public function post($path, $params) {
		return $this->_fetch($path, 'POST', $params);
	}
	
	public function get($path) {
		return $this->_fetch($path, 'GET');
	}
	
	private function _fetch($path, $method = 'GET', $params = array()) {
		$url = self::LINKEDIN_BASEURL . ltrim($path, '/');
		return $this->_oauth->executeRequestWithToken($method, $url, $params, 'Bearer');
	}
};

if(class_exists('Extension_PageMenuItem')):
class WgmLinkedIn_SetupMenuItem extends Extension_PageMenuItem {
	const POINT = 'wgm.linkedin.setup.menu';
	
	function render() {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('extension', $this);
		$tpl->display('devblocks:wgm.linkedin::setup/menu_item.tpl');
	}
};
endif;

if(class_exists('Extension_PageSection')):
class WgmLinkedIn_SetupSection extends Extension_PageSection {
	const ID = 'wgm.linkedin.setup.page';
	
	function render() {
		$tpl = DevblocksPlatform::getTemplateService();
		$visit = CerberusApplication::getVisit();
		
		$visit->set(ChConfigurationPage::ID, 'linkedin');
		
		$credentials = DevblocksPlatform::getPluginSetting('wgm.linkedin','credentials',false,true,true);
		$tpl->assign('credentials', $credentials);

		$tpl->display('devblocks:wgm.linkedin::setup/index.tpl');
	}
	
	function saveJsonAction() {
		try {
			@$consumer_key = DevblocksPlatform::importGPC($_REQUEST['consumer_key'],'string','');
			@$consumer_secret = DevblocksPlatform::importGPC($_REQUEST['consumer_secret'],'string','');
			
			if(empty($consumer_key) || empty($consumer_secret))
				throw new Exception("Both the 'Client ID' and 'Client Secret' are required.");
			
			$credentials = [
				'consumer_key' => $consumer_key,
				'consumer_secret' => $consumer_secret,
			];
			
			DevblocksPlatform::setPluginSetting('wgm.linkedin', 'credentials', $credentials, true, true);
			
			echo json_encode(array('status'=>true, 'message'=>'Saved!'));
			return;
			
		} catch (Exception $e) {
			echo json_encode(array('status'=>false, 'error'=>$e->getMessage()));
			return;
		}
	}
};
endif;

class ServiceProvider_LinkedIn extends Extension_ServiceProvider implements IServiceProvider_OAuth, IServiceProvider_HttpRequestSigner {
	const ID = 'wgm.linkedin.service.provider';

	function renderConfigForm(Model_ConnectedAccount $account) {
		$tpl = DevblocksPlatform::getTemplateService();
		$active_worker = CerberusApplication::getActiveWorker();
		
		$tpl->assign('account', $account);
		
		$params = $account->decryptParams($active_worker);
		$tpl->assign('params', $params);
		
		$tpl->display('devblocks:wgm.linkedin::provider/linkedin.tpl');
	}
	
	function saveConfigForm(Model_ConnectedAccount $account, array &$params) {
		@$edit_params = DevblocksPlatform::importGPC($_POST['params'], 'array', array());
		
		$active_worker = CerberusApplication::getActiveWorker();
		$encrypt = DevblocksPlatform::getEncryptionService();
		
		// Decrypt OAuth params
		if(isset($edit_params['params_json'])) {
			if(false == ($outh_params_json = $encrypt->decrypt($edit_params['params_json'])))
				return "The connected account authentication is invalid.";
				
			if(false == ($oauth_params = json_decode($outh_params_json, true)))
				return "The connected account authentication is malformed.";
			
			if(is_array($oauth_params))
			foreach($oauth_params as $k => $v)
				$params[$k] = $v;
		}
		
		return true;
	}
	
	private function _getAppKeys() {
		if(false == ($credentials = DevblocksPlatform::getPluginSetting('wgm.linkedin','credentials',false,true,true)))
			return false;
		
		@$consumer_key = $credentials['consumer_key'];
		@$consumer_secret = $credentials['consumer_secret'];
		
		if(empty($consumer_key) || empty($consumer_secret))
			return false;
		
		return array(
			'key' => $consumer_key,
			'secret' => $consumer_secret,
		);
	}
	
	function oauthRender() {
		$url_writer = DevblocksPlatform::getUrlService();
		
		@$form_id = DevblocksPlatform::importGPC($_REQUEST['form_id'], 'string', '');
		
		// Store the $form_id in the session
		$_SESSION['oauth_form_id'] = $form_id;
		
		// [TODO] Report about missing app keys
		if(false == ($app_keys = $this->_getAppKeys()))
			return false;
		
		$oauth = DevblocksPlatform::getOAuthService($app_keys['key'], $app_keys['secret']);
		
		// Persist the view_id in the session
		$_SESSION['oauth_state'] = CerberusApplication::generatePassword(24);
		
		// OAuth callback
		$redirect_url = $url_writer->write(sprintf('c=oauth&a=callback&ext=%s', ServiceProvider_LinkedIn::ID), true);

		$url = sprintf("%s?response_type=code&client_id=%s&redirect_uri=%s&state=%s&scope=%s", 
			WgmLinkedIn_API::LINKEDIN_AUTHENTICATE_URL,
			$app_keys['key'],
			rawurlencode($redirect_url),
			$_SESSION['oauth_state'],
			rawurlencode('r_basicprofile r_emailaddress rw_company_admin w_share')
		);
		
		header('Location: ' . $url);
	}
	
	function oauthCallback() {
		@$oauth_state = $_SESSION['oauth_state'];
		$form_id = $_SESSION['oauth_form_id'];
		unset($_SESSION['oauth_form_id']);
		
		@$code = DevblocksPlatform::importGPC($_REQUEST['code'], 'string', '');
		@$state = DevblocksPlatform::importGPC($_REQUEST['state'], 'string', '');
		@$error = DevblocksPlatform::importGPC($_REQUEST['error'], 'string', '');
		@$error_msg = DevblocksPlatform::importGPC($_REQUEST['error_description'], 'string', '');
		
		$active_worker = CerberusApplication::getActiveWorker();
		$url_writer = DevblocksPlatform::getUrlService();
		$encrypt = DevblocksPlatform::getEncryptionService();
		
		$redirect_url = $url_writer->write(sprintf('c=oauth&a=callback&ext=%s', ServiceProvider_LinkedIn::ID), true);
		
		if(false == ($app_keys = $this->_getAppKeys()))
			return false;
		
		// [TODO] Check $error state
		// [TODO] Compare $state
		
		$oauth = DevblocksPlatform::getOAuthService($app_keys['key'], $app_keys['secret']);
		$oauth->setTokens($code);
		
		$params = $oauth->getAccessToken(WgmLinkedIn_API::LINKEDIN_ACCESS_TOKEN_URL, array(
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $redirect_url,
			'client_id' => $app_keys['key'],
			'client_secret' => $app_keys['secret'],
		));
		
		if(!is_array($params) || !isset($params['access_token'])) {
			return false;
		}
		
		// Load their profile
		
		$url = WgmLinkedIn_API::LINKEDIN_BASEURL . 'people/~?format=json&oauth2_access_token=' . rawurlencode($params['access_token']);
		$ch = DevblocksPlatform::curlInit($url);
		$out = DevblocksPlatform::curlExec($ch);
		
		if(false == ($json = json_decode($out, true)) || !is_array($json))
			return false;
		
		$label = 'LinkedIn';
		
		if(isset($json['firstName']) && isset($json['lastName'])) {
			$label = $json['firstName'] . ' ' . $json['lastName'];
			@$params['label'] = $label;
		}
		
		// Output
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('form_id', $form_id);
		$tpl->assign('label', $label);
		$tpl->assign('params_json', $encrypt->encrypt(json_encode($params)));
		$tpl->display('devblocks:cerberusweb.core::internal/connected_account/oauth_callback.tpl');
	}
	
	// [TODO] Tokens expire in 60 days
	function authenticateHttpRequest(Model_ConnectedAccount $account, &$ch, &$verb, &$url, &$body, &$headers) {
		$credentials = $account->decryptParams();
		
		if(!isset($credentials['access_token']))
			return false;
		
		// Add a bearer token
		//$headers[] = sprintf('Authorization: Bearer %s', $credentials['access_token']);
		
		// Add token to the query instead
		if(false !== stripos($url,'?')) {
			$url .= '&oauth2_access_token=' . rawurlencode($credentials['access_token']);
		} else {
			$url .= '?oauth2_access_token=' . rawurlencode($credentials['access_token']);
		}
		
		return true;
	}
	
}