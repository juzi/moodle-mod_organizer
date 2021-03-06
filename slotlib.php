<?php
// This file is part of mod_organizer for Moodle - http://moodle.org/
//
// It is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// It is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// If not, see <http://www.gnu.org/licenses/>.

/**
 * slotlib.php
 *
 * @package       mod_organizer
 * @author        Andreas Hruska (andreas.hruska@tuwien.ac.at)
 * @author        Katarzyna Potocka (katarzyna.potocka@tuwien.ac.at)
 * @author        Andreas Windbichler
 * @author        Ivan Šakić
 * @copyright     2014 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function organizer_get_last_user_appointment($organizer, $userid = null, $mergegroupapps = true, $getevaluated = false) {
    global $DB, $USER;
    
    if(!isset($organizer->id)){
    	echo "ERROR";
    }

    if ($userid == null) {
        $userid = $USER->id;
    }

    if (is_number($organizer) && $organizer == intval($organizer)) {
        $organizer = $DB->get_record('organizer', array('id' => $organizer));
    }

    $params = array('userid' => $userid, 'organizerid' => $organizer->id);
    $query = "SELECT a.* FROM {organizer_slot_appointments} a
            INNER JOIN {organizer_slots} s ON a.slotid = s.id
            WHERE s.organizerid = :organizerid AND a.userid = :userid" .
            ($getevaluated ? " AND a.attended IS NOT NULL " : " ") .
            "ORDER BY a.id DESC";
    $apps = $DB->get_records_sql($query, $params);
    $app = reset($apps);

    if ($organizer->isgrouporganizer && $mergegroupapps && $app !== false) {
        $params = array('slotid' => $app->slotid, 'organizerid' => $organizer->id);
        $query = "SELECT a.* FROM {organizer_slot_appointments} a
                INNER JOIN {organizer_slots} s ON a.slotid = s.id
                WHERE s.organizerid = :organizerid AND s.id = :slotid
                ORDER BY a.id DESC";
        $groupapps = $DB->get_records_sql($query, $params);

        $appcount = 0;
        $someoneattended = false;
        foreach ($groupapps as $groupapp) {
            if ($groupapp->userid == $userid) {
                $app = $groupapp;
            }
            if (isset($groupapp->attended)) {
                $appcount++;
                if ($groupapp->attended == 1) {
                    $someoneattended = true;
                }
            }
        }

        if ($app) {
            $app->attended = ($appcount == count($groupapps)) ? $someoneattended : null;
        }
    }
    
    return $app;
}

function organizer_get_all_user_appointments($organizer, $userid = null, $mergegroupapps = true) {
    global $DB, $USER;

    if ($userid == null) {
        $userid = $USER->id;
    }

    if (is_number($organizer) && $organizer == intval($organizer)) {
        $organizer = $DB->get_record('organizer', array('id' => $organizer));
    }

    $params = array('userid' => $userid, 'organizerid' => $organizer->id);
    $query = "SELECT a.* FROM {organizer_slot_appointments} a
    INNER JOIN {organizer_slots} s ON a.slotid = s.id
    WHERE s.organizerid = :organizerid AND a.userid = :userid
    ORDER BY a.id DESC";
    $apps = $DB->get_records_sql($query, $params);

    $app = reset($apps);
    if ($organizer->isgrouporganizer && $mergegroupapps && $app !== false) {
        $params = array('slotid' => $app->slotid, 'organizerid' => $organizer->id);
        $query = "SELECT a.* FROM {organizer_slot_appointments} a
        INNER JOIN {organizer_slots} s ON a.slotid = s.id
        WHERE s.organizerid = :organizerid AND s.id = :slotid
        ORDER BY a.id DESC";
        $groupapps = $DB->get_records_sql($query, $params);

        $appcount = 0;
        $someoneattended = false;
        foreach ($groupapps as $groupapp) {
            if ($groupapp->userid == $userid) {
                $app = $groupapp;
            }
            if (isset($groupapp->attended)) {
                $appcount++;
                if ($groupapp->attended == 1) {
                    $someoneattended = true;
                }
            }
        }

        if ($app) {
            $app->attended = ($appcount == count($groupapps)) ? $someoneattended : null;
        }

        $apps = $groupapps;
    }

    return $apps;
}

class organizer_slot {

    private $slot;
    private $organizer;
    private $apps;

    public function __construct($slot, $lazy = true) {
        global $DB;

        if (is_number($slot) && $slot == intval($slot)) {
            $this->slot = $DB->get_record('organizer_slots', array('id' => $slot));
        } else {
            $this->slot = $slot;
            
            if(!isset($this->slot->organizerid)){
            	$this->slot->organizerid = $DB->get_field('organizer_slots', 'organizerid', array('id'=>$slot->slotid));
            }
            
            if(!isset($this->slot->maxparticipants)){
            	$this->slot->maxparticipants = $DB->get_field('organizer_slots', 'maxparticipants', array('id'=>$slot->slotid));
            }
        }

        foreach ((array) $this->slot as $key => $value) {
            $this->$key = $value;
        }

        if (!$lazy) {
            $this->load_organizer();
            $this->load_appointments();
        }
    }

    public function has_participants() {
        $this->load_appointments();
        return count($this->apps) != 0;
    }

    public function get_rel_deadline() {
        $this->load_organizer();
        return $this->organizer->relativedeadline;
    }

    public function get_abs_deadline() {
        $this->load_organizer();
        return $this->organizer->duedate;
    }

    public function is_upcoming() {
        return $this->slot->starttime > time();
    }

    public function is_past_deadline() {
        $deadline = $this->get_rel_deadline($this->slot);
        return $this->slot->starttime <= $deadline + time();
    }

    public function is_past_due() {
        return $this->slot->starttime <= time();
    }

    public function is_full() {
        $this->load_organizer();
        $this->load_appointments();
        if ($this->organizer->isgrouporganizer) {
            return count($this->apps) > 0;
        } else {
            return count($this->apps) >= $this->slot->maxparticipants;
        }
    }

    public function is_available() {
        return ($this->slot->availablefrom == 0) || ($this->slot->starttime - $this->slot->availablefrom <= time());
    }

    public function organizer_expired() {
        $this->load_organizer();
        return isset($this->organizer->duedate) && $this->organizer->duedate - time() < 0;
    }

    public function organizer_unavailable() {
        $this->load_organizer();
        return isset($this->organizer->allowregistrationsfromdate) && $this->organizer->allowregistrationsfromdate - time() > 0;
    }

    public function is_evaluated() {
        $this->load_appointments();

        foreach ($this->apps as $app) {
            if (!isset($app->attended)) {
                return false;
            }
        }
        return count($this->apps) > 0;
    }

    public function organizer_user_has_access() {
        $this->load_organizer();
        global $DB;
        if ($this->organizer->isgrouporganizer) {
            $moduleid = $DB->get_field('modules', 'id', array('name' => 'organizer'));
            $courseid = $DB->get_field('course_modules', 'course',
                    array('module' => $moduleid, 'instance' => $this->organizer->id));
            $groups = groups_get_user_groups($courseid);
            $groupingid = $DB->get_field('course_modules', 'groupingid',
                    array('module' => $moduleid, 'instance' => $this->organizer->id));
            if (!isset($groups[$groupingid]) || !count($groups[$groupingid])) {
                return false;
            }
        }
        return true;
    }

    private function load_organizer() {
        global $DB;
        if (!$this->organizer) {
            $this->organizer = $DB->get_record('organizer', array('id' => $this->slot->organizerid));
        }
    }

    private function load_appointments() {
        global $DB;
        if (!$this->apps) {
            $this->apps = $DB->get_records('organizer_slot_appointments', array('slotid' => $this->slot->id));
        }
    }
}

function organizer_user_has_access($slotid) {
    global $USER, $DB;
    $slot = $DB->get_record('organizer_slots', array('id' => $slotid));
    $moduleid = $DB->get_field('modules', 'id', array('name' => 'organizer'));
    $organizer = $DB->get_record('organizer', array('id' => $slot->organizerid));
    $courseid = $DB->get_field('course_modules', 'course', array('module' => $moduleid, 'instance' => $organizer->id));
    $groups = groups_get_user_groups($courseid);
    $groupingid = $DB->get_field('course_modules', 'groupingid',
            array('module' => $moduleid, 'instance' => $organizer->id));
    if (!isset($groups[$groupingid]) || !count($groups[$groupingid])) {
        return false;
    }
    return true;
}
