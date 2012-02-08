<?php

class User extends OModule
{
    public $login = "incognito";
    public $firstname = "";
    public $lastname = "unknown";
    public $email = "";
    public $phone = "";
    public $md5_password = "";
    public $UserType_id = 0;
    public $UserGroup_id = 0;
    public $last_activity = "";
    public static $mysql_table_name = "User";
    
    public function __construct($params = array())
    {
        $this->login = Language::string(77);
        $this->lastname = Language::string(78);
        parent::__construct($params);
    }

    public function has_UserGroup()
    {
        if ($this->UserGroup_id != 0) return true;
        else return false;
    }

    public function has_UserType()
    {
        if ($this->UserType_id != 0) return true;
        else return false;
    }

    public function get_UserGroup()
    {
        return UserGroup::from_mysql_id($this->UserGroup_id);
    }

    public function get_UserType()
    {
        return UserType::from_mysql_id($this->UserType_id);
    }

    public static function get_logged_user()
    {
        if (isset($_SESSION['ptap_logged_login']) && isset($_SESSION['ptap_logged_md5_password']))
        {
            $user = self::from_property(array(
                        "login" => $_SESSION['ptap_logged_login'],
                        "md5_password" => $_SESSION['ptap_logged_md5_password']
                            ), false);
            if ($user != null)
            {
                $user->last_activity = date("Y-m-d H:i:s");
                $user->mysql_save();
                return $user;
            }
        }
        return null;
    }

    public static function log_in($login, $password)
    {
        $user = self::from_property(array(
                    "login" => $login,
                    "md5_password" => md5($password)
                        ), false);
        if ($user != null)
        {
            $_SESSION['ptap_logged_login'] = $login;
            $_SESSION['ptap_logged_md5_password'] = md5($password);
        }
        return $user;
    }

    public static function log_out()
    {
        unset($_SESSION['ptap_logged_login']);
        unset($_SESSION['ptap_logged_md5_password']);
    }

    public function get_full_name()
    {
        $name = $this->firstname . " " . $this->lastname;
        if (trim($name) == "") $name = "&lt;" . $this->email . "&gt;";
        return $name;
    }

    public function mysql_delete()
    {
        $this->clear_object_links(UserType::get_mysql_table(), "Owner_id");
        $this->clear_object_links(UserGroup::get_mysql_table(), "Owner_id");
        $this->clear_object_links(Template::get_mysql_table(), "Owner_id");
        $this->clear_object_links(Table::get_mysql_table(), "Owner_id");
        $this->clear_object_links(Test::get_mysql_table(), "Owner_id");
        $this->clear_object_links(CustomSection::get_mysql_table(), "Owner_id");
        $this->mysql_delete_object();
    }

    public function mysql_save_from_post($post)
    {
        if ($post['modify_password'] == 1)
                $post['md5_password'] = md5($post['password']);
        $post['oid'] = parent::mysql_save_from_post($post);

        if ($this->id == 0)
        {
            $obj = self::from_mysql_id($post['oid']);
            $obj->Owner_id = $post['oid'];
            $obj->mysql_save();
        }
        return $post['oid'];
    }

    public function is_module_accesible($table_name)
    {
        return $this->is_module_permission_level_accesible($table_name, "r", 2);
    }

    public function is_module_writeable($table_name)
    {
        return $this->is_module_permission_level_accesible($table_name, "w", 2);
    }

    public function is_module_permission_level_accesible($table_name, $rw, $value)
    {
        if (!$this->has_UserType()) return false;
        $right = $this->get_UserType()->get_rights_by_module_table($table_name);
        if ($rw == "r" && $right->read >= $value) return true;
        if ($rw == "w" && $right->write >= $value) return true;
        return false;
    }

    public function get_module_permission_value($table_name, $rw)
    {
        if (!$this->has_UserType()) return 0;
        $right = $this->get_UserType()->get_rights_by_module_table($table_name);
        if ($rw == "r") return $right->read;
        if ($rw == "w") return $right->write;
        if ($rw == "o") return $right->ownership;
        return 0;
    }

    public function is_ownerhsip_changeable($obj)
    {
        $val = $this->get_module_permission_value($obj->get_mysql_table(), "o");
        if ($val == 1) return true;
        else return false;
    }
    
    public function is_object_accessible($obj,$rw)
    {
        $val = $this->get_module_permission_value($obj::get_mysql_table(), $rw);
        switch ($val)
        {
            case 1:
                {
                    return false;
                    break;
                }
            case 2:
                {
                    if ($obj->has_Owner())
                    {
                        if ($obj->get_Owner()->id == $this->id) return true;
                    }
                    return false;
                    break;
                }
            case 3:
                {
                    if ($obj->has_Owner())
                    {
                        if ($obj->get_Owner()->id == $this->id) return true;
                    }

                    if ($obj->Sharing_id >= 2)
                    {
                        if ($obj->has_Owner())
                        {
                            if ($obj->get_Owner()->has_UserGroup())
                            {
                                if ($obj->get_Owner()->get_UserGroup()->id == $this->UserGroup_id)
                                        return true;
                            }
                        }
                    }

                    if ($obj->Sharing_id == 3) return true;
                    return false;
                    break;
                }
            case 4:
                {
                    if ($obj->has_Owner())
                    {
                        if ($obj->get_Owner()->id == $this->id) return true;
                    }

                    if ($obj->has_Owner())
                    {
                        if ($obj->get_Owner()->has_UserGroup())
                        {
                            if ($obj->get_Owner()->get_UserGroup()->id == $this->UserGroup_id)
                                    return true;
                        }
                    }

                    if ($obj->Sharing_id == 3) return true;
                    break;
                }
            case 5:
                {
                    return true;
                    break;
                }
        }
        return false;
    }

    public function is_object_editable($obj)
    {
        return $this->is_object_accessible($obj,"w");
    }
    
    public function is_object_readable($obj)
    {
        return $this->is_object_accessible($obj,"r");
    }

    public function mysql_list_rights_filter($table_name, $sort)
    {
        $val = $this->get_module_permission_value($table_name, "r");
        $where = "";
        switch ($val)
        {
            case 1:
                {
                    $where = "1=0";
                    break;
                }
            case 2:
                {
                    $where = "`$table_name`.`Owner_id`='" . $this->id . "'";
                    break;
                }
            case 3:
                {
                    $where = "(`$table_name`.`Owner_id`='" . $this->id . "' OR owner.`UserGroup_id`='" . $this->UserGroup_id . "' AND `$table_name`.`Sharing_id`>=2 OR `$table_name`.`Sharing_id`=3)";
                    break;
                }
            case 4:
                {
                    $where = "(`$table_name`.`Owner_id`='" . $this->id . "' OR owner.`UserGroup_id`='" . $this->UserGroup_id . "' OR `$table_name`.`Sharing_id`>=3)";
                    break;
                }
            case 5:
                {
                    $where = "1=1";
                }
        }

        $sql = sprintf("SELECT `$table_name`.`id` FROM `$table_name`
		LEFT JOIN `%s` AS owner ON owner.`id`=`$table_name`.`Owner_id`
		WHERE %s
		ORDER BY %s", self::get_mysql_table(), $where, $sort);
        return $sql;
    }

    public static function get_list_columns()
    {
        $cols = parent::get_list_columns();
        
        array_push($cols, array(
            "name" => Language::string(173),
            "property" => "login",
            "searchable" => true,
            "sortable" => true
        ));
        array_push($cols, array(
            "name" => Language::string(174),
            "property" => "email",
            "searchable" => true,
            "sortable" => true
        ));
        array_push($cols, array(
            "name" => Language::string(175),
            "property" => "last_activity",
            "searchable" => true,
            "sortable" => true
        ));
        array_push($cols, array(
            "name" => Language::string(176),
            "property" => "get_UserGroup_name",
            "searchable" => true,
            "sortable" => true
        ));
        array_push($cols, array(
            "name" => Language::string(177),
            "property" => "get_UserType_name",
            "searchable" => true,
            "sortable" => true
        ));
        
        for($i=0;$i<count($cols);$i++)
        {
            if($cols[$i]["property"]=="name")
            {
                array_splice($cols, $i, 1);
                $i--;
            }
            if($cols[$i]["property"]=="get_owner_full_name")
            {
                array_splice($cols, $i, 1);
                $i--;
            }
            if($cols[$i]["property"]=="get_sharing_name")
            {
                array_splice($cols, $i, 1);
                $i--;
            }
        }

        return $cols;
    }

    public function get_UserGroup_name()
    {
        $group = $this->get_UserGroup();
        if ($group == null) return null;
        return $group->name;
    }

    public function get_UserType_name()
    {
        $type = $this->get_UserType();
        if ($type == null) return null;
        return $type->name;
    }

}

?>