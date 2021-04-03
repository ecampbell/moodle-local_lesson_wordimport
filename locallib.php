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
 * Import/Export Microsoft Word files library.
 *
 * @package    local_lesson_wordimport
 * @copyright  2020 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/lesson/lib.php');

use \booktool_wordimport\wordconverter;

/**
 * Convert the Word file into Lesson XML and import it into the current lesson.
 *
 * @param string $wordfilename Word file to be processed into XML
 * @param stdClass $lesson Lesson to import into
 * @param context_module $context Current course context
 * @return void
 */
function local_lesson_wordimport_import(string $wordfilename, stdClass $lesson, context_module $context) {
    global $CFG, $OUTPUT, $DB, $USER;

    // Convert the Word file into Lesson pages.
    $heading1styleoffset = 1; // Map "Heading 1" styles to <h1>.
    // Pass 1 - convert the Word file content into XHTML and an array of images.
    $imagesforzipping = array();
    $word2xml = new wordconverter();
    $word2xml->set_heading1styleOffset($heading1styleoffset);
    $xhtmlcontent = $word2xml->import($wordfilename, $imagesforzipping);
    $xhtmlcontent = $word2xml->body_only($xhtmlcontent);

    // Convert the returned array of images, if any, into a string.
    $imagestring = "";

    foreach ($imagesforzipping as $imagename => $imagedata) {
        $filetype = strtolower(pathinfo($imagename, PATHINFO_EXTENSION));
        $base64data = base64_encode($imagedata);
        $filedata = 'data:image/' . $filetype . ';base64,' . $base64data;
        // Embed the image name and data into the HTML.
        $imagestring .= '<img title="' . $imagename . '" src="' . $filedata . '"/>';
    }

    if (!($tempxmlfilename = tempnam($CFG->tempdir, "w2x")) || (file_put_contents($tempxmlfilename, $xhtmlcontent)) == 0) {
        throw new \moodle_exception(get_string('cannotopentempfile', 'local_lesson_wordimport', $tempxmlfilename));
    }
}

/**
 * Export HTML pages to a Word file
 *
 * @param stdClass $lesson Lesson to export
 * @param cm_info $cm Module info
 * @return string
 */
function local_lesson_wordimport_export(stdClass $lesson, cm_info $cm) {
    global $CFG;

    // Gather all the lesson content into a single HTML string.
    $lesson = new Lesson($lesson);
    $pages = $lesson->load_all_pages();
    $pageids = array_keys($pages);
    $context = context_module::instance($cm->id);
    // Set the Lesson name to be the Word file title.
    $lessonhtml = '<p class="MsoTitle">' . $lesson->name . "</p>\n";
    // Add the Description field.
    // TODO: figure out how to include images, using file_rewrite_pluginfile_urls().
    $lessonhtml .= $lesson->intro;

    $word2xml = new wordconverter();

    // Loop through the lesson pages and process each one.
    foreach ($pages as $page) {
        $answers = $page->get_answers();
        $pagehtml = $page->contents;

        // Append answers to the end of question pages.
        // TODO: Should include questions too.
        $pagehtml = local_lesson_wordimport_format_answers($page);
        // Could use format_text($pagehtml, FORMAT_MOODLE, array('overflowdiv' => false, 'allowid' => true, 'para' => false));.
        // Revert image paths back to @@PLUGINFILE@@ so that export function works properly.
        // Must revert after format_text(), or a debug developer error is triggered.
        $pagehtml = file_rewrite_pluginfile_urls($pagehtml, 'pluginfile.php', $context->id,
                                  'mod_lesson', 'page_contents', $page->id, array('reverse' => true));
        $lessonhtml .= '<div class="chapter" id="lesson' . $lesson->id . '_page' . $page->id . '">' . "\n" .
            '<h1>' . $page->title . '</h1>' . "\n" . $pagehtml;
        $lessonhtml .= $word2xml->base64_images($context->id, 'mod_lesson', 'page_contents', $page->id);
        $lessonhtml .= '</div>';
    }

    // Assemble the lesson contents and localised labels to a single XML file for easier XSLT processing.
    // Convert the XHTML string into a Word-compatible version, with images converted to Base64 data.
    $moodlelabels = local_lesson_wordimport_get_text_labels();
    $lessonword = $word2xml->export($lessonhtml, 'lesson', $moodlelabels, 'embedded');
    if (!($tempxmlfilename = tempnam($CFG->tempdir, "p2o")) || (file_put_contents($tempxmlfilename, $lessonword) == 0)) {
        throw new \moodle_exception(get_string('cannotopentempfile', 'local_lesson_wordimport', $tempxmlfilename));
    }
    return $lessonword;
}

/**
 * Get all the text strings needed to fill in the Word file labels in a language-dependent way
 *
 * A string containing XML data, populated from the language folders, is returned
 *
 * @return string
 */
function local_lesson_wordimport_get_text_labels() {
    global $CFG;

    // Release-independent list of all strings required in the XSLT stylesheets for labels etc.
    $textstrings = array(
        'lesson' => array('modulename', 'modulename_help', 'modulename_link', 'pluginname'),
        'moodle' => array('no', 'yes', 'tags'),
        );

    $labelstring = "<moodlelabels>";
    foreach ($textstrings as $typegroup => $grouparray) {
        foreach ($grouparray as $stringid) {
            $namestring = $typegroup . '_' . $stringid;
            // Clean up question type explanation, in case the default text has been overridden on the site.
            $cleantext = get_string($stringid, $typegroup);
            $labelstring .= '<data name="' . $namestring . '"><value>' . $cleantext . "</value></data>\n";
        }
    }
    $labelstring = str_replace("<br>", "<br/>", $labelstring) . "</moodlelabels>";

    return $labelstring;
}

/**
 * Library functions
 *
 * @package   local_lessonexportepub
 * @copyright 2017 Adam King, SHEilds eLearning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Retrieve and format question pages to include answers.
 *
 * @param A Lesson page.
 * @return Formatted page contents.
 */
function local_lesson_wordimport_format_answers($page) {
    $pagetype = $page->get_typeid();
    $pagehtml = $page->contents;
    $answers = $page->answers;
    $qtype = $page->qtype;

    // Don't look for answers in lesson types.
    if ($pagetype == 20) {
        return $pagehtml;
    }

    $pagetypes = array(
        1 => "shortanswer",
        2 => "truefalse",
        3 => "multichoice",
        5 => "matching",
        8 => "numerical",
        10 => "essay",
        20 => "lessonpage"
    );

    $pagetype = $pagetypes[$pagetype];

    $pagehtml .= "<div class='export_answer_" . $pagetype . "_wrapper'>";

    foreach ($answers as $answer) {
        // If this is a matching question type, only print the answers, not responses.
        if ($pagetype == 5 && $answer->answerformat == 1) {
            continue;
        }

        $pagehtml .= "<div class='export_answer_$pagetype'>$answer->answer</div>";
    }

    $pagehtml .= "</div>";

    return $pagehtml;
}

