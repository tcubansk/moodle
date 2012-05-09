<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Google Docs Plugin
 *
 * @since 2.0
 * @package    repository
 * @subpackage googledocs
 * @copyright  2009 Dan Poltawski <talktodan@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir.'/googleapi.php');

class repository_googledocs extends repository {
    private $subauthtoken = '';
    private $usesecuretoken = false;
    private $googleapikey = '';

    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        global $USER;
        parent::__construct($repositoryid, $context, $options);

	$this->googleapikey = get_config('googledocs', 'googleapikey');
	$this->usesecuretoken = get_config('googledocs', 'usesecure');

        // TODO: I wish there was somewhere we could explicitly put this outside of constructor..
        $googletoken = optional_param('token', false, PARAM_RAW);
        if($googletoken){
            $gauth = $this->get_google_authsub(false, $googletoken); // will throw exception if fails
            google_docs::set_sesskey($gauth->get_sessiontoken(), $USER->id);
        }
        $this->check_login();
    }

    private function get_google_authsub($sessiontoken = '', $authtoken = '', $options = array()) {
        if ($this->usesecuretoken) {
            return new google_authsub($sessiontoken, $authtoken, $options, $this->googleapikey);
        } else {
            return new google_authsub($sessiontoken, $authtoken, $options, '');
        }
    }

    public function check_login() {
        global $USER;

        $sesskey = google_docs::get_sesskey($USER->id);

        if($sesskey){
            try{
                $gauth = $this->get_google_authsub($sesskey);
                $this->subauthtoken = $sesskey;
                return true;
            }catch(Exception $e){
                // sesskey is not valid, delete store and re-auth
                google_docs::delete_sesskey($USER->id);
            }
        }

        return false;
    }

    public function print_login($ajax = true){
        global $CFG;
        if($ajax){
            $ret = array();
            $popup_btn = new stdClass();
            $popup_btn->type = 'popup';
            $returnurl = $CFG->wwwroot.'/repository/repository_callback.php?callback=yes&repo_id='.$this->id;
            $popup_btn->url = google_authsub::login_url($returnurl, google_docs::REALM, get_config('googledocs', 'usesecure'));
            $ret['login'] = array($popup_btn);
            return $ret;
        }
    }

    public function get_listing($path='', $page = '') {
        $gdocs = new google_docs($this->get_google_authsub($this->subauthtoken));

        $ret = array();
        $ret['dynload'] = true;
        $ret['list'] = $gdocs->get_file_list();
        return $ret;
    }

    public function search($search_text, $page = 0) {
        $gdocs = new google_docs($this->get_google_authsub($this->subauthtoken));

        $ret = array();
        $ret['dynload'] = true;
        $ret['list'] = $gdocs->get_file_list($search_text);
        return $ret;
    }

    public function logout(){
        global $USER;

        $token = google_docs::get_sesskey($USER->id);

        $gauth = $this->get_google_authsub($this->subauthtoken);
        // revoke token from google
        $gauth->revoke_session_token();

        google_docs::delete_sesskey($USER->id);
        $this->subauthtoken = '';

        return parent::logout();
    }

    public function get_file($url, $file = '') {
        global $CFG;
        $path = $this->prepare_file($file);

        $fp = fopen($path, 'w');
        $gdocs = new google_docs($this->get_google_authsub($this->subauthtoken));
        $gdocs->download_file($url, $fp);

        return array('path'=>$path, 'url'=>$url);
    }

    public function supported_filetypes() {
       return array('document');
    }
    public function supported_returntypes() {
        return FILE_INTERNAL;
    }

    public static function type_config_form($mform, $classname='repository_googledocs') {
        global $CFG;
        parent::type_config_form($mform);
        $use_secure_text = get_string('usesecure', 'repository_googledocs');
        $googleapikey_text = get_string('googleapikey', 'repository_googledocs');

        $use_secure = get_config('googledocs', 'usesecure');
        $googleapikey = get_config('googledocs', 'googleapikey');

        if (empty($use_secure)) {
            $use_secure = '';
        }
        if (empty($googleapikey)) {
            $googleapikey = '';
        }

        $mform->addElement('checkbox', 'usesecure', $use_secure_text, '', array('value' => $use_secure));
        $mform->addElement('textarea', 'googleapikey', $googleapikey_text, '', array('value' => $googleapikey));
    }

    public static function get_type_option_names() {
        return array('usesecure', 'googleapikey', 'pluginname');
    }
}
//Icon from: http://www.iconspedia.com/icon/google-2706.html
