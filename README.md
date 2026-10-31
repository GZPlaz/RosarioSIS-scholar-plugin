# Scholar plugin for RosarioSIS

Connects [RosarioSIS](https://www.rosariosis.org) to [Scholar](https://github.com/GZPlaz/scholar-system_ynh), the testing and mastery platform of MindAccel University.

- **Create and link courses.** Each RosarioSIS course period can be created in Scholar, or linked to a course that already exists there. New course periods can be created and linked automatically.
- **Linking sets the course up.** The teacher and every scheduled student are added to the Scholar course, and the teacher gets a "Scholar tests" category in their gradebook. Students get no account or password: they take tests in Scholar's test portal with the test code and their RosarioSIS student ID.
- **Test grades flow into the gradebook the moment they change.** Every Scholar test with results becomes a gradebook assignment, and each student's score is written as their grade. Scholar tells the plugin as soon as anything affecting a grade happens (a test submitted, an essay graded, a score overridden, a key corrected, a sheet scanned, an attempt deleted), and the gradebook is updated within seconds, with nobody opening a page. A change check every minute and a full sync every 15 minutes back this up.
- **A new school year cleans up after itself.** When the default school year moves forward:
  - last year's Scholar courses are archived, and their results are kept;
  - the rolled-over course periods are linked to fresh Scholar courses and set up;
  - students who left are deactivated in Scholar.

Requires RosarioSIS 11.0 or later and PHP 7.4+ with the curl and json extensions, on PostgreSQL or MySQL. Scholar must be version **1.8.0 or later** for instant updates (1.3.0 or later is enough for everything else).

## Installation

1. Download this repository and put it in the RosarioSIS `plugins/` folder, for example as `plugins/Scholar`. Any folder name works except one ending in `-main` or `-master`, which RosarioSIS refuses: rename a folder unpacked from a GitHub ZIP (`RosarioSIS-scholar-plugin-main`).

   ```bash
   cd /path/to/rosariosis/plugins
   git clone https://github.com/GZPlaz/RosarioSIS-scholar-plugin.git Scholar
   ```

2. In RosarioSIS, go to **School › Configuration › Plugins** and activate **Scholar**. The plugin's tables are created at this step.
3. In Scholar, open **Administration › Integrations**, create a token named "RosarioSIS" and copy it. It is shown only once.
4. Back in RosarioSIS, click **Configuration** next to the Scholar plugin. Under **Settings**, enter Scholar's address (for example `https://apps.mindaccel.org/scholar`) and the token, then click **Test the connection**.

5. Click **Connect instant updates**. The plugin gives Scholar its address and receives a secret that signs every notification; Scholar sends a test request to prove it can get through. Saving the settings does this automatically too, and so does the first page load after upgrading from plugin 1.x.

If the test says Scholar answered with the YunoHost portal, the `api` permission of the Scholar app is missing. Upgrade Scholar to 1.3.0 or later: the upgrade creates that permission. It lets server-to-server calls reach Scholar without a portal sign-in, and every call still needs the token.

## Settings

| Setting | What it does |
|---|---|
| Scholar address, API token | Where Scholar is and how to sign in to it. Leave the token empty to keep the saved one. |
| Teacher accounts created in Scholar | **YunoHost accounts**: teachers sign in to Scholar with the YunoHost account that has the same username as in RosarioSIS. **Local Scholar accounts**: Scholar makes a temporary password, which the plugin shows once, right after the account is created. Students never get an account: their RosarioSIS student ID is what they type in Scholar's test portal (Scholar's front page). |
| Create and link every new course period | When on, every course period created in **Scheduling › Courses** is created in Scholar and set up at once. |
| At a new default school year, link the rolled-over course periods | When on, course periods rolled over from a linked course period get their own new Scholar course. |
| At a new default school year, deactivate leavers | When on, students not enrolled in the new year are deactivated in Scholar and can no longer enter the test portal. Their results stay in Scholar. A student is reactivated if they come back. |
| Sync every (minutes) | How often the full background sync runs (rosters and every grade). Grade changes themselves arrive instantly. The default is 15. |
| Address Scholar uses to reach RosarioSIS | Leave empty: the plugin uses this site's own address. Fill in only if Scholar must reach RosarioSIS another way. |
| Gradebook category | The name of the assignment category the plugin adds to each linked teacher's gradebook. |
| ID prefix | Scholar records are tagged `rosario:student:42`, `rosario:staff:jdoe` and `rosario:cp:7`. Change the prefix only if two RosarioSIS sites share one Scholar, and only before linking anything. |

## The Courses screen

**Configuration › Courses** lists the course periods of the school year selected in the side menu. For each one you can:

- **Create in Scholar**: makes a new Scholar course, adds the teacher and students, adds the gradebook category and imports existing results.
- **Link to an existing Scholar course**: does the same with a course someone already built in Scholar, for example with its tests already written.
- **Sync now**, **Unlink**, or **Unlink and archive in Scholar**. Unlinking never deletes anything in Scholar or in the gradebook.

**Create and link all** links every course period that isn't linked yet. **Sync everything now** runs a full sync.

## How grades are written

- Each Scholar test that is published or closed and has at least one result becomes an assignment in the "Scholar tests" category.
  - The assignment is dated by the test's closing date. A test without one is dated by its first submission. The assignment goes in the marking period that contains that date: a quarter if there is one, otherwise a semester, otherwise the full year.
  - Drafts are ignored.
- The grade is the student's counted attempt, best or latest according to Scholar's attempt policy.
  - If a test is worth a whole number of points, the assignment uses the same points.
  - Otherwise the assignment is out of 100 and the score is scaled. For example, 6.25 / 7.5 is written as 83.33 / 100.
- Results that still need an essay graded are written only once grading is done. The moment the last essay is graded in Scholar, the grade appears.
- If an attempt is deleted in Scholar (so the student can retake the test), the grade the plugin wrote for it is taken back out of the gradebook, unless a teacher changed it by hand.
- **A teacher's own changes win.**
  - If a teacher changes a grade in the gradebook, later syncs leave it alone.
  - If a teacher deletes a Scholar assignment, it is not recreated.
  - Grades that nobody touched keep following Scholar, for example after a score override or a corrected answer key.
- Only students currently scheduled in the course period get grades.

## Instant updates

Scholar keeps a log of every change that can alter a grade, written in the same database transaction as the change, so none is missed. For each linked course it then sends the plugin one signed request:

```
POST https://your-rosariosis/plugins/<plugin folder>/webhook.php
X-Scholar-Timestamp: 1793418450
X-Scholar-Signature: sha256=<HMAC-SHA256 of "<timestamp>.<body>" with the shared secret>
{"event":"grades.changed","course":{"id":2,"external_id":"rosario:cp:7"}, ...}
```

`webhook.php` refuses requests that are not POST, are not signed with the secret, or are more than five minutes old, then copies that course's grades before answering. The request carries no grades: the plugin reads them from Scholar with its token, so a forged request can at worst cause a harmless extra sync (and is refused anyway).

Things that can go wrong, and what covers them:

| Problem | What happens |
|---|---|
| RosarioSIS is down or busy | Scholar retries after 10 s, 30 s, 2, 10, 30 min, then 1, 1 and 2 hours. |
| Scholar can't reach the address (portal login redirect, firewall) | **Connect instant updates** says so. Grades still arrive: the plugin asks Scholar for missed changes at least once a minute while RosarioSIS is in use, and every run of the cron job below does too. |
| A request was lost | The same change check finds it. A full sync every 15 minutes is the last safety net. |

On a YunoHost server where RosarioSIS is behind the portal, either let `/plugins/<plugin folder>/webhook.php` through the portal, or set **Address Scholar uses to reach RosarioSIS** to an address that works, or just use the cron job: with `* * * * *` grades arrive within a minute.

The Settings screen shows when the last update arrived from Scholar and when the last check ran; Scholar's **Administration → Integrations** page lists recent changes and whether each was delivered.

## When things happen

| Event | What the plugin does |
|---|---|
| Grades change in Scholar | Scholar notifies the plugin; the course's grades are copied within seconds. |
| A course period is created | Creates and links it, if automatic linking is on. |
| A course period is edited (name, teacher, marking period) | Updates the Scholar course's code, title and term, and refreshes its roster. |
| A course period is deleted | Unlinks it and archives its Scholar course. |
| Students are scheduled or dropped (Schedule, Mass Schedule, Mass Drops, Scheduler) | Starts a sync right after the page is sent. |
| Any page load | Asks Scholar for missed changes if the last check is over a minute old; starts a full sync in the background if the last one is older than the sync interval, or if the default school year changed. |
| The default school year moves forward (after a Rollover, "OK, I am ready" or a `$DefaultSyear` edit) | Archives the older courses, links the new year's course periods and deactivates leavers, once. |

Background work runs after the page has been sent, so nobody waits for it. Only one sync of a course runs at a time. Problems are listed in **Configuration › Log** and next to the course period on the Courses screen.

### Syncing without visitors

To keep rosters and grades current at night and during holidays, and as a backup for the instant updates, add a cron job on the RosarioSIS server. The **Settings** screen shows the exact line:

```cron
* * * * * php /path/to/rosariosis/plugins/<plugin folder>/cron.php
```

Run it as the web server user, for example in `crontab -u www-data -e`. Each run only asks Scholar for new changes (one small request); the full sync inside it runs only every "Sync every" minutes. If you can only use a web scheduler, click **Enable the web cron address** under Settings and call the URL it shows. The URL contains a secret key; requests without that key get `403 Forbidden`.

## Uninstalling

- **Deactivate** the plugin under **School › Configuration › Plugins** to pause it. Its links and settings are kept, so reactivating it resumes where it stopped.
- **Delete** removes the plugin's files, its tables (`scholar_links`, `scholar_assignments`, `scholar_grades`, `scholar_log`) and its settings.

In both cases, gradebook assignments and grades already written stay in RosarioSIS, and nothing is removed from Scholar.

## License

MIT, see [LICENSE](LICENSE).
