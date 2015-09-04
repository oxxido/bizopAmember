<?php

/**
 * @package Am_Utils 
 */
class Am_Validate
{
    static function empty_or_email($email)
    {
        if ($email == "") return true;
        return self::email($email);
    }
    static function email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    static function emails($emails){
        if($emails == '') return true;
        foreach(preg_split("/[,]/",$emails) as $email)
                if(!self::email($email)) return false;
        return true;
    }    
}