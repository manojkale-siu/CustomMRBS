MRBS - Meeting Room Booking System
==================================
Moodle block component: block_mrbs
Repository: https://github.com/manojkale-siu/CustomMRBS
Issues: https://github.com/manojkale-siu/CustomMRBS/issues
Documentation updated: 20 November 2026

This customized MRBS block schedules rooms and course sessions, provides personal
timetables, and synchronizes linked sessions with Moodle Attendance. All block
instances share the same booking data; adding another block does not create an
independent scheduling system.

1. Intended environment and prerequisites
-----------------------------------------
- The current deployment target is Moodle 4.5.5. Use its supported PHP version
  and required extensions. Older Moodle versions are not validated by this fork.
- Install and configure the Moodle Attendance activity (mod_attendance). Each
  course selected for a class booking must have an Attendance activity.
- Excel import uses Moodle's PhpSpreadsheet libraries and PHP ZipArchive.
- Use Moodle's existing database connection, authentication and enrolment APIs.
  There is no separate MRBS database username or password to configure.
- The course-category hierarchy expected by booking selectors is:
      Programme -> Batch -> Semester -> Course
- Configure the Moodle site timezone, at least one area and room, and a valid
  custom booking time range before scheduling sessions.

The minimum Moodle requirement and release label in version.php still contain
historical metadata. They are not a claim that the new code supports Moodle 2.7
or other old releases. Check version.php for the actual plugin upgrade number.

2. Installation
---------------
ZIP installation:
1) Upload a clean plugin ZIP through Moodle's Install plugins page.
2) The ZIP must contain one top-level mrbs/ directory, with block_mrbs.php,
   version.php and db/install.xml directly beneath it.
3) Do not include .git/, another ZIP, or an extra project-directory wrapper.
4) Complete Moodle's installation/upgrade process as a site administrator.
5) Add the MRBS block to the required page, then complete the configuration below.

Alternatively, install the Git checkout in the correct Moodle directory:
    cd /path/to/moodle/blocks
    git clone https://github.com/manojkale-siu/CustomMRBS.git mrbs

Then complete Moodle's plugin installation. Do not clone over an existing plugin
folder containing unreviewed local changes.

To produce an installation ZIP from a committed checkout:
    git archive --format=zip --prefix=mrbs/ --output=../mrbs-install.zip HEAD

This packages tracked files from HEAD without Git metadata. Uncommitted changes
are not included. db/install.xml defines a new installation; db/upgrade.php
handles versioned changes for existing installations. Do not manually create
site-prefixed tables as part of normal installation.

3. Initial configuration
------------------------
1) Confirm the Moodle site timezone under Location settings.
2) Create areas and rooms using MRBS Admin. Set room capacities and any room
   booking restrictions or room administrator email addresses.
3) Open MRBS Settings -> Time settings and choose the opening time, closing
   time and period interval carefully. The current implementation prevents
   replacing a valid custom time range after it has been saved.
4) Prepare the Programme/Batch/Semester category structure, courses, Attendance
   activities, faculty enrolments and student groups.
5) Assign additional MRBS roles only where broader scheduling access is needed.
6) Optionally configure Settings -> Programme colour settings. The colour picker
   and read-only colour-code field control programme colours on staff calendars.

Room capacity 0 means unspecified; it does not mean that the room has zero seats.

4. Permissions and timetable visibility
---------------------------------------
- Moodle site administrators, system-level Moodle Managers and MRBS
  Administrators have full MRBS administration access through the access resolver
  and block/mrbs:administermrbs capability.
- Teachers and students normally use the read-only personal timetable. They do
  not need a separate MRBS role for this access. Active course enrolment is
  required; applicable system, category-inherited and course roles are recognized.
- Teachers see sessions assigned to them as faculty in their enrolled courses.
- Students see sessions for their enrolled courses when the booking is for their
  current group, or for All groups/no group (groupid = 0).
- A late-enrolled student can see existing sessions; session creation date does
  not exclude the student. Current membership is checked when viewing.
- A person holding both Teacher and Student roles retains both visibility paths.
- Installation creates the system-context roles mrbsviewer, mrbseditor and
  mrbsadmin if they do not already exist. Explicit MRBS role assignments can
  provide broader calendar viewing or editing according to the configured
  capabilities. Do not assign these roles to every student unnecessarily.
- Editors normally change their own bookings. A user with editunconfirmed and
  the matching room administrator email can also manage bookings in that room.
- Every occurrence selected for series deletion is authorized against its own
  stored owner and actual room. If any selected occurrence is unauthorized,
  deletion stops before any booking or linked Attendance session is deleted.

Custom roles and capability overrides should be verified using representative
accounts on the actual Moodle site.

5. Booking workflow and conflict protection
--------------------------------------------
Use the Day, Week or Month staff calendar to open an available slot. Select the
programme, batch, semester, course, faculty, optional group, room, time, duration
and description. Recurring bookings are supported through the repeat controls.

Faculty must have an applicable teaching role and active enrolment in the
selected course. Unavailable faculty remain visible as disabled choices with
reasons. Server validation also rejects unavailable faculty submitted through
manual requests or Excel.

The current safeguards include:
- Sessions must remain within the configured daily opening/closing hours and,
  in period mode, valid period boundaries. They do not continue into the next day.
- Overlapping room bookings are rejected, including when multiple managers
  submit the same room/time simultaneously.
- The first successfully saved booking owns the slot. Merely opening a calendar
  cell or form does not reserve it. A waiting request rechecks availability.
- The old manager double-book override no longer permits room overlaps.
- Faculty, students and faculty-to-student overlaps are checked by person,
  including overlapping course groups and whole-course sessions.
- A recurring series cannot overlap its own occurrences.
- Whole-series changes are blocked for series containing started, individually
  modified or Attendance-protected occurrences. Use an eligible future single
  occurrence or create a new series instead.
- Eligible series edits match occurrences by start timestamp rather than position,
  protecting occurrence identity and linked Attendance records.

All current MRBS booking mutation paths share the Moodle save_booking lock in
the block_mrbs lock factory. Low-level writers reject calls without that lock.
Manual saves, Excel imports, cancellation and forced relocation use database
transactions so failures roll back their related database changes. These checks
protect plugin application paths, not arbitrary external SQL or scripts.

6. Capacity warnings and forced relocation
------------------------------------------
Capacity uses Moodle's active-enrolment API and excludes suspended accounts.
Counts include all active enrolled roles, not just students. A group booking
uses its active group count; the warning also shows the total course count.

The manual warning compares course/group users with each room's capacity and
shows the excess. Choosing Yes permits an over-capacity manual booking; it does
not bypass person conflicts, room occupancy or configured time restrictions.
Excel has a separate Allow bookings that exceed room capacity checkbox.

Authorized staff may explicitly use Forcibly book a room / Keep this booking and
move conflicting bookings. This is a separate relocation workflow, not a second
booking in an occupied room. The planner tries an eligible available room at the
same time, then another available slot in the original room on the same day.
Capacity, room eligibility and person conflicts are checked. If relocation is
not possible, nothing is changed and the user is directed to resolve the conflict.

7. Attendance synchronization and deletion
------------------------------------------
Each class booking stores its linked Attendance session ID. Manual and Excel
bookings synchronize through attendance_manager using Attendance integration APIs.

Existing Attendance can be updated only when its original start and the new
booking start are both strictly in the future, with no recorded attendance.
At the exact start time, while live, or after completion, the linked session is
preserved, including its time, faculty and group. Moving the MRBS booking can
therefore leave the protected Attendance at its original time; a preservation
notice explains the original and requested times. Forced time changes use the
same rule. Recorded future Attendance is protected as well.

Cancellation is stricter than moving a booking:
- A booking, series or room containing a started/completed booking or a protected
  linked Attendance session cannot be deleted through the cancellation workflow.
- The entire target set is checked first. One protected item stops the operation.
- Future eligible bookings and linked Attendance sessions are deleted together
  in one transaction. Failures roll back. Empty repeat headers are cleaned up;
  surviving moved occurrences retain a valid repeat-header room.
- A series-delete request for a single booking, or for a missing repeat header,
  is rejected instead of selecting unrelated independent bookings.

Booking saves and successful imports send a Moodle notification to the person
performing the operation, after commit. The booking_saved provider allows popup
notifications and disallows email. A notification-delivery failure does not undo
a committed booking. Legacy optional deletion-email settings/code still exist;
do not assume every notification path in the plugin is email-free.

8. Excel session planning
-------------------------
1) Open Import sessions from the staff navigation.
2) Download the Excel template. Replace or delete its example row.
3) Keep the worksheet name Sessions and these headers unchanged:
      Date | Start Time | End Time | Area | Room | Course Short Name |
      Faculty Username | Group | Description
4) Use YYYY-MM-DD dates and 24-hour HH:MM times in the Moodle site timezone.
   Use existing area/room names, course short names and faculty usernames.
   Group must match a course group name, or be blank for All groups.
5) Enter one session per row and upload the completed .xlsx workbook.

Limits: 500 data rows and a 2 MiB upload; expanded archive size is also bounded.
Formula cells are rejected. Import validates configured hours, room access,
course hierarchy, active faculty, group membership, capacity and participant/room
conflicts. Rows already written earlier in the same transaction are included in
later conflict checks. An invalid row stops the import and rolls back all rows.
Errors identify the row. Success shows created-session counts by date.

The legacy CSV cron importer (import.php) and its old relocation helper are
intentionally disabled. The block cron callback no longer invokes CSV import.
Use the validated Excel workflow instead.

9. Timezone policy
-------------------
All MRBS inputs, calendars, personal timetable dates and filters, room search,
reports and messages use the Moodle site timezone through booking_time. The
interface displays this timezone, and the Excel template includes guidance.
User profile timezones do not change MRBS room schedules. Recurrence calculations
preserve wall-clock times across UTC-offset changes, including half-hour DST.

Stored Unix timestamps are not automatically shifted or migrated. Attendance
receives the same instants; its own Moodle pages may display them using Moodle's
user-timezone rules. Review old data before changing the site timezone or
correcting records created under a different timezone assumption.

10. Updating an existing Git deployment
---------------------------------------
From the plugin checkout, check for local changes before updating:
    cd /path/to/moodle/blocks/mrbs
    git status --short
    git pull --ff-only

Then, from the Moodle root, apply pending upgrades and purge caches:
    cd ../..
    php admin/cli/upgrade.php --non-interactive
    php admin/cli/purge_caches.php

Back up the site/database and follow the site's deployment/maintenance procedure.
Investigate local changes or a failed fast-forward rather than discarding them.
A ZIP-installed folder must be a correctly aligned Git checkout before pull can
manage it. Pull does not attach a non-Git folder or erase uncommitted differences.

Schema, capability and message-provider changes require the corresponding Moodle
upgrade/version handling. Ordinary README changes do not require a version bump.
Moodle caches should be purged after deploying PHP, language or UI changes.

11. Data layout and maintenance
--------------------------------
Core tables (the actual Moodle database prefix is added automatically):
- block_mrbs_area: areas and creatoruserid.
- block_mrbs_room: rooms, capacities, restrictions and creatoruserid.
- block_mrbs_entry: individual occurrences, course/faculty/group IDs,
  create_by username and attendancesessionid.
- block_mrbs_repeat: recurring-series definitions and create_by username.
- block_mrbs_settings: custom time/colour settings and creatoruserid.

Database connection details belong to Moodle configuration, not plugin source.

cli/repair_period_timestamps.php is a targeted legacy timestamp-repair utility,
not a routine upgrade step. Preview with --dry-run and inspect the proposed
changes before using it. Optional --entryid=ID and --limit=N narrow its scope.
Repair uses the shared lock, availability checks and per-entry transactions.
Existing protected Attendance remains subject to the preservation rule above.

12. Tests and current limitations
---------------------------------
The tests/ directory contains Moodle PHPUnit regression tests. These do not run
on ordinary page requests or during normal scheduling. Use a separate configured
Moodle PHPUnit installation/test database. From that Moodle root, run:
    vendor/bin/phpunit blocks/mrbs/tests

Coverage includes enrolment/group visibility, faculty eligibility, participant
conflicts, booking hours, capacity, timezone handling, locking, cancellation and
deletion authority. PHP syntax and focused development checks have been run;
full Moodle integration, browser and production-database validation remain
necessary. Do not treat the presence of tests as proof that the complete Moodle
suite has been executed for this fork.

Known boundaries:
- Enrolment/group changes refresh timetable visibility, but do not trigger an
  automatic conflict audit or silently reschedule existing bookings.
- Travel and room-turnaround buffers are not implemented. Adjacent sessions
  remain valid; this is tracked as future enhancement #17.
- Room configuration and legacy room-description conventions still influence
  eligibility/relocation. Verify the destination is suitable for the activity.
- Compatibility/release metadata in version.php needs separate modernization.

For bug reports, use the repository Issues page and include the commit, Moodle /
PHP / Attendance versions, steps, expected result and actual error. Explain the
course/group/time situation without sharing passwords, tokens or private records.

13. Origins and license
------------------------
This fork builds on the MRBS Moodle block and the original Meeting Room Booking
System project. Earlier Moodle integration work includes Anthony Borrow,
Stephen Bourget and Davo Smith, with contributions from the original projects.
Historical upstream: https://github.com/arborrow/moodle-block_mrbs
Original MRBS project: https://mrbs.sourceforge.net/

See COPYING.txt for the GNU General Public License version 3 text. Preserve
existing source-file copyright and license notices when modifying this fork.
