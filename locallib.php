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
    $lessonhtml = "";

    // Loop through the lesson pages and process each one.
    foreach ($pages as $page) {
        $answers = $page->get_answers();
        $contents = $page->contents;

        // Append answers to the end of question pages.
        // TODO: Should include questions too.
        $contents = local_lesson_wordimport_format_answers($page);

        // Fix pluginfile urls.
        $contents = file_rewrite_pluginfile_urls($contents, 'pluginfile.php', $context->id,
                                                      'mod_lesson', 'page_contents', $page->id);
        $contents = format_text($contents, FORMAT_MOODLE, array('overflowdiv' => false, 'allowid' => true, 'para' => false));
        $lessonhtml .= '<h1>' . $page->title . '</h1>' . $contents;
    }


    // Assemble the lesson contents and localised labels to a single XML file for easier XSLT processing.
    $pass2input = "<html>\n" . $lessonhtml .   "\n</html>";
    // Convert the XHTML string into a Word-compatible version, with images converted to Base64 data.
    $word2xml = new wordconverter();
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
        'lesson' => array('modulename', 'modulename_help', 'modulename_link', 'pluginname'),
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
    $contents = $page->contents;
    $answers = $page->answers;
    $qtype = $page->qtype;

    // Don't look for answers in lesson types and don't print
    // short answer answer patterns.
    if ($pagetype == 1 || $pagetype == 20) {
        return $contents;
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

    $contents .= "<div class='export_answer_".$pagetype."_wrapper'>";

    foreach ($answers as $answer) {
        // If this is a matching question type, only print the answers, not responses.
        if ($pagetype == 5 && $answer->answerformat == 1) {
            continue;
        }

        $contents .= "<div class='export_answer_$pagetype'>$answer->answer</div>";
    }

    $contents .= "</div>";

    return $contents;
}

/**
 * Convert an image URL into a stored_file object, if it refers to a local file.
 * @param $fileurl
 * @param context $restricttocontext (optional) if set, only files from this lesson will be included
 * @return null|stored_file
 */
function local_lesson_wordimport_get_image_file($fileurl, $restricttocontext = null) {
    global $CFG;
    if (strpos($fileurl, $CFG->wwwroot.'/pluginfile.php') === false) {
        return null;
    }

    $fs = get_file_storage();
    $params = substr($fileurl, strlen($CFG->wwwroot.'/pluginfile.php'));
    if (substr($params, 0, 1) == '?') { // Slasharguments off.
        $pos = strpos($params, 'file=');
        $params = substr($params, $pos + 5);
    } else { // Slasharguments on.
        if (($pos = strpos($params, '?')) !== false) {
            $params = substr($params, 0, $pos - 1);
        }
    }
    $params = urldecode($params);
    $params = explode('/', $params);
    array_shift($params); // Remove empty first param.
    $contextid = (int)array_shift($params);
    $component = clean_param(array_shift($params), PARAM_COMPONENT);
    $filearea  = clean_param(array_shift($params), PARAM_AREA);
    $itemid = array_shift($params);

    if (empty($params)) {
        $filename = $itemid;
        $itemid = 0;
    } else {
        $filename = array_pop($params);
    }

    if (empty($params)) {
        $filepath = '/';
    } else {
        $filepath = '/'.implode('/', $params).'/';
    }

    if ($restricttocontext) {
        if ($component != 'mod_lesson' || $contextid != $restricttocontext->id) {
            return null; // Only allowed to include files directly from this lesson.
        }
    }

    if (!$file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename)) {
        if ($itemid) {
            $filepath = '/'.$itemid.$filepath; // See if there was no itemid in the originalPath URL.
            $itemid = 0;
            $file = $fs->get_file($contextid, $component, $filename, $itemid, $filepath, $filename);
        }
    }

    if (!$file) {
        return null;
    }
    return $file;
}


/**
 * Clean lesson page HTML, ensuring <img> tags are handled correctly.
 *
 * @param html The HTML string to clean.
 * @param title The title of the page the HTML is for.
 * @return string Cleaned up HTML
 */
function local_lesson_wordimport_add_html($html, $title) {
    if ($config['tidy'] && class_exists('tidy')) {
        $tidy = new tidy();
        $tidy->parseString($html, array(), 'utf8');
        $tidy->cleanRepair();
        $html = $tidy->html()->value;
    }

    // Handle <img> tags.
    if (preg_match_all('~(<img [^>]*?)src=([\'"])(.+?)[\'"]~', $html, $matches)) {
        foreach ($matches[3] as $imageurl) {
            if ($file = local_lesson_wordimport_get_image_file($imageurl)) {
                $newpath = implode('/', array('images', $file->get_contextid(), $file->get_component(), $file->get_filearea(),
                                              $file->get_itemid(), $file->get_filepath(), $file->get_filename()));
                $newpath = str_replace(array('///', '//'), '/', $newpath);
                // Should we add image data here?
                // local_lesson_wordimport_add_item_file($file->get_content_file_handle(), $file->get_mimetype(), $newpath);
                $html = str_replace($imageurl, $newpath, $html);
            }
        }
    }
    return $html;
}