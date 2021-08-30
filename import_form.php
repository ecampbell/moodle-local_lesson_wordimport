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
 * Import Microsoft Word file form.
 *
 * @package    local_lesson_wordimport
 * @copyright  2016 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/importhtml/import_form.php
 * (copyright 2004-2011 Petr Skoda) from Moodle 2.4. */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . DIRECTORY_SEPARATOR . 'formslib.php');

/**
 * Importer for Microsoft Word lessons.
 *
 * @copyright 2020 Eoin Campbell
 * @author Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later (5)
 */
class local_lesson_wordimport_form extends moodleform {

    /**
     * Define Microsoft Word file import form
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $data  = $this->_customdata;

        $mform->addElement('header', 'general', get_string('wordimport', 'local_lesson_wordimport'));

        // User can select 1 and only 1 Word file which must have a .docx suffix (not .docm or .doc).
        $mform->addElement('filepicker', 'importfile', get_string('filetoimport', 'local_lesson_wordimport'), null,
                           array('subdirs' => 0, 'accepted_types' => array('.docx')));
        $mform->addHelpButton('importfile', 'filetoimport', 'local_lesson_wordimport');
        $mform->addRule('importfile', null, 'required', null, 'client');

        $mform->addElement('checkbox', 'layout', null, get_string('arrangebuttonshorizontally', 'lesson'));
        $mform->setDefault('layout', true);

        $mform->addElement('checkbox', 'display', null, get_string('displayinleftmenu', 'lesson'));
        $mform->setDefault('display', true);

        $mform->addElement('header', 'importoptions', get_string('importoptions', 'local_lesson_wordimport'));
        $mform->addHelpButton('importoptions', 'importoptions', 'local_lesson_wordimport');

        $mform->addElement('checkbox', 'previousjump', null, get_string('previousjump', 'local_lesson_wordimport'));
        $mform->setDefault('previousjump', false);

        $mform->addElement('checkbox', 'endjump', null, get_string('endjump', 'local_lesson_wordimport'));
        $mform->setDefault('endjump', false);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('import'));
        $this->set_data($data);
    }

    /**
     * Define Word import form validation
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER;

        if ($errors = parent::validation($data, $files)) {
            return $errors;
        }

        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();

        if (!$files = $fs->get_area_files($usercontext->id, 'user', 'draft', $data['importfile'], 'id', false)) {
            $errors['importfile'] = get_string('required');
            return $errors;
        } else {
            $file = reset($files);
            $mimetype = $file->get_mimetype();
            if ($mimetype != 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                $errors['importfile'] = get_string('invalidfiletype', 'error', $file->get_filename());
                $fs->delete_area_files($usercontext->id, 'user', 'draft', $data['importfile']);
            }
        }

        return $errors;
    }
}
