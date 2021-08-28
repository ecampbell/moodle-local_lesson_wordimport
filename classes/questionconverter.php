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
 * Convert Moodle Lesson questions to XHTML and vice versa.
 *
 * @package    local_lesson_wordimport
 * @copyright  2021 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lesson_wordimport;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/lesson/lib.php');
require_once($CFG->dirroot . '/question/format/wordtable/format.php');

use \booktool_wordimport\wordconverter;
use \qformat_wordtable\mqxmlconverter;

/**
 * Convert Lesson questions into Moodle Question XML and then XHTML, and vice versa
 *
 * Convert Lesson question pages into XHTML for output, and XHTML into Lesson question pages for input.
 */
class questionconverter {

    /** @var array Mapping between lesson question page type names and numbers. */
    private $lessonpages = array();

    /** @var array Mapping between lesson question page type names and numbers. */
    private $lessonpagetypes = array(
        "shortanswer" => 1,
        "truefalse" => 2,
        "multichoice" => 3,
        "matching" => 5,
        "numerical" => 8,
        "essay" => 10,
        "lessonpage" => 20,
        "branchend" => 21,
        "clusterstart" => 30,
        "clusterend" => 31
    );

    /** @var array Mapping between lesson question page type names and numbers. */
    private $pagejumps = array(
        -1 => "nextpage",
        -40 => "previouspage",
        0 => "thispage",
        -9 => "endoflesson"
    );

    /** @var string Common XML fragment for all questions */
    private $commonqxml = '<generalfeedback format="html"><text></text></generalfeedback><defaultgrade>{defaultmark}</defaultgrade>
                <penalty>0.3333333</penalty><hidden>0</hidden>';

    /** @var string Default MCQ question */
    private $qmetadata = array('multichoice' => '<single>{singleanswer}</single><shuffleanswers>true</shuffleanswers>
                <answernumbering>ABCD</answernumbering><correctfeedback format="html"><text></text></correctfeedback>
                <incorrectfeedback format="html"><text></text></incorrectfeedback><shownumcorrect/>',
        'essay' => '<responseformat>editorfilepicker</responseformat><responserequired>1</responserequired><graderinfo format="html"><text>{jump}</text></graderinfo>',
                'essay2' => '<responsefieldlines>15</responsefieldlines><attachments>0</attachments>
                <attachmentsrequired>0</attachmentsrequired><graderinfo format="html"><text></text></graderinfo>
                <responsetemplate format="html"><text></text></responsetemplate>',
        'matching' => '<shuffleanswers>true</shuffleanswers><correctfeedback format="html"><text>{correctfeedback}</text></correctfeedback>
                <incorrectfeedback format="html"><text>{incorrectfeedback}</text></incorrectfeedback>',
        'numerical' => '',
        'shortanswer' => '<usecase>0</usecase>',
        'truefalse' => '');

    /**
     * Class constructor
     *
     * @param array $pages Pages object
     */
    public function __construct(array $pages) {
        // Keep track of the lesson pages to grab the titles when needed.
        $this->lessonpages = $pages;

        // Set common parameters for all XSLT transformations.
        $this->xsltparameters = array(
            'pluginname' => 'local_lesson_wordimport',
            'imagehandling' => 'referenced', // Question banks are embedded, Lessons are referenced.
            'heading1stylelevel' => 3, // Question banks are 1, Lessons should be overridden to 3.
            'debug_flag' => (debugging(null, DEBUG_DEVELOPER)) ? '1' : '0'
            );
    }

    /**
     * Convert Moodle Question XML into a Lesson question page.
     *
     * @param stdClass $page A Lesson page
     * @param string Moodle Question XML for conversion into XHTML
     * @return void
     */
    public function import_question(stdClass $page, string $mqxml) {

        $word2xml = new wordconverter($this->xsltparameters['pluginname']);
        $question2xml = new qformat_wordtable();
        $stylesheet = $question2xml->get_import_stylesheet();
        $questionxml = $word2xml->xsltransform($htmlcontent, $stylesheet);

        if (preg_match('/<question type="([^>]*)">(.+)<\/question>/is', $mqxml, $matches)) {
            $page->qtype = $this->get_pagetype_number([$matches[1]]);

            if (preg_match('/<questiontext[^>]*>[^<]*(.*)<\/questiontext>/is', $matches[2], $stemmatches)) {
                $questionstem = $stemmatches[1];
            }
            if (preg_match_all('/<answer fraction="([0-9]*)"[^>]+>[^<]*<text>![CDATA[(.*)]]><\/text>>/i', $head, $answers)) {
                // MCQ answers.
                $questionstem .= "<ol>";
                for ($i = 0; $i < count($answers[0]); $i++) {
                    $questionstem .= "<li>" . $answers[2][$i] . " (grade = " . $answers[1][$i] . ")</li>\n";
                }
                $questionstem .= "</ol>";
                $page->contents = $questionstem;
            }
        }
    }

    /**
     * Format Lesson question pages as XHTML tables suitable for round-trip editing.
     *
     * @param stdClass $page A Lesson page
     * @return XHTML table.
     */
    public function export_question($page) {
        $pagetype = $page->get_typeid();
        $answers = $page->answers;
        // Get the name of the question type.
        $pagetypes = array_flip($this->lessonpagetypes);
        $mqxmltype = $pagetypes[$pagetype];


        // Start with the standard XML common to all question types.
        $mqxml = '<quiz><question type="' . $mqxmltype . '">';
        $mqxml .= '<name><text>' . $page->title . '</text></name>';
        $mqxml .= '<questiontext format="html"><text>' . $page->contents . '</text></questiontext>';

        $grades_list = "";

        // Loop through the answers for some question types.
        switch ($mqxmltype) {
            case "multichoice":
                // Single or -multiple answer?
                $mqxml .= str_replace('{singleanswer}', ($page->qoption) ? 'false' : 'true', $this->qmetadata[$mqxmltype]);
                // Get the degault mark, which is the sum of all scores, and count the number of right and wrong answers.
                $defaultmark = 0;
                $nanswers = count($answers);
                $ncorrect = 0;
                $nincorrect = 0;
                foreach ($answers as $answer) {
                    $defaultmark += $answer->score;
                    if ($answer->score > 0) {
                        $ncorrect++;
                    } else {
                        $nincorrect++;
                    }
                }
                $mqxml .= str_replace('{defaultmark}', $defaultmark, $this->commonqxml);

                foreach ($answers as $answer) {
                    $answerstring = $this->get_jumplink($answer, $mqxmltype);
                    $responsestring = $answer->response;

                    // Calculate the grade from the current answer score and the total default marks.
                    $grade = 0;
                    if ($page->qoption == 0 && $answer->score > 0) { // Single-answer, correct.
                        $grade = 100;
                    } else if ($page->qoption == 0) { // Single-answer, incorrect.
                        $grade = 0;
                    } else if ($answer->score > 0) { // Multi-answer, correct, so calculate proportional positive score.
                        $grade = ($answer->score / $defaultmark) * 100;
                    } else { // Multi-answer, incorrect, calculate negative score.
                        $grade = - 100 / $nincorrect;
                    }
                    // Add the Jump to link to the answer.
                    $grades_list .= "$answer->answer: defaultmark = $defaultmark; score = $answer->score; grade = $grade\n";
                    $mqxml .= '<answer fraction="' . $grade . '" format="html"><text>' . $answerstring . '</text>';
                    $mqxml .= '<feedback format="html"><text><![CDATA[' . $responsestring . ']]></text></feedback>';
                    $mqxml .= '</answer>';
                }
                break;
            case "shortanswer":
            case "numerical":
                // Get the default mark, which is the highest of all scores.
                $defaultmark = 0;
                foreach ($answers as $answer) {
                    if ($answer->score > $defaultmark) {
                        $defaultmark = $answer->score;
                    }
                }
                $mqxml .= str_replace('{defaultmark}', $defaultmark, $this->commonqxml);

                // Process all the answers.
                foreach ($answers as $answer) {
                    if ($answer->answer == '@#wronganswer#@') {
                        $answerstring = get_string('allotheranswers', 'mod_lesson');
                    } else {
                        $answerstring = $answer->answer;
                    }
                    // Use the response/feedback field to store jump page.
                    $responsestring = $this->get_jumplink($answer, $mqxmltype);

                    // Calculate the grade from the current answer score and the total default marks.
                    $grade = 0;
                    if ($answer->score == $defaultmark) { // Best correct answer.
                        $grade = 100;
                    } else if ($answer->score > 0) { // Partially correct answer.
                        $grade = ($answer->score / $defaultmark) * 100;
                    }
                    // Add the Jump to link to the answer.
                    $grades_list .= "$answer->answer: defaultmark = $defaultmark; score = $answer->score; grade = $grade\n";
                    $mqxml .= '<answer fraction="' . $grade . '" format="moodle_auto_format"><text>' . $answerstring . '</text>';
                    $mqxml .= '<feedback format="html"><text>' . $responsestring . '</text></feedback>';
                    if ($mqxmltype == 'numerical') {
                        $mqxml .= '<tolerance>0</tolerance>';
                    }
                    $mqxml .= '</answer>';
                }
                if ($mqxmltype == 'numerical') {
                    $mqxml .= '<units><unit><multiplier>1</multiplier><unit_name></unit_name></unit></units>';
                    $mqxml .= '<unitgradingtype>1</unitgradingtype><unitpenalty>0.1000000</unitpenalty>';
                    $mqxml .= '<showunits>2</showunits><unitsleft>1</unitsleft>';
                }
                break;
            case "truefalse":
                $correctanswer = $answers[0];
                $mqxml .= str_replace('{defaultmark}', $correctanswer->score, $this->commonqxml);
                $correctjumplink = $this->get_jumplink($correctanswer, $mqxmltype);
                $mqxml .= '<answer fraction="100" format="html"><text>' . $correctjumplink . '</text>';
                $mqxml .= '<feedback format="html"><text><![CDATA[' . $correctanswer->response . ']]></text></feedback>';
                $mqxml .= '</answer>';

                $incorrectanswer = $answers[1];
                $incorrectjumplink = $this->get_jumplink($incorrectanswer, $mqxmltype);
                $mqxml .= '<answer fraction="0" format="html"><text>' . $incorrectjumplink . '</text>';
                $mqxml .= '<feedback format="html"><text><![CDATA[' . $incorrectanswer->response . ']]></text></feedback>';
                $mqxml .= '</answer>';
                break;
            case 'essay':
                $answer = $answers[0];
                $mqxml .= str_replace('{defaultmark}', $answer->score, $this->commonqxml);
                $jumplink = $this->get_jumplink($answer, $mqxmltype);
                $grades_list .= "$answer->answer: defaultmark = $answer->score;\n";
                $mqxml .= str_replace('{jump}',  $jumplink, $this->qmetadata[$mqxmltype]);

                break;
            case "matching":
                $correctanswer = $answers[0];
                $mqxml .= str_replace('{defaultmark}', $correctanswer->score, $this->commonqxml);
                $correctjumplink = $this->get_jumplink($correctanswer, $mqxmltype);
                $incorrectanswer = $answers[1];
                $incorrectjumplink = $this->get_jumplink($incorrectanswer, $mqxmltype);
                $grades_list .= "$correctanswer->answer: defaultmark = $correctanswer->score;\n";
                array_shift($answers); // Remove correct answer element.
                $grades_list .= "$incorrectanswer->answer: score = $incorrectanswer->score;\n";
                array_shift($answers); // Remove incorrect answer element.

                $matchingmetadata = str_replace('{correctfeedback}', $correctjumplink, $this->qmetadata[$mqxmltype]);
                $mqxml .= str_replace('{incorrectfeedback}', $incorrectjumplink, $matchingmetadata);
                // List the real answers.
                foreach ($answers as $answer) {
                    $mqxml .= '<subquestion format="html"><text>' . $answer->answer . '</text>';
                    $mqxml .= '<answer><text>' . $answer->response . '</text></answer>';
                    $mqxml .= '</subquestion>';
                }
                break;
        } // End switch.

        // Finish by adding the closing elements.
        $mqxml .= "</question></quiz>";

        // Convert the Moodle Question XML into Word-compatible XHTML.
        $mqxml2xhtml = new mqxmlconverter($this->xsltparameters['pluginname']);
        $xhtmldata = $mqxml2xhtml->convert_mqx2htm($mqxml, $this->xsltparameters['pluginname'], $this->xsltparameters['imagehandling']);
        return $xhtmldata;
    }

    /**
     * Convert Lesson page type label into a number
     *
     * @param string $label Question format page type name
     * @return int the numeric value of a Lesson page type
     */
    public function get_pagetype_number(string $label) {
        if (isset($this->lessonpagetypes[$label])) {
            return $this->lessonpagetypes[$label];
        } else {
            return 0;
        }
    }

    /**
     * Convert Lesson page type number into a label
     *
     * @param int $lpnum Lesson page type number
     * @return string Lesson page type label
     */
    public function get_pagetype_label(int $lpnum) {
        $pagetypes = array_flip($this->lessonpagetypes);
        if (isset($pagetypes[$lpnum])) {
            return $pagetypes[$lpnum];
        } else {
            return 0;
        }
    }

    /**
     * Check if page is a standard lesson page
     *
     * @param int $lpnum Lesson page type number
     * @return bool True if not a question page
     */
    public function is_lessonpage(int $lpnum) {
        if ($lpnum == $this->get_pagetype_number("lessonpage")) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get the jump link HTML
     *
     * @param lesson_page_answer $answer Answer details
     * @return string Link HTML
     */
    private function get_jumplink($answer, string $mqxmltype) {

        // First figure out what the visible text should be.
        $anchortext = $answer->answer;
        switch ($mqxmltype) {
            case "matching":
            case "essay":
                if ($answer->jumpto <= 0) {
                    $anchortext = get_string($this->pagejumps[$answer->jumpto], 'mod_lesson');
                } else {
                    $anchortext = $this->lessonpages[$answer->jumpto]->title;
                }
                break;
            case "shortanswer":
            case "numerical":
                if ($answer->response == '' and $answer->jumpto <= 0) {
                    $anchortext = get_string($this->pagejumps[$answer->jumpto], 'mod_lesson');
                } else if ($answer->response == '') {
                    $anchortext = $this->lessonpages[$answer->jumpto]->title;
                } else {
                    $anchortext = $answer->response;
                }
                break;
            case "multichoice":
            case "truefalse":
            default:
                break;
        }
        // Next figure out what the URL part should be.
        if ($answer->jumpto <= 0) {
            $linkid = $this->pagejumps[$answer->jumpto]; // For example: "previouspage", "nextpage".
        } else {
            $linkid = $answer->jumpto;
        }

        // Assemble the link and return it wrapped in a CDATA section for protection inside the Moodle Question XML.
        return '<![CDATA[<a href="#' . $linkid . '">' . $anchortext . '</a>]]>';
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
        $qconvert = new questionconverter();
        $pagetype_label = $qconvert->get_pagetype_label($page->get_typeid());
        // $pagetype = $page->get_typeid();
        $pagehtml = $page->contents;
        $answers = $page->answers;
        $qtype = $page->qtype;

        // Don't look for answers in lesson types.
        if ($qconvert->is_lessonpage($page->get_typeid()) === false) {
            return $pagehtml;
        }


        $pagehtml .= "<div class='export_answer_" . $pagetype_label . "_wrapper'>";

        foreach ($answers as $answer) {
            // If this is a matching question type, only print the answers, not responses.
            if ($pagetype_label == 'matching' && $answer->answerformat == 1) {
                continue;
            }

            $pagehtml .= "<div class='export_answer_$pagetype_label'>$answer->answer</div>";
        }

        $pagehtml .= "</div>";

        return $pagehtml;
    }
}
