<?php
namespace meDIC\PseudoService;

class SAPPatientSearch extends PseudoService {
    
    public function __construct() {
		parent::__construct();

        $this->aDefaultFilter = array('INSTITUTION' => '0001', 'MAXCNT' => $this->maxcnt, 'PATIENTS' => null);
        $this->msg_nohits = 'Keine Treffer in SAP bzw. mehr als '.$this->maxcnt.' Treffer!';
    }

    /**
    * Curl SOAP call of SAP webservice
    *
    * @author  Christian Erhardt
    * @param array $paRequest search parameters
    * @access  public
    * @return array list of patients
    */
    public function SoapCall($paRequest) {
        // insert default filters
        $aRequestTmp = array_merge($paRequest,$this->aDefaultFilter);
        
        $arrayResult = $this->_SoapCall('sap',$aRequestTmp,'BAPI_PATIENT_SEARCH');
        // error handling SAP
        if (isset($arrayResult['WORST_RETURNED_MSGTY']) && $arrayResult['WORST_RETURNED_MSGTY'] == 'E') {        
            throw new \Exception($this->msg_nohits);
        }
        
        // return search result
        if (is_array($arrayResult['PATIENTS']['item'])) { 
            return ($arrayResult['PATIENTS']['item']);
        }
    }
    
    /**
    * Search for patients in SAP
    *
    * @author  Christian Erhardt
    * @param array $paPerson search parameters ISH-ID, last name, birthdate
    * @access  public
    * @return array list of patients
    */
    public function searchPersons_SAP($paPerson) {

        $requestArray = Array();
        if (strlen($paPerson['ish_id']) > 0) {
            $requestArray['FILTER_PATIENTID'] = $paPerson['ish_id'];
        }
        if (strlen($paPerson['lastName']) > 0) {
            $requestArray['FILTER_LAST_NAME_PAT'] = $paPerson['lastName'];
            if (substr($requestArray['FILTER_LAST_NAME_PAT'],-1) != '*') {
                $requestArray['FILTER_LAST_NAME_PAT'] .= '*';
            }
        }
        if (strlen($paPerson['firstName']) > 0) {
            $requestArray['FILTER_FRST_NAME_PAT'] = $paPerson['firstName'];
            if (substr($requestArray['FILTER_FRST_NAME_PAT'],-1) != '*') {
                $requestArray['FILTER_FRST_NAME_PAT'] .= '*';
            }
        }
        if (strlen($paPerson['birthDate']) > 0) {
            $requestArray['FILTER_DOB_FROM'] = \DateTimeRC::format_user_datetime($paPerson['birthDate'], 'D.M.Y_24', 'Y-M-D_24');
            $requestArray['FILTER_DOB_TO'] = \DateTimeRC::format_user_datetime($paPerson['birthDate'], 'D.M.Y_24', 'Y-M-D_24');
        }

        try {
            $mRet = $this->SoapCall($requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }

        // only 1 hit: convert array
        $aItems = array();
        if (isset($mRet['PATIENTID'])) {
            $aItems[] = $mRet;
        } else {
            $aItems = $mRet;
        }

        return ($aItems);
    }

    /**
    * Search for ISH-ID in SAP 
    * generate MPI in E-PIX with data from SAP
    * update E-PIX with data from SAP
    *
    * @author  Christian Erhardt
    * @param integer $ish_id ISH-ID
    * @access  public
    * @return array E-PIX data
    */
    public function requestMPI_SAP($ish_id, $paCustom) {
        // return if ISH-ID is empty
        if (strlen($ish_id) == 0) {
            return (false);
        }

        // class for calling E-PIX
        $oPseudoService = new EPIX_gPAS();

        // search for SAP-ID in E-PIX
        $requestArray = array();
        $requestArray['domainName'] = $oPseudoService->epix_domain;
        $requestArray['identifier']['value'] = $ish_id;
        $requestArray['identifier']['identifierDomain']['name'] = $oPseudoService->epix_id_domain;
        try {
            $result = $oPseudoService->SoapCall("epix",$requestArray,"getPersonByLocalIdentifier");
            $mpiId = $result['return']['mpiId']['value'];
            $bMode = 'update';
            $bUpdate = false; 
            $aEPIXResult = $result['return'];

            // add custom vars
            if (is_array($this->getProjectSetting("cust-vars-list"))) {
                foreach($this->getProjectSetting("cust-vars-list") as $i => $foo) {
                    if (strlen($this->getProjectSetting("custom_field")[$i]) > 0) {
                        $sFieldTmp = 'value'.$this->getProjectSetting("custom_field")[$i];
                        if (!isset($paCustom[$sFieldTmp])) {
                            $paCustom[$sFieldTmp] = $result['return']['referenceIdentity'][$sFieldTmp];
                        } elseif ($paCustom[$sFieldTmp] !== $result['return']['referenceIdentity'][$sFieldTmp]) {
                            $bUpdate = true;
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $bMode = 'insert';
        }

        // search in SAP for ISH-ID
        $requestArray = Array();
        $requestArray['FILTER_PATIENTID'] = $ish_id;
        try {
            $result = $this->SoapCall($requestArray);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return (false);
        }

        // ISH-ID found
        if (is_array($result) && $result['VIP'] != 'X') {
            // E-PIX:
            /*
            "M" -> Männlich
            "F" -> Weiblich
            "X" -> Divers
            "O" -> Sonstiges
            "U" -> Unbekannt
            // SAP
            1 => "Männlich",
            2 => "Weiblich",
            3 => "keine Angabe"
            */

            // mapg gender from SAP to E-PIX
            $gender = 'U'; 
            if ($result['SEX'] === '1') {
    			$gender = 'M';
    		} elseif ($result['SEX'] === '2') {
    			$gender = 'F';
    		}             


            // copy data from SAP to E-PIX
            $requestArray = Array();
            $requestArray['domainName'] = $oPseudoService->epix_domain;
            $requestArray['sourceName'] = $oPseudoService->epix_safe_source;
            $requestArray['identity']['birthDate'] = $result['DOB'];
            $requestArray['identity']['firstName'] = $result['FRST_NAME_PAT'];
            $requestArray['identity']['lastName'] = $result['LAST_NAME_PAT'];
            $requestArray['identity']['gender'] = $gender;
            if ($result['LAST_NAME_PAT'] != $result['BIRTH_NAME']) {
                $requestArray['identity']['mothersMaidenName'] = $result['BIRTH_NAME'];
            } else {
                $requestArray['identity']['mothersMaidenName'] = '';
            }
            $requestArray['identity']['degree'] = $result['TITLE'];
            $requestArray['identity']['contacts']['street'] = $result['STR_NO'];
            $requestArray['identity']['contacts']['zipCode'] = $result['PCD'];
            $requestArray['identity']['contacts']['city'] = $result['CITY'];
            $requestArray['identity']['contacts']['phone'] = $result['PHONENO'];
            $requestArray['identity']['contacts']['country'] = $result['COUNTRY_TEXT'];
            $requestArray['identity']['contacts']['countryCode'] = $result['COUNTRY'];
            
            // add custom vars
            if (is_array($this->getProjectSetting("cust-vars-list"))) {
                foreach($this->getProjectSetting("cust-vars-list") as $i => $foo) {
                    if (strlen($this->getProjectSetting("custom_field")[$i]) > 0) {
                        $sFieldTmp = 'value'.$this->getProjectSetting("custom_field")[$i];
                        if (isset($paCustom[$sFieldTmp])) {
                            $requestArray['identity'][$sFieldTmp] = $paCustom[$sFieldTmp];
                        }
                    }
                }
            }

            // request MPI from E-PIX with data from SAP
            if ($bMode == 'insert') {
                try {
                    $result = $oPseudoService->SoapCall("epix",$requestArray,"requestMPI");
                } catch (\Exception $e) {
                    $this->error = $e->getMessage();
                    return (false);
                }
    
                // add ISH-ID to MPI entry in E-PIX
                $mpiId = $result['return']['person']['mpiId']['value'];
                $requestArray = array();
                $requestArray['domainName'] = $oPseudoService->epix_domain;
                $requestArray['mpiId'] = $mpiId;
                $requestArray['localIds']['value'] = $ish_id;
                $requestArray['localIds']['identifierDomain']['name'] = $oPseudoService->epix_id_domain;
                try {
                    $oPseudoService->SoapCall("epix",$requestArray, "addLocalIdentifierToMPI");
                } catch (\Exception $e) {
                    $this->error = $e->getMessage();
                    return (false);
                }
            }

            // update E-PIX data with data from SAP
            if ($bMode == 'update') {

                // has anything changed?
                $requestArray2 = $requestArray;
                if (is_array($requestArray2['identity']['gender'])) $requestArray2['identity']['gender'] = '';
                if (is_array($aEPIXResult['referenceIdentity']['gender'])) $aEPIXResult['referenceIdentity']['gender'] = '';
                if ($aEPIXResult['referenceIdentity']['gender'] !== $requestArray2['identity']['gender']) {
                    $bUpdate = true;
                }
                if (is_array($requestArray2['identity']['degree'])) $requestArray2['identity']['degree'] = '';
                if (is_array($aEPIXResult['referenceIdentity']['degree'])) $aEPIXResult['referenceIdentity']['degree'] = '';
                if ($aEPIXResult['referenceIdentity']['degree'] !== $requestArray2['identity']['degree']) {
                    $bUpdate = true;
                }
                if (is_array($requestArray2['identity']['firstName'])) $requestArray2['identity']['firstName'] = '';
                if (is_array($aEPIXResult['referenceIdentity']['firstName'])) $aEPIXResult['referenceIdentity']['firstName'] = '';
                if ($aEPIXResult['referenceIdentity']['firstName'] !== $requestArray2['identity']['firstName']) {
                    $bUpdate = true;
                }
                if (is_array($requestArray2['identity']['lastName'])) $requestArray2['identity']['lastName'] = '';
                if (is_array($aEPIXResult['referenceIdentity']['lastName'])) $aEPIXResult['referenceIdentity']['lastName'] = '';
                if ($aEPIXResult['referenceIdentity']['lastName'] !== $requestArray2['identity']['lastName']) {
                    $bUpdate = true;
                }
                
                if (is_array($requestArray2['identity']['mothersMaidenName'])) $requestArray2['identity']['mothersMaidenName'] = '';
                if (is_array($aEPIXResult['referenceIdentity']['mothersMaidenName'])) $aEPIXResult['referenceIdentity']['mothersMaidenName'] = '';
                if ($aEPIXResult['referenceIdentity']['mothersMaidenName'] !== $requestArray2['identity']['mothersMaidenName']) {
                    $bUpdate = true;
                }
                if (is_array($requestArray2['identity']['birthDate'])) $requestArray2['identity']['birthDate'] = '';
                if (is_array($aEPIXResult['referenceIdentity']['birthDate'])) $aEPIXResult['referenceIdentity']['birthDate'] = '';
                $aTmp = explode("T",$aEPIXResult['referenceIdentity']['birthDate']);
                if ($aTmp[0] !== $requestArray2['identity']['birthDate']) {
                    $bUpdate = true;
                }

                // only 1 contact
                if (isset($aEPIXResult['referenceIdentity']['contacts']['city'])) {
                    $aContact = $aEPIXResult['referenceIdentity']['contacts'];
                } else {
                    // more contacts: get newest contact
                    $aContactsTmp = array();
                    foreach($aEPIXResult['referenceIdentity']['contacts'] as $aTmp) {
                        $aContactsTmp[$aTmp['contactCreated']] = $aTmp;
                    }
                    krsort($aContactsTmp);
                    $aContact = current($aContactsTmp);
                }
                
                foreach($requestArray2['identity']['contacts'] as $key => $val) {
                    if (is_array($val)) $val = '';
                    if (is_array($aContact[$key])) $aContact[$key] = '';
                    if ($val !== $aContact[$key]) {
                        $bUpdate = true;
                    }
                }
                if ($bUpdate == true) {
                    $requestArray['mpiId'] = $mpiId;
                    try {
                        $result = $oPseudoService->SoapCall("epix",$requestArray,"updatePerson");
                    } catch (\Exception $e) {
                        $this->error = $e->getMessage();
                        return (false);
                    }
                } else {
                    try {
                        $result = $oPseudoService->getPersonByMPI($mpiId);
                    } catch (\Exception $e) {
                        $this->error = $e->getMessage();
                        return (false);
                    }
                }
            }

            // return data from E-PIX
            return ($result);
        } // ISH-ID found
        return (false);
    }

}

