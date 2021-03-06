<?php

/**
 * Page de déparrage du framework
 * @package FrameTool
 */
/*
 * En cas de lancement direct, arrêt de la procédure
 */
if (!defined('NPE_INDEX'))
    die('Initialisation incorrect');

/**
 * Détection du mode d'execution
 */
define('CONSOLE', (isset($argv) || isset($argc)) && !isset($_SERVER['HTTP_HOST']));

/**
 * Racourci pour le DIRECTORY_SEPARATOR
 */
define('DS', DIRECTORY_SEPARATOR);

/**
 * Répertoire racine du projet
 */
$root = dirname(__FILE__) . DS;

if (!CONSOLE) {
    session_name('BILLID');
    session_start();
}

$tmpdir .= DS;
$urlbase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . $urlbase;


/**
 * Usage de composer
 */
$loader = require_once $root . '..' . DS . 'vendor' . DS . 'autoload.php';

/**
 * Définition des fonts
 */
define('FPDF_FONTPATH', $root . 'libs' . DS . 'font' . DS);
setlocale(LC_ALL, 'fr_FR.utf8');

/**
 * Ouverture du fichier avec les fonctions de base de FrameTool
 */
require_once $root . 'libs' . DS . 'common.php';
require_once $root . 'libs' . DS . 'models.php';
require_once $root . 'libs' . DS . 'extend.php';

//Initialisation du PDO
$pdo = new PDO($dsn, $db_user, $db_pass);

// Initialisation du système de template


/**
 * Chargement du moteur de template Smarty
 */
//include $root . 'libs' . DS . 'Smarty' . DS . 'Smarty.class.php';

$tpl = new Smarty();
$tpl->compile_dir = $tmpdir;
$tpl->template_dir = $root . 'templates';
$tpl->registerPlugin('function', 'mkurl', 'mkurl_smarty');
$tpl->registerPlugin('function', 'mkmenu', 'mkmenu_smarty');
$tpl->registerPlugin('block', 'acl', 'acl_smarty');

if (!is_dir($tpl->compile_dir))
    @mkdir($tpl->compile_dir, 0777);

if (!is_writable($tpl->compile_dir))
    chmod($tpl->compile_dir, 0777);

// Etape 1, on charge la configuration sur l'environnement présent.
$cfg = get_configs();
$config = array();
foreach ($cfg as $cfgPart) {
    $config[$cfgPart['name']] = array();
    foreach ($cfgPart['fields'] as $field) {
        $config[$cfgPart['name']][$field['name']] = isset($field['default']) ? $field['default'] : null;
    }
}

$conf = $pdo->prepare("SELECT * FROM config WHERE env is NULL OR env = ?");
$conf->bindValue(1, $env);
$conf->execute();
while ($dat = $conf->fetch()) {
    $parts = explode('!!', $dat['name']);
    $config[$parts[0]][$parts[1]] = $dat['value'];
}

//CSRF Whitelist
$CSRF_withelist = array(
    'index' => array(
        'index',
        'login',
    ),
    'api' => array(
        'authorize',
    ),
    'wifi' => array(
        'login',
    ),
);

//Etapes seulement si HTTP
if (!CONSOLE) {


    if (!isset($action)) {
        // Etape 2, calcul du chemin d'execution
        if (!isset($_REQUEST['action']))
            redirect('index');

        $action = null;
        if (isset($_GET['action']))
            $action = $_GET['action'];
        $action = basename($action);
    }

    if (!isset($page)) {
        $page = 'index';
        if (isset($_GET['page']))
            $page = $_GET['page'];
        $page = basename($page);
    }

    // Recherche du module ...
    if (Extend::getAction($action) == false && !file_exists($root . 'action' . DS . $action . '.php')) {
        $action = 'syscore';
        $page = 'nomod';
    }

    // Etape 3, vérification des droits d'accès
    if (!isset($_SESSION['user'])) {
        $_SESSION['user'] = false;
    }
    $tpl->assign('_user', $_SESSION['user']);
    if ($_SESSION['user']) {
        $sections = $pdo->prepare('SELECT * FROM user_sections LEFT JOIN sections ON us_section = section_id WHERE us_user = ?');
        $sections->bindValue(1, $_SESSION['user']['user_id']);
        $sections->execute();
        $_SESSION['user']['sections'] = array();
        while ($line = $sections->fetch()) {
            $_SESSION['user']['sections'][$line['section_id']] = $line;
        }

        //Données de base
        $basketCount = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE t_status = "TMP" and t_user = ?');
        $basketCount->bindValue(1, $_SESSION['user']['user_id']);
        $basketCount->execute();
        $tpl->assign('bascketCnt', $basketCount->fetchColumn(0));
    }


    modsecu($action, $page, $_GET);
    needAcl(getAclLevel($action, $page), $action, $page, $_GET);

    // Etape 4 lancement du module
    modexec($action, $page);
    modexec('syscore', 'moderror');
    quit();
}
