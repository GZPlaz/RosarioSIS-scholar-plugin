# Scholar plugin for RosarioSIS

Connects [RosarioSIS](https://www.rosariosis.org) to [Scholar](https://github.com/GZPlaz/scholar-system_ynh), the testing and mastery platform of MindAccel University.

- **Create and link courses.** Each RosarioSIS course period can be created in Scholar, or linked to a course that already exists there. New course periods can be created and linked automatically.
- **Linking sets the course up.** The teacher and every scheduled student are added to the Scholar course. Accounts are created if needed. The teacher gets a "Scholar tests" category in their gradebook.
- **Test grades flow into the gradebook by themselves.** Every Scholar test with results becomes a gradebook assignment, and each student's score is written as their grade. Rosters and grades are refreshed in the background.
- **A new school year cleans up after itself.** When the default school year moves forward:
  - last year's Scholar courses are archived, and their results are kept;
  - the rolled-over course periods are linked to fresh Scholar courses and set up;
  - Scholar accounts of students who left are deactivated.

Requires RosarioSIS 11.0 or later and PHP 7.4+ with the curl and json extensions, on PostgreSQL or MySQL. Scholar must be version 1.2.0 or later.

## Installation

1. Download this repository and put it in the RosarioSIS `plugins/` folder as **`plugins/Scholar`**. The folder must be named exactly `Scholar`, not `RosarioSIS-scholar-plugin-main`.

   ```bash
   cd /path/to/rosariosis/plugins
   git clone https://github.com/GZPlaz/RosarioSIS-scholar-plugin.git Scholar
   ```

2. In RosarioSIS, go to **School › Configuration › Plugins** and activate **Scholar**. The plugin's tables are created at this step.
3. In Scholar, open **Administration › Integrations**, create a token named "RosarioSIS" and copy it. It is shown only once.
4. Back in RosarioSIS, click **Configuration** next to the Scholar plugin. Under **Settings**, enter Scholar's address (for example `https://apps.mindaccel.org/scholar`) and the token, then click **Test the connection**.

If the test says Scholar answered with the YunoHost portal, the `api` permission of the Scholar app is missing. Upgrade Scholar to 1.2.0 or later: the upgrade creates that permission. It lets server-to-server calls reach Scholar without a portal sign-in, and every call still needs the token.

## Settings

| Setting | What it does |
|---|---|
| Scholar address, API token | Where Scholar is and how to sign in to it. Leave the token empty to keep the saved one. |
| Accounts created in Scholar | **YunoHost accounts**: students and teachers sign in to Scholar with the YunoHost account that has the same username as in RosarioSIS. **Local Scholar accounts**: Scholar makes a temporary password, which the plugin shows once, right after the account is created. |
| Create and link every new course period | When on, every course period created in **Scheduling › Courses** is created in Scholar and set up at once. |
| At a new default school year, link the rolled-over course periods | When on, course periods rolled over from a linked course period get their own new Scholar course. |
| At a new default school year, deactivate leavers | When on, Scholar accounts of students not enrolled in the new year are deactivated. Their results stay in Scholar. An account is reactivated if the student comes back. |
| Sync every (minutes) | The most often the background sync runs. The default is 15. |
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
- Results that still need an essay graded are written only once grading is done.
- **A teacher's own changes win.**
  - If a teacher changes a grade in the gradebook, later syncs leave it alone.
  - If a teacher deletes a Scholar assignment, it is not recreated.
  - Grades that nobody touched keep following Scholar, for example after a score override or a corrected answer key.
- Only students currently scheduled in the course period get grades.

## When things happen

| Event in RosarioSIS | What the plugin does |
|---|---|
| A course period is created | Creates and links it, if automatic linking is on. |
| A course period is edited (name, teacher, marking period) | Updates the Scholar course's code, title and term, and refreshes its roster. |
| A course period is deleted | Unlinks it and archives its Scholar course. |
| Students are scheduled or dropped (Schedule, Mass Schedule, Mass Drops, Scheduler) | Starts a sync right after the page is sent. |
| Any page load | Starts a sync in the background if the last one is older than the sync interval, or if the default school year changed. |
| The default school year moves forward (after a Rollover, "OK, I am ready" or a `$DefaultSyear` edit) | Archives the older courses, links the new year's course periods and deactivates leavers, once. |

Background syncs run after the page has been sent, so nobody waits for them. Only one sync runs at a time. Problems are listed in **Configuration › Log** and next to the course period on the Courses screen.

### Syncing without visitors

To keep grades current at night and during holidays, add a cron job on the RosarioSIS server. The **Settings** screen shows the exact line:

```cron
*/15 * * * * php /path/to/rosariosis/plugins/Scholar/cron.php
```

Run it as the web server user, for example in `crontab -u www-data -e`. If you can only use a web scheduler, click **Enable the web cron address** under Settings and call the URL it shows. The URL contains a secret key; requests without that key get `403 Forbidden`.

## Uninstalling

- **Deactivate** the plugin under **School › Configuration › Plugins** to pause it. Its links and settings are kept, so reactivating it resumes where it stopped.
- **Delete** removes the plugin's files, its tables (`scholar_links`, `scholar_assignments`, `scholar_grades`, `scholar_log`) and its settings.

In both cases, gradebook assignments and grades already written stay in RosarioSIS, and nothing is removed from Scholar.

## License

MIT, see [LICENSE](LICENSE).
