<?php
namespace meDIC\PseudoService;
use \REDCap as REDCap;

class EPIX_gPAS extends PseudoService {
    public $aGender;
    
    public function __construct() {
		parent::__construct();

        $this->aGender = array(
            "M" => "Männlich",
            "F" => "Weiblich",
            "X" => "Divers",
            "O" => "Sonstiges",
            "U" => "Unbekannt"
        );
        
        $this->aIsoCodes = array();
        $aIsoTmp = PseudoService::csv_to_array(dirname(__FILE__).'/german-iso-3166.csv');        
        foreach($aIsoTmp as $aTmp) {
            $this->aIsoCodes[$aTmp['iso']] = $aTmp['label'];
        }
        
        // HTML code for showing IDAT on pages with forms
        $this->idatwrap = '<div class="blue" style="margin:-13px 0 0;">%s</div><div>&nbsp;</div>';
        
        // load gpas domain properties in session
        if ($this->getProjectId() && !isset($_SESSION[$this->session]['domains'][$this->gpas_domain])) {
            $_SESSION[$this->session]['domains'][$this->gpas_domain] = $this->getDomain();
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
    function _hook_data_entry_form_top ($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {

        // check access to depseudonymised data  
        if (!PseudoService::isAllowed('search')) {
            return;
        }

        // not logged in? => return
        if (!$this->getlogin()) {
            $_SESSION[$this->session]['redirect'] = $_SERVER['QUERY_STRING'];        
            printf ($this->idatwrap, 'Bitte in TEIS <a href="'.$this->moduleIndex.'">anmelden</a> zur Anzeige der IDAT');
            return;
        }
        
        // get REDCap ID
        $sID = $_GET['id'];
        
        // is idat stored in session?
        if (isset($_SESSION[$this->session]['domains'][$this->gpas_domain]['idat'][$sID])) {
            printf ($this->idatwrap, $_SESSION[$this->session]['domains'][$this->gpas_domain]['idat'][$sID]);
            return;
        }

        // call GPAS with PSN to to get MPI
        $mpiID = $this->getValueFor($sID);
        if (!$mpiID) return;

        // external PSN: Show value and return
        if ($this->getProjectSetting("extpsn") === true) {
            if (str_starts_with($mpiID,$this->getProjectSetting("extpsn_prefix"))) {
                $mpiID = substr($mpiID, strlen($this->getProjectSetting("extpsn_prefix")));
                $_SESSION[$this->session]['domains'][$this->gpas_domain]['idat'][$sID] = $mpiID;
                printf ($this->idatwrap, $mpiID);
                return;
            }
        }

        // get SAP-ID (ID_DOMAIN) from E-PIX
        $aISH_IDs = array();
        $aIdentifier = $this->getAllIdentifierForAcivePersonWithMPI($mpiID);
        foreach($aIdentifier as $aId) {
            if ($aId['identifierDomain']['name'] == $this->epix_id_domain) {
                $aISH_IDs[] = $aId['value'];
            }
        }

        // get personal data from E-PIX
        $aIdentifier = $this->getActivePersonByMPI($mpiID);
        $aRefIdentity = $aIdentifier['referenceIdentity'];
        $sIdat = '';
        if (!is_array($aRefIdentity['firstName'])) {
            $sIdat =  $aRefIdentity['firstName'];     
        }
        if (!is_array($aRefIdentity['lastName'])) {
            $sIdat .=  ' '.$aRefIdentity['lastName'];     
        }
        if (!is_array($aRefIdentity['mothersMaidenName'])) {
            if (strlen($aRefIdentity['mothersMaidenName']) > 0 && $aRefIdentity['mothersMaidenName'] != $aRefIdentity['lastName']) {
                $sIdat .= ' (geb. '.$aRefIdentity['mothersMaidenName'].')';
            }
        }
        if (!is_array($aRefIdentity['birthDate'])) {
            if (strlen($aRefIdentity['birthDate']) > 0) {
                $aTmp = explode("T",$aRefIdentity['birthDate']);
                $sIdat .=  ' ('.\DateTimeRC::format_user_datetime($aTmp[0], 'Y-M-D_24', 'D.M.Y_24').')';     
            }
        }
        if (count($aISH_IDs) > 0) {
            $sIdat .= ', SAP-ID '.implode(", ",$aISH_IDs);
        }
        $_SESSION[$this->session]['domains'][$this->gpas_domain]['idat'][$sID] = $sIdat;
        printf ($this->idatwrap, $sIdat);
    }


    /**
    * Curl SOAP call of E-PIX/gPAS webservice
    *
    * @author  Christian Erhardt
    * @param string service to call: epix / gpas / gpas_domain
    * @param array request array
    * @param string soap function (optional)
    * @access  public
    * @return array result from webservice
    */
    public function SoapCall($psService, $paRequest, $psFunction='') {
        $arrayResult = $this->_SoapCall($psService,$paRequest,$psFunction);

        // error handling E-PIX / gPAS
        if (strlen($arrayResult['faultstring']) > 0) {
            throw new \Exception($arrayResult['faultstring']);
        } 

        // return result
        return($arrayResult);
    }
    
    /**
    * E-PIX: search for external probands
    *
    * @author  Christian Erhardt
    * @param array $paPerson search parameters last name, birthdate
    * @access  public
    * @return array list of probands
    */
    public function searchPersonsByPDQ($paPerson) {
        $requestArray = Array();
        $requestArray['searchMask']['domainName'] = $this->epix_domain;
        $requestArray['searchMask']['and'] = true;
        $requestArray['searchMask']['maxResults'] = $this->maxcnt;
        if (strlen($paPerson['birthDate']) > 0) {
            $requestArray['searchMask']['identity']['birthDate'] = \DateTimeRC::format_user_datetime($paPerson['birthDate'], 'D.M.Y_24', 'Y-M-D_24');
        }
        if (strlen($paPerson['lastName']) > 0) {
            $requestArray['searchMask']['identity']['lastName'] = rtrim($paPerson['lastName'],"*");
        }
        if (strlen($paPerson['firstName']) > 0) {
            $requestArray['searchMask']['identity']['firstName'] = rtrim($paPerson['firstName'],"*");
        }
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }

        // only 1 hit: convert array
        $aItems = array();
        if (isset($result['return']['mpiId'])) {
            $aItems[] = $result['return'];
        } else {
            $aItems = $result['return'];
        }
        
        return ($aItems);
    }

    /**
    * E-PIX: search / create MPI for external proband
    *
    * @author  Christian Erhardt
    * @param array $paPerson personal data
    * @access  public
    * @return array return personal data (MPI, matchStatus)
    */
    public function requestMPI($paPerson) {
        // if MPI is given, decrypt MPI and update person
        if (strlen($paPerson['mpiid_enc']) > 0) {
            $mpiId = decrypt($paPerson['mpiid_enc'],$_SESSION[$this->session]['enckey']);
            $bMode = 'update';
        } else {
            $bMode = 'insert';
        }

        $requestArray = Array();
        $requestArray['domainName'] = $this->epix_domain;
        $requestArray['sourceName'] = $this->epix_external_source;
        $requestArray['identity']['birthDate'] = \DateTimeRC::format_user_datetime($paPerson['birthDate'], 'D.M.Y_24', 'Y-M-D_24');
        $requestArray['identity']['firstName'] = $paPerson['firstName'];
        $requestArray['identity']['lastName'] = $paPerson['lastName'];
        $requestArray['identity']['gender'] = $paPerson['gender'];
        $requestArray['identity']['mothersMaidenName'] = $paPerson['mothersMaidenName'];
        $requestArray['identity']['degree'] = $paPerson['degree'];
        $requestArray['identity']['contacts']['street'] = $paPerson['street'];
        $requestArray['identity']['contacts']['zipCode'] = $paPerson['zipCode'];
        $requestArray['identity']['contacts']['city'] = $paPerson['city'];
        $requestArray['identity']['contacts']['phone'] = $paPerson['phone'];
        if (strlen($paPerson['country']) > 0) {
            $requestArray['identity']['contacts']['country'] = $this->aIsoCodes[$paPerson['country']];
            $requestArray['identity']['contacts']['countryCode'] = $paPerson['country'];
        }
        
        // add custom vars
        if (is_array($this->getProjectSetting("cust-vars-list"))) {
            foreach($this->getProjectSetting("cust-vars-list") as $i => $foo) {
                if (strlen($this->getProjectSetting("custom_field")[$i]) > 0) {
                    $sFieldTmp = 'value'.$this->getProjectSetting("custom_field")[$i];
                    if (isset($paPerson[$sFieldTmp])) {
                        $requestArray['identity'][$sFieldTmp] = $paPerson[$sFieldTmp];
                    }
                }
            }
        }

        // create new person
        if ($bMode == 'insert') {
            try {
                $result = $this->SoapCall("epix",$requestArray);
            } catch (\Exception $e) {
                $this->error = $e->getMessage();
                return (false);
            }
        }

        // update E-PIX data 
        if ($bMode == 'update') {
            $requestArray['mpiId'] = $mpiId;
            $requestArray['force'] = true;
            try {
                $result = $this->SoapCall("epix",$requestArray,"updatePerson");
            } catch (\Exception $e) {
                $this->error = $e->getMessage();
                return (false);
            }
        }
        
        return ($result);
    }

    /**
    * E-PIX: get all identifiers for proband
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @access  private
    * @return array list of identifiers
    */
    private function getAllIdentifierForAcivePersonWithMPI($piMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->epix_domain;
        $requestArray['mpiId'] = $piMPI;
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        // only 1 hit: convert array
        $aItems = array();
        if (isset($result['return']['identifierDomain'])) {
            $aItems[] = $result['return'];
        } else {
            $aItems = $result['return'];
        }
        return ($aItems);
    }

    /**
    * E-PIX: get personal data for proband
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @access  public
    * @return array personal data
    */
    public function getActivePersonByMPI($piMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->epix_domain;
        $requestArray['mpiId'] = $piMPI;
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return ($result['return']);
    }

    /**
    * E-PIX: get personal data for probands (method available since E-PIX 3.0.0)
    *
    * @author  Christian Erhardt
    * @param array $paMPI array of MPIs
    * @access  public
    * @return array personal data
    */
    public function getActivePersonsByMPIBatch($paMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->epix_domain;
        $requestArray['mpiIds'] = $paMPI;
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        // only 1 hit: convert array
        $aItems = array();
        if (isset($result['return']['mpiId'])) {
            $aItems[] = $result['return'];
        } else {
            $aItems = $result['return'];
        }
        
        return ($aItems);
    }

    /**
    * E-PIX: search for SAP-ID
    *
    * @author  Christian Erhardt
    * @param string $piISH SAP-ID
    * @access  public
    * @return array personal data
    */
    public function getActivePersonByLocalIdentifier($piISH, $pbMPI = true) {
        // 
        $requestArray = array();
        $requestArray['domainName'] = $this->epix_domain;
        $requestArray['identifier']['value'] = $piISH;
        $requestArray['identifier']['identifierDomain']['name'] = $this->epix_id_domain;
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }

        if ($pbMPI) {
            $mpiId = $result['return']['mpiId']['value'];
            return ($mpiId);
        } else {
            // only 1 hit: convert array
            $aItems = array();
            if (isset($result['return']['mpiId'])) {
                $aItems[] = $result['return'];
            } else {
                $aItems = $result['return'];
            }
            return ($aItems);
        }
    }
    
    /**
    * E-PIX: delete person
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @access  public
    * @return array personal data
    */
    public function deletePerson($piMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->epix_domain;
        $requestArray['mpiId'] = $piMPI;
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return (true);
    }

    /**
    * E-PIX: deactivate person
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @access  public
    * @return boolean success
    */
    public function deactivatePerson($piMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->epix_domain;
        $requestArray['mpiId'] = $piMPI;
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return (true);
    }

    /**
    * E-PIX: get possible matches for domain
    *
    * @author  Christian Erhardt
    * @access  public
    * @return array doublets
    */
    public function getPossibleMatchesForDomain() {
        $requestArray = Array();
        $requestArray['domainName'] = $this->epix_domain;
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        // only 1 hit: convert array
        $aItems = array();
        if (isset($result['return']['matchingMPIIdentities'])) {
            $aItems[] = $result['return'];
        } else {
            $aItems = $result['return'];
        }
        return ($aItems);
    }

    /**
    * E-PIX: resolution of doublets: keep both persons
    *
    * @author  Christian Erhardt
    * @param integer $piLinkId LinkId
    * @access  public
    * @return void
    */
    public function removePossibleMatch($piLinkId) {
        $requestArray = Array();
        $requestArray['possibleMatchId'] = $piLinkId;
        $requestArray['comment'] = '';
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return ($result);
    }

    /**
    * E-PIX: resolution of doublets: keep person 1/2
    *
    * @author  Christian Erhardt
    * @param integer $piLinkId LinkId
    * @param integer $piIdentityId IdentityId
    * @access  public
    * @return void
    */
    public function assignIdentity($piLinkId, $piIdentityId) {
        $requestArray = Array();
        $requestArray['possibleMatchId'] = $piLinkId;
        $requestArray['winningIdentityId'] = $piIdentityId;
        $requestArray['comment'] = '';
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return ($result);
    }

    /**
    * E-PIX: get personal data for proband: filter works for only 1 MPI! (not used)
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @access  public
    * @return array personal data
    */
    public function getActivePersonsForDomainFiltered($paMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->epix_domain;
        $requestArray['filter'] = $paMPI;
        $requestArray['filterIsCaseSensitive'] = false;
        try {
            $result = $this->SoapCall("epix",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return ($result['return']);
    }

    /**
    * gPAS: checks if the given string is a valid pseudonym
    *
    * @author  Christian Erhardt
    * @param string $psPSN pseudonym
    * @access  public
    * @return boolean
    */
    public function validatePSN($psPSN) {
        if (strlen($psPSN) == 0) return;
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        $requestArray['psn'] = $this->addZero($psPSN);
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return (true);
    }

    /**
    * gPAS: gets or creates pseudonym for MPI
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @access  public
    * @return string PSN
    */
    public function getOrCreatePseudonymFor($piMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        $requestArray['value'] = $piMPI;
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        $psn = $this->trimZero($result['psn']);
        return ($psn);
    }

    /**
    * gPAS: gets pseudonym for MPI
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @access  public
    * @return mixed PSN / false if MPI doesn't exist
    */
    public function getPseudonymFor($piMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        $requestArray['value'] = $piMPI;
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            return (false);
        }
        if (isset($result['psn'])) {
            return ($this->trimZero($result['psn']));
        }
        return (false);
    }

    /**
    * gPAS: gets PSN tree
    *
    * @author  Christian Erhardt
    * @param string $psPSN pseudonym
    * @access  public
    * @return mixed array / false if PSN doesn't exist
    */
    public function getPSNNetFor($psPSN) {
        $requestArray = Array();
        $requestArray['valueOrPSN'] = $this->addZero($psPSN);
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            return (false);
        }
        if (isset($result['psnNet'])) {
            return ($result['psnNet']);
        }
        return (false);
    }

    /**
    * gPAS: search for pseudonyms with a given prefix
    *
    * @author  Christian Erhardt
    * @param string $psextPSN extPSN
    * @access  public
    * @return mixed search result / false
    */
    public function getPseudonymForValuePrefix($psextPSN) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        $requestArray['valuePrefix'] = $psextPSN;
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        // only 1 hit: convert array
        $aItems = array();
        if (isset($result['return']['entry']['key'])) {
            $aItems[] = $result['return']['entry'];
        } else {
            $aItems = $result['return']['entry'];
        }
        foreach($aItems as $i => $aItem) {
            $aItems[$i]['value'] =  $this->trimZero($aItems[$i]['value']);
        }
        return ($aItems);
    }

    /**
    * gPAS: gets original value for pseudonym
    *
    * @author  Christian Erhardt
    * @param string $psPSN pseudonym
    * @access  public
    * @return string value (MPI / extPSN)
    */
    public function getValueFor($psPSN) {
        if (strlen($psPSN) == 0) return;
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        $requestArray['psn'] = $this->addZero($psPSN);
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        $mpiID = $result['value'];
        return ($mpiID);
    }
    
    /**
    * gPAS: gets original values for pseudonym list
    *
    * @author  Christian Erhardt
    * @param string $psPSN pseudonym
    * @access  public
    * @return array values (MPI / extPSN)
    */
    public function getValueForList($paPSN) {
        if (count($paPSN) == 0) return;
        $aPSN = array();
        foreach($paPSN as $sPSN) {
            $aPSN[] = $this->addZero($sPSN);
        }
        $requestArray = Array();
        $requestArray['psnList'] = $aPSN;
        $requestArray['domainName'] = $this->gpas_domain;
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }

        // only 1 hit: convert array
        $aItems = array();
        if (isset($result['return']['entry']['key'])) {
            $aItems[] = $result['return']['entry'];
        } else {
            $aItems = $result['return']['entry'];
        }
        foreach($aItems as $i => $aItem) {
            $aItems[$i]['key'] =  $this->trimZero($aItems[$i]['key']);
        }
        return ($aItems);
    }

    /**
    * gPAS: list PSNs for gPAS domain
    *
    * @author  Christian Erhardt
    * @access  public
    * @return array PSNs
    */
    public function listPSNs() {
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        try {
            $result = $this->SoapCall("gpas_domain",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }

        // only 1 hit: convert array
        $aItems = array();
        if (isset($result['return']['psnList']['domainName'])) {
            $aItems[] = $result['return']['psnList'];
        } else {
            $aItems = $result['return']['psnList'];
        }
        foreach($aItems as $i => $aItem) {
            $aItems[$i]['pseudonym'] =  $this->trimZero($aItems[$i]['pseudonym']);
        }
        return ($aItems);

    }

    /**
    * gPAS: inserts MPI => PSN pair
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @param string $psPSN PSN
    * @access  public
    * @return boolean success?
    */
    public function insertValuePseudonymPair($piMPI, $psPSN) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        $requestArray['value'] = $piMPI;
        $requestArray['pseudonym'] = $this->addZero($psPSN);
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return (true);
    }

    /**
    * gPAS: delete value / psn
    *
    * @author  Christian Erhardt
    * @param string $piMPI MPI
    * @access  public
    * @return boolean success?
    */
    public function deleteEntry($piMPI) {
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        $requestArray['value'] = $piMPI;
        try {
            $result = $this->SoapCall("gpas",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return (true);
    }

    /**
    * gPAS: get properties of gPAS domain
    *
    * @author  Christian Erhardt
    * @access  public
    * @return array PSNs
    */
    public function getDomain() {
        $requestArray = Array();
        $requestArray['domainName'] = $this->gpas_domain;
        try {
            $result = $this->SoapCall("gpas_domain",$requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }
        return ($result['domain']);
    }

    /**
    * remove leading zeros of PSN
    *
    * @author  Christian Erhardt
    * @access  public
    * @return string PSN
    */
    public function trimZero($sPSN) {
        $sPSN = ltrim($sPSN,'0');
        return ($sPSN);
    }
    
    /**
    * add leading zeros of PSN
    *
    * @author  Christian Erhardt
    * @access  public
    * @return string PSN
    */
    public function addZero($sPSN) {
        $sPSN = str_pad($sPSN, $_SESSION[$this->session]['domains'][$this->gpas_domain]['config']['psnLength'], '0', STR_PAD_LEFT);
        return ($sPSN);
    }

    /**
    * create dataset in REDCap
    *
    * @author  Christian Erhardt
    * @param string $psPSN Pseudonym
    * @param string $psextPSN external Pseudonym
    * @access  public
    * @return boolean
    */
    public function createREDCap($psPSN, $psextPSN = '') {
        global $Proj, $project_id;
        $sPK = REDCap::getRecordIdField();
        $aData = array();
        $aData[$sPK] = $this->trimZero($psPSN);
        if (REDCap::isLongitudinal()) {
            $form = $Proj->metadata[$sPK]['form_name'];
            foreach($Proj->eventsForms as $iEventID => $aForms) {
                if (in_array($form, $aForms, true)) {
                    $aData['redcap_event_name'] = REDCap::getEventNames(true, true, $iEventID);
                    break;
                }
            }
        }
        // save data
        $result = REDCap::saveData($project_id, 'json', json_encode(array($aData)));
        if (count($result['errors']) > 0) {
            $this->setError("Der REDCap-Datensatz konnte nicht angelegt werden!");
            return (false);
        }
        if (strlen($this->getProjectSetting("extpsn_field")) > 0 && strlen($psextPSN) > 0) {
            $aData = array();
            $aData[$sPK] = $this->trimZero($psPSN);

            if (REDCap::isLongitudinal() && strlen($this->getProjectSetting("extpsn_event")) > 0) {
                $aData['redcap_event_name'] = REDCap::getEventNames(true, true, $this->getProjectSetting("extpsn_event")); 
            }
            $aData[$this->getProjectSetting("extpsn_field")] = $psextPSN;
            // save data
            $result = REDCap::saveData($project_id, 'json', json_encode(array($aData)));
            if (count($result['errors']) > 0) {
                $this->setError("Pseudonym konnte nicht in REDCap-Studie gespeichert werden!");
                return (false);
            }
        }        
        return (true);
    }
}

