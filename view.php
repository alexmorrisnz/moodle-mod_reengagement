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
 * Prints information about the reengagement to the user.
 *
 * @package    mod_reengagement
 * @author     Peter Bulmer <peter.bulmer@catlayst.net.nz>
 * @copyright  2016 Catalyst IT {@link http://www.catalyst.net.nz}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/lib.php');

define('DEFAULT_PAGE_SIZE', 20);
define('SHOW_ALL_PAGE_SIZE', 5000);

$id = optional_param('id', 0, PARAM_INT); // Course_module ID, or
$a  = optional_param('a', 0, PARAM_INT);  // reengagement instance ID.
$page         = optional_param('page', 0, PARAM_INT); // Which page to show.
$perpage      = optional_param('perpage', DEFAULT_PAGE_SIZE, PARAM_INT); // How many per page.
$selectall    = optional_param('selectall', false, PARAM_BOOL); // When rendering checkboxes against users mark them all checked.
$roleid       = optional_param('roleid', 0, PARAM_INT);
$groupparam   = optional_param('group', 0, PARAM_INT);

$params = array();

if ($id) {
    $params['id'] = $id;
} else {
    $params['a'] = $a;
}

$PAGE->set_url('/mod/reengagement/view.php', $params);

if ($id) {
    $cm = get_coursemodule_from_id('reengagement', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $reengagement = $DB->get_record('reengagement', array('id' => $cm->instance), '*', MUST_EXIST);

} else if ($a) {
    $reengagement = $DB->get_record('reengagement', array('id' => $a), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $reengagement->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('reengagement', $reengagement->id, $course->id, false, MUST_EXIST);
} else {
    print_error('errornoid', 'mod_reengagement');
}

require_login($course, true, $cm);

// Make sure completion and restriction is enabled.
if (empty($CFG->enablecompletion) || empty($CFG->enableavailability)) {
    print_error('mustenablecompletionavailability', 'mod_reengagement');
}

$context = context_module::instance($cm->id);

$event = \mod_reengagement\event\course_module_viewed::create(array(
    'objectid' => $reengagement->id,
    'context' => $context,
));
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('reengagement', $reengagement);
$event->trigger();

// Print the page header.
$strreengagements = get_string('modulenameplural', 'reengagement');
$strreengagement  = get_string('modulename', 'reengagement');

$PAGE->set_title(format_string($reengagement->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
// Print the main part of the page.

$PAGE->set_context($context);

$canstart = has_capability('mod/reengagement:startreengagement', $context, null, false);
$canedit = has_capability('mod/reengagement:editreengagementduration', $context);
$bulkoperations = has_capability('mod/reengagement:bulkactions', $context);

if (empty($canstart) && empty($canedit)) {
    print_error('errorreengagementnotvalid', 'mod_reengagement');
}

if ($canstart) {
    // Check reengagement record for this user.
    echo reengagement_checkstart($course, $cm, $reengagement);
}

if ($canedit) {
    // User is able to see admin-type features of this plugin - ie not just their own re-engagement status.
    $sql = "SELECT *
              FROM {reengagement_inprogress} rip
        INNER JOIN {user} u ON u.id = rip.userid
             WHERE rip.reengagement = :reengagementid
               AND u.deleted = 0
          ORDER BY rip.completiontime ASC, u.lastname ASC, u.firstname ASC";

    $rips = $DB->get_records_sql($sql, array('reengagementid' => $reengagement->id));

    if ($rips) {
        // There are re-engagements in progress.
        if (!in_array($reengagement->emailuser, array(REENGAGEMENT_EMAILUSER_NEVER, REENGAGEMENT_EMAILUSER_COMPLETION))) {
            // Include an extra column to show the time the user will be emailed.
            $showemailtime = true;
        } else {
            $showemailtime = false;
        }
        print '<table class="reengagementlist">' . "\n";
        print "<tr><th>" . get_string('user') . "</th>";
        if ($showemailtime) {
            print "<th>" . get_string('emailtime', 'reengagement') . '</th>';
        }
        print "<th>" . get_string('completiontime', 'reengagement') . '</th>';
        print "</tr>";
        foreach ($rips as $rip) {
            $fullname = fullname($rip);
            print '<tr><td>' . $fullname . '</td>';
            if ($showemailtime) {
                if ($rip->emailsent > $reengagement->remindercount) {
                    // Email has already been sent - don't show a time in the past.
                    print '<td></td>';
                } else {
                    // Email will be sent, but hasn't been yet.
                    print '<td>' . userdate($rip->emailtime, get_string('strftimedatetimeshort', 'langconfig')) . "</td>";
                }
            }
            if ($rip->completed) {
                // User has completed the activity, but email hasn't been sent yet.
                // Show an empty completion time.
                print '<td></td>';
            } else {
                // User hasn't complted activity yet.
                print '<td>' . userdate($rip->completiontime, get_string('strftimedatetimeshort', 'langconfig')) . "</td>";
            }
            print '</tr>';
        }
        print "</table>\n";
    } else {
        echo $OUTPUT->box('No reengagements in progress');
    }
}


// Finish the page.
echo $OUTPUT->footer($course);
