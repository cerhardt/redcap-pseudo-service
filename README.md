# REDCap module to integrate E-PIX / gPAS for (de-)pseudonymisation

This modul uses these components:

* <img src="docs/E-PIX-Logo-ohne-Text-150y.png" width="100"> E-PIX: https://www.ths-greifswald.de/forscher/e-pix/ 

* <img src="docs/gPAS-Logo-ohne-Text-160y.png" width="100"> gPAS: https://www.ths-greifswald.de/forscher/gpas/

* SAP search: it uses the SAP BAPI_PATIENT_SEARCH Function Module for IS-H BAPI Patient.Search (see [WSDL](docs/wsdl_sap.xml) of the SOAP API)

* The authorisation of the REDCap user uses OIDC authorization code flow. Basic Auth can be selected instead of OIDC (Currently, Basic Auth is realized solely for using gPAS, i.e. no use of E-PIX or SAP search is intended).


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

| Parameter             | Description                            |
|-----------------------|-----------------------------------------|
| allowed REDCap domain | allowed REDCap hostname              |
| Login Authentication Type | OAuth / Basic Auth               |
| use E-PIX             | allow E-PIX use and show E-PIX settings (OIDC) |
| use SAP               | allow use of SAP search and show SAP settings (OIDC) |
| E-PIX API URL         | API URL for E-PIX                  |
| E-PIX Domain          | E-PIX domain                            |
| E-PIX external source | source for external persons   |
| E-PIX ID Domain       | ID domain                               |
| E-PIX safe source     | safe source for patients from SAP       |
| E-PIX Scope           | E-PIX Scope (OIDC)               |
| gPAS API URL          | API URL for gPAS                   |
| gPAS Domain API URL   | API URL for gPAS Domain Manager    |
| gPAS Domain Scope     | gPAS Domains Scope (OIDC/Basic Auth)       |
| gPAS Scope            | gPAS Scope (OIDC/Basic Auth)                |
| SAP API URL           | API URL SAP search              |
| SAP Scope             | SAP search Scope (OIDC)          |
| SAP Filter: IS-H ID:  | SAP filter term for IS-H ID |
| SAP Filter: Last Name:  | SAP filter term for the last name |
| SAP Filter: First Name:  | SAP filter term for the first name |
| SAP Filter: Date of Birth FROM:  | SAP filter term for first considerable birthdate  |
| SAP Filter: Date of Birth TO:  | SAP filter term for last considerable birthdate |
| use proxy             | use REDCap system proxy for http(s) |

### OIDC specific
| Parameter             | Description                            |
|-----------------------|-----------------------------------------|
| OAuth2 Authorization URL      | Authorization URL for OIDC     |
| OAuth2 Client ID      | Client ID for OIDC     |
| OAuth2 Client secret  | Secret for OIDC        |

### Basic Auth specific
| Parameter             | Description                            |
|-----------------------|-----------------------------------------|
|Basic Auth username | username for the gPAS instance |
| Basic Auth secret | password for the gPAS instance|

**Note**: For development within Docker (using the [redcap-docker-compose](https://github.com/123andy/redcap-docker-compose/tree/master) repository), the `allowed REDCap domain` should be set to `localhost` (default) so that the `login()` method in the file [`PseudoService.php`](./PseudoService.php) can work properly.
## Project Configuration

- gPAS domain: specifiy gPAS domain for creating studyIDs
- additional fields for setting the user rights for specific roles:
  ![](docs/project_config1.png)
  **Note:** In your project, select `Designer` and `+ Create` the instruments `tc_access` and `tc_impexp` in order to make the external module accessible.