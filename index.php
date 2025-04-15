<?php
// This file is part of Moodle - http://moodle.org/
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Import Word file into lesson.
 *
 * @package    local_lesson_wordimport
 * @copyright  2020 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
require_once(__DIR__.'/import_form.php');
require_once('lib.php');
require_once($CFG->dirroot . '/mod/lesson/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT); // Course Module ID (this lesson).
$action = optional_param('action', 'import', PARAM_TEXT);  // Import or export action.
$verbose = optional_param('verbose', false, PARAM_BOOL); // Chapter ID.
$imageformat = optional_param('imageformat', 'embedded', PARAM_TEXT); // Chapter ID.

// Security checks.
list ($course, $cm) = get_course_and_cm_from_cmid($id, 'lesson');
$lesson = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);
require_course_login($course, true, $cm);

// Check import/export capabilities.
$context = context_module::instance($cm->id);
require_capability('mod/lesson:manage', $context);

// Set up page in case an import has been requested.
$PAGE->set_url('/local/lesson_wordimport/index.php', ['id' => $id, 'action' => $action]);
$PAGE->set_title($lesson->name);
$PAGE->set_heading($course->fullname);

// If exporting, just convert the lesson pages into Word.
if ($action == 'export') {
    // Export the current lesson into XHTML, and write to a Word file.
    $lessontext = local_lesson_wordimport_export($lesson, $context, $imageformat);
    $filename = clean_filename(strip_tags(format_string($lesson->name)) . '.doc');
    send_file($lessontext, $filename, 10, 0, true, ['filename' => $filename]);
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->heading($lesson->name);

// Set up the Word file upload form.
$mform = new local_lesson_wordimport_form(null, ['id' => $id, 'action' => $action]);
if ($mform->is_cancelled()) {
    // Form cancelled, go back.
    redirect($CFG->wwwroot . "/mod/lesson/view.php?id=$cm->id");
}

// Display or process the Word file upload form.
$data = $mform->get_data();
if (!$data) { // Display the form.
    $mform->display();
} else {
    // Import: save the uploaded Word file to the file system for processing.
    $fs = get_file_storage();
    $draftid = file_get_submitted_draft_itemid('importfile');
    if (!$files = $fs->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $draftid, 'id DESC', false)) {
        redirect($PAGE->url);
    }
    $file = reset($files);

    // Save the file to a temporary location on the file system.
    if (!$tmpfilename = $file->copy_content_to_temp()) {
        // Cannot save file.
        throw new moodle_exception(get_string('errorcreatingfile', 'error', $package->get_filename()));
    }

    // Convert the Word file content and import it into the glossary.
    $horizontaljumps = (!empty($data->layout)) ? true : false;
    $displaymenu = (!empty($data->display)) ? true : false;
    $previousjump = (!empty($data->previousjump)) ? true : false;
    $endjump = (!empty($data->endjump)) ? true : false;

    // Convert the Word file content and import it into the lesson.
    local_lesson_wordimport_import($tmpfilename, $lesson, $context, false, $horizontaljumps, $displaymenu,
                $previousjump, $endjump, $verbose);
    echo $OUTPUT->box_start('lessondisplay generalbox');
    echo $OUTPUT->continue_button(new moodle_url('/mod/lesson/view.php', ['id' => $id]));
    echo $OUTPUT->box_end();
}

// Finish the page.
echo $OUTPUT->footer();
