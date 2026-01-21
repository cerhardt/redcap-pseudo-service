<?php
namespace meDIC\PseudoService;
use \XMLWriter as XMLWriter;
use \REDCap as REDCap;
use \RCView as RCView;
use \Logging as Logging;


include_once('SAPPatientSearch.php');
include_once('EPIX_gPAS.php');

class PseudoService extends \ExternalModules\AbstractExternalModule {
	public $error;
    
    public function __construct() {
        parent::__construct();

        $this->AccessToken = array();
        $this->SessionPrefix = '';

        // System settings
        // Authentication Types
        $this->aSystemAuthTypes = array();
        for($i=1;$i<=3;$i++) {
            if (strlen($this->getSystemSetting("auth_type".$i)) > 0) {
                $this->aSystemAuthTypes[$i]['auth_type'] = $this->getSystemSetting("auth_type".$i);
                $this->aSystemAuthTypes[$i]['basic_name'] = $this->getSystemSetting("basic_name".$i);
                $this->aSystemAuthTypes[$i]['basic_secret'] = $this->getSystemSetting("basic_secret".$i);
                $this->aSystemAuthTypes[$i]['authorization_url'] = $this->getSystemSetting("authorization_url".$i);
                $this->aSystemAuthTypes[$i]['client_id'] = $this->getSystemSetting("client_id".$i);
                $this->aSystemAuthTypes[$i]['secret'] = $this->getSystemSetting("secret".$i);
            }
        }
        
        // gPAS
        $this->gpas_auth_type = $this->getSystemSetting("gpas_auth_type");
        $this->gpas_url = $this->getSystemSetting("gpas_url");
        $this->gpas_scope = $this->getSystemSetting("gpas_scope");
        $this->gpas_domain_url = $this->getSystemSetting("gpas_domain_url");
        $this->gpas_domain_scope = $this->getSystemSetting("gpas_domain_scope");
        
        // E-PIX
        $this->use_epix = $this->getSystemSetting("use_epix");
        $this->epix_auth_type = $this->getSystemSetting("epix_auth_type");
        $this->epix_url = $this->getSystemSetting("epix_url");
        $this->epix_scope = $this->getSystemSetting("epix_scope");
        $this->epix_domain = $this->getSystemSetting("epix_domain");
        $this->epix_safe_source = $this->getSystemSetting("epix_safe_source");
        $this->epix_external_source = $this->getSystemSetting("epix_external_source");
        $this->epix_id_domain = $this->getSystemSetting("epix_id_domain");
        
        // SAP
        $this->use_sap = $this->getSystemSetting("use_sap");
        $this->sap_auth_type = $this->getSystemSetting("sap_auth_type");
        $this->sap_url = $this->getSystemSetting("sap_url");
        $this->sap_scope = $this->getSystemSetting("sap_scope");
        $this->sap_filter_pid = $this->getSystemSetting("sap_filter_pid");
        $this->sap_filter_lastname = $this->getSystemSetting("sap_filter_lastname");
        $this->sap_filter_firstname = $this->getSystemSetting("sap_filter_firstname");
        $this->sap_filter_dob_from = $this->getSystemSetting("sap_filter_dob_from");
        $this->sap_filter_dob_to = $this->getSystemSetting("sap_filter_dob_to");
        
        // Overwrite system settings
        if ($this->getProjectId() && $this->getProjectSetting("project_custom_settings") === true) {
            // Overwrite Auth Types
            if ($this->getProjectSetting("project_auth_overwrite") === true) {
                $this->aProjectAuthTypes = array();
                // Authentication Types
                for($i=1;$i<=3;$i++) {
                    if (strlen($this->getProjectSetting("project_auth_type".$i)) > 0) {
                        $this->aProjectAuthTypes[$i]['auth_type'] = $this->getProjectSetting("project_auth_type".$i);
                        $this->aProjectAuthTypes[$i]['basic_name'] = $this->getProjectSetting("project_basic_name".$i);
                        $this->aProjectAuthTypes[$i]['basic_secret'] = $this->getProjectSetting("project_basic_secret".$i);
                        $this->aProjectAuthTypes[$i]['authorization_url'] = $this->getProjectSetting("project_authorization_url".$i);
                        $this->aProjectAuthTypes[$i]['client_id'] = $this->getProjectSetting("project_client_id".$i);
                        $this->aProjectAuthTypes[$i]['secret'] = $this->getProjectSetting("project_secret".$i);
                    }
                }

                // gPAS Auth
                $this->gpas_auth_type = $this->getProjectSetting("project_gpas_auth_type");
                $this->gpas_url = $this->getProjectSetting("project_gpas_url");
                $this->gpas_scope = $this->getProjectSetting("project_gpas_scope");
                $this->gpas_domain_url = $this->getProjectSetting("project_gpas_domain_url");
                $this->gpas_domain_scope = $this->getProjectSetting("project_gpas_domain_scope");

                // E-PIX Auth
                $this->epix_auth_type = $this->getProjectSetting("project_epix_auth_type");
                $this->epix_url = $this->getProjectSetting("project_epix_url");
                $this->epix_scope = $this->getProjectSetting("project_epix_scope");
                $this->epix_domain = $this->getProjectSetting("project_epix_domain");
                $this->epix_safe_source = $this->getProjectSetting("project_epix_safe_source");
                $this->epix_external_source = $this->getProjectSetting("project_epix_external_source");
                $this->epix_id_domain = $this->getProjectSetting("project_epix_id_domain");
            }
            // Use E-PIX
            $this->use_epix = $this->getProjectSetting("project_use_epix");
            // Use SAP
            $this->use_sap = $this->getProjectSetting("project_use_sap");
        }
        
        // manual edit allowed?        
        $this->manual_edit = false;

        if ($this->use_sap === true) {
            // SAP needs E-PIX
            $this->use_epix = true;
                            
            if ($this->getProjectId() && $this->getProjectSetting("extern") === true) {
                $this->manual_edit = true;
            }
        } elseif ($this->use_epix === true) {
            $this->manual_edit = true;
        }        
        
        // module index URL
        $this->moduleIndex = $this->getUrl('index.php');        

        // callback URL
        $this->callbackUrl = $this->moduleIndex;
        
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
            // get user rights in project
            $username = defined("USERID") ? USERID : null;
    		if (\UserRights::isImpersonatingUser()) {
    		    $username = \UserRights::getUsernameImpersonating();
    		}
            $this->user_rights = REDCap::getUserRights($username)[$username];
            if (intval($this->getProjectSetting("maxcnt")) > 0) {
                $this->maxcnt = intval($this->getProjectSetting("maxcnt"));
            }
            $this->gpas_domain = $this->getProjectSetting("gpas_domain");

            // use DAGs in E-PIX?
            $this->group_id = '';
            $this->bnoDAG = false;
            $this->dag_prefix = '';
            if ($this->getProjectSetting("use_dags") === true) {
                // Check if the user is in a data access group (DAG)
                if (is_numeric($this->user_rights['group_id'])) {
                    $this->group_id = $this->user_rights['group_id'];
                } else {
                    $this->bnoDAG = true;
                }
            }
            // use DAGs in record_ids?
            if ($this->getProjectSetting("use_dags_prefix") === true && is_numeric($this->user_rights['group_id'])) {
                $this->dag_prefix = $this->user_rights['group_id']."-";
            }

            /*
            // If $group_id is blank, then user is not in a DAG
            if ($group_id == '') {
            	print "User $this_user is NOT assigned to a data access group.";
            } else {
            	// User is in a DAG, so get the DAG's name to display
            	print "User $this_user is assigned to the DAG named \"" . REDCap::getGroupNames(false, $group_id)
            		. "\", whose unique group name is \"" . REDCap::getGroupNames(true, $group_id) . "\".";
            }
            */

        }
        
        $this->error = '';
    }


    /**
    * Login to API Gateway with OAuth2 (authorization code grant)
    *
    * @author  Christian Erhardt
    * @access  protected
    * @return void
    */
    public function login() {

       for($i=1;$i<=3;$i++) {
            $aAuth = $this->getAuth($i);
            if (!$aAuth) continue;

            if ($aAuth['auth_type'] == 'oidc_flow' || $aAuth['auth_type'] == 'oidc_client') {
                if (empty($aAuth['authorization_url'])) {
                    exit('authorization_url missing!');
                }

                $host = dirname($aAuth['authorization_url']);

                $scopes = [];
                if ($this->gpas_auth_type == $i) {
                    $scopes[] = $this->gpas_scope;
                    $scopes[] = $this->gpas_domain_scope;
                }
                if ($this->use_epix === true && $this->epix_auth_type == $i) {
                    $scopes[] = $this->epix_scope;
                }
                if ($this->use_sap === true && $this->sap_auth_type == $i) {
                    $scopes[] = $this->sap_scope;
                }
                $scopes = array_values(array_filter($scopes, static fn($s) => is_string($s) && strlen(trim($s)) > 0));
                $scopeString = implode(' ', $scopes);

                $sess =& $_SESSION[$this->session][$this->SessionPrefix][$i];
            }

            if ($aAuth['auth_type'] == 'oidc_flow') {

                $aOpt = array(
                    'clientId'                => $aAuth['client_id'],    // The client ID assigned to you by the provider
                    'clientSecret'            => $aAuth['secret'],    // The client password assigned to you by the provider
                    'redirectUri'             => $this->callbackUrl,
                    'urlAuthorize'            => $aAuth['authorization_url'],
                    'urlAccessToken'          => $host.'/token',
                    'urlResourceOwnerDetails' => $host,
                    'proxy'                   => $this->proxy ?? null,
                    'verify'                  => false,
                    'scopes' => $scopeString
                );
                $provider = new \League\OAuth2\Client\Provider\GenericProvider($aOpt);

                /*
                if (isset($_SESSION['openid_connect_id_token'])) {
                    $sess['oauth2_accesstoken'] = $_SESSION['openid_connect_id_token'];
                }
                */

                // Token expired? -> Refresh Token
                if (isset($sess['oauth2_expiredin']) && time() >= $sess['oauth2_expiredin']) {

                    try {
                        $accessToken = $provider->getAccessToken('refresh_token', [
                            'refresh_token' => $sess['oauth2_refreshtoken']
                        ]);
                        $sess['oauth2_accesstoken'] = $accessToken->getToken();
                        $sess['oauth2_expiredin'] = $accessToken->getExpires();
                        $sess['oauth2_refreshtoken'] = $accessToken->getRefreshToken();

                    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
                        // Failed to get the access token or user details.
                         exit($e->getMessage());
                    }

                }
                
                // Already logged in: Get Token from session
                if (isset($sess['oauth2_accesstoken'])) {
                    $this->AccessToken[$i] = $sess['oauth2_accesstoken'];
                    return (true);
                }

                // If we don't have an authorization code then get one
                if (!isset($_GET['code'])) {
                
                    // Fetch the authorization URL from the provider; this returns the
                    // urlAuthorize option and generates and applies any necessary parameters
                    // (e.g. state).
                    $authorizationUrl = $provider->getAuthorizationUrl();

                    // Get the state generated for you and store it to the session.
                    $sess['oauth2_state'] = $provider->getState();

                    // Redirect the user to the authorization URL.
                    redirect($authorizationUrl);
                
                // Check given state against previously stored one to mitigate CSRF attack
                } elseif (empty($_GET['state']) || (isset($sess['oauth2_state']) && $_GET['state'] !== $sess['oauth2_state'])) {
                
                    if (isset($sess['oauth2_state'])) {
                        unset($sess['oauth2_state']);
                    }
                    exit('Invalid state');
                
                } else {
                
                    try {
                        // Try to get an access token using the authorization code grant.
                        $accessToken = $provider->getAccessToken('authorization_code', [
                            'code' => $_GET['code']
                        ]);
                        $sess['oauth2_accesstoken'] = $accessToken->getToken();
                        $sess['oauth2_expiredin'] = $accessToken->getExpires();
                        $sess['oauth2_refreshtoken'] = $accessToken->getRefreshToken();

                    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
                        // Failed to get the access token or user details.
                        exit($e->getMessage());
                    }
                
                }    
            } elseif ($aAuth['auth_type'] == 'oidc_client') {
                $leeway = 60;

                $provider = new \League\OAuth2\Client\Provider\GenericProvider([
                    'clientId'                => $aAuth['client_id'],
                    'clientSecret'            => $aAuth['secret'],
                    'redirectUri'             => null,
                    'urlAuthorize'            => $aAuth['authorization_url'],
                    'urlAccessToken'          => $host . '/token',
                    'urlResourceOwnerDetails' => $host,
                    'proxy'                   => $this->proxy ?? null,
                    'verify'                  => true,
                ]);

                if (!empty($sess['oauth2_accesstoken']) && !empty($sess['oauth2_expiredin'])) {
                    if (time() < ((int)$sess['oauth2_expiredin'] - $leeway)) {
                        $this->AccessToken[$i] = $sess['oauth2_accesstoken'];
                        return true;
                    }
                }

                try {
                    $tokenOptions = [];
                    if ($scopeString !== '') {
                        $tokenOptions['scope'] = $scopeString;
                    }

                    // grant_type=client_credentials
                    $accessToken = $provider->getAccessToken('client_credentials', $tokenOptions);

                    $sess['oauth2_accesstoken'] = $accessToken->getToken();
                    $sess['oauth2_expiredin']   = $accessToken->getExpires() ?: (time() + 300);
                    unset($sess['oauth2_refreshtoken']);

                    $this->AccessToken[$i] = $sess['oauth2_accesstoken'];
                    return true;

                } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
                    exit('Token-Error (Slot ' . $i . '): ' . $e->getMessage());
                }
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
            $iAuthType = $this->sap_auth_type;
        }
        if ($psService == 'epix') {
            $sXML = str_replace('<SOAP-ENV:Envelope>','<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://service.epix.ttp.icmvc.emau.org/">',$sXML);
            $url = $this->epix_url;
            $iAuthType = $this->epix_auth_type;
        }
        if ($psService == 'gpas') {
            $sXML = str_replace('<SOAP-ENV:Envelope>','<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://psn.ttp.ganimed.icmvc.emau.org/">',$sXML);
            $url = $this->gpas_url;
            $iAuthType = $this->gpas_auth_type;
        }
        if ($psService == 'gpas_domain') {
            $sXML = str_replace('<SOAP-ENV:Envelope>','<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://psn.ttp.ganimed.icmvc.emau.org/">',$sXML);
            $url = $this->gpas_domain_url;
            $iAuthType = $this->gpas_auth_type;
        }
        if (strlen($url) == 0) return (false);

        //Logging::logEvent('', "pseudo_service", "OTHER", '', "SOAP" . ": " . htmlspecialchars($sXML), "DEBUG SOAP CALL");
        //Logging::logEvent('', "pseudo_service", "OTHER", '', "SOAP URL" . ": " . $url, "DEBUG SOAP CALL");




        $aAuth = $this->getAuth($iAuthType);
        if (!$aAuth) return (false); 

        // set core curl options
        $curl_options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $sXML,
            CURLOPT_PROXY =>  $this->curl_proxy,
            CURLOPT_PROXYUSERPWD => $this->curl_proxy_auth,
        );
        
        if ($aAuth['auth_type'] == 'oidc_flow' || $aAuth['auth_type'] == 'oidc_client') {
            // get access token from session
            if (isset($_SESSION[$this->session][$this->SessionPrefix][$iAuthType]['oauth2_accesstoken'])) {
                $this->AccessToken[$iAuthType] = $_SESSION[$this->session][$this->SessionPrefix][$iAuthType]['oauth2_accesstoken'];
            } else {
                return (false);
            }

            $curl_options[CURLOPT_HTTPHEADER] = array("content-type: text/xml; charset=utf-8","Authorization:Bearer " . $this->AccessToken[$iAuthType]);

        } elseif ($aAuth['auth_type'] == 'basic') {
            $user_pw = $aAuth['basic_name'] . ":" . $aAuth['basic_secret'];
            $curl_options[CURLOPT_HTTPHEADER] = array("content-type: text/xml; charset=utf-8","Authorization:Basic " . base64_encode($user_pw));
        }
        // debug
        //print('<pre>'.htmlspecialchars($sXML).'</pre>');

        // curl call
        $curl = curl_init();
        curl_setopt_array($curl, $curl_options);
        $response = curl_exec($curl);
        //Logging::logEvent('', "pseudo_service", "OTHER", '', "SOAP RESPONSE" . ": " . $response, "DEBUG SOAP CALL");
        //print("<pre>".htmlspecialchars(var_export($curl_options, true))."</pre>");
        //print("<pre>".htmlspecialchars(var_export($response, true))."</pre>");
        $curl_info = curl_getinfo($curl);
        //print("<pre>".htmlspecialchars(var_export($curl_info, true))."</pre>");
        $err = curl_error($curl);
        curl_close($curl);

        // user is authenticated, but has the wrong scope
        if ($curl_info['http_code'] == '401') {
          throw new \Exception(strtoupper($psService).': Anmeldung fehlgeschlagen!');
        } 
        if ($curl_info['http_code'] == '403') {
          throw new \Exception(strtoupper($psService).': keine Berechtigung!');
        } 
        if ($curl_info['http_code'] == '502') {
          throw new \Exception(strtoupper($psService).': Dienst nicht verfügbar!');
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
    * get Authentication settings
    *
    * @author  Christian Erhardt
    * @param string $i Authentication Type
    * @access  private
    * @return mixed array/false
    */
    private function getAuth($i) {
        if ($this->getProjectId() && $this->getProjectSetting("project_custom_settings") === true) {
            // Overwrite Auth Types
            if ($this->getProjectSetting("project_auth_overwrite") === true) {
                $this->SessionPrefix = $this->getProjectId();
                if (!isset($this->aProjectAuthTypes[$i])) return false;
                return ($this->aProjectAuthTypes[$i]);
            }
        }    
        $this->SessionPrefix = '';
        if (!isset($this->aSystemAuthTypes[$i])) return false;
        return ($this->aSystemAuthTypes[$i]);
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
        $username = defined("USERID") ? USERID : null;
        $user_rights = REDCap::getUserRights($username)[$username];

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

        return $link;
    }

    public function getError() {
        return $this->error;
    }

    public function setError($sError) {
        $this->error = $sError;
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