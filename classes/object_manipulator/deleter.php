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
 * Deletes files that are old enough and are in S3.
 *
 * @package   tool_objectfs
 * @author    Kenneth Hendricks <kennethhendricks@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\object_manipulator;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/objectfs/lib.php');

use Aws\S3\Exception\S3Exception;

class deleter extends manipulator {

    /**
     * How long file must exist after
     * duplication before it can be deleted.
     *
     * @var int
     */
    private $consistencydelay;

    /**
     * Whether to delete local files
     * once they are in remote.
     *
     * @var bool
     */
    private $deletelocal;

    /**
     * deleter constructor.
     *
     * @param sss_client $client S3 client
     * @param object_file_system $filesystem S3 file system
     * @param object $config sssfs config.
     */
    public function __construct($filesystem, $config) {
        parent::__construct($filesystem, $config);
        $this->consistencydelay = $config->consistencydelay;
        $this->deletelocal = $config->deletelocal;
    }

    /**
     * Get candidate content hashes for cleaning.
     * Files that are past the consistancy delay
     * and are in location duplicated.
     *
     * @return array candidate contenthashes
     */
    public function get_candidate_objects() {
        global $DB;

        if ($this->deletelocal == 0) {
            mtrace("Delete local disabled, not running query \n");
            return array();
        }

        $sql = 'SELECT f.contenthash,
                       MAX(f.filesize) AS filesize
                  FROM {files} f
             LEFT JOIN {tool_objectfs_objects} o ON f.contenthash = o.contenthash
                 WHERE o.timeduplicated <= ?
                       AND o.location = ?
              GROUP BY f.contenthash,
                       f.filesize,
                       o.location';

        $consistancythrehold = time() - $this->consistencydelay;
        $params = array($consistancythrehold, OBJECT_LOCATION_DUPLICATED);

        $starttime = time();
        $files = $DB->get_records_sql($sql, $params);
        $duration = time() - $starttime;
        $count = count($files);

        $logstring = "File deleter query took $duration seconds to find $count files \n";
        mtrace($logstring);

        return $files;
    }


    /**
     * Cleans local file system of candidate hash files.
     *
     * @param  array $candidatehashes content hashes to delete
     */
    public function execute($files) {
        global $DB;

        $starttime = time();
        $objectcount = 0;
        $totalfilesize = 0;

        if ($this->deletelocal == 0) {
            mtrace("Delete local disabled, not deleting \n");
            return;
        }

        foreach ($files as $file) {
            if (time() >= $this->finishtime) {
                break;
            }

            $success = $this->filesystem->delete_object_from_local_by_hash($file->contenthash);

            if ($success) {
                $location = OBJECT_LOCATION_REMOTE;
            } else {
                $location = $this->filesystem->get_actual_object_location_by_hash($file->contenthash);
            }

            update_object_record($file->contenthash, $location);

            $objectcount++;
            $totalfilesize += $file->filesize;
        }

        $duration = time() - $starttime;

        $totalfilesize = display_size($totalfilesize);
        $logstring = "File deleter processed $objectcount files, total size: $totalfilesize in $duration seconds \n";
        mtrace($logstring);
    }
}
