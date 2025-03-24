<?php
namespace meDIC\PseudoService;

class CheckDigit extends PseudoService {
    
    /**
     * @author Egidia Cenko
     * @param string $known_id patient ID
     * @access public
     * @return boolean valid?
     */
    public static function validateID($known_id){
        // no IDs with leading 0 exist 
        if (strlen($known_id) === 10 && $known_id[0] != "0") {
            return true;
        } else {
            return false;
        }

        /* initial logic when both 9 digits and 10 digits input is allowed
        if (strlen($known_id) === 9 && $known_id[0] == "0") {
            return false;
        } else {
            if (strlen($known_id) === 10 && $known_id[0] != "0") {
                // 10 digits with pat id and check digit -> ideal
                //TODO compare check digit from input to calculated one
                return true;
            } elseif (strlen($known_id) === 9 && $known_id[0] != "0") {
                //TODO: calculate check digit for the provided ID
                echo "9 Ziffern ohne Prüfziffer, muss berechnet werden";
                return $known_id;

            } elseif (strlen($known_id) === 10 && $known_id[0] == "0") {
                //TODO: calculate check digit for the provided ID by trimming the leading 0
                echo "10 Ziffern mit 0 am Anfang, muss berechnet werden";
                return $known_id;
            }
        }*/
    } 

    //TODO: logic for check digit acc. to DIN ISO 7064
    public static function calculateCheckDigit($known_id){
        $digits = str_split($knownID);

        //TODO: add case: if (strlen($known_id) === 10 && $known_id[0] != "0")
        if (strlen($known_id) === 9 && $known_id[0] != "0") {
            //TODO: calculate check digit for the provided ID
            // add leading zero to 9-digits input
            array_unshift($digits, "0");
        } elseif (strlen($known_id) === 10 && $known_id[0] == "0") {
            //TODO: calculate check digit for the provided ID by trimming the leading 0
            echo "10 Ziffern mit 0 am Anfang, muss berechnet werden";
            // 10 digits, leading 0 -> remains unchanged for calculation
        }  
         //TODO: compare logic for existing check digit and calculated one: i.e. for 10 digits without leading zero, split check digit and add leading zero to compare in validate
        
        if (count($digits) !== 10) {
            throw new RuntimeException("Length mismatch after processing: " . implode("", $digits));
        }

        // logic for check digit acc. to DIN ISO 7064
        $P = 10;
        foreach ($digits as $char) {
            $P += intval($char);
            if ($P > 10) $P -= 10;
            $P *= 2;
            if ($P >= 11) $P -= 11;
        }
        
        $checkDigit = 11 - $P;
        return $checkDigit === 10 ? 0 : $checkDigit;
    }
}