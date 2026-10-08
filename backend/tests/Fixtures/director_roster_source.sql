-- Synthetic post-migration column fixture. This is not a replacement native schema.
-- Names follow 2024_09_20 product renames, 2024_08_17 event/check-in and
-- 2026_09_22 respondent assignment migrations, not the legacy schema.sql alone.
CREATE TABLE accounts(id bigint PRIMARY KEY);
CREATE TABLE events(id bigint PRIMARY KEY,account_id bigint NOT NULL REFERENCES accounts(id),deleted_at timestamp);
CREATE TABLE orders(id bigint PRIMARY KEY,event_id bigint REFERENCES events(id),first_name text,last_name text,email text,status text,payment_status text,refund_status text,created_at timestamp,updated_at timestamp,deleted_at timestamp);
CREATE TABLE products(id bigint PRIMARY KEY,event_id bigint REFERENCES events(id),title text);
CREATE TABLE attendees(id bigint PRIMARY KEY,event_id bigint REFERENCES events(id),order_id bigint REFERENCES orders(id),product_id bigint REFERENCES products(id),public_id text,first_name text,last_name text,email text,status text,created_at timestamp,updated_at timestamp,deleted_at timestamp);
CREATE TABLE attendee_check_ins(id bigint PRIMARY KEY,attendee_id bigint REFERENCES attendees(id),event_id bigint REFERENCES events(id),created_at timestamp,deleted_at timestamp,ip_address text);
CREATE TABLE historical_receipt_recovery_cohorts(id bigint PRIMARY KEY,event_id bigint,account_id bigint,sealed_at timestamptz,revoked_at timestamptz,valid_until timestamptz,scope_json text);
CREATE TABLE order_receipt_recovery_evidence(id bigint PRIMARY KEY,cohort_id bigint REFERENCES historical_receipt_recovery_cohorts(id),order_id bigint UNIQUE REFERENCES orders(id),event_id bigint,purged_at timestamptz,evidence_encrypted text,CHECK((evidence_encrypted IS NULL)=(purged_at IS NOT NULL)));
CREATE TABLE gvsu_registration_assignments(id bigint PRIMARY KEY,event_id bigint,order_id bigint,attendee_id bigint,attendee_public_id text,assignment_id text UNIQUE,assignment_revision integer,respondent_id text,status text,delivery_destination_ciphertext text,recovery_evidence_id bigint REFERENCES order_receipt_recovery_evidence(id) ON DELETE SET NULL,replaced_assignment_id text,link_replacement_requested_at timestamp,UNIQUE(event_id,attendee_id));
CREATE TABLE question_answers(id bigint,answer text);
INSERT INTO accounts VALUES(1),(2);
INSERT INTO events VALUES(7,1,NULL),(8,2,NULL);
INSERT INTO orders VALUES
 (10,7,'Purchaser','Example','purchaser@example.invalid','COMPLETED','PAYMENT_RECEIVED','PARTIALLY_REFUNDED','2026-10-01','2026-10-01',NULL),
 (20,8,'Other','School','other@example.invalid','COMPLETED','PAYMENT_RECEIVED',NULL,'2026-10-01','2026-10-01',NULL);
INSERT INTO products VALUES(100,7,'Ticket'),(200,8,'Other ticket');
INSERT INTO attendees VALUES
 (1,7,10,100,'private-ticket-1','Alex','Example','ticket@example.invalid','ACTIVE','2026-10-01','2026-10-01',NULL),
 (2,7,10,100,'private-ticket-2','Alex','Example','ticket@example.invalid','CANCELLED','2026-10-01','2026-10-01',NULL),
 (3,7,10,100,'private-ticket-3','','','ticket@example.invalid','ACTIVE','2026-10-01','2026-10-01',NULL),
 (4,8,20,200,'private-ticket-4','Alex','Example','other@example.invalid','ACTIVE','2026-10-01','2026-10-01',NULL);
INSERT INTO attendee_check_ins VALUES(1,1,7,'2026-10-02',NULL,'private-ip'),(2,1,7,'2026-10-03',NULL,'private-ip'),(3,2,7,'2026-10-02','2026-10-03','private-ip');
INSERT INTO gvsu_registration_assignments(id,event_id,order_id,attendee_id,attendee_public_id,assignment_id,assignment_revision,respondent_id,status,delivery_destination_ciphertext) VALUES(1,7,10,1,'private-ticket-1','assignment-1',1,'respondent-1','delivered','private-ciphertext');
