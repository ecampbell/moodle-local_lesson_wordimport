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

use booktool_wordimport\wordconverter;
use qformat_wordtable\mqxmlconverter;

/**
 * Convert Lesson questions into Moodle Question XML and then XHTML, and vice versa
 *
 * Convert Lesson question pages into XHTML for output, and XHTML into Lesson question pages for input.
 */
class questionconverter {

    /** @var array Mapping between lesson question page type names and numbers. */
    private $lessonpages = [];

    /** @var array Mapping between lesson question page type names and numbers. */
    private $lessonpagetypes = [
        "shortanswer" => 1,
        "truefalse" => 2,
        "multichoice" => 3,
        "matching" => 5,
        "numerical" => 8,
        "essay" => 10,
        "lessonpage" => 20,
        "branchend" => 21,
        "clusterstart" => 30,
        "clusterend" => 31,
    ];

    /** @var array Mapping between lesson question page type names and numbers. */
    private $pagejumps = [
        "nextpage" => -1,
        "previouspage" => -40,
        "thispage" => 0,
        "endoflesson" => -9,
    ];

    /** @var string Common XML fragment for all questions */
    private $commonqxml = '<generalfeedback format="html"><text></text></generalfeedback><defaultgrade>{defaultmark}</defaultgrade>
                <penalty>0.3333333</penalty><hidden>0</hidden>';

    /** @var string Default MCQ question */
    private $qmetadata = ['multichoice' => '<single>{singleanswer}</single><shuffleanswers>true</shuffleanswers>
                <answernumbering>ABCD</answernumbering><correctfeedback format="html"><text></text></correctfeedback>
                <incorrectfeedback format="html"><text></text></incorrectfeedback><shownumcorrect/>',
        'essay' => '<responseformat>editorfilepicker</responseformat><responserequired>1</responserequired>
                <graderinfo format="html"><text>{jump}</text></graderinfo>',
        'matching' => '<shuffleanswers>true</shuffleanswers>
                <correctfeedback format="html"><text>{correctfeedback}</text></correctfeedback>
                <incorrectfeedback format="html"><text>{incorrectfeedback}</text></incorrectfeedback>',
        'numerical' => '',
        'shortanswer' => '<usecase>0</usecase>',
        'truefalse' => '',
        ];

    /**
     * Class constructor
     *
     * @param array $pages Pages object
     */
    public function __construct(array $pages) {
        // Keep track of the lesson pages to grab the titles when needed.
        $this->lessonpages = $pages;

        // Set common parameters for all XSLT transformations.
        $this->xsltparameters = [
            'pluginname' => 'local_lesson_wordimport',
            'imagehandling' => 'referenced', // Question banks are embedded, Lessons are referenced.
            'heading1stylelevel' => 3, // Question banks are 1, Lessons should be overridden to 3.
            'debug_flag' => (debugging(null, DEBUG_DEVELOPER)) ? '1' : '0',
            ];
    }

    /**
     * Convert a question in XHTML format into a Moodle Question XML string.
     *
     * @param string $xhtmlcontent Moodle Question XML for conversion into XHTML
     * @return string
     */
    public function import_question(string $xhtmlcontent) {
        // Convert the question table into Moodle Question XML.
        $mqxml2xhtml = new mqxmlconverter($this->xsltparameters['pluginname']);
        $mqxml = $mqxml2xhtml->convert_htm2mqx($xhtmlcontent, $this->xsltparameters['imagehandling']);
        return $mqxml;
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

        $gradeslist = "";

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
                    $gradeslist .= "$answer->answer: defaultmark = $defaultmark; score = $answer->score; grade = $grade\n";
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
                    $gradeslist .= "$answer->answer: defaultmark = $defaultmark; score = $answer->score; grade = $grade\n";
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
                $gradeslist .= "$answer->answer: defaultmark = $answer->score;\n";
                $mqxml .= str_replace('{jump}',  $jumplink, $this->qmetadata[$mqxmltype]);

                break;
            case "matching":
                $correctanswer = $answers[0];
                $mqxml .= str_replace('{defaultmark}', $correctanswer->score, $this->commonqxml);
                $correctjumplink = $this->get_jumplink($correctanswer, $mqxmltype);
                $incorrectanswer = $answers[1];
                $incorrectjumplink = $this->get_jumplink($incorrectanswer, $mqxmltype);
                $gradeslist .= "$correctanswer->answer: defaultmark = $correctanswer->score;\n";
                array_shift($answers); // Remove correct answer element.
                $gradeslist .= "$incorrectanswer->answer: score = $incorrectanswer->score;\n";
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
        $xhtmldata = $mqxml2xhtml->convert_mqx2htm($mqxml, $this->xsltparameters['pluginname'],
                $this->xsltparameters['imagehandling']);
        return $xhtmldata;
    }

    /**
     * Get the jump links for a content page.
     *
     * @param stdClass $page A Lesson page
     * @return XHTML table.
     */
    public function get_jumps($page) {
        $pagehtml = '<table><thead><tr><td colspan="2" style="width: 12.0cm">{content}</td>' .
                '<td style="width: 1.0cm"><p style="QFType">LE</p></td></tr>' .
                '<tr><th style="width: 1.0cm"><p style="TableHead">&#160;</p></th>' .
                '<th style="width: 11.0cm"><p style="TableHead">' . get_string('description', 'mod_lesson') . '/' .
                get_string('jump', 'mod_lesson') . '</p></th>' .
                '<th style="width: 1.0cm"><p style="TableHead">&#160;</p></th></tr>';
        foreach ($page->answers as $answer) {
            $pagehtml .= '<tr><td style="width: 1.0cm"><p style="Cell">&#160;</p></td>' .
                '<td style="width: 11.0cm"><a href="#' . $this->pagejumps[$answer->jumpto] . '">' .
                $answer->answer . '</a></td>' .
                '<td style="width: 1.0cm"><p style="Cell">&#160;</p></td></tr>';
        }
        $pagehtml .= "</tbody></table>";
        return $pagehtml;
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
     * Convert Jump type label into a number
     *
     * @param string $label Page jump name
     * @return int|bool the numeric value of a page jump
     */
    public function get_pagejump_number(string $label) {
        if (isset($this->pagejumps[$label])) {
            return $this->pagejumps[$label];
        } else {
            return false;
        }
    }

    /**
     * Convert page jump number into a label
     *
     * @param int $lpnum Page jump number
     * @return string|bool Page jump label
     */
    public function get_pagejump_label(int $lpnum) {
        $pagejumps = array_flip($this->pagejumps);
        if (isset($pagejumps[$lpnum])) {
            return $pagejumps[$lpnum];
        } else {
            return false;
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
     * @param string $mqxmltype Question type
     * @return string Link HTML
     */
    public function get_jumplink($answer, string $mqxmltype) {

        // First figure out what the visible text should be.
        $anchortext = $answer->answer;
        switch ($mqxmltype) {
            case "cluster":
            case "clusterend":
            case "branchend":
                $answer->answer;
                break;
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
                if ($answer->response == '' && $answer->jumpto <= 0) {
                    $anchortext = get_string($this->pagejumps[$answer->jumpto], 'mod_lesson');
                } else if ($answer->response == '') {
                    $anchortext = $this->lessonpages[$answer->jumpto]->title;
                } else {
                    $anchortext = $answer->response;
                }
                break;
            case "multichoice":
            case "truefalse":
                break;
            case "nextpage":
            case "previouspage":
            case "thispage":
            case "endoflesson":
            default:
                break;
        }
        // Next figure out what the URL part should be.
        if ($answer->jumpto <= 0) {
            $linkid = $this->pagejumps[$answer->jumpto]; // For example: previouspage.
        } else {
            $linkid = $answer->jumpto;
        }

        // Assemble the link and return it wrapped in a CDATA section for protection inside the Moodle Question XML.
        return '<![CDATA[<a href="#' . $linkid . '">' . $anchortext . '</a>]]>';
    }
}
