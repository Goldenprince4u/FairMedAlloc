ALTER TABLE algorithm_audit_logs
    MODIFY COLUMN allocation_decision ENUM('Allocated','Waitlisted','No Bed','Constraint Violation');

ALTER TABLE allocations
    ADD UNIQUE INDEX IF NOT EXISTS uniq_allocations_room_bed (room_id, bed_space);
