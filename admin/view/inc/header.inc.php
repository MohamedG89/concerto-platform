<?php
/*
  Concerto Testing Platform,
  Web based adaptive testing platform utilizing R language for computing purposes.

  Copyright (C) 2011  Psychometrics Centre, Cambridge University

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if (!isset($ini)) {
    require_once '../../../model/Ini.php';
    $ini = new Ini();
}
$user = User::get_logged_user();
if ($user == null)
    die(Language::string(85));
?>

<script>
    Methods.iniIconButtons();
</script>

<table class="fullWidth">
    <tr>
        <td class="noWrap" align="center" style="width:33%;" valign="middle">
            <div><button class="btnHomepage" onclick="location.href='http://code.google.com/p/concerto-platform'" style="width:250px;"/></div>
            <div style="margin-top:5px;"><button class="btnGoogleGroup" onclick="location.href='http://groups.google.com/group/concerto-platform'"  style="width:250px;"/></div>
        </td>
        <td class="noWrap" align="center" style="width:33%;" valign="middle">
            <h2>Concerto Platform, v<?= Ini::$version ?></h2> 
            <div id="divVersionCheck"></div>
        </td>
        <td class="noWrap" align="center" style="width:33%;" valign="middle">
            <?= Language::string(84) ?>: <b><?= $user->login . ", " . $user->get_full_name() ?><br/>
                <button class="btnLogout" onclick="User.uiLogOut()"/>
        </td>
    </tr>
</table>