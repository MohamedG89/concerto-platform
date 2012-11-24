<?php

/*
  Concerto Platform - Online Adaptive Testing Platform
  Copyright (C) 2011-2012, The Psychometrics Centre, Cambridge University

  This program is free software; you can redistribute it and/or
  modify it under the terms of the GNU General Public License
  as published by the Free Software Foundation; version 2
  of the License, and not any of the later versions.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

class TestSession extends OTable {

    public static $mysql_table_name = "TestSession";
    public $Test_id = 0;
    public $status = 0;
    public $time_limit = 0;
    public $HTML = "";
    public $Template_id = 0;
    public $time_tamper_prevention = 0;
    public $hash = "";
    public $debug = 0;
    public $release = 0;
    public $output = "";
    public $state = "";

    const TEST_SESSION_STATUS_NEW = 0;
    const TEST_SESSION_STATUS_WORKING = 1;
    const TEST_SESSION_STATUS_TEMPLATE = 2;
    const TEST_SESSION_STATUS_COMPLETED = 3;
    const TEST_SESSION_STATUS_ERROR = 4;
    const TEST_SESSION_STATUS_TAMPERED = 5;
    const TEST_SESSION_STATUS_WAITING = 6;
    const TEST_SESSION_STATUS_SERIALIZED = 7;

    public function get_Test() {
        return Test::from_mysql_id($this->Test_id);
    }

    public function get_Template() {
        return Template::from_mysql_id($this->Template_id);
    }

    public function register() {
        if (array_key_exists("sids", $_SESSION)) {
            if (array_key_exists(session_id(), $_SESSION['sids'])) {
                TestSession::unregister($_SESSION['sids'][session_id()]);
                $_SESSION['sids'][session_id()] = $this->id;
            }
            else
                $_SESSION['sids'][session_id()] = $this->id;
        }
        else {
            $_SESSION['sids'] = array();
            $_SESSION['sids'][session_id()] = $this->id;
        }
    }

    public static function unregister($id) {
        $obj = TestSession::from_mysql_id($id);
        if ($obj != null)
            $obj->remove();
        unset($_SESSION['sids'][session_id()]);
    }

    public static function start_new($test_id, $debug = false) {
        $session = new TestSession();
        $session->Test_id = $test_id;
        $session->debug = ($debug ? 1 : 0);

        $lid = $session->mysql_save();

        if (!$debug) {
            $sql = sprintf("UPDATE `%s` SET `session_count`=`session_count`+1 WHERE `%s`.`id`=%d", Test::get_mysql_table(), Test::get_mysql_table(), $test_id);
            mysql_query($sql);
        }

        $session = TestSession::from_mysql_id($lid);
        if ($debug)
            $session->register();
        return $session;
    }

    public function remove() {
        $this->close();
        $this->mysql_delete();
    }

    public function mysql_delete() {
        parent::mysql_delete();
        $this->remove_returns();
    }

    public function remove_returns() {
        $sql = sprintf("DELETE FROM `%s` WHERE `TestSession_id`=%d", TestSessionReturn::get_mysql_table(), $this->id);
        mysql_query($sql);
    }

    public function close() {
        if (TestServer::is_running())
            TestServer::send("close:" . $this->id);
        $this->remove_files();
    }

    public function serialize() {
        if (TestServer::is_running())
            TestServer::send("serialize:" . $this->id);
    }

    public function remove_files() {
        if (file_exists($this->get_RSession_file_path()))
            unlink($this->get_RSession_file_path());
    }

    public function does_RSession_file_exists() {
        if (file_exists($this->get_RSession_file_path()))
            return true;
        else
            return false;
    }

    public function RCall($values = null, $code = null, $resume_from_last_template = false) {

        $test = Test::from_mysql_id($this->Test_id);
        $loader = $test->get_loader_Template();

        //resume from last template
        if ($resume_from_last_template) {
            $template = $this->get_Template();

            if ($template != null) {
                $response = array(
                    "data" => array(
                        "HEAD" => $template->head,
                        "HASH" => $this->hash,
                        "TIME_LIMIT" => $this->time_limit,
                        "HTML" => $this->html,
                        "TEST_ID" => $this->Test_id,
                        "TEST_SESSION_ID" => $this->id,
                        "STATUS" => $this->status,
                        "TEMPLATE_ID" => $this->Template_id,
                        "FINISHED" => $this->finished,
                        "EFFECT_SHOW" => $template->effect_show,
                        "EFFECT_HIDE" => $template->effect_hide,
                        "EFFECT_SHOW_OPTIONS" => $template->effect_show_options,
                        "EFFECT_HIDE_OPTIONS" => $template->effect_hide_options,
                        "LOADER_HTML" => "",
                        "LOADER_HEAD" => "",
                        "LOADER_EFFECT_SHOW" => "none",
                        "LOADER_EFFECT_SHOW_OPTIONS" => "",
                        "LOADER_EFFECT_HIDE" => "none",
                        "LOADER_EFFECT_HIDE_OPTIONS" => "",
                    )
                );

                if ($loader != null) {
                    $response["data"]["LOADER_HTML"] = $loader->HTML;
                    $response["data"]["LOADER_HEAD"] = $loader->head;
                    $response["data"]["LOADER_EFFECT_SHOW"] = $loader->effect_show;
                    $response["data"]["LOADER_EFFECT_SHOW_OPTIONS"] = $loader->effect_show_options;
                    $response["data"]["LOADER_EFFECT_HIDE"] = $loader->effect_hide;
                    $response["data"]["LOADER_EFFECT_HIDE_OPTIONS"] = $loader->effect_hide_options;
                }
                return $response;
            }
        }

        //R server connection
        $command_obj = json_encode(array(
            "session_id" => $this->id,
            "hash" => $this->hash,
            "values" => $values,
            "code" => $code
                ));

        if (TestServer::$debug)
            TestServer::log_debug("TestSession->RCall --- checking for server");
        if (!TestServer::is_running())
            TestServer::start_process();
        if (TestServer::$debug)
            TestServer::log_debug("TestSession->RCall --- server found, trying to send");

        $response = TestServer::send($command_obj);
        $result = json_decode(trim($response));
        if (TestServer::$debug)
            TestServer::log_debug("TestSession->RCall --- sent and recieved response");

        $status = TestSession::TEST_SESSION_STATUS_ERROR;
        $removed = false;
        $release = 0;
        $html = "";
        $head = "";
        $Template_id = 0;
        $debug = 0;
        $hash = "";
        $time_limit = 0;
        $Test_id = 0;
        $finished = 0;

        $loader_HTML = "";
        $loader_head = "";
        $loader_effect_show = "none";
        $loader_effect_hide = "none";
        $loader_effect_show_options = "";
        $loader_effect_hide_options = "";

        $effect_show = "none";
        $effect_hide = "none";
        $effect_show_options = "";
        $effect_hide_options = "";
        $state = "[]";

        $thisSession = TestSession::from_mysql_id($this->id);

        $return = $result->return;

        if ($thisSession != null) {

            $output = explode("\n", $thisSession->output);
            $state = $thisSession->state;

            $status = $thisSession->status;
            $release = $thisSession->release;
            $html = $thisSession->HTML;
            $Template_id = $thisSession->Template_id;
            $debug = $thisSession->debug;
            $hash = $thisSession->hash;
            $time_limit = $thisSession->time_limit;
            $Test_id = $thisSession->Test_id;

            if ($loader != null) {
                $loader_HTML = $loader->HTML;
                $loader_head = $loader->head;
                $loader_effect_hide = $loader->effect_hide;
                $loader_effect_hide_options = $loader->effect_hide_options;
                $loader_effect_show = $loader->effect_show;
                $loader_effect_show_options = $loader->effect_show_options;
            }

            $template = Template::from_mysql_id($thisSession->Template_id);

            if ($template != null) {
                $effect_hide = $$template->effect_hide;
                $effect_hide_options = $template->effect_hide_options;
                $effect_show = $template->effect_show;
                $effect_show_options = $template->effect_show_options;
            }

            if ($return != 0) {
                $status = TestSession::TEST_SESSION_STATUS_ERROR;
            }

            if ($status == TestSession::TEST_SESSION_STATUS_WORKING && $release == 1 || $close)
                $status = TestSession::TEST_SESSION_STATUS_COMPLETED;

            $thisSession->status = $status;
            $thisSession->mysql_save();

            switch ($status) {
                case TestSession::TEST_SESSION_STATUS_COMPLETED: {
                        if ($debug) {
                            TestSession::unregister($thisSession->id);
                            $removed = true;
                        }
                        else
                            $thisSession->serialize();
                        break;
                    }
                case TestSession::TEST_SESSION_STATUS_ERROR:
                case TestSession::TEST_SESSION_STATUS_TAMPERED: {
                        if ($debug) {
                            TestSession::unregister($thisSession->id);
                            $removed = true;
                        }
                        else
                            $thisSession->close();
                        break;
                    }
                case TestSession::TEST_SESSION_STATUS_TEMPLATE: {
                        if ($debug) {
                            $html = Template::strip_html($html);
                            if ($release)
                                TestSession::unregister($thisSession->id);
                        }
                        else {
                            $head = Template::from_mysql_id($Template_id)->head;
                            if ($release)
                                $thisSession->serialize();
                        }
                        break;
                    }
            }
        }
        else
            $removed = true;

        $debug_data = false;
        $logged_user = User::get_logged_user();
        if ($logged_user != null)
            $debug_data = $logged_user->is_object_readable($test);

        if ($release == 1 || $status == TestSession::TEST_SESSION_STATUS_COMPLETED || $status == TestSession::TEST_SESSION_STATUS_ERROR || $status == TestSession::TEST_SESSION_STATUS_TAMPERED) {
            $finished = 1;
        }

        $response = array(
            "data" => array(
                "HEAD" => $head,
                "HASH" => $hash,
                "TIME_LIMIT" => $time_limit,
                "HTML" => $html,
                "TEST_ID" => $Test_id,
                "TEST_SESSION_ID" => $this->id,
                "STATUS" => $status,
                "TEMPLATE_ID" => $Template_id,
                "FINISHED" => $finished,
                "LOADER_HTML" => $loader_HTML,
                "LOADER_HEAD" => $loader_head,
                "LOADER_EFFECT_SHOW" => $loader_effect_show,
                "LOADER_EFFECT_SHOW_OPTIONS" => $loader_effect_show_options,
                "LOADER_EFFECT_HIDE" => $loader_effect_hide,
                "LOADER_EFFECT_HIDE_OPTIONS" => $loader_effect_hide_options,
                "EFFECT_SHOW" => $effect_show,
                "EFFECT_HIDE" => $effect_hide,
                "EFFECT_SHOW_OPTIONS" => $effect_show_options,
                "EFFECT_HIDE_OPTIONS" => $effect_hide_options
            )
        );

        if ($debug_data) {
            for ($i = 0; $i < count($output); $i++) {
                if (strpos($output[$i], "CONCERTO_DB_PASSWORD <-") !== false)
                    $output[$i] = "[hidden]";
                $output[$i] = htmlspecialchars($output[$i], ENT_QUOTES);
            }

            if (!is_array($response))
                $response = array();
            $response["debug"] = array(
                "return" => $return,
                "output" => $output,
                "state" => $state
            );
        }

        if (Ini::$timer_tamper_prevention && !$removed) {
            $sql = sprintf("UPDATE `%s` SET `time_tamper_prevention`=%d WHERE `id`=%d", TestSession::get_mysql_table(), time(), $this->id);
            mysql_query($sql);
        }

        return $response;
    }

    public function get_RSession_file_path() {
        return Ini::$path_temp . $this->get_Test()->Owner_id . "/session_" . $this->id . ".Rs";
    }

    public function mysql_save() {
        $new = false;
        if ($this->id == 0)
            $new = true;
        $lid = parent::mysql_save();
        if ($new) {
            $ts = TestSession::from_mysql_id($lid);
            $ts->hash = TestSession::generate_hash($lid);
            $ts->mysql_save();
        }
        return $lid;
    }

    public static function generate_hash($id) {
        return md5("cts" . $id . "." . rand(0, 100) . "." . time());
    }

    public static function authorized_session($id, $hash) {
        $session = TestSession::from_property(array("id" => $id, "hash" => $hash), false);
        if ($session == null)
            return null;
        switch ($session->status) {
            case TestSession::TEST_SESSION_STATUS_ERROR: return null;
            case TestSession::TEST_SESSION_STATUS_TAMPERED: return null;
            case TestSession::TEST_SESSION_STATUS_COMPLETED: return null;
        }
        return $session;
    }

    public static function forward($tid, $sid, $hash, $values, $btn_name, $debug, $time, $resume_from_last_template = false) {
        $session = null;
        $result = array();
        if ($sid != null && $hash != null) {
            $session = TestSession::authorized_session($sid, $hash);

            if ($session != null) {
                if ($values == null)
                    $values = array();

                if ($btn_name != null) {
                    array_push($values, json_encode(array(
                                "name" => "LAST_PRESSED_BUTTON_NAME",
                                "value" => $btn_name
                            )));
                }

                if (Ini::$timer_tamper_prevention && $session->time_limit > 0 && $time - $session->time_tamper_prevention - Ini::$timer_tamper_prevention_tolerance > $session->time_limit) {
                    if ($session->debug == 1)
                        TestSession::unregister($session->id);
                    else
                        $session->close();

                    $result = array(
                        "data" => array(
                            "HASH" => $hash,
                            "TIME_LIMIT" => 0,
                            "HTML" => "",
                            "TEST_ID" => 0,
                            "TEST_SESSION_ID" => $sid,
                            "STATUS" => TestSession::TEST_SESSION_STATUS_TAMPERED,
                            "TEMPLATE_ID" => 0,
                            "HEAD" => "",
                            "FINISHED" => 1
                        )
                    );
                    if ($session->debug == 1) {
                        $result["debug"] = array(
                            "return" => 0,
                            "output" => "",
                            "state" => "[]"
                        );
                    }
                } else {
                    $result = $session->RCall($values, null, $resume_from_last_template);
                }
            } else {
                $result = array(
                    "data" => array(
                        "HASH" => $hash,
                        "TIME_LIMIT" => 0,
                        "HTML" => "",
                        "TEST_ID" => 0,
                        "TEST_SESSION_ID" => $sid,
                        "STATUS" => TestSession::TEST_SESSION_STATUS_TAMPERED,
                        "TEMPLATE_ID" => 0,
                        "HEAD" => "",
                        "FINISHED" => 1
                    ),
                    "debug" => array(
                        "return" => 0,
                        "output" => "",
                        "state" => "[]"
                    )
                );
            }
        } else {
            if ($tid != null) {
                if ($debug == 1)
                    $debug = true;
                else
                    $debug = false;
                $session = TestSession::start_new($tid, $debug);

                if ($values == null)
                    $values = array();

                $test = $session->get_Test();
                if ($test != null) {
                    $values = $test->verified_input_values($values);
                } else {
                    $result = array(
                        "data" => array(
                            "HASH" => $hash,
                            "TIME_LIMIT" => 0,
                            "HTML" => "",
                            "TEST_ID" => $tid,
                            "TEST_SESSION_ID" => $sid,
                            "STATUS" => TestSession::TEST_SESSION_STATUS_TAMPERED,
                            "TEMPLATE_ID" => 0,
                            "HEAD" => "",
                            "FINISHED" => 1
                        ),
                        "debug" => array(
                            "return" => 0,
                            "output" => "",
                            "state" => "[]"
                        )
                    );
                    return $result;
                }

                $result = $result = $session->RCall($values, null, $resume_from_last_template);
            }
        }
        return $result;
    }

    public static function create_db($delete = false) {
        if ($delete) {
            if (!mysql_query("DROP TABLE IF EXISTS `TestSession`;"))
                return false;
        }
        $sql = "
            CREATE TABLE IF NOT EXISTS `TestSession` (
            `id` bigint(20) NOT NULL auto_increment,
            `updated` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
            `created` timestamp NOT NULL default '0000-00-00 00:00:00',
            `Test_id` bigint(20) NOT NULL,
            `status` tinyint(4) NOT NULL,
            `time_limit` int(11) NOT NULL,
            `HTML` text NOT NULL,
            `Template_id` bigint(20) NOT NULL,
            `time_tamper_prevention` INT NOT NULL,
            `hash` text NOT NULL,
            `debug` tinyint(1) NOT NULL,
            `release` tinyint(1) NOT NULL,
            `output` longtext NOT NULL,
            `state` longtext NOT NULL,
            PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB  DEFAULT CHARSET=utf8;
            ";
        return mysql_query($sql);
    }

}

?>