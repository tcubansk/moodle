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

require_once '../../../config.php';
require_once $CFG->dirroot.'/grade/lib.php';
require_once $CFG->dirroot.'/grade/report/lib.php';
require_once 'edit_form.php';

$courseid = optional_param('courseid', 0, PARAM_INT);
$id       = optional_param('id', 0, PARAM_INT);

$PAGE->set_url('/grade/edit/scale/edit.php', array('id' => $id, 'courseid' => $courseid));

$systemcontext = get_context_instance(CONTEXT_SYSTEM);
$heading = '';

// a bit complex access control :-O
if ($id) {
    $heading = get_string('editscale', 'grades');

    /// editing existing scale
    if (!$scale_rec = $DB->get_record('scale', array('id' => $id))) {
        print_error('invalidscaleid');
    }
    if ($scale_rec->courseid) {
        $scale_rec->standard = 0;
        if (!$course = $DB->get_record('course', array('id' => $scale_rec->courseid))) {
            print_error('invalidcourseid');
        }
        require_login($course);
        $context = get_context_instance(CONTEXT_COURSE, $course->id);
        require_capability('moodle/course:managescales', $context);
        $courseid = $course->id;
    } else {
        if ($courseid) {
            if (!$course = $DB->get_record('course', array('id' => $courseid))) {
                print_error('invalidcourseid');
            }
        }
        $scale_rec->standard = 1;
        $scale_rec->courseid = $courseid;
        require_login($courseid);
        require_capability('moodle/course:managescales', $systemcontext);
    }

} else if ($courseid){
    $heading = get_string('addscale', 'grades');
    /// adding new scale from course
    if (!$course = $DB->get_record('course', array('id' => $courseid))) {
        print_error('nocourseid');
    }
    $scale_rec = new object();
    $scale_rec->standard = 0;
    $scale_rec->courseid = $courseid;
    require_login($course);
    $context = get_context_instance(CONTEXT_COURSE, $course->id);
    require_capability('moodle/course:managescales', $context);

} else {
    /// adding new scale from admin section
    $scale_rec = new object();
    $scale_rec->standard = 1;
    $scale_rec->courseid = 0;
    require_login();
    require_capability('moodle/course:managescales', $systemcontext);
}

if (!$courseid) {
    require_once $CFG->libdir.'/adminlib.php';
    admin_externalpage_setup('scales');
}

// default return url
$gpr = new grade_plugin_return();
$returnurl = $gpr->get_return_url('index.php?id='.$courseid);
$editoroptions = array('maxfiles'=>EDITOR_UNLIMITED_FILES, 'maxbytes'=>$CFG->maxbytes, 'trusttext'=>false, 'noclean'=>true);

if (!empty($scale_rec->id)) {
    $scale_rec = file_prepare_standard_editor($scale_rec, 'description', $editoroptions, $systemcontext, 'grade_scale', $scale_rec->id);
} else {
    $scale_rec = file_prepare_standard_editor($scale_rec, 'description', $editoroptions, $systemcontext, 'grade_scale', null);
}
$mform = new edit_scale_form(null, compact('gpr', 'editoroptions'));

$mform->set_data($scale_rec);

if ($mform->is_cancelled()) {
    redirect($returnurl);

} else if ($data = $mform->get_data()) {
    $scale = new grade_scale(array('id'=>$id));
    $data->userid = $USER->id;

    if (empty($scale->id)) {
        $data->description = $data->description_editor['text'];
        grade_scale::set_properties($scale, $data);
        if (!has_capability('moodle/grade:manage', $systemcontext)) {
            $data->standard = 0;
        }
        $scale->courseid = !empty($data->standard) ? 0 : $courseid;
        $scale->insert();
        $data = file_postupdate_standard_editor($data, 'description', $editoroptions, $systemcontext, 'grade_scale', $scale->id);
        $DB->set_field($scale->table, 'description', $data->description, array('id'=>$scale->id));
    } else {
        $data = file_postupdate_standard_editor($data, 'description', $editoroptions, $systemcontext, 'grade_scale', $id);
        grade_scale::set_properties($scale, $data);
        if (isset($data->standard)) {
            $scale->courseid = !empty($data->standard) ? 0 : $courseid;
        } else {
            unset($scale->courseid); // keep previous
        }
        $scale->update();
    }
    redirect($returnurl);
}

if ($courseid) {
    print_grade_page_head($course->id, 'scale', 'edit', $heading);
} else {
    echo $OUTPUT->header();
}

$mform->display();

echo $OUTPUT->footer();