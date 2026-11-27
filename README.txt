MRBS - Meeting Room Booking System
==================================
Moodle block component: block_mrbs
Repository: https://github.com/manojkale-siu/CustomMRBS
Issues: https://github.com/manojkale-siu/CustomMRBS/issues
Documentation updated: 24 November 2026

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

Installation automatically adds an MRBS link to the site's Custom menu items,
using this Moodle site's URL and the role-aware /blocks/mrbs/web/index.php entry.
Existing menu entries are preserved, and an existing equivalent MRBS entry link
is not duplicated. The 2026112100 upgrade step also adds it for installed sites.
Complete the Moodle upgrade after pulling this version; pulling files alone does
not run that step. Administrators can later edit/remove the entry under Appearance
-> Advanced theme settings -> Custom menu items, including after uninstalling.
The link is a normal theme-supported custom menu entry, not a permission grant.
Themes must display Moodle custom menu items. A custommenuitems value forced in
config.php is left untouched and must be managed there.

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
  The personal timetable heading identifies Student view, Faculty view, or both.
  Each displayed session has an Open course button that opens its course in a
  new tab; the course page still enforces Moodle access permissions.
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
and Description/Topics. Recurring bookings are supported through the repeat controls.

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
Calendar synchronization verifies the linked Attendance activity/course and uses
authorized MRBS booking permission; users are not granted general calendar-edit
rights. Unexpected save errors include a reference for the server log.

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
      Faculty Username | Group | Description/Topics
4) Use YYYY-MM-DD dates and 24-hour HH:MM times in the Moodle site timezone.
   Use existing area/room names, course short names and faculty usernames.
   Group must match a course group name, or be blank for All groups.
5) Fill Description/Topics for each session and upload the completed .xlsx workbook.
   New templates use Description/Topics; older templates with Description are
   still accepted. The database field remains description.

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

Faculty session planning (2026112700)
-----------------------------------
Active enrolled teachers can propose a lecture using Proposed date, Topics,
Teaching Methodology, Teaching Resource, Assessment Method and optional Remarks.
A proposal has no start/end time. Old timing values remain for upgrade compatibility;
their site-local dates migrate into Proposed date. Multiple plans per day are valid.

The current Faculty Plans template uses Proposed Date, Topics, Teaching Methodology,
Teaching Resource ID, Assessment Method, Remarks. The Course Resources sheet lists
available course activity IDs; use 0 for no linked activity. Links obey Moodle
visibility and availability. Replace/delete the example. Maximum 500 rows / 2 MB.
Formulas and invalid rows are rejected; any failed row rolls back the whole import.
Replace old time-based faculty templates with the current downloadable template.

Planning remains separate from room booking and Attendance. Booking no longer
fetches planned topics; enter Description/Topics manually. Empty descriptions keep
the Not planned/disclosed by faculty fallback. Run the Moodle upgrade, then purge
caches after deploying this update.

Attendance plan selection (2026112701)
------------------------------------
A separate local mod/attendance patch requires staff to select a faculty plan
and confirm topics actually taught before marking Attendance. MRBS bookings use
their assigned faculty; standalone Attendance sessions offer course faculty plans.
Attendance saves and immutable planned/taught snapshots use one transaction and
shared session/faculty locks. Once taken, the linked plan cannot be edited/deleted.
Student self-marking, web services and automarking need prior staff selection;
user-report bulk marking must use individual session marking instead.

The default_attendance_patch/ folder is intentionally ignored and not pushed.
Its patch targets upstream Attendance build 2024082405 at commit
b1568f7cd538b699969fd02870552d1f22a2f208. Moodle core 4.5.5 is not the Attendance
plugin version. Check the installed plugin build and local changes before staging
and applying the patch. Upgrade MRBS first, run git apply --check from the target
mod/attendance checkout, apply the patch, then purge caches. No production module
files are changed by deploying this MRBS repository alone. Without the patch,
Attendance cannot enforce mandatory selection; report evidence remains incomplete.
Old taken sessions without a selection remain unmapped rather than inventing
retrospective planned/taught evidence.

Director Deviation Report
-------------------------
The default MRBS entry link redirects eligible directors to web/deviation_report.php.
Access requires Moodle profile user_category=Staff, user_status=Active and a
designation containing Director, plus a nondeleted, unsuspended Moodle account.
These profile short names match the institution's Course Archival access setup.
There is no implicit site-admin or MRBS-admin bypass for this report.

Filter by course and faculty. Summary shows faculty, employee code, course short
name and category path, planned sessions, conducted Attendance sessions, distinct
plans completed, completion percent, past proposed dates still incomplete and
sessions with date or topic-text differences. Details preserve planned/taught
text and proposed/conducted dates as captured when Attendance was taken.
Completion uses distinct completed plan IDs divided by planned records; reusing a
plan in several Attendance sessions does not inflate completion. Pending future
plans are not overdue. Topic comparison normalizes whitespace but does not infer
semantic equivalence. Historical Attendance without confirmed plan evidence is
counted separately. The mandatory selection patch must be deployed to capture new
evidence. Unknown institutional data (credits, curriculum weeks, academic-year
labels) is not invented from the reference spreadsheet examples.

The Attendance patch preserves native marking form/pagination URLs. Its local
bridge fails closed if MRBS cannot load. Reverse this integration patch before
uninstalling MRBS.

Connect an installed plugin to Git (server)
------------------------------------------
Replace /path/to/moodle with the actual Moodle directory. The backup preserves
the installed files before git reset --hard replaces tracked files with the
GitHub version. Run this initial setup once, using the Moodle deployment account.

cd /path/to/moodle/blocks/mrbs

cp -a . "../mrbs_backup_$(date +%Y%m%d_%H%M%S)"

git init -b main
git remote add origin https://github.com/manojkale-siu/CustomMRBS.git
git fetch origin
git reset --hard origin/main
git branch --set-upstream-to=origin/main main
git config pull.ff only

git status

Future server updates
---------------------
cd /path/to/moodle/blocks/mrbs
git pull --ff-only

php ../../admin/cli/upgrade.php --non-interactive
php ../../admin/cli/purge_caches.php

Continue with the PHP commands only after git pull succeeds. The separate
Attendance patch is Git-ignored and is not downloaded or applied by git pull.

Attendance final-save lock
--------------------------
After the first successful Attendance save, further marking or manual corrections
are blocked, including direct writer calls. The check runs again inside the shared
session lock; attendance logs or a confirmed snapshot also keep the session locked
if lasttaken is cleared. Plan review rejects already-taken sessions.
Use the updated version-specific local Attendance patch: it blocks entry to the
marking form for taken sessions and displays the full roster on the first marking
page, so later pages are not left unmarked by the final-save lock. This also means
self-marking and incremental web-service marking cannot continue after the first
successful write. Review all attendance before saving. Report viewing is unchanged.
If an older integration patch is installed, reverse that exact old patch before
applying the replacement patch; keep a copy of the installed patch for rollback.
