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
use \question\format\xml;
use \mod\lesson\format;

/**
 * Convert the Word file into a set of HTML files and insert them the current lesson.
 *
 * @param string $wordfilename Word file to be imported
 * @param stdClass $lesson Lesson to import into
 * @param context_module $context Current course context
 * @param int $pageid Current page ID
 * @param bool $horizontaljumps Jump button layout
 * @param bool $displaymenu Display menu on left
 * @param bool $endjump Add end of lesson jump to each content page
 * @param bool $verbose Print extra information
 * @return void
 */
function local_lesson_wordimport_import(string $wordfilename, stdClass $lesson, context_module $context, int $pageid,
            bool $horizontaljumps, bool $displaymenu, bool $previousjump, bool $endjump, bool $verbose = false) {
    global $CFG,  $DB, $PAGE;

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

    $xsltparameters = array('pluginname' => 'local_lesson_wordimport',
            'heading1stylelevel' => 3, // Map "Heading 1" style to <h3> element.
            'imagehandling' => 'referenced'
        );

    // Prepare a temporary working area for the HTML and image files stored inside the Zip file.
    $fs = get_file_storage();
    $packer = get_file_packer('application/zip');
    $fs->delete_area_files($context->id, 'mod_lesson', 'importwordtemp', 0);
    $zipfile->extract_to_storage($packer, $context->id, 'mod_lesson', 'importwordtemp', 0, '/');

    // Replace the standard lesson object with a real one, and get the current last page ID in the lesson.
    $lesson = new Lesson($lesson);
    $currentpages = $lesson->load_all_pages();
    $lastpageid = $DB->get_field_sql('SELECT MAX(id) FROM {lesson_pages} WHERE lessonid = ?', array($lesson->id));

    // Process the HTML files and insert them as Lesson pages. Argument 2 specifies whether Zip file contains directories.
    $newpages = array(); // Array to store pages after they've been added to the database.
    $qconverter = new questionconverter($currentpages);
    $pagefiles = toolbook_importhtml_get_chapter_files($zipfile, 2);
    foreach ($pagefiles as $pagefile) {
        if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_lesson/importwordtemp/0/$pagefile->pathname"))) {
            $page = new stdClass();
            $page->properties = $lesson->properties();
            // If this is the first page in a new lesson, set some default lesson properties which seem to work.
            // Otherwise the first page can't be created.
            if ($lastpageid == 0) {
            } else {
                $page->pageid = $lastpageid;
            }
            $page->lessonid = $lesson->id;
            $page->timecreated = time();

            // Prepare a default answer set.
            $newanswer = new stdClass();
            $newanswer->lessonid = $lesson->id;
            $newanswer->pageid = $page->pageid;
            $newanswer->timecreated = $page->timecreated;

            // Read the page title and body into separate fields.
            $htmlcontent = $file->get_content();
            $page->title = toolbook_importhtml_parse_title($htmlcontent, $pagefile->pathname);

            // Is this a Question page?
            if (stripos($htmlcontent, 'moodleQuestion') !== false) {
                // Convert XHTML into Moodle Question XML, ignoring images.
                $mqxml = $qconverter->import_question($htmlcontent);

                // Save the MQ XML to a file, and convert it into a question in the lesson.
                if (!($tempxmlfilename = tempnam($CFG->tempdir, "mqx")) || (file_put_contents($tempxmlfilename, $mqxml)) == 0) {
                    throw new \moodle_exception(get_string('cannotopentempfile', 'booktool_wordimport', $tempxmlfilename));
                }
                // Rename the temporary file to give it an xml suffix or import operation fails.
                $xmlfilename = dirname($tempxmlfilename) . DIRECTORY_SEPARATOR . basename($tempxmlfilename, '.tmp') . '.xml';
                rename($tempxmlfilename, $xmlfilename);

                $formatclass = 'qformat_xml';
                require_once($CFG->dirroot.'/question/format/xml/format.php');
                $format = new $formatclass();
                // $format->set_importcontext($context);
                $format->setFilename($xmlfilename);
                if (!($format->importprocess($xmlfilename, $lesson, $lastpageid))) {
                    unlink($xmlfilename);
                    throw new \moodle_exception(get_string('processerror', 'lesson'));
                }
            } else {
                $page->qtype = $qconverter->get_pagetype_number("lessonpage");
                $page->type = $page->qtype;
                $page->layout = $horizontaljumps;
                $page->display = $displaymenu;
                $page->contents_editor = array();
                $page->contents_editor['text'] = toolbook_importhtml_parse_body($htmlcontent);
                $page->contents_editor['format'] = FORMAT_HTML;
                // I don't know why we need both contents_editor['text'] and contents properties.
                $page->contents = $page->contents_editor['text'];

                // Configure automatic jumps for the page, since they are not explicitly included.
                $i = 0;
                $answers = array();
                // Add jump to next page.
                $answer = clone($newanswer);
                $answer->jumpto = $qconverter->get_pagejump_number('nextpage');
                $answer->answer = get_string('nextpage', 'mod_lesson');
                $answer->id = $DB->insert_record("lesson_answers", $answer);
                $answers[$answer->id] = new lesson_page_answer($answer);
                $answers[$i] = $answer;
                $i++;

                // Add jump to previous page if we're not the first page.
                if ($lastpageid != 0 && $previousjump) {
                    $answer = clone($newanswer);
                    $answer->jumpto = $qconverter->get_pagejump_number('previouspage');
                    $answer->answer = get_string('previouspage', 'mod_lesson');
                    $answer->id = $DB->insert_record("lesson_answers", $answer);
                    $answers[$answer->id] = new lesson_page_answer($answer);
                    $answers[$i] = $answer;
                    $i++;
                }

                // Add jump to end-of-lesson if requested.
                if ($endjump) {
                    $answer = clone($newanswer);
                    $answer->jumpto = $qconverter->get_pagejump_number('endoflesson');
                    $answer->answer = get_string('endoflesson', 'mod_lesson');
                    $answer->id = $DB->insert_record("lesson_answers", $answer);
                    $answers[$answer->id] = new lesson_page_answer($answer);
                    $answers[$i] = $answer;
                    $i++;
                }
                $page->answers = $answers;

                // Import the content into Lesson pages.
                $lessonpage = lesson_page::create($page, $lesson, $context, $PAGE->course->maxbytes);
            }

            // Use this pages ID the next time around.
            $lastpageid = $lessonpage->id;
            // Remember the page information because we need to post-process image paths.
            $newpages[$lessonpage->id] = $lessonpage;
        }
    }

    // Now process the pages to fix up image references.
    foreach ($newpages as $page) {
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
    unset($newpages);

    // TODO: Rewrite link references in the HTML.

    $fs->delete_area_files($context->id, 'mod_lesson', 'importwordtemp', 0);
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

    $qconvert = new questionconverter($pages);
    $word2xml = new wordconverter('local_lesson_wordimport');

    // Loop through the lesson pages and process each one.
    foreach ($pages as $page) {
        // Append answers to the end of question pages.
        $pagetype = $qconvert->get_pagetype_label($page->qtype);
        switch ($pagetype) {
            case "branchend":
            case "clusterstart":
            case "clusterend":
                $pagehtml = $page->contents;
                break;
            case "lessonpage":
                $pagehtml = $qconvert->get_jumps($page);
                $pagehtml = str_replace('{content}', $page->contents, $pagehtml);
                break;
            case "essay":
            case "matching":
            case "multichoice":
            case "numerical":
            case "shortanswer":
            case "truefalse":
                $pagehtml = $qconvert->export_question($page);
                 break;
            default:
                 break;
        }
        if ($qconvert->is_lessonpage($page->type)) {
        } else {  // Some kind of question page.
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
        $lessonhtml .= '<h1>' . $page->title . '</h1>' . "\n" . $pagehtml . $imagestring . '</div>' ."\n";
    }

    // Wrap the lesson contents in a HTML file.
    $lessonhtml = "<html><head><title>Fred</title></head><body>" . $lessonhtml . "</body></html>";
    // Convert the XHTML string into a Word-compatible version, with images converted to Base64 data.
    $moodlelabels = local_lesson_wordimport_get_text_labels();
    $lessonword = $word2xml->export($lessonhtml, 'local_lesson_wordimport', $moodlelabels, 'referenced');
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
