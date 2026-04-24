<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Interfaces\JobInterface;

class QueueManager
{
    private static ?QueueManager $instance = null; // Singleton instance
    private string $tableName;

    /**
     * Private constructor to initialize the table name.
     * Prevents direct instantiation.
     */
    private function __construct()
    {
        $this->tableName = Tables::get(Tables::MAILERPRESS_QUEUE_JOB); // Replace with your table name
    }

    /**
     * Prevent cloning of the instance.
     */
    private function __clone()
    {
        // Prevent cloning
    }

    /**
     * Prevent unserialization of the instance.
     */
    public function __wakeup(): void
    {
        // Prevent unserialization
    }

    /**
     * Get the singleton instance of the QueueManager.
     */
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a job in the queue table.
     *
     * @param JobInterface $jobInstance the job instance
     *
     * @return int the inserted job ID
     *
     * @throws \DateMalformedStringException
     */
    public function registerJob(JobInterface $jobInstance): int
    {
        global $wpdb;

        $data = $jobInstance->getData();

        // Check if table exists before inserting
        $table_exists = Tables::exists($this->tableName);
        if (!$table_exists) {
            throw new \RuntimeException("Queue table {$this->tableName} does not exist. Please run migrations.");
        }

        // Serialize the job instance and store it in the table
        $serializedJob = wp_json_encode(serialize($jobInstance));

        // Always set available_at to now
        // Note: If this job comes from mailerpress_process_contact_chunk, Action Scheduler
        // has already scheduled it at the right time, so the job should be available immediately
        // when mailerpress_process_contact_chunk executes
        $at = current_time('mysql');

        $wpdb->insert(
            $this->tableName,
            [
                'job' => $serializedJob,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $at,
                'created_at' => current_time('mysql'),
            ]
        );

        $job_id = $wpdb->insert_id;
    

        do_action('mailerpress_queue_job_registered', $job_id, $data);

        return $job_id;
    }

    /**
     * Get a job by its ID
     *
     * @param int $jobId the job ID
     * @return null|object the job row or null if not found
     */
    public function getJobById(int $jobId): ?object
    {
        global $wpdb;

        // Check if table exists before querying
        $table_exists = Tables::exists($this->tableName);
        if (!$table_exists) {
            return null;
        }

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tableName} WHERE id = %d",
            $jobId
        ));

        return $job ?: null;
    }

    /**
     * Fetch the next available job for processing.
     *
     * @return null|object the job row or null if no job is available
     */
    public function getNextJob(): ?object
    {
        global $wpdb;

        // Check if table exists before querying
        $table_exists = Tables::exists($this->tableName);
        if (!$table_exists) {
            // Table doesn't exist yet, return null
            // This can happen during initial installation before migrations run
            return null;
        }

        $current_time = current_time('mysql');
        
        // Count total jobs and available jobs for logging
        $total_jobs = $wpdb->get_var("SELECT COUNT(*) FROM {$this->tableName}");
        $available_jobs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE reserved_at IS NULL AND available_at <= %s",
            $current_time
        ));

        // Fetch the next job that is available
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tableName}
            WHERE reserved_at IS NULL AND available_at <= %s
            ORDER BY id ASC
            LIMIT 1",
            $current_time
        ));

        if ($job) {
            // Reserve the job
            $wpdb->update($this->tableName, [
                'reserved_at' => current_time('mysql'),
            ], ['id' => $job->id]);

            return $job;
        }

        return null;
    }

    public function processJob(object $jobRow): void
    {
        global $wpdb;
    
        
        // Check if table exists before processing
        $table_exists = Tables::exists($this->tableName);
        if (!$table_exists) {
            // Table doesn't exist yet, skip processing
            return;
        }

        // Reserve the job if not already reserved
        if (empty($jobRow->reserved_at)) {
            $wpdb->update($this->tableName, [
                'reserved_at' => current_time('mysql'),
            ], ['id' => $jobRow->id]);
        }

        $jobInstance = unserialize(json_decode($jobRow->job), ['allowed_classes' => [
            \MailerPress\Jobs\SendEmailJob::class,
            \MailerPress\Core\Abstract\BaseJob::class,
        ]]);
        if ($jobInstance instanceof JobInterface) {
            
            // Trigger an action when the job starts.
            do_action('mailerpress_job_started', $jobRow->id, $jobInstance);

            try {
                
                // Execute the job
                $jobInstance->handle(
                    $jobInstance->getData()
                );

                // Trigger an action when the job is successfully completed.
                do_action('mailerpress_job_started_job_completed', $jobRow->id, $jobInstance);

                // Remove the job from the queue
                $this->completeJob((int) $jobRow->id);

            } catch (\Exception $e) {

                // Handle job failure and optionally trigger a failure action
                do_action('mailerpress_job_failed', $jobRow->id, $jobInstance, $e);

                // Retry job automatically (max 3 attempts)
                $this->retryJob((int) $jobRow->id, 3);
            }
        }
    }

    /**
     * Mark a job as completed and remove it from the queue.
     *
     * @param int $jobId the ID of the job
     */
    public function completeJob(int $jobId): void
    {
        global $wpdb;
        
        // Check if table exists before deleting
        $table_exists = Tables::exists($this->tableName);
        if (!$table_exists) {
            return;
        }
        
        $wpdb->delete($this->tableName, ['id' => $jobId]);
    }

    /**
     * Log message to file for debugging
     */
    private function log(string $message, array $context = []): void
    {
        $logDir = WP_CONTENT_DIR . '/mailerpress-logs';
        if (!is_dir($logDir)) {
            wp_mkdir_p($logDir);
        }

        $logFile = $logDir . '/queue-debug.log';
        $timestamp = current_time('mysql');
        $contextStr = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
        $logEntry = sprintf("[%s] %s%s\n", $timestamp, $message, $contextStr);
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Retry a failed job by increasing its attempt count.
     *
     * @param int $jobId       the ID of the job
     * @param int $maxAttempts the maximum allowed attempts
     */
    public function retryJob(int $jobId, int $maxAttempts = 3): void
    {
        global $wpdb;

        // Check if table exists before querying
        $table_exists = Tables::exists($this->tableName);
        if (!$table_exists) {
            return;
        }

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tableName} WHERE id = %d",
            $jobId
        ));

        if ($job && $job->attempts < $maxAttempts) {
            // Retry the job
            $wpdb->update($this->tableName, [
                'attempts' => $job->attempts + 1,
                'reserved_at' => null,
                'available_at' => current_time('mysql'),
            ], ['id' => $jobId]);
        } else {
            // Delete the job if it exceeds the maximum attempts
            $this->completeJob($jobId);
        }
    }
}
