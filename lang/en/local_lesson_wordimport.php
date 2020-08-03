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
 * Import Word file language strings.
 *
 * @package    local_lesson_wordimport
 * @copyright  2020 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


$string['cannotopentempfile'] = 'Cannot open temporary file <b>{$a}</b>';
$string['filetoimport'] = 'File to import.';
$string['filetoimport_help'] = 'Upload <i>.docx</i> file saved from Microsoft Word or LibreOffice';
$string['pluginname'] = 'Microsoft Word file Import/Export (Lesson)';
$string['privacy:metadata']      = 'The Microsoft Word file import/export tool for lessons does not store personal data.';
$string['replaceglossary'] = 'Replace lesson';
$string['replaceglossary_help'] = 'Delete the current content of lesson before importing';
$string['stylesheetunavailable'] = 'XSLT Stylesheet <b>{$a}</b> is not available';
$string['transformationfailed'] = 'XSLT transformation failed (<b>{$a}</b>)';
$string['teacherentry'] = 'Teacher entry';
$string['wordexport'] = 'Export to Microsoft Word';
$string['wordfilerequired'] = 'Microsoft Word file required';
$string['wordimport'] = 'Import from Microsoft Word';
$string['xsltunavailable'] = 'You need the XSLT library installed in PHP to save this Word file';

