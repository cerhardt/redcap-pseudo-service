<?php
namespace meDIC\PseudoService;

class CheckDigit extends PseudoService {
    
    /**
     * Validation check digit of Pat ID 
     * 10 and 9 digits cases are considered 
     *   10 digits: returns input if calculated check digit match the input check digit
     *    9 digits: append a calculated check digit after the input Pat-ID
     * edge cases are avoided through live form validation in the index.php
     * 
     * @author Egidia Cenko
     * @param string $known_id patient ID
     * @access public
     * @return string known_id / -1
     */
    public static function validateID($known_id){
       	// calculate check digit for Pat-ID
        $calculatedCheckDigit = CheckDigit::calculateCheckDigit($known_id);
        
        if (strlen($known_id) === 10) {
            // compare calculated check digit with check digit from user input
            $checkDigit = substr($known_id, -1);
            
            // check digit correct?
            if (intval($checkDigit) === $calculatedCheckDigit) {
                // user input for Pat-ID is returned
                return $known_id;
            } else {
                return -1;
            }
        } elseif (strlen($known_id) === 9) {
            // return input Pat-ID with calculated check sum digit - no further check can be done
            return $known_id . $calculatedCheckDigit;
        }
    } 


    /**
     * Calculation of the check digit based on DIN ISO 7064
     * 10 digits: remove the check digit, add a leading 0 for calculation
     * 9 digits: add a leading 0 for calculation
     * 
     * @author Egidia Cenko
     * @param string $known_id patient ID
     * @access public
     * @return integer $checkDigit
     */
    public static function calculateCheckDigit($known_id){
        $digits = str_split($known_id);

        if (strlen($known_id) === 10) {
            // add leading 0 to digit, remove original input check digit for calculation
            array_unshift($digits, "0");
            $digits = array_slice($digits, 0, -1);
        } elseif (strlen($known_id) === 9) {
            // add leading zero to 9-digits input
            array_unshift($digits, "0");
        }
        
        if (count($digits) !== 10) {
            throw new \Exception(strtoupper("Length mismatch after processing: ") . implode($digits));
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
<?php
namespace meDIC\PseudoService;

class CheckDigit extends PseudoService {
    
    /**
     * Validation check digit of Pat ID 
     * 10 and 9 digits cases are considered 
     *   10 digits: returns input if calculated check digit match the input check digit
     *    9 digits: append a calculated check digit after the input Pat-ID
     * edge cases are avoided through live form validation in the index.php
     * 
     * @author Egidia Cenko
     * @param string $known_id patient ID
     * @access public
     * @return string known_id / -1
     */
    public static function validateID($known_id){
       	// calculate check digit for Pat-ID
        $calculatedCheckDigit = CheckDigit::calculateCheckDigit($known_id);
        
        if (strlen($known_id) === 10) {
            // compare calculated check digit with check digit from user input
            $checkDigit = substr($known_id, -1);
            
            // check digit correct?
            if (intval($checkDigit) === $calculatedCheckDigit) {
                // user input for Pat-ID is returned
                return $known_id;
            } else {
                return -1;
            }
        } elseif (strlen($known_id) === 9) {
            // return input Pat-ID with calculated check sum digit - no further check can be done
            return $known_id . $calculatedCheckDigit;
        }
    } 


    /**
     * Calculation of the check digit based on DIN ISO 7064
     * 10 digits: remove the check digit, add a leading 0 for calculation
     * 9 digits: add a leading 0 for calculation
     * 
     * @author Egidia Cenko
     * @param string $known_id patient ID
     * @access public
     * @return integer $checkDigit
     */
    public static function calculateCheckDigit($known_id){
        $digits = str_split($known_id);

        if (strlen($known_id) === 10) {
            // add leading 0 to digit, remove original input check digit for calculation
            array_unshift($digits, "0");
            $digits = array_slice($digits, 0, -1);
        } elseif (strlen($known_id) === 9) {
            // add leading zero to 9-digits input
            array_unshift($digits, "0");
        }
        
        if (count($digits) !== 10) {
            throw new \Exception(strtoupper("Length mismatch after processing: ") . implode($digits));
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