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
 * Definition of the library class for the Microsoft Word (.docx) file conversion plugin.
 *
 * @package   local_lesson_wordimport
 * @copyright 2020 Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Add import/export commands to the Lesson settings block
 *
 * @param settings_navigation $settings The settings navigation object
 */
function local_lesson_wordimport_extend_settings_navigation(settings_navigation $settings) {
    global $PAGE;

    // Do nothing when installing the plugin, or if we're not in a lesson.
    if (!$PAGE->cm || $PAGE->cm->modname !== 'lesson') {
        return;
    }

    // Use the permissions context to decide whether to add custom links to the activity settings.
    $context = \context_module::instance($PAGE->cm->id);

    // Get the the activity menu node from the navigation settings.
    $menu = $settings->find('modulesettings', settings_navigation::TYPE_SETTING);

    // Add the import link if the user has the capability.
    if (has_capability('mod/lesson:manage', $context)) {
        $url1 = new moodle_url('/local/lesson_wordimport/index.php', array('id' => $PAGE->cm->id, 'action' => 'import'));
        $menu->add(get_string('wordimport', 'local_lesson_wordimport'), $url1, navigation_node::TYPE_SETTING, null, null,
               new pix_icon('f/document', '', 'moodle', array('class' => 'iconsmall', 'title' => '')));
        $url2 = new moodle_url('/local/lesson_wordimport/index.php', array('id' => $PAGE->cm->id, 'action' => 'export'));
        $menu->add(get_string('wordexport', 'local_lesson_wordimport'), $url2, navigation_node::TYPE_SETTING,
           null, null, new pix_icon('f/document', '', 'moodle', array('class' => 'iconsmall', 'title' => '')));
    }

}
