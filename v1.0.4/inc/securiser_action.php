<?php
/*************************************************************************\
*  SPIP, Systeme de publication pour l'internet                           *
*                                                                         *
*  Copyright (c) 2001-2016                                                *
*  Arnaud Martin, Antoine Pitrou, Philippe Riviere, Emmanuel Saint-James  *
*                                                                         *
*  Ce programme est un logiciel libre distribue sous licence GNU/GPL.     *
*  Pour plus de details voir le fichier COPYING.txt ou l'aide en ligne.   *
\*************************************************************************/

/* inc/securiser_action.php
* Plugin OpenID Connect client pour SPIP
* Surcharge de la dist pour assurer l'identification par OIDC
* Auteur : B.Degoy DnC
* Copyright (c) 2018 B.Degoy
* Licence GPL v3.0
*/

/**
* Gestion des actions sécurisées
*
* @package SPIP\Core\Actions
**/


if (!defined('_ECRIRE_INC_VERSION')) {
    return;
}

/**
* Génère ou vérifie une action sécurisée
*
* Interface d'appel:
*
* - au moins un argument: retourne une URL ou un formulaire securisés
* - sans argument : vérifie la sécurité et retourne `_request('arg')`, ou exit.
*
* @uses securiser_action_auteur() Pour produire l'URL ou le formulaire
* @example
*     Tester une action reçue et obtenir son argument :
*     ```
*     $securiser_action = charger_fonction('securiser_action');
*     $arg = $securiser_action();
*     ```
*
* @param string $action
* @param string $arg
* @param string $redirect
* @param bool|int|string $mode
*   - -1 : renvoyer action, arg et hash sous forme de array()
*   - true ou false : renvoyer une url, avec &amp; (false) ou & (true)
*   - string : renvoyer un formulaire
* @param string|int $att
*   id_auteur pour lequel generer l'action en mode url ou array()
*   atributs du formulaire en mode formulaire
* @param bool $public
* @return array|string
*/

function inc_securiser_action_dist($action = '', $arg = '', $redirect = "", $mode = false, $att = '', $public = false) {

    if ($action) {
        return securiser_action_auteur($action, $arg, $redirect, $mode, $att, $public);
    } else {
        $arg = _request('arg');
        $hash = _request('hash');
        $action = _request('action') ? _request('action') : _request('formulaire_action');

        if ( $action == 'auth' ) {   //TODO: préciser OIDC ???

            $error = _request('error');

            if ( empty($error) ) {

                /* OIDC Step 2 ...
                L'action de retour n'est pas sécurisée comme le cas général :
                il faut poursuivre l'échange avec le serveur OIDC.
                */
                include_spip('inc/oidc_steps');
                $userinfo = oidc_step_2();

                if ( is_array($userinfo) ) {

                    include_spip('inc/minipres');       

                    $error = _T('oidcclient:oidc_authentication_error');         
                    if ( ! empty($userinfo['error']) ) {     
                        // Erreur générée à l'étape 2 d'OIDC 1 : montrer à l'end-user
                        echo minipres($error, 
                            '<center>' . $userinfo['error'] . '<br/><a href="?page=login">' . _T('oidcclient:retour_login') . '</a></center>'
                        );   //[dnc17] [1]

                    } else {

                        $login = $userinfo['sub'];  
                        if ( !empty($login) ) {

                            // Ok, mais il reste à vérifier qu'il existe un auteur avec un login identique à ce login OIDC, 
                            $l = sql_quote($login, '', 'text');
                            $loginspip = sql_getfetsel('login', 'spip_auteurs',
                                "statut<>'5poubelle'" .
                                " AND login<>'' AND login=$l");
                            if ( ! empty($loginspip) ) {
                                spip_log('OpenID client : connexion OIDC avec le login de l\'auteur : ' . $loginspip, _LOG_INFO_IMPORTANTE);    
                            } else { 
                                // ou bien que l'application SPIP a un auteur lié à ce login OIDC. 
                                $loginspip = sql_getfetsel('login', 'spip_auteurs',
                                    "statut<>'5poubelle'" .
                                    " AND login<>'' AND oidc=$l");
                                if ( ! empty($loginspip) ) {
                                    spip_log('OpenID client : connexion avec le login OIDC : ' . $l . ' lié à l\'auteur : ' . $loginspip, _LOG_INFO_IMPORTANTE);  
                                }
                            }
                            if (!$loginspip) {    
                                /** Si le compte OIDC n'est pas lié à un utilisateur, il faudra :
                                * - soit lier le compte OIDC à un utilisateur (auteur) SPIP existant, 
                                * - soit créer une nouvelle entrée dans la table auteurs.
                                */
                                $error = sprintf(_T('oidcclient:login_pas_lie'), $login);
                                spip_log( $error,_LOG_ERREUR);  
                                echo minipres($error,
                                    _T('oidcclient:login_pas_lie_msg') . '<br/>' . 
                                    '<center><br/><a href="?page=login">' . _T('oidcclient:retour_login') . '</a></center>'
                                ); //[dnc17] [1]

                            }
                            // Ok
                            $arg = "oidcclient/$loginspip";
                            return $arg;

                        } else {
                            // login is empty
                            echo minipres($error, 
                                '<center>' . _T('oidcclient:null_login') . '<br/><a href="?page=login">' . _T('oidcclient:retour_login') . '</a></center>'
                            );   //[dnc17] [1]
                        }
                    }
                } else {
                    echo minipres($error, 
                        '<center>' . _T('oidcclient:general_error') . '<br/><a href="?page=login">' . _T('oidcclient:retour_login') . '</a></center>'
                    ); //[dnc17] [1]
                    exit;  
                }

            } else {
                // Erreur générée à l'étape 1 d'OIDC 1 : montrer à l'end-user
                include_spip('inc/minipres');
                echo minipres($error, 
                    '<center>' . _request('error_description') . '<br/><a href="?page=login">' . _T('oidcclient:retour_login') . '</a></center>'
                );  //[dnc17] [1]
                exit;     
            }

        } else {
            // cours normal
            if ($a = verifier_action_auteur("$action-$arg", $hash)) {
                return $arg;
            } else {
                // erreur générale
                include_spip('inc/minipres');
                echo minipres(_T('oidcclient:oidc_authentication_error'), 
                    '<center><br/><a href="?page=login">' . _T('oidcclient:retour_login') . '</a></center>'
                );  //[dnc17] [1]
                exit;
            }

        }
    }

}


/**
* Retourne une URL ou un formulaire sécurisés
*
* @note
*   Attention: PHP applique urldecode sur $_GET mais pas sur $_POST
*   cf http://fr.php.net/urldecode#48481
*   http://code.spip.net/@securiser_action_auteur
*
* @uses calculer_action_auteur()
* @uses generer_form_action()
*
* @param string $action
* @param string $arg
* @param string $redirect
* @param bool|int|string $mode
*   - -1 : renvoyer action, arg et hash sous forme de array()
*   - true ou false : renvoyer une url, avec &amp; (false) ou & (true)
*   - string : renvoyer un formulaire
* @param string|int $att
*   - id_auteur pour lequel générer l'action en mode URL ou array()
*   - atributs du formulaire en mode formulaire
* @param bool $public
* @return array|string
*    - string URL, si $mode = true ou false,
*    - string code HTML du formulaire, si $mode texte,
*    - array Tableau (action=>x, arg=>x, hash=>x) si $mode=-1.
*/
function securiser_action_auteur($action, $arg, $redirect = "", $mode = false, $att = '', $public = false) {

    // mode URL ou array
    if (!is_string($mode)) {
        $hash = calculer_action_auteur("$action-$arg", is_numeric($att) ? $att : null);

        $r = rawurlencode($redirect);
        if ($mode === -1) {
            return array('action' => $action, 'arg' => $arg, 'hash' => $hash);
        } else {
            return generer_url_action($action, "arg=" . rawurlencode($arg) . "&hash=$hash" . (!$r ? '' : "&redirect=$r"),
                $mode, $public);
        }
    }

    // mode formulaire
    $hash = calculer_action_auteur("$action-$arg");
    $att .= " style='margin: 0px; border: 0px'";
    if ($redirect) {
        $redirect = "\n\t\t<input name='redirect' type='hidden' value='" . str_replace("'", '&#39;', $redirect) . "' />";
    }
    $mode .= $redirect . "
    <input name='hash' type='hidden' value='$hash' />
    <input name='arg' type='hidden' value='$arg' />";

    return generer_form_action($action, $mode, $att, $public);
}

/**
* Caracteriser un auteur : l'auteur loge si $id_auteur=null
*
* @param int|null $id_auteur
* @return array
*/
function caracteriser_auteur($id_auteur = null) {
    static $caracterisation = array();

    if (is_null($id_auteur) and !isset($GLOBALS['visiteur_session']['id_auteur'])) {
        // si l'auteur courant n'est pas connu alors qu'il peut demander une action
        // c'est une connexion par php_auth ou 1 instal, on se rabat sur le cookie.
        // S'il n'avait pas le droit de realiser cette action, le hash sera faux.
        if (isset($_COOKIE['spip_session'])
        and (preg_match('/^(\d+)/', $_COOKIE['spip_session'], $r))
        ) {
            return array($r[1], '');
            // Necessaire aux forums anonymes.
            // Pour le reste, ca echouera.
        } else {
            return array('0', '');
        }
    }
    // Eviter l'acces SQL si le pass est connu de PHP
    if (is_null($id_auteur)) {
        $id_auteur = isset($GLOBALS['visiteur_session']['id_auteur']) ? $GLOBALS['visiteur_session']['id_auteur'] : 0;
        if (isset($GLOBALS['visiteur_session']['pass']) and $GLOBALS['visiteur_session']['pass']) {
            return $caracterisation[$id_auteur] = array($id_auteur, $GLOBALS['visiteur_session']['pass']);
        }
    }

    if (isset($caracterisation[$id_auteur])) {
        return $caracterisation[$id_auteur];
    }

    if ($id_auteur) {
        include_spip('base/abstract_sql');
        $t = sql_fetsel("id_auteur, pass", "spip_auteurs", "id_auteur=$id_auteur");
        if ($t) {
            return $caracterisation[$id_auteur] = array($t['id_auteur'], $t['pass']);
        }
        include_spip('inc/minipres');
        echo minipres();
        exit;
    } // Visiteur anonyme, pour ls forums par exemple
    else {
        return array('0', '');
    }
}

/**
* Calcule une cle securisee pour une action et un auteur donnes
* utilisee pour generer des urls personelles pour executer une action qui modifie la base
* et verifier la legitimite de l'appel a l'action
*
* @param string $action
* @param int $id_auteur
* @param string $pass
* @param string $alea
* @return string
*/
function _action_auteur($action, $id_auteur, $pass, $alea) {
    static $sha = array();
    if (!isset($sha[$id_auteur . $pass . $alea])) {
        if (!isset($GLOBALS['meta'][$alea]) and _request('exec') !== 'install') {
            include_spip('inc/acces');
            charger_aleas();
            if (empty($GLOBALS['meta'][$alea])) {
                include_spip('inc/minipres');
                echo minipres();
                spip_log("$alea indisponible");
                exit;
            }
        }
        include_spip('auth/sha256.inc');
        $sha[$id_auteur . $pass . $alea] = _nano_sha256($id_auteur . $pass . @$GLOBALS['meta'][$alea]);
    }
    if (function_exists('sha1')) {
        return sha1($action . $sha[$id_auteur . $pass . $alea]);
    } else {
        return md5($action . $sha[$id_auteur . $pass . $alea]);
    }
}

/**
* Calculer le hash qui signe une action pour un auteur
*
* @param string $action
* @param int|null $id_auteur
* @return string
*/
function calculer_action_auteur($action, $id_auteur = null) {
    list($id_auteur, $pass) = caracteriser_auteur($id_auteur);

    return _action_auteur($action, $id_auteur, $pass, 'alea_ephemere');
}


/**
* Verifier le hash de signature d'une action
* toujours exclusivement pour l'auteur en cours
*
* @param $action
* @param $hash
* @return bool
*/
/*
function verifier_action_auteur($action, $hash) {
list($id_auteur, $pass) = caracteriser_auteur();
if ($hash == _action_auteur($action, $id_auteur, $pass, 'alea_ephemere')) {
return true;
}
if ($hash == _action_auteur($action, $id_auteur, $pass, 'alea_ephemere_ancien')) {
return true;
}

return false;
} //*/

function verifier_action_auteur($action, $hash) {
    if ( $action == 'auth-' ) {
        // OIDC : l'action de retour n'est pas sécurisée ainsi
        return true;
    } else {
        // Cas général
        list($id_auteur, $pass) = caracteriser_auteur();
        if ($hash == _action_auteur($action, $id_auteur, $pass, 'alea_ephemere')) {
            return true;
        }
        if ($hash == _action_auteur($action, $id_auteur, $pass, 'alea_ephemere_ancien')) {
            return true;
        }
    }

    return false;
}



//
// Des fonctions independantes du visiteur, qui permettent de controler
// par exemple que l'URL d'un document a la bonne cle de lecture
//

/**
* Renvoyer le secret du site, et le generer si il n'existe pas encore
* Le secret du site doit rester aussi secret que possible, et est eternel
* On ne doit pas l'exporter
*
* @return string
*/
function secret_du_site() {
    if (!isset($GLOBALS['meta']['secret_du_site'])) {
        include_spip('base/abstract_sql');
        $GLOBALS['meta']['secret_du_site'] = sql_getfetsel('valeur', 'spip_meta', "nom='secret_du_site'");
    }
    if (!isset($GLOBALS['meta']['secret_du_site'])
    or (strlen($GLOBALS['meta']['secret_du_site']) < 64)
    ) {
        include_spip('inc/acces');
        include_spip('auth/sha256.inc');
        ecrire_meta('secret_du_site',
            _nano_sha256($_SERVER["DOCUMENT_ROOT"] . $_SERVER["SERVER_SIGNATURE"] . creer_uniqid()), 'non');
        lire_metas(); // au cas ou ecrire_meta() ne fonctionne pas
    }

    return $GLOBALS['meta']['secret_du_site'];
}

/**
* Calculer une signature valable pour une action et pour le site
*
* @param string $action
* @return string
*/
function calculer_cle_action($action) {
    if (function_exists('sha1')) {
        return sha1($action . secret_du_site());
    } else {
        return md5($action . secret_du_site());
    }
}

/**
* Verifier la cle de signature d'une action valable pour le site
*
* @param string $action
* @param string $cle
* @return bool
*/
function verifier_cle_action($action, $cle) {
    return ($cle == calculer_cle_action($action));
}


/**
 * Calculer le token de prévisu
 *
 * Il permettra de transmettre une URL publique d’un élément non encore publié,
 * pour qu’une personne tierce le relise. Valable quelques temps.
 *
 * @see verifier_token_previsu()
 * @param string $url Url à autoriser en prévisu
 * @param int|null id_auteur qui génère le token de prévisu. Null utilisera auteur courant.
 * @param string $alea Nom de l’alea à utiliser
 * @return string Token, de la forme "{id}*{hash}"
 */
function calculer_token_previsu($url, $id_auteur = null, $alea = 'alea_ephemere') {
    if (is_null($id_auteur)) {
        if (!empty($GLOBALS['visiteur_session']['id_auteur'])) {
            $id_auteur = $GLOBALS['visiteur_session']['id_auteur'];
        }
    }
    if (!$id_auteur = intval($id_auteur)) {
        return "";
    }
    // On nettoie l’URL de tous les var_.
    $url = nettoyer_uri_var($url);

    $token = _action_auteur('previsualiser-' . $url, $id_auteur, null, $alea);
    return "$id_auteur-$token";
}


/**
 * Vérifie un token de prévisu
 *
 * Découpe le token pour avoir l’id_auteur,
 * Retrouve à partir de l’url un objet/id_objet en cours de parcours
 * Recrée un token pour l’auteur et l’objet trouvé et le compare au token.
 *
 * @see calculer_token_previsu()
 * @param string $token Token, de la forme '{id}*{hash}'
 * @return false|array
 *     - `False` si echec,
 *     + Tableau (id auteur, type d’objet, id_objet) sinon.
 */
function verifier_token_previsu($token) {
    // retrouver auteur / hash
    $e = explode('-', $token, 2);
    if (count($e) == 2 and is_numeric(reset($e))) {
        $id_auteur = intval(reset($e));
    } else {
        return false;
    }

    // calculer le type et id de l’url actuelle
    include_spip('inc/urls');
    include_spip('inc/filtres_mini');
    $url = url_absolue(self());

    // verifier le token
    $_token = calculer_token_previsu($url, $id_auteur, 'alea_ephemere');
    if (!$_token or $token !== $_token) {
        $_token = calculer_token_previsu($url, $id_auteur, 'alea_ephemere_ancien');
        if (!$_token or $token !== $_token) {
            return false;
        }
    }

    return array(
        'id_auteur' => $id_auteur,
    );
}

/**
 * Décrire un token de prévisu en session
 * @uses verifier_token_previsu()
 * @return bool|array
 */
function decrire_token_previsu() {
    static $desc = null;
    if (is_null($desc)) {
        if ($token = _request('var_previewtoken')) {
            $desc = verifier_token_previsu($token);
        } else {
            $desc = false;
        }
    }
    return $desc;
}
