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
 * view_action.php
 *
 * @package       mod_organizer
 * @author        Andreas Hruska (andreas.hruska@tuwien.ac.at)
 * @author        Katarzyna Potocka (katarzyna.potocka@tuwien.ac.at)
 * @author        Andreas Windbichler
 * @author        Ivan Šakić
 * @copyright     2014 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// define('ORGANIZER_ACTION_ADD', 'add');
// define('ORGANIZER_ACTION_EDIT', 'edit');
// define('ORGANIZER_ACTION_DELETE', 'delete');
// define('ORGANIZER_ACTION_EVAL', 'eval');
// define('ORGANIZER_ACTION_PRINT', 'print');
define('ORGANIZER_ACTION_REGISTER', 'register');
define('ORGANIZER_ACTION_UNREGISTER', 'unregister');
define('ORGANIZER_ACTION_REREGISTER', 'reregister');
define('ORGANIZER_ACTION_QUEUE', 'queue');
define('ORGANIZER_ACTION_UNQUEUE', 'unqueue');
// define('ORGANIZER_ACTION_REMIND', 'remind');
// define('ORGANIZER_ACTION_REMINDALL', 'remindall');
define('ORGANIZER_ACTION_COMMENT', 'comment');

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/locallib.php');
// require_once(dirname(__FILE__) . '/view_action_form_add.php');
// require_once(dirname(__FILE__) . '/view_action_form_eval.php');
// require_once(dirname(__FILE__) . '/view_action_form_edit.php');
// require_once(dirname(__FILE__) . '/view_action_form_delete.php');
require_once(dirname(__FILE__) . '/view_action_form_comment.php');
require_once(dirname(__FILE__) . '/view_action_form_print.php');
require_once($CFG->dirroot . '/mod/organizer/classes/event/queue_added.php');
// require_once(dirname(__FILE__) . '/view_action_form_remind_all.php');
// require_once(dirname(__FILE__) . '/print.php');
require_once(dirname(__FILE__) . '/view_lib.php');
require_once(dirname(__FILE__) . '/messaging.php');

//--------------------------------------------------------------------------------------------------

list($cm, $course, $organizer, $context) = organizer_get_course_module_data();

require_login($course, false, $cm);
require_sesskey();

$mode = optional_param('mode', null, PARAM_INT);
$action = optional_param('action', null, PARAM_ACTION);
$user = optional_param('user', null, PARAM_INT);
$slot = optional_param('slot', null, PARAM_INT);
$slots = optional_param_array('slots', array(), PARAM_INT);
$app = optional_param('app', null, PARAM_INT);
$tsort = optional_param('tsort', null, PARAM_ALPHA);

$url = new moodle_url('/mod/organizer/view_action.php');
$url->param('id', $cm->id);
$url->param('mode', $mode);
$url->param('action', $action);
$url->param('sesskey', sesskey());

$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title($organizer->name);
$PAGE->set_heading($course->fullname);

$jsmodule = array(
        'name' => 'mod_organizer',
        'fullpath' => '/mod/organizer/module.js',
        'requires' => array('node', 'event', 'node-screen', 'panel', 'node-event-delegate'),
);
$PAGE->requires->js_module($jsmodule);

$redirecturl = new moodle_url('/mod/organizer/view.php', array('id' => $cm->id, 'mode' => $mode, 'action' => $action));

$logurl = 'view_action.php?id=' . $cm->id . '&mode=' . $mode . '&action=' . $action;

if ($action == ORGANIZER_ACTION_REGISTER || $action == ORGANIZER_ACTION_QUEUE) {
    require_capability('mod/organizer:register', $context);

    if (!organizer_security_check_slots($slot)) {
        print_error('Security failure: Selected slot doesn\'t belong to this organizer!');
    }

    if (!organizer_organizer_student_action_allowed($action, $slot)) {
        print_error('Inconsistent state: Cannot execute registration action! Please navigate back and refresh your browser!');
    }

    $group = organizer_fetch_my_group();
    $groupid = $group ? $group->id : 0;
    $success = organizer_register_appointment($slot, $groupid);

    if ($success) {
        if ($action == ORGANIZER_ACTION_QUEUE) {
            $event = \mod_organizer\event\queue_added::create(array(
                    'objectid' => $PAGE->cm->id,
                    'context' => $PAGE->context
            ));
        } else {
            $event = \mod_organizer\event\appointment_added::create(array(
                    'objectid' => $PAGE->cm->id,
                    'context' => $PAGE->context
            ));
        }
        $event->trigger();
        
        // TODO send correct notification when queue
        organizer_prepare_and_send_message($slot, 'register_notify:teacher:register');
        // ---------------------------------------- MESSAGE!!!
        if ($group) {
            organizer_prepare_and_send_message($slot, 'group_registration_notify:student:register');
        }
    } else {
        if (organizer_is_group_mode()) {
            $redirecturl->param('messages[]', 'message_error_slot_full_group');
        } else {
            $redirecturl->param('messages[]', 'message_error_slot_full_single');
        }
    }

    redirect($redirecturl);

} else if ($action == ORGANIZER_ACTION_UNREGISTER || $action == ORGANIZER_ACTION_UNQUEUE) {
    require_capability('mod/organizer:unregister', $context);

    $event = \mod_organizer\event\appointment_removed::create(array(
            'objectid' => $PAGE->cm->id,
            'context' => $PAGE->context
    ));
    $event->trigger();

    if (!organizer_security_check_slots($slot)) {
        print_error('Security failure: Selected slot doesn\'t belong to this organizer!');
    }

    if (!organizer_organizer_student_action_allowed($action, $slot)) {
        print_error('Inconsistent state: Cannot execute registration action! Please navigate back and refresh your browser!');
    }

    $group = organizer_fetch_my_group();
    $groupid = $group ? $group->id : 0;

    organizer_prepare_and_send_message($slot, 'register_notify:teacher:unregister'); // ---------------------------------------- MESSAGE!!!
    if ($group) {
        organizer_prepare_and_send_message($slot, 'group_registration_notify:student:unregister');
    }

    organizer_unregister_appointment($slot, $groupid);

    redirect($redirecturl);
} else if ($action == ORGANIZER_ACTION_REREGISTER) {
    require_capability('mod/organizer:register', $context);
    require_capability('mod/organizer:unregister', $context);

    if (!organizer_security_check_slots($slot)) {
        print_error('Security failure: Selected slot doesn\'t belong to this organizer!');
    }

    if (!organizer_organizer_student_action_allowed($action, $slot)) {
        print_error('Inconsistent state: Cannot execute registration action! Please navigate back and refresh your browser!');
    }

    $group = organizer_fetch_my_group();
    $groupid = $group ? $group->id : 0;
    $success = organizer_reregister_appointment($slot, $groupid);

    if ($success) {
        $event = \mod_organizer\event\appointment_removed::create(array(
                'objectid' => $PAGE->cm->id,
                'context' => $PAGE->context
        ));
        $event->trigger();
        
        $event = \mod_organizer\event\appointment_added::create(array(
                'objectid' => $PAGE->cm->id,
                'context' => $PAGE->context
        ));
        $event->trigger();
        
        organizer_prepare_and_send_message($slot, 'register_notify:teacher:reregister');
        if ($group) {
            organizer_prepare_and_send_message($slot, 'group_registration_notify:student:reregister');
        }
    } else {
        if (organizer_is_group_mode()) {
            $redirecturl->param('messages[]', 'message_error_slot_full_group');
        } else {
            $redirecturl->param('messages[]', 'message_error_slot_full_single');
        }
    }

    redirect($redirecturl);
} else {
    print_error('Either a wrong method or no method was selected!');
}

die;

function organizer_organizer_student_action_allowed($action, $slot) {
    global $DB, $USER;

    if (!$DB->record_exists('organizer_slots', array('id' => $slot))) {
        return false;
    }

    $slotx = new organizer_slot($slot);

    list($cm, $course, $organizer, $context) = organizer_get_course_module_data();

    $canregister = has_capability('mod/organizer:register', $context, null, false);
    $canunregister = has_capability('mod/organizer:unregister', $context, null, false);
    $canreregister = $canregister && $canunregister;

    $myapp = organizer_get_last_user_appointment($organizer);
    if ($myapp) {
        $regslot = $DB->get_record('organizer_slots', array('id' => $myapp->slotid));
        if (isset($regslot)) {
            $regslotx = new organizer_slot($regslot);
        }
    }

    $myslotexists = isset($regslot);
    $organizerdisabled = $slotx->organizer_unavailable() || $slotx->organizer_expired();
    $slotdisabled = $slotx->is_past_due() || $slotx->is_past_deadline();
    $myslotpending = $myslotexists && $regslotx->is_past_deadline() && !$regslotx->is_evaluated();
    $ismyslot = $myslotexists && ($slotx->id == $regslot->id);
    $slotfull = $slotx->is_full();

    $disabled = $myslotpending || $organizerdisabled || $slotdisabled || !$slotx->organizer_user_has_access() || $slotx->is_evaluated();
    
	$isalreadyinqueue = false;
    if ($organizer->isgrouporganizer) {
    	$isalreadyinqueue = $slotx->is_group_in_queue($groupid);
    } else {
    	$isalreadyinqueue = $slotx->is_user_in_queue($USER->id);
    }

    $isqueueable = $organizer->queue && !$isalreadyinqueue && !$myslotpending && !$organizerdisabled
                 && !$slotdisabled && $slotx->organizer_user_has_access() && !$slotx->is_evaluated();

    if ($myslotexists) {
        if (!$slotdisabled) {
            if ($ismyslot) {
                $disabled |= !$canunregister
                || (isset($regslotx) && $regslotx->is_evaluated() && !$myapp->allownewappointments);
            } else {
                $disabled |= $slotfull || !$canreregister
                || (isset($regslotx) && $regslotx->is_evaluated() && !$myapp->allownewappointments);
            }
        }
        $allowedaction = $ismyslot ? ORGANIZER_ACTION_UNREGISTER : ORGANIZER_ACTION_REREGISTER;
    } else {
        $disabled |= $slotfull || !$canregister || $ismyslot;
        $allowedaction = $ismyslot ? ORGANIZER_ACTION_UNREGISTER : ORGANIZER_ACTION_REGISTER;
    }

    $result = !$disabled && ($action == $allowedaction);
    if (!$result && $isqueueable &&  $action == ORGANIZER_ACTION_QUEUE) {
        $result = true;     
    }
    if (!$result && $isalreadyinqueue && $action == ORGANIZER_ACTION_UNQUEUE) {
    	$result = true;
    }

    return $result;
}