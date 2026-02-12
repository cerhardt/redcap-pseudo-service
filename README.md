# REDCap module to integrate E-PIX / gPAS for (de-)pseudonymisation

This modul uses these components:

* <img src="docs/E-PIX-Logo-ohne-Text-150y.png" width="100"> E-PIX: https://www.ths-greifswald.de/forscher/e-pix/ 

* <img src="docs/gPAS-Logo-ohne-Text-160y.png" width="100"> gPAS: https://www.ths-greifswald.de/forscher/gpas/

* SAP search: it uses the SAP BAPI_PATIENT_SEARCH Function Module for IS-H BAPI Patient.Search (see [WSDL](docs/wsdl_sap.xml) of the SOAP API)

* The authorisation of the REDCap user uses OIDC authorization code flow.

## Prerequisites
- REDCap with external modules framework (>= v.8.0.0)
- E-PIX server
- gPAS server
- SAP SOAP API for searching patients
- API Gateway for secure access to APIs with OIDC

## Installation
- Unzip the module to the modules directory (folder name pseudo_service_vX.X.X)
- Go to **Control Center > External Modules > Manage** and enable the module
- For each project you want to use this module, go to the project home page, click on **External Modules > Manage**, and then enable the module for that project.

## System Configuration

### Authentication
| Parameter             | Description                            |
|-----------------------|-----------------------------------------|
| Login Authentication Type 1-3 | Basic Auth / OIDC client credentials / OIDC auth code flow              |
| Authentication Name         | custom label                  |
| Basic Auth: user/password          |                             |
| OIDC: Authorization URL  |    |
| OIDC: client_id/secret   |    |

### gPAS settings
| Parameter             | Description                            |
|-----------------------|-----------------------------------------|
| gPAS API URL          | API URL for gPAS                   |
| gPAS Authentication Type | System Auth Type 1-3 |
| gPAS Scope            | gPAS Scope (OIDC)                |
| gPAS Domain API URL   | API URL for gPAS Domain Manager    |
| gPAS Domain Scope     | gPAS Domains Scope (OIDC)       |

### E-PIX settings
| Parameter                 | Description                            |
|---------------------------|-----------------------------------------|
| Use E-PIX? |  | 
| E-PIX Authentication Type | System Auth Type 1-3 |
| E-PIX API URL         | API URL for E-PIX                  |
| E-PIX Scope           | E-PIX Scope (OIDC)               |
| E-PIX Domain          | E-PIX domain                            |
| E-PIX safe source     | safe source for patients from SAP       |
| E-PIX external source | source for external persons   |
| E-PIX ID Domain       | ID domain                               |

### SAP settings
| Parameter                       | Description                            |
|---------------------------------|-----------------------------------------|
| Use SAP?                        |  | 
| SAP Authentication Type         | System Auth Type 1-3 |
| SAP API URL                     | API URL SAP search              |
| SAP Scope                       | SAP search Scope (OIDC)          |
| SAP Filter: IS-H ID:            | SAP filter term for IS-H ID | `FILTER_PATIENTID`
| SAP Filter: Last Name:          | SAP filter term for the last name | `FILTER_LAST_NAME_PAT`
| SAP Filter: First Name:         | SAP filter term for the first name | `FILTER_FRST_NAME_PAT`
| SAP Filter: Date of Birth FROM: | SAP filter term for first considerable birthdate  | `FILTER_DOB_FROM`
| SAP Filter: Date of Birth TO:   | SAP filter term for last considerable birthdate | `FILTER_DOB_TO`

### general settings
| Parameter                   | Description                          |
|-----------------------------|--------------------------------------|
| use proxy                       | use REDCap system proxy for http(s) |

## Project Configuration
| Parameter                   | Description                          |
|-----------------------------|--------------------------------------|
| use custom project settings | use E-PIX / SAP from system settings? | 
| overwrite system auth settings    | overwrite System Authentication      |
| gPAS domain     | specifiy gPAS domain for creating studyIDs      |
| store pseudonyms in gPAS    |       |

### DAG settings
| Parameter                   | Description                          |
|-----------------------------|--------------------------------------|
| use DAGs for E-PIX access                      | DAG specific access to E-PIX |
| use DAG prefix for record-ids                      | add DAG-IDs to studyIDs in REDCap |


- additional fields for setting the user rights for specific roles:
  ![](docs/project_config1.png)
  **Note:** In your project, select `Designer` and `+ Create` the instruments `tc_access` and `tc_impexp` in order to make the external module accessible.