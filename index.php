<?php 
namespace meDIC\PseudoService;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use \REDCap as REDCap;
use \Logging as Logging;
use \RCView as RCView;

if ($module->getProjectSetting("validate_pat_id") === true) {
    include_once('CheckDigit.php');
}

$sExit = '';
// exit if gPAS domain is not configured or no auth type is defined
if (strlen($module->getProjectSetting("gpas_domain")) == 0 || strlen($module->getSystemSetting("auth_type1")) == 0) {
    $sExit = 'please configure the module first!';
}

// exit if access forms are missing
$aForms = REDCap::getInstrumentNames(); 
if (!isset($aForms['tc_access']) || !isset($aForms['tc_impexp'])) {
    $sExit = 'please create access forms "tc_access" and "tc_impexp"!';
}
if (!PseudoService::isAllowed('search')) {
    $sExit = 'access not allowed!';
}
if (strlen($sExit) > 0) {
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
    echo '<h3 style="color:#800000;">'.$sExit.'</h3>';
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';    
    exit();
}

// ================================================================================================
// initialization
// ================================================================================================

// E-PIX/gPAS class
$oPseudoService = new EPIX_gPAS();
// login
$oPseudoService->login();

if ($oPseudoService->use_sap === true) {
    // SAP class
    $oSAPPatientSearch = new SAPPatientSearch();
}

// mode: search / create / export / import / delete: POST overwrites GET
$sMode = $_GET['mode'];
if (isset($_POST['mode'])) {
    $sMode = $_POST['mode'];
}
if (strlen($sMode) == 0) {
    $sMode = 'search';
}

// encrypted ISH-ID: POST overwrites GET
$iISH_ID_ENC = $_GET['ish_id_enc'];
if (isset($_POST['ish_id_enc'])) {
    $iISH_ID_ENC = $_POST['ish_id_enc'];
}

// encrypted MPI: POST overwrites GET
$iMPI_ID_ENC = $_GET['mpiid_enc'];
if (isset($_POST['mpiid_enc'])) {
    $iMPI_ID_ENC = $_POST['mpiid_enc'];
}

// encryption key in session
if (!isset($_SESSION[$oPseudoService->session]['enckey'])) {
    $_SESSION[$oPseudoService->session]['enckey'] = md5($_SESSION['username'].microtime());
}

// Pseudonym from GPAS
$sPSN = '';

// ================================================================================================
// process form data
// ================================================================================================
if (count($_POST) > 0 && isset($_POST['submit'])) {

  // ================================================================================================
  // search mode
  // ================================================================================================
  if ($sMode == 'search' && PseudoService::isAllowed('search')) {
        $i = 0;
        $aEpixResult = array();
        $bPatientSearch = true;
        
        // patient search only when E-PIX is enabled
        if ($oPseudoService->use_epix !== true) {
            $bPatientSearch = false;
        }

        // external pseudonym
        if ($module->getProjectSetting("extpsn") === true) {
            if (strlen($_POST['extID']) > 0 ) {
                $bPatientSearch = false;
                $aResult = $oPseudoService->getPseudonymForValuePrefix($module->getProjectSetting("extpsn_prefix").$_POST['extID']);
                Logging::logEvent('', $module->getModuleName(), "OTHER", '', print_r($_POST,true), "extID search");
                
                foreach($aResult as $key => $aPat) {
                    $aEpixResult[$i]['extid'] = substr($aPat['key'], strlen($module->getProjectSetting("extpsn_prefix")));
                    $aEpixResult[$i]['psn'] = $aPat['value'];
                    
                    $i++;
                }
            }
        }

        if ($bPatientSearch) {

            //Logging::logEvent('', $module->getModuleName(), "OTHER", '', '', "search");
    
            // SAP search only if user has create access
            if (PseudoService::isAllowed('create') && $oPseudoService->use_sap === true) {

                $aResult = $oSAPPatientSearch->searchPersons_SAP($_POST);

                if (is_array($aResult)) {
        
                    foreach($aResult as $key => $aPat) {
                        // don't show patients with VIP mark
                        if ($aPat['VIP'] == 'X') continue;
                        
                        $ish_id_tmp = ltrim($aPat['PATIENTID'],'0');
                        $aEpixResult[$ish_id_tmp]['ish_id'] = ltrim($aPat['PATIENTID'],'0');
                        $aEpixResult[$ish_id_tmp]['name'] = $aPat['LAST_NAME_PAT'];
                        $aEpixResult[$ish_id_tmp]['vorname'] = $aPat['FRST_NAME_PAT'];
                        $aEpixResult[$ish_id_tmp]['gebdat'] = $aPat['DOB'];
                    }
                }
            }

            // E-PIX search
            $aResult = array();
            if (strlen($_POST['birthDate']) > 0 || strlen($_POST['lastName']) > 0 || strlen($_POST['firstName']) > 0) {
                $aResult = $oPseudoService->searchPersonsByPDQ($_POST);
            } elseif (strlen($_POST['ish_id']) > 0) {
                $aResult = $oPseudoService->getActivePersonByLocalIdentifier($_POST['ish_id'],false); 
            }

            if (is_array($aResult)) {            

                foreach($aResult as $key => $aPat) {
                    // get reference identity
                    $aRefIdentity = $aPat['referenceIdentity'];
        
                    // get MPI
                    $mpiId = $aPat['mpiId']['value'];
        
                    // get SAP-ID (ID_DOMAIN)
                    $aISH_IDs = array();
                    if ($oPseudoService->use_sap === true) {
                        if (isset($aRefIdentity['identifiers'])) {
                            // only 1 hit: convert array
                            $aIdentifier = array();
                            if (isset($aRefIdentity['identifiers']['identifierDomain'])) {
                                $aIdentifier[] = $aRefIdentity['identifiers'];
                            } else {
                                $aIdentifier = $aRefIdentity['identifiers'];
                            }
                            foreach($aIdentifier as $aId) {
                                if ($aId['identifierDomain']['name'] == $oPseudoService->epix_id_domain) {
                                    $aISH_IDs[] = $aId['value'];
                                }
                            }
                        }
                    }            
        
                    // get all PSNs for given MPI 
                    $bOtherProjects = false;
                    $bPSNInProject = false;
                    $aPSNNet = $oPseudoService->getPSNNetFor($mpiId);
                    if (is_array($aPSNNet['nodes'])) {
                        foreach($aPSNNet['nodes'] as $aNode) {
                            if (!isset($aNode['level']) || !isset($aNode['domainName'])) continue;
                            if ($aNode['level'] == '0' && $aNode['domainName'] != $module->getProjectSetting("gpas_domain")) {
                                $bOtherProjects = true;
                            }
                            if ($aNode['level'] == '0' && $aNode['domainName'] == $module->getProjectSetting("gpas_domain")) {
                                $bPSNInProject = true;
                            }
                        }
                    }

                    // skip probands with no psn in project
                    if (!$bPSNInProject) continue;
    
                    // also in SAP results: add MPI to SAP search results and skip
                    if (count($aISH_IDs) > 0 && isset($aEpixResult[$aISH_IDs[0]])) {
                        $aEpixResult[$aISH_IDs[0]]['mpiid'] = $mpiId;
                        
                        // edit custom vars of persons with ISH-ID
                        if (strlen($module->getProjectSetting("custom_field")[0]) > 0) {
                            $aEpixResult[$aISH_IDs[0]]['edit'] = true;
                        } 
                        continue;
                    } 

                    $sKey = 'idx'.$i;
                    $aTmp = explode("T",$aRefIdentity['birthDate']);
                    $aEpixResult[$sKey]['mpiid'] = $mpiId;
                    if (count($aISH_IDs) > 0) {
                        $aEpixResult[$sKey]['ish_id_epix'] = $aISH_IDs[0];
                    }
                    $aEpixResult[$sKey]['name'] = $aRefIdentity['lastName'];
                    $aEpixResult[$sKey]['vorname'] = $aRefIdentity['firstName'];
                    $aEpixResult[$sKey]['gebdat'] = $aTmp[0];
                    if (!$bOtherProjects && count($aISH_IDs) == 0) {
                        $aEpixResult[$sKey]['edit'] = true;
                    }
    
                    $i++;
                } // end foreach $aItems

            } else {
                // suppress errors in search
                $oPseudoService->setError('');    
            }
        } // $bPatientSearch && PseudoService::isAllowed('search')

        // SAP and external: resort mixed results by name
        if ($bPatientSearch && count($aEpixResult) > 0) {
            $aNameSort = array_column($aEpixResult, 'name');
            $aVornameSort = array_column($aEpixResult, 'vorname');
            array_multisort($aNameSort, SORT_ASC, SORT_STRING, $aVornameSort, SORT_ASC, SORT_STRING, $aEpixResult);
            unset($aNameSort);
            unset($aVornameSort);
        }
  } // search

  // ================================================================================================
  // create mode
  // ================================================================================================
  if ($sMode == 'create' && PseudoService::isAllowed('create')) {
      // external pseudonyms: create PSN
      if ($module->getProjectSetting("extpsn") === true && strlen($_POST['extID']) > 0) {

            $sExtPS = $_POST['extID'];
            if ($module->getProjectSetting("validate_pat_id") === true) {
                $validatedID = CheckDigit::validateID($sExtPS);

                if (strlen($validatedID) === 10) {
                    $sExtPS = substr($validatedID, 0, 9);
                } elseif ($validatedID == -1) {
                    // incorrect check digit for given pat id: user feedback and return to index page
                    $oPseudoService->setError("Die Prüfziffer (d.h. die 10. Stelle) ist nicht korrekt! Erneute Eingabe nötig.");
                }
            }
            
          $mEpixResult = $oPseudoService->getPseudonymFor($module->getProjectSetting("extpsn_prefix").$sExtPS);
          if (!$mEpixResult) {
              $sPSN = $oPseudoService->getOrCreatePseudonymFor($module->getProjectSetting("extpsn_prefix").$sExtPS);

              // redcap log
              Logging::logEvent('', $module->getModuleName(), "OTHER", '', $sExtPS.": ".$sPSN, "extID: psn created");
              // save pseudonym in REDCap study
              $oPseudoService->createREDCap($sPSN, $sExtPS);
          } else {
              $oPseudoService->setError("Dieses Pseudonym existiert bereits!");
          }
      }

      // external probands: create PSN
      if ($oPseudoService->manual_edit === true) {
          if (strlen($_POST['firstName']) > 0 && 
            strlen($_POST['lastName']) > 0 && 
            strlen($_POST['gender']) > 0 &&
            strlen($_POST['birthDate']) > 0 &&
            strlen($_POST['extID']) == 0 && 
            strlen($iISH_ID_ENC) == 0) {

            // DAGs: load all DAG assignments into session
            if ($oPseudoService->getProjectSetting("use_dags") === true && !isset($_SESSION[$oPseudoService->session]['epix'][$oPseudoService->epix_domain])) {
                // get all psns for domain
                $aResult = $oPseudoService->listPSNs();

                $aEPIXFilter = array();
                foreach($aResult as $agPAS) {
                  $aEPIXFilter[] = $agPAS['originalValue'];
                }
                $aAllPersons = $oPseudoService->getActivePersonsByMPIBatch($aEPIXFilter);
                foreach($aAllPersons as $aPerson) {
                  $_SESSION[$oPseudoService->session]['epix'][$oPseudoService->epix_domain][$aPerson['mpiId']['value']] = $aPerson['referenceIdentity']['value10'];
                }
            }

            $aResult = $oPseudoService->requestMPI($_POST);
            
            // update person
            if (strlen($iMPI_ID_ENC) > 0) {
                if (!$aResult) {
                    $_SESSION[$oPseudoService->session]['msg'] = $oPseudoService->getError();
                } else {
                    $_SESSION[$oPseudoService->session]['msg'] = 'Die Personendaten wurden aktualisiert!';
                }
                redirect($module->moduleIndex);
            }
            
            if (is_array($aResult)) {
                // PERFECT_MATCH = Vorhanden, NO_MATCH = nicht vorhanden, POSSIBLE_MATCH = evtl. doppelt
                $matchStatus = $aResult['return']['matchStatus'];
                // TODO: PERECT_MATCH, aber neue Identität angelegt: Referenzidentität auswählen?
                // TODO: POSSIBLE MATCH: Dubletten abfragen und auflösen
                $mpiId_create = $aResult['return']['person']['mpiId']['value'];
                /*
                if ($matchStatus == 'NO_MATCH' || $matchStatus == 'POSSIBLE_MATCH') {
                    Logging::logEvent('', $module->getModuleName(), "OTHER", '', $mpiId_create, "MPI created");
                }  
                */
                // PSN erzeugen
                $sPSN = $oPseudoService->getOrCreatePseudonymFor($mpiId_create);
                if ($sPSN) {
                    // create REDCap dataset for PSN
                    $oPseudoService->createREDCap($sPSN);
                    // redcap log
                    Logging::logEvent('', $module->getModuleName(), "OTHER", '', $sPSN, "PSN retrieved");
                }
            }
          } 
      }  
  
  } // create

  // ================================================================================================
  // export mode
  // ================================================================================================
  if ($sMode == 'export' && PseudoService::isAllowed('export')) {
        $agPASMap = array();
        $aItems = array();

        // psn filter
        $aPSNs = array();
        $sPSNs = str_replace(",","\n",$_POST['psn_filter']);
        $aPSNsTmp = preg_split('/\r\n|[\r\n]/', $sPSNs);
        foreach($aPSNsTmp as $sTmp) {
            if (strlen(trim($sTmp)) > 0) {
                $aPSNs[] = $sTmp;
            }
        }

        if (count($aPSNs) > 0) {
            // get psn list
            $aResult = $oPseudoService->getValueForList($aPSNs);

            foreach($aResult as $i => $agPAS) {
                $agPASMap[$agPAS['value']] = $agPAS['key'];
            }
        } else {
            // get all psns for domain
            $aResult = $oPseudoService->listPSNs();

            foreach($aResult as $agPAS) {
                $agPASMap[$agPAS['originalValue']] = $agPAS['pseudonym'];
            }
        }
        unset($aResult);
        
        // csv array
        $aCSV = array();
        // filter for E-PIX
        $aEPIXFilter = array();
        // header variables
        $aHeader = array();
        $aHeader['psn'] = true;
        
        // build filter for E-PIX and get extIDs for csv array
        $i = 0;
        foreach($agPASMap as $original => $psn) {
            $aCSV[$i]['psn'] = $psn;

            if ($module->getProjectSetting("extpsn") === true) {
                if (str_starts_with($original,$module->getProjectSetting("extpsn_prefix"))) {
                    $original = substr($original, strlen($module->getProjectSetting("extpsn_prefix")));
                    $aCSV[$i]['extID'] = $original;
                    $aHeader['extID'] = true;
                    $i++;
                    continue;
                }
            }
            $aEPIXFilter[] = $original;
            $i++;
        }
        
        if (count($aEPIXFilter) > 0 && $oPseudoService->use_epix === true) {
            // get personal data from E-PIX
            $aItems = $oPseudoService->getActivePersonsByMPIBatch($aEPIXFilter);

            // copy data to new array with psn as key 
            $aRefIdentity = array();
            foreach($aItems as $key => $aResult) {
                $mpiId = $aResult['mpiId']['value'];
                $sPSNTmp = $agPASMap[$mpiId];
                $aRefIdentity[$sPSNTmp] = $aResult['referenceIdentity'];
            }
            unset($aItems);

            // header vars for personal data
            if ($oPseudoService->use_sap === true) {
                $aHeader['SAP-ID'] = true;
            }
            $aHeader['gender'] = true;
            $aHeader['degree'] = true;
            $aHeader['firstName'] = true;
            $aHeader['lastName'] = true;
            $aHeader['mothersMaidenName'] = true;
            $aHeader['birthDate'] = true;
            $aHeader['street'] = true;
            $aHeader['zipCode'] = true;
            $aHeader['city'] = true;
            $aHeader['phone'] = true;
            $aHeader['country'] = true;
            $aHeader['countryCode'] = true;
            
            // extract personal data for csv array
            foreach($aCSV as $i => $aRow) {
                if (isset($aRefIdentity[$aRow['psn']])) {

                    // Filter DAGs
                    if ($oPseudoService->getProjectSetting("use_dags") === true && strlen($oPseudoService->group_id) > 0) {
                        if (strpos($aRefIdentity[$aRow['psn']]['value10'],'|'.$oPseudoService->getProjectId().':'.$oPseudoService->group_id.'|') === false) {
                            unset($aCSV[$i]);
                            continue;
                        }
                    }
                    $aISH_IDs = array();
                    if ($oPseudoService->use_sap === true) {
                        if (isset($aRefIdentity[$aRow['psn']]['identifiers'])) {
                            // only 1 hit: convert array
                            $aIdentifier = array();
                            if (isset($aRefIdentity[$aRow['psn']]['identifiers']['identifierDomain'])) {
                                $aIdentifier[] = $aRefIdentity[$aRow['psn']]['identifiers'];
                            } else {
                                $aIdentifier = $aRefIdentity[$aRow['psn']]['identifiers'];
                            }
                            foreach($aIdentifier as $aId) {
                                if ($aId['identifierDomain']['name'] == $oPseudoService->epix_id_domain) {
                                    $aISH_IDs[] = $aId['value'];
                                }
                            }
                        }
                    }
                    
                    if (count($aISH_IDs) > 0) {
                        $aCSV[$i]['SAP-ID'] = implode(", ",$aISH_IDs);
                    } 
                    if (!is_array($aRefIdentity[$aRow['psn']]['gender'])) {
                        $aCSV[$i]['gender'] = $oPseudoService->aGender[$aRefIdentity[$aRow['psn']]['gender']];
                    }
                    if (!is_array($aRefIdentity[$aRow['psn']]['degree'])) {
                        $aCSV[$i]['degree'] = $aRefIdentity[$aRow['psn']]['degree'];
                    }
                    if (!is_array($aRefIdentity[$aRow['psn']]['firstName'])) {
                        $aCSV[$i]['firstName'] = $aRefIdentity[$aRow['psn']]['firstName'];
                    }
                    if (!is_array($aRefIdentity[$aRow['psn']]['lastName'])) {
                        $aCSV[$i]['lastName'] = $aRefIdentity[$aRow['psn']]['lastName'];
                    }
                    if (!is_array($aRefIdentity[$aRow['psn']]['mothersMaidenName'])) {
                        if (strlen($aRefIdentity[$aRow['psn']]['mothersMaidenName']) > 0 && $aRefIdentity[$aRow['psn']]['mothersMaidenName'] != $aRefIdentity[$aRow['psn']]['lastName']) {                    
                            $aCSV[$i]['mothersMaidenName'] = $aRefIdentity[$aRow['psn']]['mothersMaidenName'];
                        }
                    }
                    if (!is_array($aRefIdentity[$aRow['psn']]['birthDate'])) {
                        $aTmp = explode("T",$aRefIdentity[$aRow['psn']]['birthDate']);
                        $aCSV[$i]['birthDate'] = \DateTimeRC::format_user_datetime($aTmp[0], 'Y-M-D_24', 'D.M.Y_24');
                    }

                    // only 1 contact
                    if (isset($aRefIdentity[$aRow['psn']]['contacts']['city'])) {
                        $aContact = $aRefIdentity[$aRow['psn']]['contacts'];
                    } else {
                        // more contacts: get newest contact
                        $aContactsTmp = array();
                        foreach($aRefIdentity[$aRow['psn']]['contacts'] as $aTmp) {
                            $aContactsTmp[$aTmp['contactCreated']] = $aTmp;
                        }
                        krsort($aContactsTmp);
                        $aContact = current($aContactsTmp);
                    }
                    if (!is_array($aContact['street'])) {
                        $aCSV[$i]['street'] = $aContact['street'];               
                    }
                    if (!is_array($aContact['zipCode'])) {
                        $aCSV[$i]['zipCode'] = $aContact['zipCode'];               
                    }
                    if (!is_array($aContact['city'])) {
                        $aCSV[$i]['city'] = $aContact['city'];               
                    }
                    if (!is_array($aContact['phone'])) {
                        $aCSV[$i]['phone'] = $aContact['phone'];               
                    }
                    if (!is_array($aContact['country'])) {
                        $aCSV[$i]['country'] = $aContact['country'];     
                    }
                    if (!is_array($aContact['countryCode'])) {
                        $aCSV[$i]['countryCode'] = $aContact['countryCode'];     
                    }
                
                    // add custom vars
                    if (is_array($module->getProjectSetting("cust-vars-list"))) {
                        foreach($module->getProjectSetting("cust-vars-list") as $iCust => $foo) {
                            if (strlen($module->getProjectSetting("custom_field")[$iCust]) > 0) {
                                $sFieldTmp = 'value'.$module->getProjectSetting("custom_field")[$iCust];
                                $sLabelTmp = $module->getProjectSetting("custom_label")[$iCust];
                                $aHeader[$sLabelTmp] = true;
                                if (!is_array($aRefIdentity[$aRow['psn']][$sFieldTmp])) {
                                    $aCSV[$i][$sLabelTmp] = $aRefIdentity[$aRow['psn']][$sFieldTmp];
                                }
                            }
                        }
                    }
                }
            }
            
        }

        // output csv file
        $output = fopen("php://output",'w') or die("Can't open php://output");
        fwrite($output, "\xEF\xBB\xBF");
      	header("Content-Type:application/csv"); 
      	header("Content-Disposition:attachment;filename=psn_export_".$module->getProjectSetting("gpas_domain")."_".date("Ymd_His").".csv"); 
      	fputcsv($output, array_keys($aHeader), ";");
      	foreach($aCSV as $row) {
          $Vals = array();
          foreach($aHeader as $field => $foo) {
              if (!isset($row[$field])) {
                  $Vals[$field] = '';    
              } else {
                  $Vals[$field] = $row[$field]."\t";
              }
          } 
          fputcsv($output,$Vals, ";");
      	}
      	fclose($output) or die("Can't close php://output");
        // redcap log
        Logging::logEvent('', $module->getModuleName(), "OTHER", '', '', "export");
        exit;
  }
  
  // ================================================================================================
  // import mode
  // ================================================================================================
  if ($sMode == 'import' && PseudoService::isAllowed('import')) {
      
      // check: is file uploaded?
      if (is_array($_FILES['file_upload']) && $_FILES['file_upload']['error'] == UPLOAD_ERR_OK) {
          $aUploadedFile = $_FILES['file_upload'];
        
          if (is_uploaded_file($aUploadedFile['tmp_name'])) {
              $aImport = PseudoService::csv_to_array($aUploadedFile['tmp_name'], ';');    

              $aCSV = array();
              foreach($aImport as $i => $aRow) {
  
                  // create or import psn?
                  $sPSN_mode = 'import';
                  if (strlen($aRow['psn']) == 0) {
                      $sPSN_mode = 'create';
                  }
                  $ishId = '';
                  
                  $aCSV[$i] = $aRow;
                  $aCSV[$i]['imported'] = '';
                  $aCSV[$i]['error'] = '';
                  
                  // import PSNs: validate PSNs
                  if (!$oPseudoService->validatePSN($aRow['psn']) && $sPSN_mode == 'import') {
                      $aCSV[$i]['error'] = $oPseudoService->getError();
                      continue;
                  }
                  
                  // extID
                  if ($module->getProjectSetting("extpsn") === true && strlen($aRow['extID']) > 0) {
                      // does psn already exist?
                      $mEpixResult = $oPseudoService->getPseudonymFor($module->getProjectSetting("extpsn_prefix").$aRow['extID']);
                      if (!$mEpixResult) {
                          // create new value -> psn pair
                          if ($sPSN_mode == 'import') {
                              $sPSN = $aRow['psn'];
                              if (!$oPseudoService->insertValuePseudonymPair($module->getProjectSetting("extpsn_prefix").$aRow['extID'], $sPSN)) {
                                  $aCSV[$i]['error'] = $oPseudoService->getError();
                                  continue;
                              }
                          }
                          // create new psn
                          if ($sPSN_mode == 'create') {
                              $sPSN = $oPseudoService->getOrCreatePseudonymFor($module->getProjectSetting("extpsn_prefix").$_POST['extID']);
                              if (!$sPSN) {
                                  $aCSV[$i]['error'] = $oPseudoService->getError();
                                  continue;
                              }
                              $aCSV[$i]['psn'] = $sPSN;
                          }

                          $aCSV[$i]['imported'] = $sPSN_mode;
                          Logging::logEvent('', $module->getModuleName(), "OTHER", '', $_POST['extID'].": ".$oPseudoService->trimZero($sPSN), "extID: psn created");

                          // create REDCap entry
                          if (!$oPseudoService->createREDCap($sPSN, $aRow['extID'])) {
                              $aCSV[$i]['error'] = $oPseudoService->getError();
                          }
                      } else {
                          $aCSV[$i]['error'] = "Dieses Pseudonym existiert bereits!";
                      }
                      continue;
                  }

                  // convert custom variable labels to keys
                  if (is_array($module->getProjectSetting("cust-vars-list"))) {
                      foreach($module->getProjectSetting("cust-vars-list") as $iCust => $foo) {
                          if (strlen($module->getProjectSetting("custom_field")[$iCust]) > 0) {
                              $sFieldTmp = 'value'.$module->getProjectSetting("custom_field")[$iCust];
                              $sLabelTmp = $module->getProjectSetting("custom_label")[$iCust];
                              if (!isset($aRow[$sFieldTmp])) {
                                  $aRow[$sFieldTmp] = $aRow[$sLabelTmp];
                                  unset($aRow[$sLabelTmp]);
                              }
                          }
                      }
                  }

                  // convert gender labels to keys
                  if (strlen($aRow['gender']) > 0 && !isset($oPseudoService->aGender[$aRow['gender']])) {
                      $sGender = array_search($aRow['gender'], $oPseudoService->aGender, true);
                      if ($sGender !== false) {
                          $aRow['gender'] = $sGender;
                      } else {
                          $aCSV[$i]['error'] = "Bitte überprüfen Sie das Geschlecht! (M,F,X,O,U)";
                          continue;
                      }
                  }

                  // external probands: create PSN
                  if ($oPseudoService->manual_edit === true && 
                        strlen($aRow['firstName']) > 0 && 
                        strlen($aRow['lastName']) > 0 && 
                        strlen($aRow['gender']) > 0 &&
                        strlen($aRow['birthDate']) > 0 &&
                        strlen($aRow['extID']) == 0 &&
                        strlen($aRow['SAP-ID']) == 0) {
                      
                        $aResult = $oPseudoService->requestMPI($aRow);
                        if (!is_array($aResult)) {
                            $aCSV[$i]['error'] = $oPseudoService->getError();
                            continue;
                        }
                      
                  }  elseif ($oPseudoService->use_sap === true && strlen($aRow['SAP-ID']) > 0) {
                      // probands from SAP
                      $ishId = ltrim($aRow['SAP-ID'],'0');

                      // create / get MPI with SAP-ID
                      $aResult = $oSAPPatientSearch->requestMPI_SAP($ishId, $aRow);
                      if (!is_array($aResult)) {
                          $aCSV[$i]['error'] = $oSAPPatientSearch->getError();
                          continue;
                      }
                  } else {
                      // nothing to do
                      continue;
                  }          

                  // align arrays
                  if (isset($aResult['return']['person'])) {
                      $mpiId_create = $aResult['return']['person']['mpiId']['value'];
                  } else {
                      $mpiId_create = $aResult['mpiId']['value'];
                  }

                  /*
                  if ($matchStatus == 'NO_MATCH' || $matchStatus == 'POSSIBLE_MATCH') {
                      Logging::logEvent('', $module->getModuleName(), "OTHER", '', $mpiId_create, "MPI created");
                  } 
                  */ 
                  // does psn already exist?
                  $sPSNTmp = $oPseudoService->getPseudonymFor($mpiId_create);
                  if (!$sPSNTmp) {
                      // create new value -> psn pair
                      if ($sPSN_mode == 'import') {
                          $sPSN = $aRow['psn'];
                          if (!$oPseudoService->insertValuePseudonymPair($mpiId_create, $sPSN)) {
                              $aCSV[$i]['error'] = $oPseudoService->getError();
                              continue;
                          } 
                      }
                      // create new psn
                      if ($sPSN_mode == 'create') {
                          $sPSN = $oPseudoService->getOrCreatePseudonymFor($mpiId_create);
                          if (!$sPSN) {
                              $aCSV[$i]['error'] = $oPseudoService->getError();
                              continue;
                          }
                          $aCSV[$i]['psn'] = $sPSN;
                      }
                      
                      $aCSV[$i]['imported'] = $sPSN_mode;
                      Logging::logEvent('', $module->getModuleName(), "OTHER", '', $oPseudoService->trimZero($sPSN), "PSN created");

                      // create REDCap entry
                      if (!$oPseudoService->createREDCap($sPSN, '', $ishId)) {
                          $aCSV[$i]['error'] = $oPseudoService->getError();
                      }
                  } else {
                    $aCSV[$i]['error'] = "Diese Person existiert bereits!";
                  }
              
              } // end foreach import row

              if (count($aCSV) > 0) {
                  // output csv file
                  $aHeader = array_keys($aCSV[0]);
                  $output = fopen("php://output",'w') or die("Can't open php://output");
                  fwrite($output, "\xEF\xBB\xBF");
                  header("Content-Type:application/csv"); 
                  header("Content-Disposition:attachment;filename=psn_import_".$module->getProjectSetting("gpas_domain")."_".date("Ymd_His").".csv"); 
                  fputcsv($output, $aHeader, ";");
                  foreach($aCSV as $row) {
                    fputcsv($output,$row, ";");
                  }
                  fclose($output) or die("Can't close php://output");
                  // redcap log
                  Logging::logEvent('', $module->getModuleName(), "OTHER", '', '', "import");
                  exit;
              } else {
                  $oPseudoService->setError('Bitte überprüfen Sie die Importdatei!');
              }
          
          } //is_uploaded_file
      }  
  }  // import

} // $_POST

// ================================================================================================
// dubletten
// ================================================================================================
if ($sMode == 'dubletten' && PseudoService::isAllowed('edit') && $oPseudoService->use_epix === true) {
    // keep person 1 or 2
    if (isset($_POST['assignIdentity'])) {
      $aTmp = explode(":",$_POST['assignIdentity']);
      //assignIdentity(PossibleMatchDTO.linkId, PossibleMatchDTO.matchingMPIIdentities[0].identity.identityId, comment)
      $oPseudoService->assignIdentity($aTmp[0],$aTmp[1]);
      Logging::logEvent('', $module->getModuleName(), "OTHER", '', '', "doublet resolution: ".$aTmp[0].", keep person ".$aTmp[1]);
    }
     
    // keep both persons
    if (isset($_POST['removePossibleMatch'])) {
      // removePossibleMatch(PossibleMatchDTO.linkId, comment)
      $oPseudoService->removePossibleMatch($_POST['removePossibleMatch']);
      Logging::logEvent('', $module->getModuleName(), "OTHER", '', '', "doublet resolution (keep both persons): ".$_POST['removePossibleMatch']);
    } 
}

// ================================================================================================
// get PSN from MPI or SAP-ID (GET parameter from search results)
// ================================================================================================
if (strlen($sPSN) == 0 && PseudoService::isAllowed('search') && $_GET['edit'] != '1') {

    // SAP-ID from search result
    if (strlen($iISH_ID_ENC) > 0 && $oPseudoService->use_sap === true) {
        $ishId_dc = decrypt($iISH_ID_ENC,$_SESSION[$oPseudoService->session]['enckey']);
        if ($ishId_dc) {
            $ishId = ltrim($ishId_dc,'0');
            // create access?
            if (PseudoService::isAllowed('create')) {
                // create / get MPI with SAP-ID
                $aResult = $oSAPPatientSearch->requestMPI_SAP($ishId, $_POST);

                if (strlen($iMPI_ID_ENC) > 0) {
                    if (!$aResult) {
                        $_SESSION[$oPseudoService->session]['msg'] = $oSAPPatientSearch->getError();
                    } else {
                        $_SESSION[$oPseudoService->session]['msg'] = 'Die Personendaten wurden aktualisiert!';
                    }
                    redirect($module->moduleIndex);
                }

                if (is_array($aResult)) {
                    if (isset($aResult['return']['person']['mpiId']['value'])) {
                        $mpiId_create = $aResult['return']['person']['mpiId']['value'];
                    } elseif (isset($aResult['mpiId']['value'])) {
                        $mpiId_create = $aResult['mpiId']['value'];
                    }
                    if (isset($aResult['return']['matchStatus'])) {
                        $matchStatus = $aResult['return']['matchStatus'];
                    }
                    /*
                    if ($matchStatus == 'NO_MATCH' || $matchStatus == 'POSSIBLE_MATCH') {
                        Logging::logEvent('', $module->getModuleName(), "OTHER", '', $mpiId_create, "MPI created");
                    } 
                    */ 
                    $sPSNTmp = $oPseudoService->getPseudonymFor($mpiId_create);
                    if ((($sPSNTmp == false && count($_POST) == 0) || $matchStatus == 'NO_MATCH' || $matchStatus == 'POSSIBLE_MATCH') && strlen($module->getProjectSetting("custom_field")[0]) > 0) {
                        $sMode = 'create';
                    } else {
                        // create / get PSN
                        $sPSN = $oPseudoService->getOrCreatePseudonymFor($mpiId_create);
                        if ($sPSN) {
                            $oPseudoService->createREDCap($sPSN, '', $ishId);
                            // redcap log
                            Logging::logEvent('', $module->getModuleName(), "OTHER", '', $sPSN, "PSN retrieved");
                        }
                    }
                } 
            } else {
                // only search access!
                $mpiId = $oPseudoService->getActivePersonByLocalIdentifier($ishId);
                if ($mpiId) {
                    // get PSN
                    $sPSN = $oPseudoService->getPseudonymFor($mpiId);
                    if ($sPSN) {
                        // redcap log
                        Logging::logEvent('', $module->getModuleName(), "OTHER", '', $sPSN, "PSN retrieved");
                    }
                }
            }
        }
    } elseif (strlen($iMPI_ID_ENC) > 0) { 
        // MPI from search result
        $mpiId = decrypt($iMPI_ID_ENC,$_SESSION[$oPseudoService->session]['enckey']);
        if ($mpiId) {
            // get PSN
            $sPSN = $oPseudoService->getPseudonymFor($mpiId);
            if ($sPSN) {
                // redcap log
                Logging::logEvent('', $module->getModuleName(), "OTHER", '', $sPSN, "PSN retrieved");
            }
        }
    }
}

// ================================================================================================
// PSN set => redirect to REDCap data entry home
// ================================================================================================
if (strlen($sPSN) > 0 && strlen($oPseudoService->getError()) == 0) {
    $sPSN = $oPseudoService->dag_prefix.$oPseudoService->trimZero($sPSN);
    $homeURL = APP_PATH_WEBROOT . "DataEntry/record_home.php?" . http_build_query([
              "pid" => $project_id,
              "id" => $sPSN
          ]);
    redirect($homeURL);
}

// ================================================================================================
// delete PSN / Person
// ================================================================================================
if ($sMode == 'delete' && isset($_GET['del_mpiid_enc']) && PseudoService::isAllowed('delete')) {
    $mpiID = decrypt($_GET['del_mpiid_enc'],$_SESSION[$oPseudoService->session]['enckey']);
    if ($mpiID) {
        $sPSNTmp = $oPseudoService->getPseudonymFor($mpiID);
        if ($sPSNTmp) {
            $sPSNTmp = $oPseudoService->dag_prefix.$sPSNTmp;
            // delete REDCap record
            $deleted = REDCAP::deleteRecord($project_id, $sPSNTmp);
            if ($deleted) {
                // delete gPAS entry
                $deleted = $oPseudoService->deleteEntry($mpiID);
                if ($deleted) {
                    // redcap log
                    Logging::logEvent('', $module->getModuleName(), "OTHER", '', $sPSNTmp, "PSN deleted");
        
                    // extID? skip E-PIX
                    if ($module->getProjectSetting("extpsn") === true && str_starts_with($mpiID,$module->getProjectSetting("extpsn_prefix"))) {
                        $oPseudoService->setError("Pseudonym / REDCap Datensatz wurde gelöscht!");
                    } elseif ($oPseudoService->use_epix === true) {
                        // delete person if not in other projects
                        $bOtherProjects = false;
                        $bPSNInProject = false;
                        $aPSNNet = $oPseudoService->getPSNNetFor($mpiID);
                        if (is_array($aPSNNet['nodes'])) {
                            foreach($aPSNNet['nodes'] as $aNode) {
                                if (!isset($aNode['level']) || !isset($aNode['domainName'])) continue;
                                if ($aNode['level'] == '0' && $aNode['domainName'] != $module->getProjectSetting("gpas_domain")) {
                                    $bOtherProjects = true;
                                }
                                if ($aNode['level'] == '0' && $aNode['domainName'] == $module->getProjectSetting("gpas_domain")) {
                                    $bPSNInProject = true;
                                }
                            }
                            if (!$bOtherProjects && !$bPSNInProject) {
                                $oPseudoService->deactivatePerson($mpiID);
                                $deleted = $oPseudoService->deletePerson($mpiID);
                                if ($deleted) {
                                    // redcap log
                                    Logging::logEvent('', $module->getModuleName(), "OTHER", '', '', "Person deleted");
                                    $oPseudoService->setError("Person / REDCap Datensatz wurde gelöscht!");
                                }
                            } else {
                                $oPseudoService->setError("Pseudonym / REDCap Datensatz wurde gelöscht!");
                            }
                        }
                    }
                }
            }
        }
    }
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

// ================================================================================================
// display navigation
// ================================================================================================
if (PseudoService::isAllowed('search')) {
?>
      <ul class="nav nav-pills">
          <?php
          if (!$oPseudoService->bnoDAG) {
          ?>
        <li class="nav-item">
          <?php if ($sMode == 'search') { ?>
          <a class="nav-link active" aria-current="page" href="<?php echo ($module->moduleIndex); ?>">Suche</a>
          <?php } ?>
          <?php if ($sMode == 'create' && $_GET['edit'] != '1') { ?>
          <a class="nav-link active" aria-current="page" href="<?php echo ($module->moduleIndex); ?>">Anlegen</a>
          <?php } ?>
          <?php if ($sMode == 'create' && $_GET['edit'] == '1') { ?>
          <a class="nav-link active" aria-current="page" href="<?php echo ($module->moduleIndex); ?>">Bearbeiten</a>
          <?php } ?>
          <?php if ($sMode != 'create' && $sMode != 'search') { ?>
          <a class="nav-link" href="<?php echo ($module->moduleIndex); ?>">Suche</a>
          <?php } ?>
        </li>
<?php   }
        if (PseudoService::isAllowed('edit') && $oPseudoService->use_epix === true) { ?>
        <li class="nav-item">
          <a class="nav-link<?php if ($sMode == 'dubletten') print (' active" aria-current="page"'); else print ('"'); ?> href="<?php echo ($module->moduleIndex); ?>&mode=dubletten">Dubletten</a>
        </li>
<?php   } ?>
<?php   if (PseudoService::isAllowed('export')) { ?>
        <li class="nav-item">
          <a class="nav-link<?php if ($sMode == 'export') print (' active" aria-current="page"'); else print ('"'); ?> href="<?php echo ($module->moduleIndex); ?>&mode=export">Export</a>
        </li>
<?php   } ?>
<?php   if (PseudoService::isAllowed('import') && !$oPseudoService->bnoDAG) { ?>
        <li class="nav-item">
          <a class="nav-link<?php if ($sMode == 'import') print (' active" aria-current="page"'); else print ('"'); ?> href="<?php echo ($module->moduleIndex); ?>&mode=import">Import</a>
        </li>
<?php   } ?>
      </ul><br />
<?php

    // ================================================================================================
    // display errors
    // ================================================================================================
    $sErrorTmp = '';
    if (strlen($oPseudoService->getError()) > 0) {
        $sErrorTmp .= $oPseudoService->getError().'<br />';
    }
    if ($oPseudoService->use_sap === true && strlen($oSAPPatientSearch->getError()) > 0) {
        $sErrorTmp .= $oSAPPatientSearch->getError().'<br />';
    
    }
    if (strlen($_SESSION[$oPseudoService->session]['msg']) > 0) {
        $sErrorTmp .= $_SESSION[$oPseudoService->session]['msg'];
        unset($_SESSION[$oPseudoService->session]['msg']);
    }
    
    if ($sErrorTmp) {
        print ('<div class="red" style="max-width:700px;">'.$sErrorTmp.'</div><br />');
    }
    
    // ================================================================================================
    // display search form
    // ================================================================================================
    if ($sMode == 'search' && !$oPseudoService->bnoDAG) { ?>
    <?php if ($oPseudoService->use_epix === true) { ?>
          <h5>Personen suchen</h5>
    <?php } ?>
          <form style="max-width:700px;" method="post" action="<?php echo ($module->moduleIndex); ?>">
    <?php if ($oPseudoService->use_sap === true) { ?>
          <div class="form-group row">
            <label for="ish_id" class="col-sm-2 col-form-label">SAP-ID</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="ish_id" name="ish_id" value="<?php echo $_POST['ish_id']; ?>">
            </div>
          </div>
    <?php } ?>
    <?php if ($oPseudoService->use_epix === true) { ?>
          <div class="form-group row">
            <label for="firstName" class="col-sm-2 col-form-label">Vorname</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="firstName" name="firstName" value="<?php echo $_POST['firstName']; ?>">
            </div>
          </div>
          <div class="form-group row">
            <label for="lastName" class="col-sm-2 col-form-label">Nachname</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="lastName" name="lastName" value="<?php echo $_POST['lastName']; ?>">
            </div>
          </div>
          <div class="form-group row">
            <label for="birthDate" class="col-sm-2 col-form-label">Geburtsdatum (DD.MM.YYYY)</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="birthDate" name="birthDate" value="<?php echo $_POST['birthDate']; ?>">
            </div>
          </div>
    <?php } ?>
    <?php if ($module->getProjectSetting("extpsn") === true) { ?>
           <!-- externes Pseudonym -->
          <h5><?php print ($module->getProjectSetting("extpsn_label")); ?> suchen</h5>
          <div class="form-group row">
            <label for="extID" class="col-sm-2 col-form-label"><?php print ($module->getProjectSetting("extpsn_label")); ?></label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="extID" name="extID" value="<?php echo $_POST['extID']; ?>">
            </div>
          </div>
    <?php } ?>      
          <div class="form-group row">
            <div class="col-sm-offset-2 col-sm-5">
              <button type="submit" class="btn btn-secondary" name="submit">Suchen</button>
            </div>
          </div>
          <input type="hidden" name="mode" value="search">
          </form>
      
    <?php
    } // end search mode 
} // end isAllowed('search')

// ================================================================================================
// display create form
// ================================================================================================
$aPost = $_POST;
if (isset($_GET['extID'])) {
    $aPost['extID'] = $_GET['extID'];
} 
// load data for edit form
if ($sMode == 'create' && $_GET['edit'] == '1' && PseudoService::isAllowed('edit') && $oPseudoService->use_epix === true) {
    if (strlen($iMPI_ID_ENC) > 0 && count($aPost) == 0) {
        $mpiId = decrypt($iMPI_ID_ENC,$_SESSION[$oPseudoService->session]['enckey']);
        if ($mpiId) {
            $result = $oPseudoService->getActivePersonByMPI($mpiId);

            if (!is_array($result['referenceIdentity']['gender'])) {
                $aPost['gender'] = $result['referenceIdentity']['gender'];
            }
            if (!is_array($result['referenceIdentity']['degree'])) {
                $aPost['degree'] = $result['referenceIdentity']['degree'];
            }
            if (!is_array($result['referenceIdentity']['firstName'])) {
                $aPost['firstName'] = $result['referenceIdentity']['firstName'];
            }
            if (!is_array($result['referenceIdentity']['lastName'])) {
                $aPost['lastName'] = $result['referenceIdentity']['lastName'];
            }
            if (!is_array($result['referenceIdentity']['mothersMaidenName'])) {
                $aPost['mothersMaidenName'] = $result['referenceIdentity']['mothersMaidenName'];
            }
            if (!is_array($result['referenceIdentity']['birthDate'])) {
                $aTmp = explode("T",$result['referenceIdentity']['birthDate']);
                $aPost['birthDate'] = \DateTimeRC::format_user_datetime($aTmp[0], 'Y-M-D_24', 'D.M.Y_24');
            }
            // only 1 contact
            if (isset($result['referenceIdentity']['contacts']['city'])) {
                $aContact = $result['referenceIdentity']['contacts'];
            } else {
                // more contacts: get newest contact
                $aContactsTmp = array();
                foreach($result['referenceIdentity']['contacts'] as $aTmp) {
                    $aContactsTmp[$aTmp['contactCreated']] = $aTmp;
                }
                krsort($aContactsTmp);
                $aContact = current($aContactsTmp);
            }

            if (!is_array($aContact['street'])) {
                $aPost['street'] = $aContact['street'];               
            }
            if (!is_array($aContact['zipCode'])) {
                $aPost['zipCode'] = $aContact['zipCode'];               
            }
            if (!is_array($aContact['city'])) {
                $aPost['city'] = $aContact['city'];               
            }
            if (!is_array($aContact['phone'])) {
                $aPost['phone'] = $aContact['phone'];               
            }
            if (!is_array($aContact['country'])) {
                $aPost['country'] = $aContact['countryCode'];     
            }

            // add custom fields value1..10
            if (is_array($module->getProjectSetting("cust-vars-list"))) {
                foreach($module->getProjectSetting("cust-vars-list") as $i => $foo) {
                    if (strlen($module->getProjectSetting("custom_field")[$i]) == 0) continue;
                    $sFieldTmp = 'value'.$module->getProjectSetting("custom_field")[$i];
                    if (!is_array($result['referenceIdentity'][$sFieldTmp])) {
                        $aPost[$sFieldTmp] =  $result['referenceIdentity'][$sFieldTmp];
                    }
                }
            } 
        }
    }
}

if ($sMode == 'create' 
    && (PseudoService::isAllowed('create') || ($_GET['edit'] == '1' && PseudoService::isAllowed('edit')))) { 

    if ($oPseudoService->manual_edit === true || strlen($iISH_ID_ENC) > 0) { 

        $aIsoCodes = PseudoService::csv_to_array(dirname(__FILE__).'/german-iso-3166.csv');
        if (!isset($aPost['country'])) {
            $aPost['country'] = 'DE';
        }

        if ($_GET['edit'] == '1') {
            print("<h5>Person bearbeiten</h5>");
        } else {
            print("<h5>Person anlegen</h5>");
        }
        ?>
          <form method="post" action="<?php echo ($module->moduleIndex); ?>">
    <?php 
        $sDis = '';
        if (strlen($iISH_ID_ENC) > 0 && $oPseudoService->use_sap === true) {
            $sDis = ' disabled="disabled"';
            $ishId_dc = decrypt($iISH_ID_ENC,$_SESSION[$oPseudoService->session]['enckey']);
            if ($ishId_dc) {
                print('
                <div class="form-group row">
                  <label for="ish_id" class="col-sm-2 col-form-label">SAP-ID</label>
                  <div class="col-sm-5">
                    <input type="text" class="form-control" id="ish_id" name="ish_id" value="'.$ishId_dc.'" '.$sDis.'>
                  </div>
                </div>');
            }
        }
        if ((strlen($iISH_ID_ENC) == 0 || strlen($iMPI_ID_ENC) > 0) && $oPseudoService->manual_edit === true) {
         ?>
          <div class="form-group row">
            <label for="gender" class="col-sm-2 col-form-label">Geschlecht*</label>
            <div class="radio">
              <?php foreach($oPseudoService->aGender as $sKey => $sVal) {
                  $sSel = '';
                  if ($sKey == $aPost['gender']) {
                    $sSel = ' checked="checked"';
                  }
                  echo ('<label class="radio-inline"><input type="radio" name="gender" value="'.$sKey.'"'.$sSel.$sDis.'> '.$sVal.'</input>&nbsp;&nbsp;</label>'."\n");
              }
              ?>
              
            </div>
          </div>
          <div class="form-group row">
            <label for="degree" class="col-sm-2 col-form-label">Titel</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="degree" name="degree" value="<?php echo $aPost['degree']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="firstName" class="col-sm-2 col-form-label">Vorname*</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="firstName" name="firstName" value="<?php echo $aPost['firstName']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="lastName" class="col-sm-2 col-form-label">Nachname*</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="lastName" name="lastName" value="<?php echo $aPost['lastName']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="mothersMaidenName" class="col-sm-2 col-form-label">Geburtsname</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="mothersMaidenName" name="mothersMaidenName" value="<?php echo $aPost['mothersMaidenName']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="birthDate" class="col-sm-2 col-form-label">Geburtsdatum (DD.MM.YYYY)*</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="birthDate" name="birthDate" value="<?php echo $aPost['birthDate']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="street" class="col-sm-2 col-form-label">Straße</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="street" name="street" value="<?php echo $aPost['street']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="zipCode" class="col-sm-2 col-form-label">Postleitzahl</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="zipCode" name="zipCode" value="<?php echo $aPost['zipCode']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="city" class="col-sm-2 col-form-label">Wohnort</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="city" name="city" value="<?php echo $aPost['city']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="phone" class="col-sm-2 col-form-label">Telefon</label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="phone" name="phone" value="<?php echo $aPost['phone']; ?>"<?php echo ($sDis); ?>>
            </div>
          </div>
          <div class="form-group row">
            <label for="country" class="col-sm-2 col-form-label">Land</label>
            <div class="col-sm-5">
            <select class="form-control" id="country" name="country"<?php echo ($sDis); ?>>
                  <option value=""></option>
              <?php foreach($aIsoCodes as $aTmp) {
                  $sSel = '';
                  if ($aTmp['iso'] == $aPost['country']) {
                    $sSel = ' selected="selected"';
                  }
                  echo ('<option value="'.$aTmp['iso'].'"'.$sSel.'>'.$aTmp['label'].'</option>'."\n");
              }
              ?>
              </select>
              </div>
          </div>
    <?php 
    }
    // add custom fields value1..10
    if (is_array($module->getProjectSetting("cust-vars-list"))) {
        foreach($module->getProjectSetting("cust-vars-list") as $i => $foo) {
            if (strlen($module->getProjectSetting("custom_field")[$i]) == 0) continue;
            $sFieldTmp = 'value'.$module->getProjectSetting("custom_field")[$i];
            $sLabelTmp = $module->getProjectSetting("custom_label")[$i];
    ?>
          <div class="form-group row">
            <label for="<?php print ($sFieldTmp); ?>" class="col-sm-2 col-form-label"><?php print ($sLabelTmp); ?></label>
            <div class="col-sm-5">
              <input type="text" class="form-control" id="<?php print ($sFieldTmp); ?>" name="<?php print ($sFieldTmp); ?>" value="<?php echo $aPost[$sFieldTmp]; ?>">
            </div>
          </div>
    <?php 
        }
    } ?>
          <div class="form-group row">
            <div class="col-sm-offset-2 col-sm-5">
              <button type="submit" class="btn btn-secondary" name="submit">Eintragen</button>
            </div>
          </div>
          <input type="hidden" name="mode" value="create">
          <input type="hidden" name="ish_id_enc" value="<?php print ($iISH_ID_ENC); ?>">
          <input type="hidden" name="mpiid_enc" value="<?php print ($iMPI_ID_ENC); ?>">
          </form>
          <br />
<?php  
    }
    
    if ($module->getProjectSetting("extpsn") === true && strlen($iISH_ID_ENC) == 0 && strlen($iMPI_ID_ENC) == 0) { ?>
       <!-- externes Pseudonym -->
      <h5><?php print ($module->getProjectSetting("extpsn_label")); ?> anlegen</h5>
      <form method="post" action="<?php echo ($module->moduleIndex); ?>">
      <div class="form-group row">
        <label for="extID" class="col-sm-2 col-form-label"><?php print ($module->getProjectSetting("extpsn_label")); ?>
        <?php if ($module->getProjectSetting("validate_pat_id") === true) { 
            echo ("<br>".$module->getProjectSetting("use_9_digits_pat_id") ? '(9-/10-stellig erlaubt)' : '(10-stellig)'); 
        } ?></label>
        <div class="col-sm-5">
          <input type="text" class="form-control" id="extID" name="extID" value="<?php echo $aPost['extID']; ?>">
        <?php if ($module->getProjectSetting("validate_pat_id") === true) { ?> 
            <span id="error-msg-leading-0" style="color:red; display:none;">Pat-IDs dürfen nicht mit 0 beginnen.<br>Geben Sie die ID ab der 2. Stelle an<br>(damit wird die Eingabe insg. 9-stellig)</span>
        <?php } ?>
        </div>
      </div>
      <div class="form-group row">
        <div class="col-sm-offset-2 col-sm-5">
          <button type="submit" class="btn btn-secondary" name="submit" id="submitGen" <?php if ($module->getProjectSetting("validate_pat_id") === true) { echo "disabled"; } ?>>Eintragen</button>
        </div>
      </div>
      <input type="hidden" name="mode" value="create">
      </form>
<?php if ($module->getProjectSetting("validate_pat_id") === true) { ?>
        <script type="text/javascript">
            // javascript to enable generation button only if regex matches 
            document.addEventListener("DOMContentLoaded", function () {
                let inputField = document.getElementById("extID");
                let submitButton = document.getElementById("submitGen");
                let errorMsg = document.getElementById("error-msg-leading-0");
                
                // ideal input: 10 digits ID (pat ID + check digit, no leading 0 accepted)
                let regex = /^[1-9]{1}[0-9]{9}$/;
                
                // allow 9 digit input as well if activated in settings 
                var use_9_digits = "<?php echo json_encode($module->getProjectSetting("use_9_digits_pat_id")); ?>";
                if (use_9_digits == "true") {
                    regex = /^[1-9]{1}[0-9]{8,9}$/;
                }

                // display error message on leading 0
                inputField.addEventListener("input", function() {
                    errorMsg.style.display = inputField.value.startsWith("0") ? "inline" : "none";

                    if (inputField.value.length == 9 && use_9_digits == "true") {
                        errorMsg.innerHTML = "Prüfen Sie bitte selbst die Gültigkeit der 9-stelligen ID.";
                        errorMsg.style.color = "#e08f1d";
                        errorMsg.style.display = "inline";
                    } else {
                        errorMsg.style.color = "red";
                    }
                });

                // activate generation button on valid ID
                inputField.addEventListener("keyup", function () {
                    if (regex.test(inputField.value)) {
                        submitButton.disabled = false;
                    } else {
                        submitButton.disabled = true;
                    }
                });
            });
        </script>
<?php } ?>
<?php }       
} // end if mode=create

// ================================================================================================
// display export form
// ================================================================================================
if ($sMode == 'export' && PseudoService::isAllowed('export')) { ?>
      <h5>Liste exportieren</h5>
      <form style="max-width:700px;" method="post" action="<?php echo ($module->moduleIndex); ?>">
      <div class="form-group row">
        <div class="col-sm-5">
          <label for="psn_filter">Studienpseudonyme (optional):</label>
          <textarea id="psn_filter" name="psn_filter" class="form-control" rows="10"><?php echo $_POST['psn_filter']; ?></textarea>
        </div>
      </div>
      <div class="form-group row">
        <div class="col-sm-offset-2 col-sm-5">
          <button type="submit" class="btn btn-secondary" name="submit">Export</button>
        </div>
      </div>
      <input type="hidden" name="mode" value="export">
      </form>
<?php } 

// ================================================================================================
// display import form
// ================================================================================================
if ($sMode == 'import' && PseudoService::isAllowed('import')) { ?>
      <h5>Liste importieren</h5>
      <form style="max-width:700px;" enctype="multipart/form-data" method="post" action="<?php echo ($module->moduleIndex); ?>">
      <div class="form-group row">
        <div class="col-sm-5">
            <label for="file_upload">CSV-Datei (mit ";" getrennt)</label>
            <input type="file" id="file_upload" name="file_upload"> 
        </div>
      </div>
      <div class="form-group row">
        <div class="col-sm-offset-2 col-sm-5">
          <button type="submit" class="btn btn-secondary" name="submit">Import</button>
        </div>
      </div>
      <input type="hidden" name="MAX_FILE_SIZE" value="3000000" />
      <input type="hidden" name="mode" value="import">
      </form>
<?php } ?>

<script type="text/javascript">

$(function(){
    $('.table tr[data-href]').each(function(){
        $(this).css('cursor','pointer').hover(
            function(){ 
                $(this).addClass('active'); 
            },  
            function(){ 
                $(this).removeClass('active'); 
            }
        );
    });
});

(function(window, document, $) {
    $(document).ready(function() {
        var defaultVisibility = 0;
        var icons = ['down', 'up'];
        var btnLbl = [ 'anzeigen', 'einklappen' ];
        var collapsedToggle = [ 'addClass', 'removeClass' ];
        var toggleAction = [ 'show', 'hide' ];
        var currentForm = '';

        function btnLblText(visibility) {
            return '<i class="fas fa-chevron-'+icons[visibility]+'"></i>&nbsp;'+btnLbl[visibility];
        }


        var toggleRows = function() {
            const $this = $(this);
            const formName = $this.attr('data-toggle-form');
            const visible = btnLbl.indexOf($this.text().trim()); // visible when button says "Collapse"
            // Toggle and switch button label
            $this.html(btnLblText(1-visible));
            $('#dubletten-table tr[data-form="' + formName + '"]')[toggleAction[visible]]();
        };

        $('#dubletten-table .codebook-form-header').each(function() {
            const $this = $(this);
            const form = $this.attr('data-form-name');
            const $btn = $('<button type="button" data-toggle-form="'+form+'" class="btn btn-xs btn-primaryrc toggle-rows d-print-none" style="float:right;" data-toggle="button">'+btnLblText(defaultVisibility)+'</button>');
            $btn.on('click', toggleRows);
            $this.append($btn);
            $('#dubletten-table tr[data-form="' + form + '"]')[toggleAction[1]]();
        });

    });
})(window, document, jQuery);

    
// confirm delete popup
function confirmDelete(mpi) {
  	$('#confirmDelete').dialog({ bgiframe: true, modal: true, width: 400, buttons: {
  		Cancel: function() { $(this).dialog('close'); },
  		'Delete': function () {
              window.location.href = '<?=$oPseudoService->moduleIndex.'&mode=delete&'?>' + mpi;
  		}
  	} });
}

</script>
<div id="confirmDelete" title="REDCap-Datensatz / Pseudonym / Person löschen" style="display:none;">
	<p>Wollen Sie wirklich den REDCap Datensatz, das Studienpseudonym und die Person löschen?</p>
</div>

<?php

// ================================================================================================
// display search results
// ================================================================================================
if (is_array($aEpixResult) && count($aEpixResult) > 0 && PseudoService::isAllowed('search')) {
    // insert delete column header
    $sDelTD = '';
    if (PseudoService::isAllowed('delete')) {
        $sDelTD = '<th></th>';
    }
    
    // show correct header row
    if (!$bPatientSearch) {
        print ('
        <table class="table table-hover" style="max-width:700px;">
            <thead>
              <tr>
                <th>'.$module->getProjectSetting("extpsn_label").'</th>
                <th>Studienpseudonym</th>
                <th></th>
                '.$sDelTD.'
              </tr>
            </thead>
            <tbody>');
    } else {
        print ('
        <table class="table table-hover" style="max-width:700px;">
            <thead>
              <tr>
                <th>Name</th>
                <th>Vorname</th>
                <th>Geburtsdatum</th>');
        if ($oPseudoService->use_sap === true) { 
            print ('<th>SAP-ID</th>');
        }
        print ('<th></th>
                <th></th>
                '.$sDelTD.'
              </tr>
            </thead>
            <tbody>');
    } 

    foreach($aEpixResult as $aData) {
        
        // insert delete column
        $sDelTD = '';
        if (PseudoService::isAllowed('delete')) {
            $sDelTD = '<td></td>';
        }

        // external pseudonyms
        if (!$bPatientSearch) {
            $homeURL = APP_PATH_WEBROOT . "DataEntry/record_home.php?" . http_build_query([
                      "pid" => $project_id,
                      "id" => $aData['psn']
                  ]);
            // delete REDCap data entry / psn / person
            if (PseudoService::isAllowed('delete')) {
                $del_mpi_url = http_build_query(["del_mpiid_enc" => encrypt($module->getProjectSetting("extpsn_prefix").$aData['extpsn'],$_SESSION[$oPseudoService->session]['enckey'])]);
                $sDelTD = '<td onclick="confirmDelete(\''.$del_mpi_url.'\');"><span style="color:red;">'.RCView::fa('fa-solid fa-user-xmark"').'</span></td>';
            }
            
            print ('
                  <tr>
                    <td>'.$aData['extid'].'</td>
                    <td>'.$aData['psn'].'</td>
                    <td><a href="'.$homeURL.'">'.RCView::fa('fa-solid fa-arrow-right"').'<img src="'.APP_PATH_IMAGES.'redcap_icon.gif"></a></td>                    
                    '.$sDelTD.'
                  </tr>');
        } else {
            $sIdTD = $sEdTD = '<td></td>';

            // E-PIX data
            if (strlen($aData['mpiid']) > 0) {
                $mpi_url = http_build_query(["mpiid_enc" => encrypt($aData['mpiid'],$_SESSION[$oPseudoService->session]['enckey'])]);
                $sJumpTD = '<td><a href="'.$module->moduleIndex.'&'.$mpi_url.'">'.RCView::fa('fa-solid fa-arrow-right"').'<img src="'.APP_PATH_IMAGES.'redcap_icon.gif"></a></td>';
                if (isset($aData['ish_id_epix'])) {
                    $sIdTD = '<td>'.$aData['ish_id_epix'].'</td>';
                }
                if ($aData['edit'] == true && PseudoService::isAllowed('edit')) {
                    $sEdTD = '<td><a href="'.$module->moduleIndex.'&'.$mpi_url.'&mode=create&edit=1">'.RCView::fa('fa-solid fa-user-pen').'</a></td>';
                }

                // delete REDCap data entry / psn / person
                if (PseudoService::isAllowed('delete')) {
                    $del_mpi_url = http_build_query(["del_mpiid_enc" => encrypt($aData['mpiid'],$_SESSION[$oPseudoService->session]['enckey'])]);
                    $sDelTD = '<td onclick="confirmDelete(\''.$del_mpi_url.'\');"><span style="color:red;">'.RCView::fa('fa-solid fa-user-xmark"').'</span></td>';
                }
            }
            // SAP data
            if (strlen($aData['ish_id']) > 0) {
                $ish_url = http_build_query(["ish_id_enc" => encrypt($aData['ish_id'],$_SESSION[$oPseudoService->session]['enckey'])]);
                $sIdTD = '<td>'.$aData['ish_id'].'</td>';
                $sJumpTD = '<td><a href="'.$module->moduleIndex.'&'.$ish_url.'">'.RCView::fa('fa-solid fa-arrow-right"').'<img src="'.APP_PATH_IMAGES.'redcap_icon.gif"></a></td>';
                if ($aData['edit'] == true && PseudoService::isAllowed('edit')) {
                    $sEdTD = '<td><a href="'.$module->moduleIndex.'&'.$ish_url.'&'.$mpi_url.'&mode=create&edit=1">'.RCView::fa('fa-solid fa-user-pen').'</a></td>';
                }
            }
            
            print ('
                  <tr>
                    <td>'.$aData['name'].'</td>
                    <td>'.$aData['vorname'].'</td>
                    <td>'.\DateTimeRC::format_user_datetime($aData['gebdat'], 'Y-M-D_24', 'D.M.Y_24').'</td>
                    '.$sIdTD.'
                    '.$sJumpTD.'
                    '.$sEdTD.'
                    '.$sDelTD.'
              </tr>');
        
        }
    }

    print ('
        </tbody>
      </table>');

}

// ================================================================================================
// dubletten
// ================================================================================================
if ($sMode == 'dubletten' && PseudoService::isAllowed('edit') && $oPseudoService->use_epix === true) {
     
      $aDubletten = $oPseudoService->getPossibleMatchesForDomain();

      $aIntDubletten = array();
      foreach($aDubletten as $aDubEntry) {
          // keep both persons when they have different ISH-IDs
          if ($aDubEntry['matchingMPIIdentities'][0]['identity']['identifiers']['identifierDomain']['name'] == $oPseudoService->epix_id_domain &&
          $aDubEntry['matchingMPIIdentities'][1]['identity']['identifiers']['identifierDomain']['name'] == $oPseudoService->epix_id_domain) {
              if ($aDubEntry['matchingMPIIdentities'][0]['identity']['identifiers']['value'] != 
              $aDubEntry['matchingMPIIdentities'][1]['identity']['identifiers']['value']) {
                  $oPseudoService->removePossibleMatch($aDubEntry['linkId']);
                  // redcap log
                  $sPSN0 = $oPseudoService->getPseudonymFor($aDubEntry['matchingMPIIdentities'][0]['mpiId']['value']);
                  $sPSN1 = $oPseudoService->getPseudonymFor($aDubEntry['matchingMPIIdentities'][1]['mpiId']['value']);
                  Logging::logEvent('', $module->getModuleName(), "OTHER", '', '', "doublet resolution (keep both persons): ".$sPSN0." - ".$sPSN1);
                  continue;
              }          
          }
          
          // show only internal dublettes
          $bOtherProjects = $bPSNInProject = 0;
          for($i=0;$i<=1;$i++) {
              $mpiId_tmp = $aDubEntry['matchingMPIIdentities'][$i]['mpiId']['value'];
              $aPSNNet = $oPseudoService->getPSNNetFor($mpiId_tmp);
              if (is_array($aPSNNet['nodes'])) {
                  foreach($aPSNNet['nodes'] as $aNode) {
                      if (!isset($aNode['level']) || !isset($aNode['domainName'])) continue;
                      if ($aNode['level'] == '0' && $aNode['domainName'] != $module->getProjectSetting("gpas_domain")) {
                          $bOtherProjects ++;
                      }
                      if ($aNode['level'] == '0' && $aNode['domainName'] == $module->getProjectSetting("gpas_domain")) {
                          $bPSNInProject ++;
                      }
                  }
              }  
           }
           if ($bOtherProjects == 0 && $bPSNInProject > 0) {
                $aIntDubletten[] = $aDubEntry;
           }
      }

      print ('
      <form style="max-width:700px;" method="post" action="'.$module->moduleIndex.'">      
      <table class="table table-hover" style="max-width:700px;" id="dubletten-table">
          <thead>
            <tr>
              <th></th>
              <th>Name</th>
              <th>Vorname</th>
              <th>Geburtsdatum</th>
              <th></th>
              <th>Name</th>
              <th>Vorname</th>
              <th>Geburtsdatum</th>
              <th></th>
            </tr>
          </thead>
          <tbody>');

      foreach($aIntDubletten as $aDubEntry) {
          $aDubPersons = array();
          for($i=0;$i<=1;$i++) {
              if (!is_array($aDubEntry['matchingMPIIdentities'][$i]['identity']['birthDate'])) {
                  $aTmp = explode("T",$aDubEntry['matchingMPIIdentities'][$i]['identity']['birthDate']);
                  $aDubPersons[$i]['birthDate'] = \DateTimeRC::format_user_datetime($aTmp[0], 'Y-M-D_24', 'D.M.Y_24');
              }
              if (!is_array($aDubEntry['matchingMPIIdentities'][$i]['identity']['firstName'])) {
                  $aDubPersons[$i]['firstName'] = $aDubEntry['matchingMPIIdentities'][$i]['identity']['firstName'];
              }
              if (!is_array($aDubEntry['matchingMPIIdentities'][$i]['identity']['gender'])) {
                  $aDubPersons[$i]['gender'] = $oPseudoService->aGender[$aDubEntry['matchingMPIIdentities'][$i]['identity']['gender']];
              }
              if (!is_array($aDubEntry['matchingMPIIdentities'][$i]['identity']['lastName'])) {
                  $aDubPersons[$i]['lastName'] = $aDubEntry['matchingMPIIdentities'][$i]['identity']['lastName'];
              }
              if (!is_array($aDubEntry['matchingMPIIdentities'][$i]['identity']['contacts']['country'])) {
                  $aDubPersons[$i]['country'] = $aDubEntry['matchingMPIIdentities'][$i]['identity']['contacts']['country'];
              }
              $aDubPersons[$i]['psn'] = $oPseudoService->getPseudonymFor($aDubEntry['matchingMPIIdentities'][$i]['mpiId']['value']);
          }
          $sCreated_date = date('d.m.Y H:i:s', strtotime($aDubEntry['possibleMatchCreated']));
          print ('
                <tr>
                  <td>'.$sCreated_date.'</td>
                  <td>'.$aDubPersons[0]['firstName'].'</td>
                  <td>'.$aDubPersons[0]['lastName'].'</td>
                  <td>'.$aDubPersons[0]['birthDate'].'</td>
                  <td>&nbsp;&nbsp;</td>
                  <td>'.$aDubPersons[1]['firstName'].'</td>
                  <td>'.$aDubPersons[1]['lastName'].'</td>
                  <td>'.$aDubPersons[1]['birthDate'].'</td>
                  <th class="codebook-form-header" data-form-name="'.$aDubEntry['linkId'].'"></th>
                </tr>
                <tr data-form="'.$aDubEntry['linkId'].'">
                  <td>PSN</td>
                  <td colspan="3">'.$aDubPersons[0]['psn'].'</td>
                  <td>&nbsp;&nbsp;</td>
                  <td colspan="3">'.$aDubPersons[1]['psn'].'</td>
                </tr>
                <tr data-form="'.$aDubEntry['linkId'].'">
                  <td>Vorname</td>
                  <td colspan="3">'.$aDubPersons[0]['firstName'].'</td>
                  <td>&nbsp;&nbsp;</td>
                  <td colspan="3">'.$aDubPersons[1]['firstName'].'</td>
                </tr>
                <tr data-form="'.$aDubEntry['linkId'].'">
                  <td>Nachname</td>
                  <td colspan="3">'.$aDubPersons[0]['lastName'].'</td>
                  <td>&nbsp;&nbsp;</td>
                  <td colspan="3">'.$aDubPersons[1]['lastName'].'</td>
                </tr>
                <tr data-form="'.$aDubEntry['linkId'].'">
                  <td>Geschlecht</td>
                  <td colspan="3">'.$aDubPersons[0]['gender'].'</td>
                  <td>&nbsp;&nbsp;</td>
                  <td colspan="3">'.$aDubPersons[1]['gender'].'</td>
                </tr>
                <tr data-form="'.$aDubEntry['linkId'].'">
                  <td>Geburtsdatum</td>
                  <td colspan="3">'.$aDubPersons[0]['birthDate'].'</td>
                  <td>&nbsp;&nbsp;</td>
                  <td colspan="3">'.$aDubPersons[1]['birthDate'].'</td>
                </tr>
                <tr data-form="'.$aDubEntry['linkId'].'">
                  <td>Land</td>
                  <td colspan="3">'.$aDubPersons[0]['country'].'</td>
                  <td>&nbsp;&nbsp;</td>
                  <td colspan="3">'.$aDubPersons[1]['country'].'</td>
                </tr>
                <tr data-form="'.$aDubEntry['linkId'].'">
                  <td></td>
                  <td colspan="3" style="text-align: center"><button type="submit" class="btn btn-primaryrc d-print-none" name="assignIdentity" value="'.$aDubEntry['linkId'].':'.$aDubEntry['matchingMPIIdentities'][0]['identity']['identityId'].'">Behalten</button></td>
                  <td><button type="submit" class="btn btn-primaryrc d-print-none" name="removePossibleMatch" value="'.$aDubEntry['linkId'].'">Beide&nbsp;behalten</button></td>
                  <td colspan="3" style="text-align: center"><button type="submit" class="btn btn-primaryrc d-print-none" name="assignIdentity" value="'.$aDubEntry['linkId'].':'.$aDubEntry['matchingMPIIdentities'][1]['identity']['identityId'].'">Behalten</button></td>
                </tr>
                ');
      
      }
      print ('
          </tbody>
        </table>
        <input type="hidden" name="mode" value="dubletten">
        </form>');
      
}

// ================================================================================================
// Neue Person anlegen auch anzeigen, wenn keine Suchtreffer vorhanden
// ================================================================================================
if (count($_POST) > 0 && isset($_POST['submit'])) {
  if ($sMode == 'search'
      && ($module->getProjectSetting("extpsn") === true || $oPseudoService->manual_edit === true)
      && PseudoService::isAllowed('create')) {

      echo ('<br />&nbsp;<br />');
      if ($module->getProjectSetting("extpsn") === true && strlen($_POST['extID']) > 0) {
          print('<a href="'.$module->moduleIndex.'&mode=create&extID='.$_POST['extID'].'">Neue Person mit '.$module->getProjectSetting("extpsn_label").' '.$_POST['extID'].' anlegen</a>');
      } elseif ($oPseudoService->manual_edit === true) {
          print('<a href="'.$module->moduleIndex.'&mode=create">Neue Person anlegen</a>');
      }

  }
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';