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

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/mod/lesson/lib.php');

use \booktool_wordimport\wordconverter;

/**
 * Convert the Word file into Lesson XML and import it into the current lesson.
 *
 * @param string $wordfilename Word file to be processed into XML
 * @param stdClass $lesson Lesson to import into
 * @param context_module $context Current course context
 * @return array Array with 2 elements $importedentries and $rejectedentries
 */
function local_lesson_wordimport_import(string $wordfilename, stdClass $lesson, context_module $context) {
    global $CFG, $OUTPUT, $DB, $USER;

    // Convert the Word file into Lesson XML
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

    // Pass 2 - convert the initial XHTML into Moodle Lesson XML using localised table cell labels.
    // XSLT stylesheet and parameters to convert generic XHTML into Moodle Lesson XML.
    $importstylesheet = __DIR__ . DIRECTORY_SEPARATOR . "xhtml2lesson.xsl";
    $parameters = array (
        'moodle_language' => current_language(),
        'moodle_textdirection' => (right_to_left()) ? 'rtl' : 'ltr',
        'heading1stylelevel' => $heading1styleoffset,
        'username' => $USER->firstname . ' ' . $USER->lastname,
        'debug_flag' => '1'
    );

    $xmlcontainer = "<pass2Container>\n<lesson>" . $xhtmlcontent . "</lesson>\n" .
        "<imagesContainer>\n" . $imagestring . "</imagesContainer>\n" .
        local_lesson_wordimport_get_text_labels() . "\n</pass2Container>";
    $lessonxml = $word2xml->convert($xmlcontainer, $importstylesheet, $parameters);
    $lessonxml = str_replace('<GLOSSARY xmlns="http://www.w3.org/1999/xhtml"', '<GLOSSARY', $lessonxml);
    if (!($tempxmlfilename = tempnam($CFG->tempdir, "x2g")) || (file_put_contents($tempxmlfilename, $lessonxml)) == 0) {
        throw new \moodle_exception(get_string('cannotopentempfile', 'local_lesson_wordimport', $tempxmlfilename));
    }

    // Convert the Lesson XML into an internal structure for importing into database.
    // This code is copied from /mod/lesson/import.php line 187 onwards.
    $importedentries = 0;
    $importedcats    = 0;
    $entriesrejected = 0;
    $rejections      = '';
    $glossarycontext = $context;



}

/**
 * Export HTML pages to a Word file
 *
 * @param stdClass $lesson Lesson to export
 * @return string
 */
function local_lesson_wordimport_export(stdClass $lesson) {
    global $CFG;

    // Export the current lesson into Lesson XML, then into XHTML, and write to a Word file.
    $lessonxml = glossary_generate_export_file($lesson, null, 0); // Include categories.
    // Get a temporary file and store the XML content to transform.
    if (!($tempxmlfilename = tempnam($CFG->tempdir, "gls")) || (file_put_contents($tempxmlfilename, $lessonxml)) == 0) {
        throw new \moodle_exception(get_string('cannotopentempfile', 'local_lesson_wordimport', $tempxmlfilename));
    }
    $lessonxml = preg_replace('/<\?xml version="1.0" ([^>]*)>/', "", $lessonxml);

    if (!($tempxmlfilename = tempnam($CFG->tempdir, "mdl")) ||
        (file_put_contents($tempxmlfilename, local_lesson_wordimport_get_text_labels())) == 0) {
        throw new \moodle_exception(get_string('cannotopentempfile', 'local_lesson_wordimport', $tempxmlfilename));
    }
    // Pass 1 - convert the Lesson XML into XHTML and an array of images.
    // Stylesheet to convert Moodle Lesson XML into generic XHTML.
    $exportstylesheet = __DIR__ . "/glossary2xhtml.xsl";
    // Set parameters for XSLT transformation. Note that we cannot use $arguments though.
    $parameters = array (
        'moodle_language' => current_language(),
        'moodle_textdirection' => (right_to_left()) ? 'rtl' : 'ltr',
        'moodle_release' => $CFG->release,
        'moodle_url' => $CFG->wwwroot . "/",
        'moodle_module' => 'lesson',
        'debug_flag' => '1',
        'transformationfailed' => get_string('transformationfailed', 'local_lesson_wordimport', $exportstylesheet)
    );

    // Assemble the lesson contents and localised labels to a single XML file for easier XSLT processing.
    $pass1input = "<pass1Container>\n" . $lessonxml .  local_lesson_wordimport_get_text_labels() . "\n</pass1Container>";

    if (!($tempxmlfilename = tempnam($CFG->tempdir, "p1i")) || (file_put_contents($tempxmlfilename, $pass1input) == 0)) {
        throw new \moodle_exception(get_string('cannotopentempfile', 'local_lesson_wordimport', $tempxmlfilename));
    }
    $word2xml = new wordconverter();
    $lessonhtml = $word2xml->convert($pass1input, $exportstylesheet, $parameters);
    $lessonhtml = preg_replace('/<\?xml version="1.0" ([^>]*)>/', "", $lessonhtml);

    // Pass 2 - convert XHTML into Word-compatible XHTML using localised table cell labels.
    if (!($tempxmlfilename = tempnam($CFG->tempdir, "p1o")) || (file_put_contents($tempxmlfilename, $lessonhtml) == 0)) {
        throw new \moodle_exception(get_string('cannotopentempfile', 'local_lesson_wordimport', $tempxmlfilename));
    }
    // Assemble the lesson contents and localised labels to a single XML file for easier XSLT processing.
    $pass2input = "<html>\n" . $lessonhtml .   "\n</html>";
    // Convert the XHTML string into a Word-compatible version, with images converted to Base64 data.
    $lessonword = $word2xml->export($pass2input, 'lesson');
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
        'lesson' => array('aliases', 'casesensitive', 'concept',  'categories', 'definition', 'entryusedynalink',
            'fullmatch', 'linking', 'pluginname'),
        'local_lesson_wordimport' => array('teacherentry'),
        'moodle' => array('no', 'yes', 'tags'),
        );

    $expout = "<moodlelabels>\n";
    foreach ($textstrings as $typegroup => $grouparray) {
        foreach ($grouparray as $stringid) {
            $namestring = $typegroup . '_' . $stringid;
            // Clean up question type explanation, in case the default text has been overridden on the site.
            $cleantext = get_string($stringid, $typegroup);
            $expout .= '<data name="' . $namestring . '"><value>' . $cleantext . "</value></data>\n";
        }
    }
    $expout .= "</moodlelabels>";
    $expout = str_replace("<br>", "<br/>", $expout);

    return $expout;
}
