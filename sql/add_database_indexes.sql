-- ============================================================
-- Migration: Add Performance Indexes
-- Purpose: Optimize query performance for frequently accessed
--          data patterns used in allocation and dashboard queries.
-- ============================================================

-- Indexes on rooms table for allocation lookups
CREATE INDEX IF NOT EXISTS idx_rooms_hostel_occupied 
  ON rooms(hostel_id, occupied_count);

CREATE INDEX IF NOT EXISTS idx_rooms_is_test 
  ON rooms(is_test);

-- Indexes on allocations for student lookups and batch operations
CREATE INDEX IF NOT EXISTS idx_allocations_student_id 
  ON allocations(student_id);

CREATE INDEX IF NOT EXISTS idx_allocations_room_id 
  ON allocations(room_id);

CREATE INDEX IF NOT EXISTS idx_allocations_session 
  ON allocations(academic_session);

-- Indexes on student_profiles for allocation filtering
CREATE INDEX IF NOT EXISTS idx_student_profiles_allocation_status 
  ON student_profiles(allocation_status);

CREATE INDEX IF NOT EXISTS idx_student_profiles_paid 
  ON student_profiles(is_paid);

CREATE INDEX IF NOT EXISTS idx_student_profiles_gender 
  ON student_profiles(gender);

-- Indexes on hostels for block lookups
CREATE INDEX IF NOT EXISTS idx_hostels_name_block 
  ON hostels(name, block_name);

CREATE INDEX IF NOT EXISTS idx_hostels_postgrad_foundation 
  ON hostels(is_postgrad, is_foundation);

-- Indexes on medical records for urgency scoring
CREATE INDEX IF NOT EXISTS idx_medical_records_urgency_score 
  ON medical_records(urgency_score);

CREATE INDEX IF NOT EXISTS idx_medical_records_condition 
  ON medical_records(condition_category);

-- Index on payments for fee verification
CREATE INDEX IF NOT EXISTS idx_payments_student_status 
  ON payments(student_id, status);

-- Index on users for login performance
CREATE INDEX IF NOT EXISTS idx_users_username_role 
  ON users(username, role);

-- Verify indexes were created
SHOW INDEXES FROM rooms;
SHOW INDEXES FROM allocations;
SHOW INDEXES FROM student_profiles;
