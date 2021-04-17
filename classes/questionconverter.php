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

/**
 * Convert Lesson questions into Moodle Question XML and then XHTML, and vice versa
 *
 * Convert Lesson question pages into XHTML for output, and XHTML into Lesson question pages for input.
 */
class questionconverter {

    /** @var array Mapping between lesson question page type names and numbers. */
    private $lessonpagetypes = array(
        "shortanswer" => 1,
        "truefalse" => 2,
        "multichoice" => 3,
        "matching" => 5,
        "numerical" => 8,
        "essay" => 10,
        "lessonpage" => 20
    );

    /** @var string Common XML fragment for all questions */
    private $commonqxml = '<generalfeedback format="html"><text></text></generalfeedback>
                <defaultgrade>1.0000000</defaultgrade>
                <penalty>0.3333333</penalty>
                <hidden>0</hidden>
                <idnumber></idnumber>';

    /** @var string Default MCQ question */
    private $multichoicexml = '<single>true</single>
        <shuffleanswers>true</shuffleanswers>
        <answernumbering>ABCD</answernumbering>
        <correctfeedback format="html"><text></text></correctfeedback>
        <incorrectfeedback format="html"><text></text></incorrectfeedback>
        <shownumcorrect/>
        ';

    /** @var Essay question fragment */
    private $essayxml = '<responseformat>editorfilepicker</responseformat>
        <responserequired>1</responserequired>
        <responsefieldlines>15</responsefieldlines>
        <attachments>0</attachments>
        <attachmentsrequired>0</attachmentsrequired>
        <graderinfo format="html">
          <text></text>
        </graderinfo>
        <responsetemplate format="html">
          <text></text>
        </responsetemplate>';

    /** @var Short answer question fragment */
    private $shortanswerxml = '<usecase>0</usecase>';

    /** @var Short answer question fragment */
    private $matchingxml = '<shuffleanswers>true</shuffleanswers>
        <correctfeedback format="html">
          <text></text>
        </correctfeedback>
        <partiallycorrectfeedback format="html">
          <text></text>
        </partiallycorrectfeedback>
        <incorrectfeedback format="html">
          <text></text>
        </incorrectfeedback>';

    /**
     * Convert Moodle Question XML into a Lesson question page.
     *
     * @param stdClass $page A Lesson page
     * @return void
     */
    public function import_question(stdClass $page, string $mqxml) {

        $word2xml = new wordconverter('lesson_wordimport');
        $question2xml = new qformat_wordtable();
        $stylesheet = $question2xml->get_import_stylesheet();
        $questionxml = $word2xml->convert($htmlcontent, $stylesheet);

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
        $pagehtml = $page->contents;

        // Return the HTML if its a standard lesson page.
        // TODO: Handle jumps to other pages.
        if ($this->is_lessonpage($pagetype)) {
            return $pagehtml;
        }

        // Get the name of the question type.
        $pagetypes = array_flip($this->lessonpagetypes);
        $mqxmltype = $pagetypes[$pagetype];

        // Start with the standard XML common to all question types.
        $mqxml = '<question type="' . $mqxmltype . '">';
        $mqxml .= '<name><text>' . $page->title . '</text></name>';
        $mqxml .= '<questiontext format="html"><text>' . $pagehtml . '</text></questiontext>';
        $mqxml .= $this->commonqxml;

        // Now do the stuff that's specific to each question type.
        switch ($mqxmltype) {
            case "shortanswer":
                $mqxml .= $this->shortanswerxml;
                break;
            case "truefalse":
                break;
            case "multichoice":
                $mqxml .= $this->multichoicexml;
                break;
            case "matching":
                $mqxml .= $this->shortanswerxml;
                break;
            case "numerical":
                break;
            case "essay":
                $mqxml .= $this->essayxml;
            default:
                break;
        }

        // Loop through the answers for some question types.
        $answers = $page->answers;
        if ($mqxmltype == "multichoice" || $mqxmltype == "truefalse") {
            foreach ($answers as $answer) {
                // Handle Boolean special case.
                if ($mqxmltype == "truefalse") {
                    $answerstring = ($answer->score == 0) ? 'false' : 'true';
                } else {
                    $answerstring = $answer->answer;
                }
                // Is there a jump associated with this answer?
                $jumpto = ' (Next: ' . $answer->nextpageid . ')';

                $responsestring = $answer->response;
                $mqxml .= '<answer fraction="' . $answer->grade . '" format="html"><text>' . $answerstring . $jumpto . '</text>';
                $mqxml .= '<feedback format="html"><text>' . $responsestring . '</text></feedback>';
                $mqxml .= '</answer>';
            }
        } else if ($mqxmltype == "matching") {
            // Could print page object here.
            foreach ($answers as $answer) {
                $answerstring = $answer->answer;
                $responsestring = $answer->response;
                // Is there a jump associated with this answer?
                switch ($answer->jumpto) {
                    case 0: // This page.
                        $jumpto = ' (Next: ' .$page->id . ')';
                        break;
                    case -1: // Next page.
                        $jumpto = ' (Next: ' . $page->nextpageid . ')';
                        break;
                    default:
                        break;
                }

                $mqxml .= '<subquestion format="html"><text>' . $answerstring . $jumpto . '</text>';
                $mqxml .= '<answer><text>' . $responsestring . '</text></answer>';
                $mqxml .= '</subquestion>';
            }
        }

        // Finish by adding the closing element.
        $mqxml .= "</question>";

        // Wrap the Moodle Question XML and the labels data in a single XML container for processing into XHTML tables.
        $question2xml = new \qformat_wordtable();
        $questionlabels = $question2xml->get_core_question_labels();
        $mqxml = "<container>\n<quiz>" . $mqxml . "</quiz>\n" . $questionlabels . "\n</container>";
        $stylesheet = $question2xml->get_export_stylesheet();

        // Convert the Moodle Question XML into XHTML.
        $word2xml = new wordconverter('lesson_wordimport');
        $pagehtml = $word2xml->convert($mqxml, $stylesheet);
        return $pagehtml;
    }

    /**
     * Convert Lesson page type label into a number
     *
     * @param string $qlabel Question format page type name
     * @return int the numeric value of a Lesson page type
     */
    public function get_pagetype_number(string $label) {
        return $this->lessonpagetypes[$label];
    }

    /**
     * Convert Lesson page type number into a label
     *
     * @param int $lpnum Lesson page type number
     * @return string Lesson page type label
     */
    public function get_pagetype_label(int $lpnum) {
        $pagetypes = array_flip($this->lessonpagetypes);
        return $pagetypes[$lpnum];
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
}
