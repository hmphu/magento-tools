<?php

require_once("filters.php");
require_once("interaction.php");
require_once("xml.php");

// get the classname contained by a file.
function file_to_class( $file ) {
    return exec("cat $file | grep \"^class\" | sed -e \"s/^ *class \([a-zA-Z_]*\) .*/\\1/\"");
}

// get the classname that a handle represents.
function handle_to_class( $handle, $type ) {
    // try to let magento find it for us
    if($class = get_magento_class($handle, $type)) {
        return $class;
    }

    list( $module, $class )   = explode("/", $handle);  
    list( $codepool, $company, $module ) = explode("/", module_path($module));

    $type                   = ucfirst($type);
    $class                  = str_replace(" ", "_", ucwords(str_replace("_", " ", $class)));
 
    return "{$company}_{$module}_{$type}_{$class}";
}//end handle_to_class

// keep in mind that this may be called on not-yet-existant classes
function get_magento_class($handle, $type, $manual = false) {
    init_magento();

    try {
        switch($type) {
            case 'block':
                return Mage::getConfig()->getBlockClassName($handle);
            case 'model':
                return get_class(Mage::getModel($handle));
            default:
                throw new Exception("for the hell of it");
        }
    } catch(Exception $e) {
        return _get_magento_class_manual($handle, $type);
    }
}

// HACKS!
function _get_magento_class_manual($handle, $type) {
    list( $module, $class ) = explode("/", $handle);  
    list( $codepool, $company, $module )  =  explode("/", module_path($module));
    $class = str_replace(" ", "_", ucwords(str_replace("_", " ", $class)));
    $type = ucfirst($type);
    return "{$company}_{$module}_{$type}_{$class}";
}

// get the controller that a handle represents.
function handle_to_controller( $handle ) {
    list( $module, $class )               = explode("/", $handle);  
    list( $codepool, $company, $module )  =  explode("/", module_path($module));
    $class                  = str_replace(" ", "_", ucwords(str_replace("_", " ", $class)));

    return "{$company}_{$module}_{$class}Controller";
}

function class_to_handle( $class ) {
    $matches = array();
    if(!preg_match("#[a-z]+_([a-z]+)_[a-z]+_([a-z_]+)#i", $class, $matches)) {
        return null;
    }

    return strtolower("{$matches[1]}/{$matches[2]}");
}

// return the path to magento
function magento_path() {
    global $magento; // this is better than passing it 100 times
    return $magento;
}

// get module path information w/ an overly generic name. returns codepool/Company/ModuleName
function module_path($target) {
    $magento = magento_path();
    return find_path($target, "$magento/app/code/*/*", "$magento/app/code/");
}

// get module path inside of LOCAL only, but match only on partial name
function fuzzy_module_path($target) {
    $magento = magento_path();
    return find_path($target, "$magento/app/code/local/*", "$magento/app/code/", false, true);
}

// get something that looks remotely like the path to something
// else. take no comfort in this.
function horrible_path($target) {
    $magento = magento_path();

    $opts = `find $magento/app/code | grep -v .svn | grep -i "$target$"`;
    if(!$opts) {
        throw new Exception("Couldn't find anything even close to that...");
    }

    return explode("\n", trim($opts));
}

// check if a module has already been created
function module_exists($target) {
    $magento = magento_path();
    return find_path($target, "$magento/app/code/*/*", null, true) > 0;
}

// get the module that we're currently in
function current_module($code_pool = "[a-z]+") {
    $magento    = magento_path();
    $pwd        = `pwd`;

    $relative   = str_replace($magento, "", $pwd);
    $match      = array();
    if(!preg_match("#/app/code/$code_pool/[a-z]+/([a-z]+)#i", $relative, $match)) {
        return null;
    }

    return $match[1];
}

// get the company dir from some generalized handle. returns codepool/Company
function company_path($target) {
    $magento = magento_path();
    return find_path($target, "$magento/app/code/*", "$magento/app/code/");
}

// case-insensitively find a dir that is a subdirectory of some other dir. return the path to it.
function find_path($target, $search, $trim = null, $return_count = false, $use_fuzzy = false) {
    if(false !== strpos($target, "/")) {
        throw new Exception("Directory names don't contain slashes. Can't search for $target");
    }

    if($use_fuzzy) { $target = "*$target*"; }

    $cmd = "find $search -maxdepth 1 -mindepth 1 -iname \"$target\" -type d";
    //print "$cmd\n";
    $res = `$cmd`;

    $res = explode("\n", $res);
    array_pop($res); // get rid of trailing newline

    if($return_count) { return count($res); }

    if(0 == count($res)) {
        throw new Exception("Couldn't find target dir $target in $search");
    }

    // this is a bad place for this, but prefer core over other paths
    $result = array_shift($res);
    foreach($res as $r) {
        if(false !== strpos($r, "/core")) {
            $result = $r;
        }
    }

    $result = str_replace($trim, "", $result);
    return $result;
}

// given a handle (like catalog/product), get the full directory path to the PHP file that houses it
function handle_to_path( $handle, $type = "model" ) {
    if(false === strpos($handle, "/")) {
        throw new Exception("$handle isn't a valid class handle");
    }

    list($plugin, $model) = explode("/", $handle);
    $plugin_path    = module_path($plugin);

    $magento        = magento_path();
    $type           = (0 == strcmp("controller", $type))? "{$type}s": ucfirst($type);
    $full_path      = "$magento/app/code/$plugin_path/$type";

    $dir            = "";
    if(false !== strpos($model, "_")) {
        $dir        = "/".str_replace(" ", "/", ucwords(str_replace("_", " ", substr($model, 0, strrpos($model, "_")))));
    }

    return "$full_path$dir";
}

// get the filename that a handle should represent. for checkout/cart_item_renderer. returns Renderer.php
function handle_to_file( $handle, $type ) {
    if(false !== strpos($handle, "_")) {
        $handle = substr($handle, strrpos($handle, "_")+1);
    } else if(false !== strpos($handle, "/")) {
        $handle = substr($handle, strpos($handle, "/")+1);
    }

    $filename = ucfirst($handle);
    if(0 == strcmp("controller", $type)) {
        $filename = "{$filename}Controller";     
    }

    return "$filename.php";
}

// create necessary parent directories for a path
function create_path($path) {
    @mkdir($path, 0777, true);
}

function parse_opts($args) {
    $opts = array();
    foreach($args as $arg) {
        $match      = array();
        if(preg_match_all("/--([a-z_-]+)=(.*)/", $arg, $match)) {
            $key = $match[1][0];
            $val = $match[2][0];
            $opts[$key] = $val;
        }
    }

    return $opts;
}

function get_string_params($string) {
    $matches    = array();
    if(!preg_match_all("/<!--@(\w+)(.*?)@-->/ism", $string, $matches)) {
        return array();
    }

    $ret    = array();
    for($i = 0, $count = count($matches[1]); $i < $count; $i++) {
        $ret[$matches[1][$i]] = str_replace("\n", " ", trim($matches[2][$i]));
    }

    return $ret;
}

function get_hash($string, $salt_digits) {
    init_magento();
    $salt = "";
    for($i = 0; $i < $salt_digits; $i++) {
        $salt .= chr(rand(65,90));
    }

    return Mage::helper("core")->getHash($string, $salt);
}                                                                                                                            

function get_edition() {
    $magento = magento_path();
    if(file_exists("$magento/LICENSE_EE.txt")) {
        $edition = "enterprise";
    } else {
        $edition = "community";
    }

    return $edition;
}

function module_version($company, $module) {
    init_magento();
    return (string)Mage::getConfig()->getNode("modules/{$company}_{$module}/version");
}

function dump_object($object) {
    foreach($object->getData() as $key => $value) {
        if(is_object($value)) {
            $value = get_class($value);
        } else if(is_string($value) && strlen($value) > 20) {
            $value = "string (".strlen($value).") \"".substr($value, 0, 20)."...\"";
        } else if(is_array($value)) {
            $value = "array";
        } else {
            $value = var_export($value, true);
        }

        printf("%-40s%s\n", $key, $value);
    }
}
