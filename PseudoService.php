<?php
namespace meDIC\PseudoService;
use \XMLWriter as XMLWriter;
use \REDCap as REDCap;
use \RCView as RCView;

include_once('SAPPatientSearch.php');
include_once('EPIX_gPAS.php');

class PseudoService extends \ExternalModules\AbstractExternalModule {
	public $error;
    
    public function __construct() {
        parent::__construct();
        
        // System settings
        
        // gPAS
        $this->gpas_url = $this->getSystemSetting("gpas_url");
        $this->gpas_scope = $this->getSystemSetting("gpas_scope");
        $this->gpas_domain_url = $this->getSystemSetting("gpas_domain_url");
        $this->gpas_domain_scope = $this->getSystemSetting("gpas_domain_scope");
        
        // E-PIX
        $this->epix_url = $this->getSystemSetting("epix_url");
        $this->epix_scope = $this->getSystemSetting("epix_scope");
        $this->epix_domain = $this->getSystemSetting("epix_domain");
        $this->epix_safe_source = $this->getSystemSetting("epix_safe_source");
        $this->epix_external_source = $this->getSystemSetting("epix_external_source");
        $this->epix_id_domain = $this->getSystemSetting("epix_id_domain");
        
        // SAP
        $this->sap_url = $this->getSystemSetting("sap_url");
        $this->sap_scope = $this->getSystemSetting("sap_scope");
        
        // module index URL
        $this->moduleIndex = $this->replaceHost($this->getUrl('index.php'));        

        // callback URL
        $this->callbackUrl = $this->moduleIndex;

        // API Authentication
        $this->authorization_url = $this->getSystemSetting("authorization_url");
        $this->client_id = $this->getSystemSetting("client_id");    // The client ID assigned to you by the provider
        $this->client_secret = $this->getSystemSetting("secret");    // The client password assigned to you by the provider
        
        // namespace for session variables
        $this->session = strtolower($this->getModuleName());
        
        // set curl proxy from REDCap settings
        if ($this->getSystemSetting("use_proxy") === true) {
            $this->curl_proxy = $GLOBALS['proxy_hostname'];
            $this->curl_proxy_auth = $GLOBALS['proxy_username_password'];
            
            $proxy_tmp = parse_url($GLOBALS['proxy_hostname']);
            $this->proxy = $proxy_tmp['scheme'].'://';
            if (strlen($this->curl_proxy_auth) > 0) {
                $this->proxy .= $this->curl_proxy_auth.'@';
            }
            $this->proxy .= $proxy_tmp['host'];
            if (strlen($proxy_tmp['port']) > 0) {
                $this->proxy .= ':'.$proxy_tmp['port'];
            }
        }

        // default: max count of search hits
        $this->maxcnt = 50;

        // Project settings
        if ($this->getProjectId()) {
            if (intval($this->getProjectSetting("maxcnt")) > 0) {
                $this->maxcnt = intval($this->getProjectSetting("maxcnt"));
            }
            $this->gpas_domain = $this->getProjectSetting("gpas_domain");
        }
        
        $this->error = '';
    }


    /**
    * Login to API Gateway with OAuth2 (authorization code grant)
    *
    * @author  Christian Erhardt
    * @access  public
    * @return void
    */
    public function login() {
        // verify allowed domain
        if ($GLOBALS['_SERVER']['SERVER_NAME'] != $this->getSystemSetting("allowed_domain")) {
            exit('Zugriff verweigert!');
       }
        if (strlen($this->authorization_url) == 0) {
            exit('Authorisierungs URL fehlt!');
        }
        $host = parse_url($this->authorization_url, PHP_URL_HOST);

        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $this->client_id,    // The client ID assigned to you by the provider
            'clientSecret'            => $this->client_secret,    // The client password assigned to you by the provider
            'redirectUri'             => $this->callbackUrl,
            'urlAuthorize'            => 'https://'.$host.'/authorize',
            'urlAccessToken'          => 'https://'.$host.'/token',
            'urlResourceOwnerDetails' => 'https://'.$host,
            'proxy'                   => $this->proxy,
            'verify'                  => false,
            'scopes' => $this->sap_scope.' '.$this->gpas_scope.' '.$this->gpas_domain_scope.' '.$this->epix_scope
        ]);
        
        // Token expired? -> Refresh Token
        if (isset($_SESSION[$this->session]['oauth2_expiredin']) && time() >= $_SESSION[$this->session]['oauth2_expiredin']) {

            try {
                $accessToken = $provider->getAccessToken('refresh_token', [
                    'refresh_token' => $_SESSION[$this->session]['oauth2_refreshtoken']
                ]);
                $_SESSION[$this->session]['oauth2_accesstoken'] = $accessToken->getToken();
                $_SESSION[$this->session]['oauth2_expiredin'] = $accessToken->getExpires();
                $_SESSION[$this->session]['oauth2_refreshtoken'] = $accessToken->getRefreshToken();

            } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
                // Failed to get the access token or user details.
                 exit($e->getMessage());
            }

        }
        
        // Already logged in: Get Token from session
        if (isset($_SESSION[$this->session]['oauth2_accesstoken'])) {
            $this->AccessToken = $_SESSION[$this->session]['oauth2_accesstoken'];
            return (true);
        }

        // If we don't have an authorization code then get one
        if (!isset($_GET['code'])) {
        
            // Fetch the authorization URL from the provider; this returns the
            // urlAuthorize option and generates and applies any necessary parameters
            // (e.g. state).
            $authorizationUrl = $provider->getAuthorizationUrl();

            // Get the state generated for you and store it to the session.
            $_SESSION[$this->session]['oauth2_state'] = $provider->getState();
            
            // Redirect the user to the authorization URL.
            redirect($authorizationUrl);
        
        // Check given state against previously stored one to mitigate CSRF attack
        } elseif (empty($_GET['state']) || (isset($_SESSION[$this->session]['oauth2_state']) && $_GET['state'] !== $_SESSION[$this->session]['oauth2_state'])) {
        
            if (isset($_SESSION[$this->session]['oauth2_state'])) {
                unset($_SESSION[$this->session]['oauth2_state']);
            }
            exit('Invalid state');
        
        } else {
        
            try {
                // Try to get an access token using the authorization code grant.
                $accessToken = $provider->getAccessToken('authorization_code', [
                    'code' => $_GET['code']
                ]);
                $_SESSION[$this->session]['oauth2_accesstoken'] = $accessToken->getToken();
                $_SESSION[$this->session]['oauth2_expiredin'] = $accessToken->getExpires();
                $_SESSION[$this->session]['oauth2_refreshtoken'] = $accessToken->getRefreshToken();

            } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
                // Failed to get the access token or user details.
                exit($e->getMessage());
            }
        
        }    
    }

    /**
    * Curl SOAP call of E-PIX/gPAS/SAP webservice
    *
    * @author  Christian Erhardt
    * @param string service to call: epix / gpas / gpas_domain / sap
    * @param array request array
    * @param string soap function (optional)
    * @access  protected
    * @return array result from webservice
    */
    protected function _SoapCall($psService, $paRequest, $psFunction='') {
        if (strlen($psFunction) == 0) {
            $sFunctioName = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'];
        } else {
            $sFunctioName = $psFunction;
        }

        // convert request array to XML
        $aRequest = array('SOAP-ENV_Body' => array('ns1_'.$sFunctioName => $paRequest));
        $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\"?><SOAP-ENV:Envelope></SOAP-ENV:Envelope>");
        array_to_xml( $aRequest, $xml );
        $sXML= $xml->asXML();    
        $sXML = str_replace('SOAP-ENV_Body>','SOAP-ENV:Body>',$sXML);
        $sXML = str_replace('ns1_'.$sFunctioName,'ns1:'.$sFunctioName,$sXML);
        
        // determine namespace / url
        $url = '';
        if ($psService == 'sap') {
            $sXML = str_replace('<SOAP-ENV:Envelope>','<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="urn:sap-com:document:sap:rfc:functions">',$sXML);
            $url = $this->sap_url;
        }
        if ($psService == 'epix') {
            $sXML = str_replace('<SOAP-ENV:Envelope>','<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://service.epix.ttp.icmvc.emau.org/">',$sXML);
            $url = $this->epix_url;
        }
        if ($psService == 'gpas') {
            $sXML = str_replace('<SOAP-ENV:Envelope>','<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://psn.ttp.ganimed.icmvc.emau.org/">',$sXML);
            $url = $this->gpas_url;
        }
        if ($psService == 'gpas_domain') {
            $sXML = str_replace('<SOAP-ENV:Envelope>','<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://psn.ttp.ganimed.icmvc.emau.org/">',$sXML);
            $url = $this->gpas_domain_url;
        }
        if (strlen($url) == 0) return (false);

        // get access token from session
        if (isset($_SESSION[$this->session]['oauth2_accesstoken'])) {
            $this->AccessToken = $_SESSION[$this->session]['oauth2_accesstoken'];
        } else {
            return (false);
        }

        // debug
        //print('<pre>'.htmlspecialchars($sXML).'</pre>');
        
        // curl call
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => $url,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS => $sXML, 
          CURLOPT_HTTPHEADER => array("content-type: text/xml; charset=utf-8","Authorization:Bearer " . $this->AccessToken),
          CURLOPT_PROXY =>  $this->curl_proxy, 
          CURLOPT_PROXYUSERPWD => $this->curl_proxy_auth,     
        ));
 
        $response = curl_exec($curl);
        $curl_info = curl_getinfo($curl);
        $err = curl_error($curl);
        curl_close($curl);

        // user is authenticated, but has the wrong scope
        if ($curl_info['http_code'] == '401') {
          throw new \Exception(strtoupper($psService).': Anmeldung fehlgeschlagen!');
        } 
        if ($curl_info['http_code'] == '403') {
          throw new \Exception(strtoupper($psService).': keine Berechtigung!');
        } 

        // curl error
        if ($err) {
          throw new \Exception(strtoupper($psService).': '.$err);
        } 

        // convert xml to array
        $plainXML = PseudoService::mungXML($response);
        $arrayResult = json_decode(json_encode(SimpleXML_Load_String($plainXML, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        // omit first two levels of response
        $arrayResult = call_user_func_array('array_merge', array_values($arrayResult));        
        $arrayResult = call_user_func_array('array_merge', array_values($arrayResult));        

        // return result
        return($arrayResult);
    }

    /**
    * check access
    *
    * @author  Christian Erhardt
    * @param string $psTerm (search/edit/create/export/import/delete)
    * @access  public
    * @return boolean true/false
    */
    public static function isAllowed ($psTerm) {
        global $user_rights;

        switch ($psTerm) {
            case 'search':
                if (intval($user_rights['forms']['tc_access']) > 0) {
                    return true;
                }
                break;
            case 'edit':
                if (intval($user_rights['forms']['tc_access']) === 1) {
                    return true;
                }
                break;
            case 'create':
                if (intval($user_rights['record_create']) === 1 && intval($user_rights['forms']['tc_access']) === 1) {
                    return true;
                }
                break;
            case 'export':
                if (intval($user_rights['forms']['tc_impexp']) > 0 && intval($user_rights['forms']['tc_access']) === 1) {
                    return true;
                }
                break;
            case 'import':
                if (intval($user_rights['forms']['tc_impexp']) === 1 && intval($user_rights['forms']['tc_access']) === 1) {
                    return true;
                }
                break;
            case 'delete':
                if (intval($user_rights['forms']['tc_impexp']) === 1 && intval($user_rights['forms']['tc_access']) === 1 && intval($user_rights['record_delete']) === 1) {
                    return true;
                }
                break;
            default:
                return (false);
        }
    }
    
    /**
    * show depseudonymised data above data entry form
    *
    * @author  Christian Erhardt
    * @param integer $project_id
    * @param string $record
    * @param string $instrument
    * @param integer $event_id
    * @param integer $group_id
    * @param integer $repeat_instance
    * @access  public
    * @return void
    */
    public function hook_data_entry_form_top ($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $oPseudoService = new EPIX_gPAS();
        $oPseudoService->_hook_data_entry_form_top ($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance);
    }

    /**
    * show link to module for all users with search privileges
    *
    * @author  Christian Erhardt
    * @param integer $project_id
    * @param string $link
    * @access  public
    * @return boolean
    */
    public function redcap_module_link_check_display($project_id, $link) {
        if (!PseudoService::isAllowed('search')) {
            return false;
        }

        $link['url'] = $this->replaceHost($link['url']);
        return $link;
    }

    public function getError() {
        return $this->error;
    }

    public function setError($sError) {
        $this->error = $sError;
    }

    /**
    * get login state of current session (TEIS) 
    *
    * @author  Christian Erhardt
    * @access  public
    * @return boolean is user logged in?
    */
    public function getlogin() {
        if (isset($_SESSION[$this->session]['oauth2_accesstoken'])) {
            return (true);
        }
        return (false);
    }
    
    /**
    * deactivate namespaces in soap xml
    *
    * @author  https://localcoder.org/php-converting-xml-to-array-in-php-parsing-a-soap-xml-in-php-and-storing-it
    * @param string $xml XML string
    * @access  public
    * @return string XML
    */
    public static function mungXML($xml)
    {
        $obj = SimpleXML_Load_String($xml);
        if ($obj === FALSE) return $xml;
    
        // GET NAMESPACES, IF ANY
        $nss = $obj->getNamespaces(TRUE);
        if (empty($nss)) return $xml;
    
        // CHANGE ns: INTO ns_
        $nsm = array_keys($nss);
        foreach ($nsm as $key)
        {
            // A REGULAR EXPRESSION TO MUNG THE XML
            $rgx
            = '#'               // REGEX DELIMITER
            . '('               // GROUP PATTERN 1
            . '\<'              // LOCATE A LEFT WICKET
            . '/?'              // MAYBE FOLLOWED BY A SLASH
            . preg_quote($key)  // THE NAMESPACE
            . ')'               // END GROUP PATTERN
            . '('               // GROUP PATTERN 2
            . ':{1}'            // A COLON (EXACTLY ONE)
            . ')'               // END GROUP PATTERN
            . '#'               // REGEX DELIMITER
            ;
            // INSERT THE UNDERSCORE INTO THE TAG NAME
            $rep
            = '$1'          // BACKREFERENCE TO GROUP 1
            . '_'           // LITERAL UNDERSCORE IN PLACE OF GROUP 2
            ;
            // PERFORM THE REPLACEMENT
            $xml =  preg_replace($rgx, $rep, $xml);
        }
        return $xml;
    }
    
    public static function csv_to_array($filename='', $delimiter=',') {
        if(!file_exists($filename) || !is_readable($filename))
            return FALSE;
    
        // BOM as a string for comparison.
        $bom = "\xef\xbb\xbf";
        
        $header = NULL;
        $data = array();
        if (($handle = fopen($filename, 'r')) !== FALSE)
        {
            // Progress file pointer and get first 3 characters to compare to the BOM string.
            if (fgets($handle, 4) !== $bom) {
                // BOM not found - rewind pointer to start of file.
                rewind($handle);
            }
            while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE)
            {
                $row = array_map('trim', $row);
                if(!$header)
                    $header = $row;
                else
                    $data[] = array_combine($header, $row);
            }
            fclose($handle);
        }
        return $data;
    }

    /**
    * replace host in URLs if "allowed_domain" differs from redcap_base_url in REDCap settings
    *
    * @author  Christian Erhardt
    * @param string $sUrl
    * @access  private
    * @return string 
    */
    private function replaceHost($sUrl) {
        if (strlen($this->getSystemSetting("allowed_domain")) > 0) {
            if (strpos($sUrl, $this->getSystemSetting("allowed_domain")) === false) {
                $host = parse_url($GLOBALS['redcap_base_url'], PHP_URL_HOST);
                $sUrl = str_replace($host, $this->getSystemSetting("allowed_domain"),$sUrl);
            }
        }
        return $sUrl;    
    }
}

if (!function_exists('array_to_xml')) {
    function array_to_xml( $array, $xml = null ) {
      if ( is_array( $array ) ) {
        foreach( $array as $key => $value ) {
          if ( is_int( $key ) ) {
            if ( $key == 0 ) {
              $node = $xml;
            } else {
              $parent = $xml->xpath( ".." )[0];
              $node = $parent->addChild( $xml->getName() );
            }
          } else {
            $node = $xml->addChild( $key );
          }
          array_to_xml( $value, $node );
        }
      } else {
        $xml[0] = $array;
      }
    }
}