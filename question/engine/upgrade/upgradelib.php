<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains the code required to upgrade all the attempt data from
 * old versions of Moodle into the tables used by the new question engine.
 *
 * @package    moodlecore
 * @subpackage questionengine
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/upgrade/logger.php');
require_once($CFG->dirroot . '/question/engine/upgrade/behaviourconverters.php');


/**
 * This class manages upgrading all the question attempts from the old database
 * structure to the new question engine.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_engine_attempt_upgrader {
    /** @var question_engine_upgrade_question_loader */
    protected $questionloader;
    /** @var question_engine_assumption_logger */
    protected $logger;
    /** @var int used by {@link prevent_timeout()}. */
    protected $dotcounter = 0;

    /**
     * Called before starting to upgrade all the attempts at a particular quiz.
     * @param int $done the number of quizzes processed so far.
     * @param int $outof the total number of quizzes to process.
     * @param int $quizid the id of the quiz that is about to be processed.
     */
    protected function print_progress($done, $outof, $quizid) {
        gc_collect_cycles(); // This was really helpful in PHP 5.2. Perhaps remove.
        print_progress($done, $outof);
    }

    protected function prevent_timeout() {
        set_time_limit(300);
        echo '.';
        $this->dotcounter += 1;
        if ($this->dotcounter % 100 == 0) {
            echo '<br />';
        }
    }

    protected function get_quiz_ids() {
        global $DB;
        // TODO, if local/qeupgradehelper/ is installed, and defines the right
        // function, use that to get the lest of quizzes instead.
        return $DB->get_records_menu('quiz', '', '', 'id', 'id,1');
    }

    public function convert_all_quiz_attempts() {
        global $DB;

        $quizids = $this->get_quiz_ids();
        if (empty($quizids)) {
            return true;
        }

        $done = 0;
        $outof = count($quizids);
        $this->logger = new question_engine_assumption_logger();

        foreach ($quizids as $quizid => $notused) {
            $this->print_progress($done, $outof, $quizid);

            $quiz = $DB->get_record('quiz', array('id' => $quizid), '*', MUST_EXIST);
            $this->update_all_attempts_at_quiz($quiz);

            $done += 1;
        }

        $this->print_progress($outof, $outof, 'All done!');
        $this->logger = null;
    }

    public function get_attempts_extra_where() {
        return ' AND needsupgradetonewqe = 1';
    }

    public function update_all_attempts_at_quiz($quiz) {
        global $DB;

        // Wipe question loader cache.
        $this->questionloader = new question_engine_upgrade_question_loader($this->logger);

        $transaction = $DB->start_delegated_transaction();

        $params = array('quizid' => $quiz->id);
        $where = 'quiz = :quizid AND preview = 0' . $this->get_attempts_extra_where();

        $quizattemptsrs = $DB->get_recordset_select('quiz_attempts', $where, $params, 'uniqueid');
        $questionsessionsrs = $DB->get_recordset_sql("
                SELECT *
                FROM {question_sessions}
                WHERE attemptid IN (
                    SELECT uniqueid FROM {quiz_attempts} WHERE $where)
                ORDER BY attemptid, questionid
        ", $params);

        $questionsstatesrs = $DB->get_recordset_sql("
                SELECT *
                FROM {question_states}
                WHERE attempt IN (
                    SELECT uniqueid FROM {quiz_attempts} WHERE $where)
                ORDER BY attempt, question, seq_number, id
        ", $params);

        $datatodo = $quizattemptsrs && $questionsessionsrs && $questionsstatesrs;
        while ($datatodo && $quizattemptsrs->valid()) {
            $attempt = $quizattemptsrs->current();
            $quizattemptsrs->next();
            $this->convert_quiz_attempt($quiz, $attempt, $questionsessionsrs, $questionsstatesrs);
        }

        $quizattemptsrs->close();
        $questionsessionsrs->close();
        $questionsstatesrs->close();

        $transaction->allow_commit();
    }

    protected function convert_quiz_attempt($quiz, $attempt, moodle_recordset $questionsessionsrs,
            moodle_recordset $questionsstatesrs) {
        $qas = array();
        $this->logger->set_current_attempt_id($attempt->id);
        while ($qsession = $this->get_next_question_session($attempt, $questionsessionsrs)) {
            $question = $this->load_question($qsession->questionid, $quiz->id);
            $qstates = $this->get_question_states($attempt, $question, $questionsstatesrs);
            try {
                $qas[$qsession->questionid] = $this->convert_question_attempt($quiz, $attempt, $question, $qsession, $qstates);
            } catch (Exception $e) {
                notify($e->getMessage());
            }
        }
        $this->logger->set_current_attempt_id(null);

        if (empty($qas)) {
            $this->logger->log_assumption("All the question attempts for
                    attempt {$attempt->id} at quiz {$attempt->quiz} were missing.
                    Deleting this attempt", $attempt->id);
            // Somehow, all the question attempt data for this quiz attempt
            // was lost. (This seems to have happened on labspace.)
            // Delete the corresponding quiz attempt.
            return $this->delete_quiz_attempt($attempt->uniqueid);
        }

        $questionorder = array();
        foreach (explode(',', $quiz->questions) as $questionid) {
            if ($questionid == 0) {
                continue;
            }
            if (!array_key_exists($questionid, $qas)) {
                $this->logger->log_assumption("Supplying minimal open state for
                        question {$questionid} in attempt {$attempt->id} at quiz
                        {$attempt->quiz}, since the session was missing.", $attempt->id);
                try {
                    $qas[$questionid] = $this->supply_missing_question_attempt(
                            $quiz, $attempt, $question);
                } catch (Exception $e) {
                    notify($e->getMessage());
                }
            }
        }

        return $this->save_usage($quiz->preferredbehaviour, $attempt, $qas, $quiz->questions);
    }

    protected function save_usage($preferredbehaviour, $attempt, $qas, $quizlayout) {
        $missing = array();

        $layout = explode(',', $attempt->layout);
        $questionkeys = array_combine(array_values($layout), array_keys($layout));

        $this->set_quba_preferred_behaviour($attempt->uniqueid, $preferredbehaviour);

        $i = 0;
        foreach (explode(',', $quizlayout) as $questionid) {
            if ($questionid == 0) {
                continue;
            }
            $i++;

            if (!array_key_exists($questionid, $qas)) {
                $missing[] = $questionid;
                continue;
            }

            $qa = $qas[$questionid];
            $qa->questionusageid = $attempt->uniqueid;
            $qa->slot = $i;
            $this->insert_record('question_attempts', $qa);
            $layout[$questionkeys[$questionid]] = $qa->slot;

            foreach ($qa->steps as $step) {
                $step->questionattemptid = $qa->id;
                $this->insert_record('question_attempt_steps', $step);

                foreach ($step->data as $name => $value) {
                    $datum = new stdClass();
                    $datum->attemptstepid = $step->id;
                    $datum->name = $name;
                    $datum->value = $value;
                    $this->insert_record('question_attempt_step_data', $datum, false);
                }
            }
        }

        $this->set_quiz_attempt_layout($attempt->uniqueid, implode(',', $layout));

        if ($missing) {
            notify("Question sessions for questions " .
                    implode(', ', $missing) .
                    " were missing when upgrading question usage {$attempt->uniqueid}.");
        }
    }

    protected function set_quba_preferred_behaviour($qubaid, $preferredbehaviour) {
        global $DB;
        $DB->set_field('question_usages', 'preferredbehaviour', $preferredbehaviour,
                array('id' => $qubaid));
    }

    protected function set_quiz_attempt_layout($qubaid, $layout) {
        global $DB;
        $DB->set_field('quiz_attempts', 'layout', $layout, array('uniqueid' => $qubaid));
        $DB->set_field('quiz_attempts', 'needsupgradetonewqe', 0, array('uniqueid' => $qubaid));
    }

    protected function delete_quiz_attempt($qubaid) {
        global $DB;
        $DB->delete_records('quiz_attempts', array('uniqueid' => $qubaid));
        $DB->delete_records('question_attempts', array('id' => $qubaid));
    }

    protected function insert_record($table, $record, $saveid = true) {
        global $DB;
        $newid = $DB->insert_record($table, $record, $saveid);
        if ($saveid) {
            $record->id = $newid;
        }
        return $newid;
    }

    public function load_question($questionid, $quizid = null) {
        return $this->questionloader->get_question($questionid, $quizid);
    }

    public function get_next_question_session($attempt, moodle_recordset $questionsessionsrs) {
        $qsession = $questionsessionsrs->current();

        if (!$qsession || $qsession->attemptid != $attempt->uniqueid) {
            // No more question sessions belonging to this attempt.
            return false;
        }

        // Session found, move the pointer in the RS and return the record.
        $questionsessionsrs->next();
        return $qsession;
    }

    public function get_question_states($attempt, $question, moodle_recordset $questionsstatesrs) {
        $qstates = array();

        while ($state = $questionsstatesrs->current()) {
            if (!$state || $state->attempt != $attempt->uniqueid ||
                    $state->question != $question->id) {
                // We have found all the states for this attempt. Stop.
                break;
            }

            // Add the new state to the array, and advance.
            $qstates[$state->seq_number] = $state;
            $questionsstatesrs->next();
        }

        return $qstates;
    }

    protected function get_converter_class_name($question, $quiz, $qsessionid) {
        if ($question->qtype == 'essay') {
            return 'qbehaviour_manualgraded_converter';
        } else if ($question->qtype == 'description') {
            return 'qbehaviour_informationitem_converter';
        } else if ($quiz->preferredbehaviour == 'deferredfeedback') {
            return 'qbehaviour_deferredfeedback_converter';
        } else if ($quiz->preferredbehaviour == 'adaptive') {
            return 'qbehaviour_adaptive_converter';
        } else if ($quiz->preferredbehaviour == 'adaptivenopenalty') {
            return 'qbehaviour_adaptivenopenalty_converter';
        } else {
            throw new coding_exception("Question session {$qsessionid}
                    has an unexpected preferred behaviour {$quiz->preferredbehaviour}.");
        }
    }

    public function supply_missing_question_attempt($quiz, $attempt, $question) {
        if ($question->qtype == 'random') {
            throw new coding_exception("Cannot supply a missing qsession for question
                    {$question->id} in attempt {$attempt->id}.");
        }

        $converterclass = $this->get_converter_class_name($question, $quiz, 'missing');

        $qbehaviourupdater = new $converterclass($quiz, $attempt, $question,
                null, null, $this->logger);
        $qa = $qbehaviourupdater->supply_missing_qa();
        $qbehaviourupdater->discard();
        return $qa;
    }

    public function convert_question_attempt($quiz, $attempt, $question, $qsession, $qstates) {
        $this->prevent_timeout();

        if ($question->qtype == 'random') {
            list($question, $qstates) = $this->decode_random_attempt($qstates, $question->maxmark);
            $qsession->questionid = $question->id;
        }

        $converterclass = $this->get_converter_class_name($question, $quiz, $qsession->id);

        $qbehaviourupdater = new $converterclass($quiz, $attempt, $question, $qsession,
                $qstates, $this->logger);
        $qa = $qbehaviourupdater->get_converted_qa();
        $qbehaviourupdater->discard();
        return $qa;
    }

    protected function decode_random_attempt($qstates, $maxmark) {
        $realquestionid = null;
        foreach ($qstates as $i => $state) {
            if (strpos($state->answer, '-') < 6) {
                // Broken state, skip it.
                $this->logger->log_assumption("Had to skip brokes state {$state->id}
                        for question {$state->question}.");
                unset($qstates[$i]);
                continue;
            }
            list($randombit, $realanswer) = explode('-', $state->answer, 2);
            $newquestionid = substr($randombit, 6);
            if ($realquestionid && $realquestionid != $newquestionid) {
                throw new coding_exception("Question session {$this->qsession->id}
                        for random question points to two different real questions
                        {$realquestionid} and {$newquestionid}.");
            }
            $qstates[$i]->answer = $realanswer;
        }

        if (empty($newquestionid)) {
            // This attempt only had broken states. Set a fake $newquestionid to
            // prevent a null DB error later.
            $newquestionid = 0;
        }

        $newquestion = $this->load_question($newquestionid);
        $newquestion->maxmark = $maxmark;
        return array($newquestion, $qstates);
    }
}


/**
 * This class deals with loading (and caching) question definitions during the
 * question engine upgrade.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_engine_upgrade_question_loader {
    private $cache = array();

    public function __construct($logger) {
        $this->logger = $logger;
    }

    protected function load_question($questionid, $quizid) {
        global $DB;

        if ($quizid) {
            $question = $DB->get_record_sql("
                SELECT q.*, qqi.grade AS maxmark
                FROM {question} q
                JOIN {quiz_question_instances} qqi ON qqi.question = q.id
                WHERE q.id = $questionid AND qqi.quiz = $quizid");
        } else {
            $question = $DB->get_record('question', array('id' => $questionid));
        }

        if (!$question) {
            return null;
        }

        if (empty($question->defaultmark)) {
            if (!empty($question->defaultgrade)) {
                $question->defaultmark = $question->defaultgrade;
            } else {
                $question->defaultmark = 0;
            }
            unset($question->defaultgrade);
        }

        $qtype = question_bank::get_qtype($question->qtype, false);
        if ($qtype->name() === 'missingtype') {
            $this->logger->log_assumption("Dealing with question id {$question->id}
                    that is of an unknown type {$question->qtype}.");
            $question->questiontext = '<p>' . get_string('warningmissingtype', 'quiz') .
                    '</p>' . $question->questiontext;
        }

        $qtype->get_question_options($question);

        return $question;
    }

    public function get_question($questionid, $quizid) {
        if (isset($this->cache[$questionid])) {
            return $this->cache[$questionid];
        }

        $question = $this->load_question($questionid, $quizid);

        if (!$question) {
            $this->logger->log_assumption("Dealing with question id {$questionid}
                    that was missing from the database.");
            $question = new stdClass();
            $question->id = $questionid;
            $question->qtype = 'deleted';
            $question->maxmark = 1; // Guess, but that is all we can do.
            $question->questiontext = get_string('deletedquestiontext', 'qtype_missingtype');
        }

        $this->cache[$questionid] = $question;
        return $this->cache[$questionid];
    }
}


/**
 * Base class for the classes that convert the question-type specific bits of
 * the attempt data.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class question_qtype_attempt_updater {
    /** @var object the question definition data. */
    protected $question;
    /** @var question_behaviour_attempt_updater */
    protected $updater;
    /** @var question_engine_assumption_logger */
    protected $logger;

    public function __construct($updater, $question, $logger) {
        $this->updater = $updater;
        $this->question = $question;
        $this->logger = $logger;
    }

    public function discard() {
        // Help the garbage collector, which seems to be struggling.
        $this->updater = null;
        $this->question = null;
        $this->logger = null;
    }

    protected function to_text($html) {
        return $this->updater->to_text($html);
    }

    public function question_summary() {
        return $this->to_text($this->question->questiontext);
    }

    public function compare_answers($answer1, $answer2) {
        return $answer1 == $answer2;
    }

    public abstract function right_answer();
    public abstract function response_summary($state);
    public abstract function was_answered($state);
    public abstract function set_first_step_data_elements($state, &$data);
    public abstract function set_data_elements_for_step($state, &$data);
    public abstract function supply_missing_first_step_data(&$data);
}


class question_deleted_question_attempt_updater extends question_qtype_attempt_updater {
    public function right_answer() {
        return '';
    }

    public function response_summary($state) {
        return $state->answer;
    }

    public function was_answered($state) {
        return !empty($state->answer);
    }

    public function set_first_step_data_elements($state, &$data) {
        $data['upgradedfromdeletedquestion'] = $state->answer;
    }

    public function supply_missing_first_step_data(&$data) {
    }

    public function set_data_elements_for_step($state, &$data) {
        $data['upgradedfromdeletedquestion'] = $state->answer;
    }
}