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

use \booktool_wordimport\wordconverter;
use \local_lesson_wordimport\questionconverter;

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
    global $CFG;

    // Convert the Word file content into XHTML and an array of images.
    $imagesforzipping = array();
    $word2xml = new wordconverter('local_lesson_wordimport');
    $htmlcontent = $word2xml->import($wordfilename, $imagesforzipping);

    // Store images in a Zip file and split the HTML file into sections.
    // Add the sections to the Zip file and store it in Moodles' file storage area.
    $zipfilename = tempnam($CFG->tempdir, "zip");
    $zipfile = $word2xml->zip_images($zipfilename, $imagesforzipping);
    $word2xml->split_html($htmlcontent, $zipfile, false, $verbose);
    $zipfile = $word2xml->store_html($zipfilename, $zipfile, $context);
    unlink($zipfilename);

    // Import the content into Lesson pages.
    local_lesson_wordimport_import_lesson_pages($zipfile, $lesson, $context, $verbose);
}

/**
 * Export Lesson pages to a Word file
 *
 * @param stdClass $lesson Lesson to export
 * @param context_module $context Current course context
 * @return string HTML string with embedded image data
 */
function local_lesson_wordimport_export(stdClass $lesson, context_module $context) {
    global $CFG;

    // Gather all the lesson content into a single HTML string.
    $lesson = new Lesson($lesson);
    $pages = $lesson->load_all_pages();
    $pageids = array_keys($pages);
    // Set the Lesson name to be the Word file title.
    $lessonhtml = '<p class="MsoTitle">' . $lesson->name . "</p>\n";
    // Add the Description field.
    // TODO: figure out how to include images, using file_rewrite_pluginfile_urls().
    $lessonhtml .= $lesson->intro;

    $word2xml = new wordconverter('local_lesson_wordimport');

    // Loop through the lesson pages and process each one.
    $qconvert = new questionconverter($pages);
    foreach ($pages as $page) {
        // Append answers to the end of question pages.
        if ($qconvert->is_lessonpage($page->type)) {
            $pagehtml = $page->contents;
        } else {  // Some kind of question page.
            $pagehtml = $qconvert->export_question($page);
        }

        // Could use format_text($pagehtml, FORMAT_MOODLE, array('overflowdiv' => false, 'allowid' => true, 'para' => false));.
        // Revert image paths back to @@PLUGINFILE@@ so that export function works properly.
        // Must revert after format_text(), or a debug developer error is triggered.

        // Grab the images, convert any GIFs to PNG, and return the list of converted images.
        $giffilenames = array();
        $imagestring = $word2xml->base64_images($context->id, 'mod_lesson', 'page_contents', $page->id, $giffilenames);

        // Grab the page text content, and update any GIF image names to the new PNG name.
        $pagehtml = file_rewrite_pluginfile_urls($pagehtml, 'pluginfile.php', $context->id,
                                  'mod_lesson', 'page_contents', $page->id, array('reverse' => true));
        if (count($giffilenames) > 0) {
            $pagehtml = str_replace($giffilenames['gif'], $giffilenames['png'], $pagehtml);
        }

        $lessonhtml .= '<div class="chapter" id="page' . $page->id . '">' . "\n";
        $lessonhtml .= '<h1>' . $page->title . '</h1>' . "\n" . $pagehtml . $imagestring . '</div>';
    }

    // Assemble the lesson contents and localised labels to a single XML file for easier XSLT processing.
    // Convert the XHTML string into a Word-compatible version, with images converted to Base64 data.
    $moodlelabels = local_lesson_wordimport_get_text_labels();
    $lessonword = $word2xml->export($lessonhtml, 'local_lesson_wordimport', $moodlelabels, 'embedded');
    return $lessonword;
}

/**
 * Import HTML content into Lesson pages.
 *
 * This function consists of code copied from toolbook_importhtml_import_chapters() and modified for Lesson activity.
 *
 * @param stored_file $package
 * @param stdClass $lesson
 * @param context_module $context
 * @param bool $verbose Display extra information messages
 * @return void
 */
function local_lesson_wordimport_import_lesson_pages(stored_file $package,
                stdClass $lesson, context_module $context, bool $verbose = false) {
    global $DB, $PAGE;

    // Array to store pages after they've been added to the database.
    $pages = array();

    // Display extra messages for debugging when verbose is true. $trace = new html_progress_trace();.

    // Replace the standard lesson object with a real one, and get the current last page ID in the lesson.
    $lesson = new Lesson($lesson);
    $lastpage = $DB->get_field_sql('SELECT MAX(id) FROM {lesson_pages} WHERE lessonid = ?', array($lesson->id));

    // Prepare a temporary working area for the HTML and image files stored inside the Zip file.
    $fs = get_file_storage();
    $packer = get_file_packer('application/zip');
    $fs->delete_area_files($context->id, 'mod_lesson', 'importwordtemp', 0);
    $package->extract_to_storage($packer, $context->id, 'mod_lesson', 'importwordtemp', 0, '/');

    // Process the HTML files and insert them as Lesson pages. Argument 2 specifies whether Zip file contains directories.
    $pagefiles = toolbook_importhtml_get_chapter_files($package, 2);
    foreach ($pagefiles as $pagefile) {
        if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_lesson/importwordtemp/0/$pagefile->pathname"))) {
            $page = new stdClass();
            $page->pageid = $lastpage;
            $page->lessonid = $lesson->id;

            // Read the page title and body into separate fields.
            $htmlcontent = $file->get_content();
            // Is this a Question page?
            if (stripos($htmlcontent, 'moodleQuestion') === true) {
                $page->type = 20; // TODO: support importing question pages.
                $page->qtype = 20;
            } else {
                $page->type = 20; // Everything is a page for the moment, no questions.
                $page->qtype = 20;
            }

            $page->title = toolbook_importhtml_parse_title($htmlcontent, $pagefile->pathname);
            $page->contents_editor = array();
            $page->contents_editor['text'] = toolbook_importhtml_parse_body($htmlcontent);
            $page->contents_editor['format'] = FORMAT_HTML;
            // I don't know why we need both contents_editor['text'] and contents properties.
            $page->contents = $page->contents_editor['text'];

            // Import the content into Lesson pages.
            $lessonpage = lesson_page::create($page, $lesson, $context, $PAGE->course->maxbytes);
            // Use this pages ID the next time around.
            $lastpage = $lessonpage->id;
            // Remember the page information because we need to post-process image paths.
            $pages[$lessonpage->id] = $lessonpage;
        }
    }

    // Now process the pages to fix up image references.
    // $allpages = $DB->get_records('lesson_pages', array('lessonid' => $lesson->id), 'id');
    foreach ($pages as $page) {
        // Find references to all image files and copy them.
        $matches = null;
        if (preg_match_all('/src="([^"]+)"/i', $page->contents, $matches)) {
            $filerecord = array('contextid' => $context->id, 'component' => 'mod_lesson', 'filearea' => 'page_contents',
                            'itemid' => $page->id);
            $dbhtml = $page->contents; // Copy page content temporarily, as $page->contents causes absolute URL in the image.
            foreach ($matches[0] as $i => $match) {
                $filepath = '/' . $matches[1][$i];
                if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_lesson/importwordtemp/0$filepath"))) {
                    // Copy each image file from the temporary space to its proper location (mod_lesson/page_contents).
                    $fs->create_file_from_storedfile($filerecord, $file);

                    // Prepend the default string before the image name in the src attribute, only if image found.
                    $dbhtml = str_replace($match, 'src="@@PLUGINFILE@@' . $filepath . '"', $dbhtml);
                }
            }
            $DB->set_field('lesson_pages', 'contents', $dbhtml, array('id' => $page->id));
        }
    }
    unset($pages);

    // TODO: Rewrite link references in the HTML.

    $fs->delete_area_files($context->id, 'mod_lesson', 'importwordtemp', 0);
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
