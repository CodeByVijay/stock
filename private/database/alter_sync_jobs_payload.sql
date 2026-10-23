-- Run once if sync_jobs was created before payload_json existed.
ALTER TABLE sync_jobs
    ADD COLUMN payload_json JSON NULL AFTER job_type;
