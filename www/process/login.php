<?php

require 'base.inc';
require BASE . '/../config.inc';

// http://ltb-project.org/wiki/documentation/self-service-password
function retrieve_ldap_password($login, $password){

    global $ldapUrl, $ldapBase, $ldapFilter, $ldapBindUser, $ldapBindPassword, $ldap_use_tls;

    # Connect to LDAP
    $ldap = @ldap_connect($ldapUrl);
    @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

	if ($ldap_use_tls) {
		if(!@ldap_start_tls($ldap)){
			$result = "ldaperror";
			$err = "LDAP - failed to start tls";
			die("LDAP - failed to start tls");
		}    
	}

    # Bind
    $bind = @ldap_bind($ldap, $ldapBindUser, $ldapBindPassword);
    
    $errno = @ldap_errno($ldap);
    if ( $errno ) {
        $result = "ldaperror";
        $err = "LDAP - Bind error $errno  (".@ldap_error($ldap).")";
        die("LDAP - Bind error $errno  (".@ldap_error($ldap).")");
    }
    else {
		# Search for user
		$ldapFilter = str_replace("{login}", $login, $ldapFilter);
		$search = @ldap_search($ldap, $ldapBase, $ldapFilter);

		$errno = @ldap_errno($ldap);
		if ( $errno ) {
			$result = "ldaperror";
			$err = "LDAP - Search error $errno  (".@ldap_error($ldap).")";
		}
		else {
			# Get user DN
			$entry = @ldap_first_entry($ldap, $search);
			$userdn = @ldap_get_dn($ldap, $entry);

			if( !$userdn ) {
				$result = "badcredentials";
				$err = "LDAP - User $login not found";
			}
			else {    
				# Bind with password
				$bind = @ldap_bind($ldap, $userdn, $password);
				$errno = @ldap_errno($ldap);
				if ( $errno ) {
					$result = "badcredentials";
					$err = "LDAP - Bind user error $errno  (".@ldap_error($ldap).")";
				} else {
					// Everything is OK ;)
					$result = "OK";
					$err = "";
				}
			}
		}
	}
	@ldap_close($ldap);
	if ($result == "OK") {
		return True;
	}
	else {
		return False;
	}		
}

// deconnexion
if(isset($_GET['action']) && $_GET['action'] == 'logout') {
	// Audit
	if (CONFIG_SOPLANNING_OPTION_AUDIT == 1 && CONFIG_SOPLANNING_OPTION_AUDIT_CONNEXIONS == 1)
	{
		logAction('D');
	}
	
	unset($_SESSION['user_id']);
	session_regenerate_id();
	@session_destroy();
	if(CONFIG_LOGOUT_REDIRECT != '') {
		header('Location: ' . CONFIG_LOGOUT_REDIRECT);
		exit;
	} else {
		header('Location: ../index.php' . (isset($_GET['language']) ? '?language=' . $_GET['language'] : ''));
		exit;
	}
}

function active_directory_login($username, $password){
	global $ADServer, $ADDomain;
	$ldap = ldap_connect($ADServer);
	if($ldap === false) {
		die('LDAP connexion failed');
	}
	ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
	ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

	$ldaprdn = $username . "@" . $ADDomain;
	$bind = @ldap_bind($ldap, $ldaprdn, $password);
	
	if(!$bind) {
		$ldaprdn = $ADDomain . "\\" . $username;
		$bind = @ldap_bind($ldap, $ldaprdn, $password);
	}

	if ($bind) {
		return true;
	} else {
		return false;
	}
}

if(isset($_GET['direct_periode_id']) && $_GET['direct_periode_id'] > 0) {
	// direct access from email to a specific task
	if(!isset($_GET['date']) || $_GET['date'] < date('Y-m-d')) {
		$_SESSION['message'] = 'Invalid URL';
		header('Location: index.php');
		exit;
	}
	if(!isset($_GET['hash']) || $_GET['hash'] != md5($_GET['direct_periode_id'] . '!�' . $_GET['date'] . '�' . CONFIG_SECURE_KEY)) {
		$_SESSION['message'] = 'Invalid URL';
		header('Location: index.php');
		exit;
	}
	
	$_SESSION['direct_periode_id'] = round($_GET['direct_periode_id']);
	header('Location: ../index.php');
	exit;
}

//login 
if(!isset($_POST['login']) || !isset($_POST['password']) || $_POST['login'] == '' || $_POST['password'] == '') {
	$_SESSION['message'] = 'erreur_bad_login';
	header('Location: ../index.php');
	exit;
}

// ldap password
if($ldapLogin) {
    if(!isset($_POST['password']) || !retrieve_ldap_password($_POST['login'], $_POST['password'])) {
        $_SESSION['message'] = 'erreur_bad_login';
        header('Location: ../index.php');
        exit;
    }
}

$user = New User();
 // AD account
if($ADLogin && ($_POST['login'] != 'admin')) {
	if(!active_directory_login($_POST['login'], $_POST['password'])){
		$_SESSION['message'] = 'erreur_bad_login';
		header('Location: ../index.php');
		exit;
	}
    if(!$user->db_load(array('login', '=', $_POST['login']))) {
        $_SESSION['message'] = 'erreur_bad_login';
        header('Location: ../index.php');
        exit;
    }
} elseif($ldapLogin && ($_POST['login'] != 'admin')) {
    if(!$user->db_load(array('login', '=', $_POST['login']))) {
        $_SESSION['message'] = 'erreur_bad_login';
        header('Location: ../index.php');
        exit;
    }
} else {
	$pwd = sha1("�" . $_POST['password'] . "�");
	if(!$user->db_load(array('login', '=', $_POST['login'], 'password', '=', $pwd))) {
        $_SESSION['message'] = 'erreur_bad_login';
        header('Location: ../index.php');
        exit;
    }
}

if($user->login_actif == 'non'){
	$_SESSION['message'] = 'erreur_bad_login';
	header('Location: ../index.php');
	exit;	
}

// Pr�f�rence de s�lection par d�faut
chargerSessionStatutsDefaut();

$_SESSION['user_id'] = $user->user_id;
$user->date_dernier_login = date('Y-m-d H:i:s');
$user->db_save();

// Audit
if (CONFIG_SOPLANNING_OPTION_AUDIT == 1 && CONFIG_SOPLANNING_OPTION_AUDIT_CONNEXIONS == 1) {
	logAction('C');
}
	
$user->setSessionPref();

// Pr�f�rence de vue planning
if (isset($_SESSION['preferences']['vuePlanning']) && ($_SESSION['preferences']['vuePlanning']=="vueTaches") && (CONFIG_SOPLANNING_OPTION_TACHES == 1)) {
	header('Location: ../taches.php');
} else {
	header('Location: ../planning.php');
}
exit;
?>