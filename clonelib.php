<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Find an activity by resource ID site-wide and clone it into the target course.
 *
 * @param string $resourceid The LTI resource ID (mapped to CM idnumber)
 * @param int $targetcourseid The ID of the course to clone into
 * @return int|bool The new CM ID or false on failure
 */
function poodlllti_find_and_clone_activity($resourceid, $targetcourseid) {
    global $DB, $USER, $CFG;

    // 1. Find the source activity
    $sourcecm = $DB->get_record('course_modules', ['idnumber' => $resourceid], '*', IGNORE_MULTIPLE);
    if (!$sourcecm) {
        return false;
    }

    $course = $DB->get_record('course', ['id' => $targetcourseid], '*', MUST_EXIST);
    $bc = null;
    $rc = null;
    $newcmid = false;

    try {
        // 2. Perform Backup of the single activity
        $backupid = md5(uniqid('poodlllti_backup_', true));
        $bc = new backup_controller(backup::TYPE_1ACTIVITY, $sourcecm->id, backup::FORMAT_MOODLE,
                backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id);
        
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];

        // 3. Perform Restore into the target course
        $folder = $CFG->dataroot . '/temp/backup/' . $backupid;
        $file->extract_to_storage(get_file_packer('application/vnd.moodle.backup'), 
                $folder);

        $rc = new restore_controller($backupid, $targetcourseid, backup::INTERACTIVE_NO,
                backup::MODE_GENERAL, $USER->id, backup::TARGET_EXISTING_ADDING);

        if (!$rc->execute_precheck()) {
            return false;
        }

        $rc->execute_plan();

        // 4. Find the newly restored CM
        // The restore process should have created a new CM. 
        // We can get it from the mapping.
        $tasks = $rc->get_plan()->get_tasks();
        foreach ($tasks as $task) {
            if ($task instanceof restore_activity_task) {
                $newcmid = $task->get_moduleid();
                break;
            }
        }

        // 5. Update the new CM's idnumber
        if ($newcmid) {
            $DB->set_field('course_modules', 'idnumber', $resourceid, ['id' => $newcmid]);
        }

    } catch (Exception $e) {
        debugging('Poodll LTI Cloning Error: ' . $e->getMessage());
        $newcmid = false;
    }

    return $newcmid;
}
