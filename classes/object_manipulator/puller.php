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
 * Pulls files from remote storage if they meet the configured criterea.
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

class puller extends manipulator {

    /**
     * Size threshold for pulling files from remote in bytes.
     *
     * @var int
     */
    private $sizethreshold;

    /**
     * Puller constructor.
     *
     * @param object_client $client object client
     * @param object_file_system $filesystem object file system
     * @param object $config objectfs config.
     */
    public function __construct($filesystem, $config, $logger) {
        parent::__construct($filesystem, $config);
        $this->sizethreshold = $config->sizethreshold;

        $this->logger = $logger;
        $this->logger->set_action('pull');
    }

    /**
     * Get candidate content hashes for pulling.
     * Files that are less or equal to the sizethreshold,
     * and are external.
     *
     * @return array candidate contenthashes
     */
    public function get_candidate_objects() {
        global $DB;
        $sql = 'SELECT f.contenthash,
                       MAX(f.filesize) AS filesize
                  FROM {files} f
             LEFT JOIN {tool_objectfs_objects} o ON f.contenthash = o.contenthash
              GROUP BY f.contenthash,
                       f.filesize,
                       o.location
                HAVING MAX(f.filesize) <= ?
                       AND (o.location = ?)';

        $params = array($this->sizethreshold, OBJECT_LOCATION_REMOTE);

        $this->logger->start_timing();
        $objects = $DB->get_records_sql($sql, $params);
        $this->logger->end_timing();

        $totalobjectsfound = count($objects);

        $this->logger->log_object_manipulation_query($totalobjectsfound);

        return $objects;
    }


    /**
     * Pushes files from local file system to S3.
     *
     * @param  array $candidatehashes content hashes to push
     */
    public function execute($files) {
        $this->logger->start_timing();

        foreach ($files as $file) {
            if (time() >= $this->finishtime) {
                break;
            }

            $success = $this->filesystem->copy_object_from_remote_to_local_by_hash($file->contenthash);

            if ($success) {
                $location = OBJECT_LOCATION_DUPLICATED;
            } else {
                $location = $this->filesystem->get_actual_object_location_by_hash($file->contenthash);
            }

            update_object_record($file->contenthash, $location);

            $this->logger->add_object_manipulation($file->filesize);
        }

        $this->logger->end_timing();
        $this->logger->log_object_manipulation();
    }
}


