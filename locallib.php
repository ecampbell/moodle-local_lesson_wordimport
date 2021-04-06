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
 * @copyright  2021 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/lesson/lib.php');
require_once($CFG->dirroot . '/mod/book/tool/wordimport/locallib.php');

use \booktool_wordimport\wordconverter;

/**
 * Convert the Word file into a set of HTML files and insert them the current lesson.
 *
 * @param string $wordfilename Word file to be imported
 * @param stdClass $lesson Lesson to import into
 * @param context_module $context Current course context
 * @param bool $verbose Print extra information
 * @return void
 */
function local_lesson_wordimport_import(string $wordfilename, stdClass $lesson, context_module $context, bool $verbose = false) {
    global $CFG, $OUTPUT, $DB, $USER;

    // Convert the Word file content into XHTML and an array of images.
    $heading1styleoffset = 3; // Map "Heading 1" styles to <h1>.
    // Pass 1 - convert the Word file content into XHTML and an array of images.
    $imagesforzipping = array();
    $word2xml = new wordconverter();
    $word2xml->set_heading1styleOffset($heading1styleoffset);
    $htmlcontent = $word2xml->import($wordfilename, $imagesforzipping);

    // Create a temporary Zip file to store the HTML and images for feeding to import function.
    $zipfilename = tempnam($CFG->tempdir, "zip");
    $zipfile = new ZipArchive;
    if (!($zipfile->open($zipfilename, ZipArchive::CREATE))) {
        // Cannot open zip file.
        throw new \moodle_exception('cannotopenzip', 'error');
    }

    // Add any images to the Zip file.
    $imagefilelist = "";
    if (count($imagesforzipping) > 0) {
        foreach ($imagesforzipping as $imagename => $imagedata) {
            $zipfile->addFromString($imagename, $imagedata);
            $imagefilelist .= ", " . $imagename;
        }
    }
    // Display extra messages.
    $trace = new html_progress_trace();
    if ($verbose) {
        $trace->output("Images: " . $imagefilelist);
    }

    // Split the HTML file into sections based on headings, and add the sections to the Zip file.
    booktool_wordimport_split($htmlcontent, $zipfile, false, $verbose);

    // Add the Zip file to the file storage area.
    $fs = get_file_storage();
    $zipfilerecord = array(
        'contextid' => $context->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => 0,
        'filepath' => "/",
        'filename' => basename($zipfilename)
        );
    $zipfile = $fs->create_file_from_pathname($zipfilerecord, $zipfilename);

    // Import the content into Lesson pages. Argument 2, value 2 means 1 page per HTML file.
    local_lesson_wordimport_import_lesson_pages($zipfile, 2, $lesson, $context, $verbose);
}

/**
 * Import HTML content into Lesson pages.
 *
 * This function consists of code copied from toolbook_importhtml_import_chapters() and modified for Lesson activity.
 *
 * @param stored_file $package
 * @param string $type type of the package ('typezipdirs' or 'typezipfiles')
 * @param stdClass $lesson
 * @param context_module $context
 * @param bool $verbose Display extra information messages
 * @return void
 */
function local_lesson_wordimport_import_lesson_pages(stored_file $package, string $type,
            stdClass $lesson, context_module $context, bool $verbose) {
    global $DB, $OUTPUT, $PAGE;

    // Array to store pages after they've been added to the database.
    $pages = array();

    // Display extra messages for debugging when verbose is true.
    $trace = new html_progress_trace();

    // Replace the standard lesson object with a real one, and get the current last page ID in the lesson.
    $lesson = new Lesson($lesson);
    $lastpage = $DB->get_field_sql('SELECT MAX(id) FROM {lesson_pages} WHERE lessonid = ?', array($lesson->id));

    // Prepare a temporary working area for the HTML and image files stored inside the Zip file.
    $fs = get_file_storage();
    $packer = get_file_packer('application/zip');
    $fs->delete_area_files($context->id, 'mod_lesson', 'importwordtemp', 0);
    $package->extract_to_storage($packer, $context->id, 'mod_lesson', 'importwordtemp', 0, '/');

    // Process the HTML files and insert them as Lesson pages.
    $pagefiles = toolbook_importhtml_get_chapter_files($package, $type);
    foreach ($pagefiles as $pagefile) {
        if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_lesson/importwordtemp/0/$pagefile->pathname"))) {
            $page = new stdClass();
            $page->pageid = $lastpage;
            $page->lessonid = $lesson->id;
            $page->type = 20; // Everything is a page for the moment, no questions.
            $page->qtype = 20; // Everything is a page for the moment, no questions.

            // Read the page title and body into separate fields.
            $htmlcontent = $file->get_content();
            $page->title = toolbook_importhtml_parse_title($htmlcontent, $pagefile->pathname);
            $page->contents_editor = array();
            $page->contents_editor['text'] = toolbook_importhtml_parse_body($htmlcontent);
            $page->contents_editor['format'] = FORMAT_HTML;
            // I don't know why we need both contents_editor['text'] and contents properties.
            $page->contents = $page->contents_editor['text'];

            if ($verbose) {
                $trace->output("Inserting page after " . $page->pageid . " (" . $pagefile->pathname . "): " . $page->title, 1);
            }

            // Import the content into Lesson pages.
            $lessonpage = lesson_page::create($page, $lesson, $context, $PAGE->course->maxbytes);
            // Use this pages ID the next time around.
            $lastpage = $lessonpage->id;
            // Remember the page information because we need to post-process image paths.
            $pages[$lessonpage->id] = $lessonpage;
        }
    }

    // Now process the pages to fix up image references and link the pages sequentially.
    if ($verbose) {
            $trace->output("Relinking lesson pages...", 0);
    }

    $allpages = $DB->get_records('lesson_pages', array('lessonid' => $lesson->id), 'id');
    foreach ($pages as $page) {
        // find references to all files and copy them + relink them.
        $matches = null;
        if (preg_match_all('/(src)\s*=\s*"([^"]+)"/i', $page->contents, $matches)) {
            $file_record = array('contextid' => $context->id, 'component' => 'mod_lesson', 'filearea' => 'page_contents', 'itemid' => $page->id);
            foreach ($matches[0] as $i => $match) {
                $filepath =  $matches[2][$i];
                $filepath = toolbook_importhtml_fix_path($filepath);

                if ($verbose) {
                        $trace->output("Found image link: page = $page->id, matched = " . $matches[2][$i] . ", filepath = " . $filepath, 0);
                }
                if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_lesson/importwordtemp/0$filepath"))) {
                    // Copy each image file from the temporary space to its proper location (mod_lesson/page_contents).
                    $fs->create_file_from_storedfile($file_record, $file);
                    // Add the standard Moodle prefix to the path in the img/@src attribute.
                    $page->contents = str_replace($match, $matches[1][$i] . '="@@PLUGINFILE@@' . $filepath . '"', $page->contents);
                }
            }
            // Write the modified HTML contents back to the Lesson page field.
            $DB->set_field('lesson_pages', 'contents', $page->contents, array('id' => $page->id));
        }
    }
    unset($pages);

    // TODO: Rewrite link references in the HTML.

    $fs->delete_area_files($context->id, 'mod_lesson', 'importwordtemp', 0);
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
 * @copyright 2017 Adam King, SHEilds eLearning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Retrieve and format question pages to include answers.
 *
 * @param stdClass $page A Lesson page
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

