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
 * Import Microsoft Word file into lesson version information
 *
 * @package    local_lesson_wordimport
 * @copyright  2021 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2021041700;              // The current plugin version (Date: YYYYMMDDXX).
$plugin->requires = 2016052300;               // Requires Moodle 3.1 or higher.
$plugin->component = 'local_lesson_wordimport';   // Full name of the plugin (used for diagnostics).
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.8.4 (Build: 2021041700)'; // Human readable version information.
$plugin->dependencies = array('booktool_wordimport' => 2021041100);

