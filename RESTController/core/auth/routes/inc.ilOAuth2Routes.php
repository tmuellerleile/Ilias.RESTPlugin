<?php
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// OAuth 2.0 Support
// Authorization server implementation: endpoints token and (authorization)
// see http://tools.ietf.org/html/rfc6749

/*
 * Authorization Endpoint
 */
$app->post('/v1/oauth2/auth', function () use ($app) {
    try {
        $app = \Slim\Slim::getInstance();
        $request = $app->request();
        $response_type = $request->params('response_type');
        //$client_id = $request->params('api_key');
        $client_id = $_POST['api_key'];
        $redirect_uri = $request->params('redirect_uri');
        $username = $request->params('username');
        $password = $request->params('password');
        $authenticity_token = $request->params('authenticity_token');

        if ($response_type == "code"){

            if ($redirect_uri && $client_id && is_null($authenticity_token) && is_null($username) && is_null($password)) {
                $app->render('oauth2loginform.php', array('api_key' => $client_id, 'redirect_uri' => $redirect_uri, 'response_type' => $response_type));
            } elseif ($username && $password) {

                $iliasAuth = & ilAuthLib::getInstance();
                //$isAuth = $iliasAuth->authMDB2($username,$password);
                $isAuth = $iliasAuth->authenticateViaIlias($username, $password);

                $clientValid = $iliasAuth->checkOAuth2Client($client_id);

                if ($isAuth == true && $clientValid == true){
                    $temp_authenticity_token = ilTokenLib::serializeToken(ilTokenLib::generateToken($username, $client_id, "", 10));
                    $app->render('oauth2grantpermissionform.php', array('api_key' => $client_id, 'redirect_uri' => $redirect_uri, 'response_type' => $response_type, 'authenticity_token' => $temp_authenticity_token));
                }else {
                    $app->response()->status(404);
                }
            } elseif ($authenticity_token && $redirect_uri) {
                $authenticity_token = ilTokenLib::deserializeToken($authenticity_token);
                $user = $authenticity_token['user'];

                if (!ilTokenLib::tokenExpired($authenticity_token)) {
                    $tempToken = ilTokenLib::generateToken($user, $client_id, $redirect_uri,10);
                    $authorization_code = ilTokenLib::serializeToken($tempToken);
                    $url = $redirect_uri . "?code=".$authorization_code;
                    $app->redirect($url);
                }
            }
        } elseif ($response_type == "token") { // implicit grant
            if ($redirect_uri && $client_id && is_null($authenticity_token) && is_null($username) && is_null($password)) {
                $app->render('oauth2loginform.php', array('api_key' => $client_id, 'redirect_uri' => $redirect_uri, 'response_type' => $response_type));
            } elseif ($username && $password) {
                $iliasAuth = & ilAuthLib::getInstance();
                //$isAuth = $iliasAuth->authMDB2($username,$password);
                $isAuth = $iliasAuth->authenticateViaIlias($username, $password);
                $clientValid = $iliasAuth->checkOAuth2Client($client_id);
                if ($isAuth == true) {
                    $app->log->debug("Implicit Grant Flow - Auth valid");
                } else {
                    $app->log->debug("Implicit Grant Flow - Auth NOT valid");
                }
                $app->log->debug("Implicit Grant Flow - Client valid: ".print_r($clientValid,true));
                if ($isAuth == true && $clientValid != false) {
                    $app->log->debug("Implicit Grant Flow - proceed to grant permission form" );
                    $temp_authenticity_token = ilTokenLib::serializeToken(ilTokenLib::generateToken($username, $client_id, "", 10));
                    $app->render('oauth2grantpermissionform.php', array('api_key' => $client_id, 'redirect_uri' => $redirect_uri, 'response_type' => $response_type, 'authenticity_token' => $temp_authenticity_token));
                }else {
                    // Username/Password wrong or client does not exist (which is less likely)
                    $app->render('oauth2loginform.php', array('error_msg' => "Username or password incorrect!",'api_key' => $client_id, 'redirect_uri' => $redirect_uri, 'response_type' => $response_type));
                    $app->response()->status(404);
                }
            } elseif ($authenticity_token && $redirect_uri) {
                $authenticity_token = ilTokenLib::deserializeToken($authenticity_token);
                $user = $authenticity_token['user'];

                if (!ilTokenLib::tokenExpired($authenticity_token)) { // send bearer token
                    $bearerToken = ilTokenLib::generateBearerToken($user, $client_id);
                    $url = $redirect_uri . "#access_token=".$bearerToken['access_token']."&token_type=bearer"."&expires_in=".$bearerToken['expires_in']."&state=xyz";
                    $app->redirect($url);
                }

            }
        }
    } catch (Exception $e) {
        $app->response()->status(400);
        $app->response()->header('X-Status-Reason', $e->getMessage());
    }
});

$app->get('/v1/oauth2/auth', function () use ($app) {
    try {
        $app = \Slim\Slim::getInstance();
        $request = $app->request();
        $apikey = $_GET['api_key']; // Issue: Standard ILIAS Init absorbs client_id GET request field
        $client_redirect_uri = $_GET['redirect_uri'];
        $response_type = $_GET['response_type'];

        if ($response_type == "code") {
            if ($apikey && $client_redirect_uri && $response_type){
                $app->render('oauth2loginform.php', array('api_key' => $apikey, 'redirect_uri' => $client_redirect_uri, 'response_type' => $response_type));
            }

        }else if ($response_type == "token") { // implicit grant
            if ($apikey && $client_redirect_uri && $response_type){
                $app->render('oauth2loginform.php', array('api_key' => $apikey, 'redirect_uri' => $client_redirect_uri, 'response_type' => $response_type));
            }
        }
    } catch (Exception $e) {
        $app->response()->status(400);
        $app->response()->header('X-Status-Reason', $e->getMessage());
    }
});

/*
 * Token endpoint
 * Grant types: Resource Owner(User), Client Credentials & Authorization Code Grant
 * http://tools.ietf.org/html/rfc6749
 * see also http://bshaffer.github.io/oauth2-server-php-docs/grant-types/authorization-code/
*/
$app->post('/v1/oauth2/token', function () use ($app) {
    try {
        $app = \Slim\Slim::getInstance();
        $request = $app->request();

        if (count($request->post()) == 0) {
            $req_data = json_decode($app->request()->getBody(),true); // json
        } else {
            $req_data = $_REQUEST;
            //$grant_type = $request->params('grant_type');
        }

        if ($req_data['grant_type'] == "password") {    // User credentials

            $user = $req_data['username'];
            $pass = $req_data['password'];

            $iliasAuth = & ilAuthLib::getInstance();
            //$isAuth = $iliasAuth->authMDB2($user,$pass);
            $isAuth = $iliasAuth->authenticateViaIlias($user, $pass);


            if ($isAuth == false) {
                $app->response()->status(401);
                // optional: send msg
            }
            else {
                $result = ilTokenLib::generateBearerToken($user, "");
                $app->response()->header('Content-Type', 'application/json');
                $app->response()->header('Cache-Control', 'no-store');
                $app->response()->header('Pragma', 'no-cache');
                echo json_encode($result); // output-format: {"access_token":"03807cb390319329bdf6c777d4dfae9c0d3b3c35","expires_in":3600,"token_type":"bearer","scope":null}
            }
        } elseif ($req_data['grant_type'] == "client_credentials") {
            $client_id = $_POST['api_key'];
            $client_secret = $req_data['client_secret'];

            $iliasAuth = & ilAuthLib::getInstance();
            $authResult = $iliasAuth->checkOAuth2ClientCredentials($client_id, $client_secret);

            if (!$authResult) {
                $app->response()->status(401);

            }
            else {
                $result = ilTokenLib::generateBearerToken("",$client_id);
                $app->response()->header('Content-Type', 'application/json');
                $app->response()->header('Cache-Control', 'no-store');
                $app->response()->header('Pragma', 'no-cache');
                echo json_encode($result);
            }
        } elseif ($req_data['grant_type'] == "authorization_code") {

            $code = $req_data["code"];
            $redirect_uri = $req_data["redirect_uri"];
            //$client_id = $req_data["api_key"];
            $client_id = $_POST['api_key'];
            $client_secret = $req_data['client_secret']; // also check by other means

            $iliasAuth = & ilAuthLib::getInstance();
            $isClientAuthorized = $iliasAuth->checkOAuth2ClientCredentials($client_id, $client_secret);

            if (!$isClientAuthorized) {
                $app->response()->status(401);
            }else {

                $code_token = ilTokenLib::deserializeToken($code);
                $valid = ilTokenLib::tokenValid($code_token);
                if (!ilTokenLib::tokenExpired($code_token)){
                    $t_redirect_uri = $code_token['misc'];
                    $t_user = $code_token['user'];
                    $t_client_id = $code_token['client_id'];

                    if ($t_redirect_uri == $redirect_uri && $t_client_id == $client_id) {
                        $result = ilTokenLib::generateBearerToken($t_user, $t_client_id);
                        $app->response()->header('Content-Type', 'application/json');
                        $app->response()->header('Cache-Control', 'no-store');
                        $app->response()->header('Pragma', 'no-cache');
                        echo json_encode($result);
                    }
                }

            }
        }


    } catch (Exception $e) {
        $app->response()->status(400);
        $app->response()->header('X-Status-Reason', $e->getMessage());
    }
});

// Tokens obtained via the implicit code grant MUST by validated by the Javascript client
// to prevent the "confused deputy problem".
$app->get('/v1/oauth2/tokeninfo', function () use ($app) {
    try {
        $app = \Slim\Slim::getInstance();
        $request = $app->request();
        $access_token = $request->params('access_token');
        if (!isset($access_token)) {
            $a_data = array();
            $jsondata = $app->request()->getBody(); // json
            $a_data = json_decode($jsondata, true);
            $access_token = $a_data['token'];
            if (!isset($access_token)) {
                $headers = apache_request_headers();
                $authHeader = $headers['Authorization'];
                if ($authHeader!=null) {
                    $a_auth = explode(" ",$authHeader);
                    $access_token = $a_auth[1];    // Bearer Access Token
                }
            }
        }

        $token = ilTokenLib::deserializeToken($access_token);
        $valid = ilTokenLib::tokenValid($token);
        $result = array();
        if ($valid) {
            $result['rest_client_id'] = $token['client_id'];
            // scope
            $result['user'] =  $token['user'];
            $result['expires_in'] = ilTokenLib::getRemainingTime($token);

        } else {
            $app->response()->status(400);
            $result['error'] = "Invalid token.";
        }
        echo json_encode($result);
    } catch (Exception $e) {
        $app->response()->status(400);
        $app->response()->header('X-Status-Reason', $e->getMessage());
    }
});


$app->post('/v1/ilauth/rtoken2bearer', function () use ($app) {
    $app = \Slim\Slim::getInstance();
    $result = array();
    $user_id = "";
    $rtoken = "";
    $session_id = "";
    $client_id = "";

    $request = $app->request();
    if (count($request->post()) == 0) {
        $a_data = array();
        $reqdata = $app->request()->getBody(); // json
        $a_data = json_decode($reqdata, true);
        //var_dump($a_data);
        $user_id = $a_data['user_id'];
        $rtoken = $a_data['rtoken'];
        $session_id = $a_data['session_id'];
        $client_id = $a_data['client_id'];
    } else {
        $user_id = $request->params('user_id');
        $rtoken = $request->params('rtoken');
        $session_id = $request->params('session_id');
        $client_id = $request->params('api_key');
    }

    $iliasAuth = & ilAuthLib::getInstance();
    $isAuth = $iliasAuth->authFromIlias($user_id, $rtoken, $session_id);

    if ($isAuth == false) {
        //$app->response()->status(400);
        $result['status'] = "error";
        $result['error'] = "Invalid token.";
        $result['user_id']=$user_id;
        $result['rtoken']=$rtoken;
        $result['session_id']=$session_id;

    }
    else {
        $user = ilRestLib::userIdtoLogin($user_id);
        $access_token = ilTokenLib::generateBearerToken($user, $client_id);
        $result['status'] = "success";
        $result['user'] = $user;
        $result['token'] = $access_token;
    }
    $app->response()->header('Content-Type', 'application/json');
    $app->response()->header('Cache-Control', 'no-store');
    $app->response()->header('Pragma', 'no-cache');
    echo json_encode($result); // output-format: {"access_token":"03807cb390319329bdf6c777d4dfae9c0d3b3c35","expires_in":3600,"token_type":"bearer","scope":null}

});

?>