<?php
require_once "./Services/Database/classes/class.ilAuthContainerMDB2.php";
require_once "./Services/User/classes/class.ilObjUser.php";

class ilAuthLib {
	
	static private $instance = null;
	static private $db_container = null;
	static public $user = null;
	
	static public function getInstance()
    {
		if (null === self::$instance) {
			self::$db_container = new ilAuthContainerMDB2();
			self::$instance = new self;
		}
		return self::$instance;
	}
    
	static public function authMDB2($user,$pwd)
    {
		return self::$db_container->fetchData($user,$pwd);
	}	
	
	static public function headerBasicAuth()
    {
		header('WWW-Authenticate: Basic realm="ILIAS Restservice"');
		self::headerUnauthorized();
	}
	
	static public function headerNoCache()
    {
		header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' ); 
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' ); 
		header( 'Cache-Control: no-store, no-cache, must-revalidate' ); 
		header( 'Cache-Control: post-check=0, pre-check=0', false ); 
		header( 'Pragma: no-cache' ); 
	}
	
	static public function headerUnauthorized()
    {
		header('HTTP/1.1 401 Unauthorized'); 
	}
	
	static public function headerForbidden()
    {
		header('HTTP/1.1 403 Forbidden'); 
	}
	
	static public function setUserContext($login)
    {
		global $ilias, $ilInit;
		$userId = ilObjUser::_lookupId($login);
		if (!$userId) { 
			self::headerUnauthorized();
			exit;
		}
		$ilUser = new ilObjUser($userId);
		$ilias->account =& $ilUser;
		self::$user =& $ilUser;
        ilRestLib::initGlobal("ilUser", $ilUser);
	}

	static public function initAccessHandling()
    {
        ilRestLib::initGlobal("rbacreview", "ilRbacReview",
			"./Services/AccessControl/classes/class.ilRbacReview.php");
		
		require_once "./Services/AccessControl/classes/class.ilRbacSystem.php";
		$rbacsystem = ilRbacSystem::getInstance();
        ilRestLib::initGlobal("rbacsystem", $rbacsystem);

        ilRestLib::initGlobal("rbacadmin", "ilRbacAdmin",
			 "./Services/AccessControl/classes/class.ilRbacAdmin.php");

        ilRestLib::initGlobal("ilAccess", "ilAccessHandler",
			 "./Services/AccessControl/classes/class.ilAccessHandler.php");
		
		require_once "./Services/AccessControl/classes/class.ilConditionHandler.php";
	}

    /**
     * Authentication via the ILIAS Auth mechanisms.
     *
     * This method is used as backend for OAuth2.
     *
     * @param $username
     * @param $password
     */
    static public function authenticateViaIlias($username, $password)
    {

        ilRestLib::initDefaultRestGlobals();
        ilRestLib::initAccessHandling();
        ilRestLib::initSettings();

        // see initUser
        $_POST['username'] = $username;
        $_POST['password'] = $password;

        // add code 1
        if (!is_object($GLOBALS["ilPluginAdmin"]))
        {
            ilRestLib::initGlobal("ilPluginAdmin", "ilPluginAdmin",
                "./Services/Component/classes/class.ilPluginAdmin.php");
        }
        // add code 2
        include_once "Services/Authentication/classes/class.ilSession.php";
        include_once "Services/Authentication/classes/class.ilSessionControl.php";

        require_once "Auth/Auth.php";
        require_once "./Services/AuthShibboleth/classes/class.ilShibboleth.php";
        include_once("./Services/Authentication/classes/class.ilAuthUtils.php");
        ilAuthUtils::_initAuth();
        global $ilAuth;

        $ilAuth->start();
        $checked_in = $ilAuth->getAuth();

        /*if ($checked_in == true)
        {
            $result['msg'] = "User logged in successfully.";
        } else
        {
            $result['msg'] = "User could not be logged in.";
        }
        */
        //echo "sessid: ".session_name().' // '.session_id();
        //(session_id().'::'.$client)

        $ilAuth->logout();

        session_destroy();
        header_remove('Set-Cookie');

        return $checked_in;
    }


    /*
     * Checks if provided OAuth2 client credentials are valid.
     * Compare with http://tools.ietf.org/html/rfc6749#section-4.4 (client credentials grant type).
     *
     * @param int client_id
     * @param string client_secret
     * @return bool
     */
   static public function checkOAuth2ClientCredentials($client_id, $client_secret)
   {
       global $ilDB;
       $query = "SELECT * FROM rest_apikeys WHERE client_id=\"".$client_id."\" AND client_secret=\"".$client_secret."\"";
       $set = $ilDB->query($query);
       $ret = $ilDB->fetchAssoc($set);
       if ($ret) {
		   return $ret;
	   }
	   else {
		   return false;
	   }
   }

    /**
     * Checks if provided OAuth2 - client_id does exist.
     *
     * @param	int	client_id
     * @return	bool
     */
    static public function checkOAuth2Client($client_id)
    {
        global $ilDB;
        $query = "SELECT * FROM rest_apikeys WHERE client_id=\"".$client_id."\"";
        $set = $ilDB->query($query);
        $ret = $ilDB->fetchAssoc($set);
        if ($ret) {
            return $ret;
        }
        else {
            return false;
        }
    }

    /**
     * Checks if a rest client is allowed to enter a route (aka REST endpoint).
     *
     * @param $route
     * @param $operation
     * @param $client_id
     * @return bool
     */
    static public function checkOAuth2Scope($route, $operation, $client_id)
    {
        global $ilDB;
        $operation = strtoupper($operation);
        $query = "SELECT * FROM rest_apikeys WHERE client_id=\"".$client_id."\"";
        $set = $ilDB->query($query);
        $ret = $ilDB->fetchAssoc($set);
        if ($ret) {
            $a_permissions = json_decode($ret['permissions'],true);
            foreach ($a_permissions as $entry) {
                if ($entry['pattern'] == $route && $entry['verb'] == $operation) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Checks if an ILIAS session is valid and belongs to a particular user.
     * And furthermore if rToken is valid.
     * see also Services/UICore/classes/class.ilCtrl.php
     * Services/Authentication/classes/ilSessionControl.php
     * @param $user_id
     * @param $rtoken
     * @param $session_id
     * @return bool
     */
    static public function authFromIlias($user_id, $rtoken, $session_id)
    {
        global $ilDB;

        $rtokenValid = false;
        $sessionValid = false;
        $set = $ilDB->query("SELECT * FROM il_request_token WHERE ".
            " user_id = ".$ilDB->quote($user_id, "integer")." AND ".
            " token = ".$ilDB->quote($rtoken, "text")." AND ".
            "session_id = ".$ilDB->quote($session_id,"text"));
        if ($ilDB->numRows($set) > 0)
        {
            $rtokenValid = true;
        }

        $set = $ilDB->query("SELECT * FROM usr_session WHERE ".
            " user_id = ".$ilDB->quote($user_id, "integer")." AND ".
            "session_id = ".$ilDB->quote($session_id,"text"));
        if ($ilDB->numRows($set) > 0)
        {
            $row = $ilDB->fetchAssoc($set);
            $ts = time();
            if( $row['expires'] > $ts ) {
                $sessionValid = true;
            }
        }

        return $rtokenValid && $sessionValid;
    }

}
?>
