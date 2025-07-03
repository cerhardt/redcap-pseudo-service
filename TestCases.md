
# Test Cases

General preconditions: 
* the external module is imported in REDCap. 
* all required settings are filled. 
* the instruments *tc_access* and *tc_impexp* are created in order to make the external module accessible from a project.

## OIDC
| Test Case | Description                            | Preconditions                      | Steps                                                                         | Expected Result                                                  |
| --------- | -------------------------------------- | ---------------------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| **TC1**   |  OIDC logic (default)    | 1. Select `OAuth`as *Login Authentication Type* and provide the configurations for OAuth2 <br> 2. Activate *use E-PIX* <br> 3. Activate *use SAP*                          | Click on the external module   | All default tabs are visible, default logic works as expected    |

## Basic Auth Login

| Test Case | Description                            | Preconditions                      | Steps                                                                         | Expected Result                                                  |
| --------- | -------------------------------------- | ---------------------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| **TC1**  | Entering a correct 10-digit ID (default) | Select `Basic Auth` as *Login Authentication Type* in the project settings | Type the 10-digit ID `1234567896` and press *Generieren* | Pseudonyms for IDs with less than 9 digits or more than 11 digits cannot be generated (= the generate-button is deactivated). A record is successfully created, with the pseudonym as the record ID. |
| **TC2**  | Entering a wrong 10-digit ID | Select `Basic Auth` as *Login Authentication Type* in the project settings | Type the 10-digit ID `1234567890` and press *Generieren* | The user gets feedback on the wrong input and can re-enter an ID. |
| **TC3**   | Entering a 9-digit ID  | Select `Basic Auth` as *Login Authentication Type* in the project settings and provide the configurations for Basic Auth <br> 2. Activate *allow 9 digits input for Pat.-ID (default: 10 digits)* | Type the 9-digit ID `123456789` and press *Generieren*.  |  The form *Pseudonym erzeugen* (only for Basic Auth!) provides a hint for 9-digit input. Pseudonyms for IDs with less than 9 digits or more than 11 digits cannot be generated (= the generate-button is deactivated). A record is successfully created, with the pseudonym as the record ID (the same pseudonym as in **TC1**)   | 
| **TC4**  | Entering a correct 10-digit ID (when allowing 9 digits as well) | same as in **TC3**| Type the 10-digit ID `1234567896` and press *Generieren* | Pseudonyms for IDs with less than 9 digits or more than 11 digits cannot be generated (= the generate-button is deactivated). As the 9th digit is entered, a hint for 9-digit input is provided below the form and invisible as soon as 10 digits are entered . A record is successfully created, with the pseudonym as the record ID (the same pseudonym as in **TC1**)  |

